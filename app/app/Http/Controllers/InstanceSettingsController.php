<?php

namespace App\Http\Controllers;

use App\Models\IntegrationToken;
use App\Services\AuditEvents;
use App\Services\BackupRetention;
use App\Services\FtpAccessSettings;
use App\Services\InstanceTimezone;
use App\Services\IntegrationApiSettings;
use App\Services\RetentionSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InstanceSettingsController extends Controller
{
    public function edit(Request $request, InstanceTimezone $timezone, RetentionSettings $retentionSettings, FtpAccessSettings $ftpAccess, IntegrationApiSettings $integrationApi): View
    {
        $this->authorize('settings.view');

        $currentTimezone = $timezone->get();
        $timezoneOptions = [
            'America/Rio_Branco' => 'Acre (AC) — Rio Branco',
            'America/Eirunepe' => 'Amazonas (oeste) — Eirunepé',
            'America/Manaus' => 'Amazonas (AM) — Manaus',
            'America/Boa_Vista' => 'Roraima (RR) — Boa Vista',
            'America/Porto_Velho' => 'Rondônia (RO) — Porto Velho',
            'America/Cuiaba' => 'Mato Grosso (MT) — Cuiabá',
            'America/Campo_Grande' => 'Mato Grosso do Sul (MS) — Campo Grande',
            'America/Santarem' => 'Pará (oeste) — Santarém',
            'America/Belem' => 'Amapá e Pará (AP/PA) — Belém',
            'America/Fortaleza' => 'Ceará, Maranhão e Piauí (CE/MA/PI)',
            'America/Recife' => 'Paraíba, Pernambuco e Rio Grande do Norte (PB/PE/RN)',
            'America/Maceio' => 'Alagoas e Sergipe (AL/SE)',
            'America/Bahia' => 'Bahia (BA) — Salvador',
            'America/Araguaina' => 'Tocantins (TO) — Araguaína',
            'America/Sao_Paulo' => 'Brasília, Goiás, Sudeste e Sul (DF/GO/ES/MG/RJ/SP/PR/SC/RS)',
            'America/Noronha' => 'Fernando de Noronha (PE)',
        ];

        if (! array_key_exists($currentTimezone, $timezoneOptions)) {
            $timezoneOptions = [$currentTimezone => "Fuso atual — {$currentTimezone}"] + $timezoneOptions;
        }

        return view('settings.edit', [
            'timezone' => $currentTimezone,
            'currentTime' => $timezone->localNow()->format('d/m/Y H:i:s'),
            'timezoneOptions' => $timezoneOptions,
            'retentionEnabled' => $retentionSettings->enabled(),
            'retentionAvailable' => $retentionSettings->available(),
            'retentionPreview' => $request->session()->get('retention_preview'),
            'ftpAccessAvailable' => $ftpAccess->available(),
            'ftpAccess' => $ftpAccess->get(),
            'integrationApiAvailable' => $integrationApi->available(),
            'integrationApi' => $integrationApi->get(),
            'integrationApiEnabled' => $integrationApi->enabled(),
            'integrationTokens' => $integrationApi->available()
                ? IntegrationToken::query()->select(['id', 'name', 'scope', 'expires_at', 'revoked_at', 'created_at'])
                    ->orderByDesc('id')->limit(20)->get() : collect(),
        ]);
    }

    public function update(Request $request, InstanceTimezone $timezone): RedirectResponse
    {
        $this->authorize('settings.manage');
        $validated = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);
        $timezone->set($validated['timezone']);

        return redirect()->route('settings.edit')->with('success', 'Fuso horário atualizado.');
    }

    public function updateFtpAccess(Request $request, FtpAccessSettings $ftpAccess): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless($ftpAccess->available(), 503);
        $validated = $request->validate([
            'ftp_allowed_cidrs' => ['required', 'string', 'max:4096'],
        ]);
        $cidrs = $ftpAccess->normalize($validated['ftp_allowed_cidrs']);
        $before = $ftpAccess->get()['cidrs'];
        if ($before !== $cidrs) {
            $revision = $ftpAccess->set($cidrs);
            app(AuditEvents::class)->record('ftp.access.changed', 'application_settings', '1', 'Origens FTP',
                'success', ['cidrs_before' => $before, 'cidrs_after' => $cidrs, 'revision' => $revision],
                $request->user()->id, $request->ip());
        }

        return redirect()->to(route('settings.edit').'#ftp-access')
            ->with('success', 'Origens FTP salvas. A aplicação no firewall será confirmada nesta tela.');
    }

    public function updateIntegrationApi(Request $request, IntegrationApiSettings $settings, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless($settings->available(), 503);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'integration_api_allowed_ips' => ['nullable', 'string', 'max:4096'],
        ]);
        $enabled = (bool) $validated['enabled'];
        $allowedIps = $settings->normalizeIps($validated['integration_api_allowed_ips'] ?? '');
        if ($enabled && (! $request->isSecure() || $allowedIps === [] || $settings->get()['active_tokens'] === 0)) {
            throw ValidationException::withMessages([
                'integration_api_allowed_ips' => 'Para ligar a API, acesse por HTTPS, informe IPs permitidos e crie um token ativo.',
            ]);
        }

        $before = $settings->get();
        if ($before['enabled'] !== $enabled || $before['allowed_ips'] !== $allowedIps) {
            $settings->save($enabled, $allowedIps);
            $audit->record('api.integration.settings_changed', 'application_settings', '1', 'API de integração',
                'success', ['enabled_before' => $before['enabled'], 'enabled_after' => $enabled,
                    'allowed_ips_before' => $before['allowed_ips'], 'allowed_ips_after' => $allowedIps],
                $request->user()->id, $request->ip());
        }

        return redirect()->to(route('settings.edit').'#integration-api')
            ->with('success', $enabled ? 'API de integração ativada.' : 'Configuração da API salva; acesso desativado.');
    }

    public function issueIntegrationToken(Request $request, IntegrationApiSettings $settings, AuditEvents $audit): Response
    {
        $this->authorize('settings.manage');
        abort_unless($settings->available(), 503);
        if (! $request->isSecure()) {
            throw ValidationException::withMessages(['integration_token' => 'A emissão de tokens exige acesso por HTTPS.']);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', 'integer', 'between:1,365'],
        ]);
        [$plain, $token] = IntegrationToken::issue(trim($validated['name']), (int) $validated['days']);
        $audit->record('api.integration.token.issued', 'integration_token', (string) $token->id,
            $token->name, 'success', ['scope' => $token->scope, 'expires_at' => $token->expires_at->toIso8601String()],
            $request->user()->id, $request->ip());

        return response()->view('settings.integration-token', ['plainToken' => $plain, 'token' => $token])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function revokeIntegrationToken(Request $request, IntegrationToken $integrationToken, IntegrationApiSettings $settings, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless($settings->available(), 503);
        $integrationToken->revoke();
        $audit->record('api.integration.token.revoked', 'integration_token', (string) $integrationToken->id,
            $integrationToken->name, 'success', [], $request->user()->id, $request->ip());
        $current = $settings->get();
        if ($current['enabled'] && $current['active_tokens'] === 0) {
            $settings->save(false, $current['allowed_ips']);
        }

        return redirect()->to(route('settings.edit').'#integration-api')
            ->with('success', 'Token revogado.');
    }

    public function previewRetention(Request $request, BackupRetention $retention): RedirectResponse
    {
        $this->authorize('settings.manage');
        $summary = $retention->run(false);
        $request->session()->put('retention_preview', [
            'created_at' => now()->timestamp,
            'summary' => $summary,
        ]);

        return redirect()->to(route('settings.edit').'#retention')
            ->with('success', 'Prévia da limpeza gerada. Nenhum arquivo foi removido.');
    }

    public function updateRetention(Request $request, RetentionSettings $settings, BackupRetention $retention): RedirectResponse
    {
        $this->authorize('settings.manage');
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];
        $previous = $settings->enabled();

        if ($enabled && ! $previous) {
            $preview = $request->session()->get('retention_preview');
            if (! $request->boolean('confirmed') || ! is_array($preview) ||
                now()->timestamp - ($preview['created_at'] ?? 0) > 600) {
                throw ValidationException::withMessages([
                    'enabled' => 'Gere uma prévia recente e confirme antes de ligar a limpeza automática.',
                ]);
            }

            $summary = $retention->run(false);
            if ($summary !== ($preview['summary'] ?? null)) {
                $request->session()->put('retention_preview', [
                    'created_at' => now()->timestamp,
                    'summary' => $summary,
                ]);

                return redirect()->to(route('settings.edit').'#retention')
                    ->with('warning', 'Os candidatos à limpeza mudaram. Confira a nova prévia antes de ligar.');
            }
        }

        if ($enabled !== $previous) {
            $settings->setEnabled($enabled);
            app(AuditEvents::class)->record('backup_retention.setting_changed', 'application_settings', '1', 'Retenção automática',
                'success', ['enabled_before' => $previous, 'enabled_after' => $enabled], $request->user()->id, $request->ip());
        }
        $request->session()->forget('retention_preview');

        return redirect()->to(route('settings.edit').'#retention')
            ->with('success', $enabled ? 'Limpeza automática ligada.' : 'Limpeza automática desligada.');
    }
}
