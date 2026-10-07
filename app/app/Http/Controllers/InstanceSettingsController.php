<?php

namespace App\Http\Controllers;

use App\Services\AuditEvents;
use App\Services\BackupRetention;
use App\Services\FtpAccessSettings;
use App\Services\InstanceTimezone;
use App\Services\RetentionSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InstanceSettingsController extends Controller
{
    public function edit(Request $request, InstanceTimezone $timezone, RetentionSettings $retentionSettings, FtpAccessSettings $ftpAccess): View
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
