<?php

namespace App\Http\Controllers;

use App\Services\AuditEvents;
use App\Services\InstanceTimezone;
use App\Services\NotificationManager;
use App\Services\NotificationSettings;
use App\Services\NotificationSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificationSettingsController extends Controller
{
    private const ERRORS = [
        'TELEGRAM_UNAUTHORIZED' => 'Token do bot inválido ou revogado.',
        'TELEGRAM_DESTINATION_REJECTED' => 'Destino recusado: revise o Chat ID e se o bot participa do chat.',
        'TELEGRAM_RATE_LIMITED' => 'Limite de envio do Telegram; nova tentativa agendada.',
        'TELEGRAM_UNREACHABLE' => 'Telegram inacessível a partir do servidor.',
        'TELEGRAM_HTTP_ERROR' => 'Resposta inesperada do Telegram.',
    ];

    public function edit(NotificationSettings $settings, InstanceTimezone $timezone): View
    {
        $this->authorize('settings.view');
        $queue = DB::table('notification_queue');

        return view('settings.notifications', [
            'settings' => $settings->get(),
            'timezone' => $timezone->get(),
            'pending' => (clone $queue)->where('status', 'pending')->count(),
            'failed24h' => (clone $queue)->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'lastSent' => (clone $queue)->where('status', 'sent')->max('sent_at'),
            'history' => (clone $queue)->orderByDesc('id')->limit(30)->get(),
            'errorLabels' => self::ERRORS,
        ]);
    }

    public function update(Request $request, NotificationSettings $settings, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        $time = ['required', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/'];
        $validated = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:200', 'regex:/\A\d{6,}:[A-Za-z0-9_-]{30,}\z/'],
            'chat_id' => ['nullable', 'string', 'max:100', 'regex:/\A(?:-?\d+|@[A-Za-z0-9_]{5,})\z/'],
            'thread_id' => ['nullable', 'integer', 'min:1'],
            'cooldown_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'maintenance_start' => $time,
            'maintenance_end' => $time,
            'daily_time' => ['nullable', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/'],
            'weekly_time' => ['nullable', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/'],
            'weekly_day' => ['nullable', 'integer', 'between:0,6'],
        ], [
            'bot_token.regex' => 'Token em formato inválido (esperado 123456:ABC…).',
            'thread_id.integer' => 'ID do tópico inválido: use somente o número.',
            'chat_id.regex' => 'Chat ID inválido: use o número do chat (negativo em grupos) ou @canal.',
        ]);
        $enabled = $request->boolean('enabled');
        $current = $settings->get();
        $hasToken = $current->bot_token || ($validated['bot_token'] ?? '') !== '';
        $chatId = $validated['chat_id'] ?? $current->chat_id;
        if ($enabled && (! $hasToken || ! $chatId)) {
            return back()->withInput()->withErrors(['enabled' => 'Informe o token e o Chat ID antes de habilitar o canal.']);
        }

        $settings->save([
            'enabled' => $enabled, 'chat_id' => $chatId, 'thread_id' => $validated['thread_id'] ?? null,
            'cooldown_minutes' => $validated['cooldown_minutes'],
            'maintenance_enabled' => $request->boolean('maintenance_enabled'),
            'maintenance_start' => $validated['maintenance_start'], 'maintenance_end' => $validated['maintenance_end'],
            'daily_enabled' => $request->boolean('daily_enabled'), 'daily_time' => $validated['daily_time'] ?? $current->daily_time,
            'weekly_enabled' => $request->boolean('weekly_enabled'), 'weekly_time' => $validated['weekly_time'] ?? $current->weekly_time,
            'weekly_day' => $validated['weekly_day'] ?? $current->weekly_day,
        ], $validated['bot_token'] ?? null);
        // Never log the token: only that it changed.
        $audit->record('notifications.settings_updated', 'notification_settings', '1', 'Notificações Telegram', 'success', [
            'enabled' => $enabled, 'token_changed' => ($validated['bot_token'] ?? '') !== '',
        ], $request->user()->id, $request->ip());

        return redirect()->route('settings.notifications.edit')->with('success', 'Configuração de notificações salva.');
    }

    public function revealToken(Request $request, NotificationSettings $settings, AuditEvents $audit): JsonResponse
    {
        $this->authorize('settings.manage');
        abort_unless($settings->get()->bot_token, 404);
        $audit->record('notifications.token_revealed', 'notification_settings', '1', 'Notificações Telegram', 'success', [], $request->user()->id, $request->ip());

        return response()->json(['token' => $settings->token()])
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function testSummary(Request $request, string $kind, NotificationSettings $settings, NotificationManager $manager, NotificationSummary $summary, InstanceTimezone $timezone): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless(in_array($kind, ['daily', 'weekly'], true), 404);
        $current = $settings->get();
        if (! $current->bot_token || ! $current->chat_id) {
            return back()->withErrors(['enabled' => 'Salve o token e o Chat ID antes de testar.']);
        }
        [$title, $body] = $summary->build($kind, $timezone->localNow());
        $manager->enqueue('test', null, 'summary', "Prévia — {$title}", $body);

        return redirect()->route('settings.notifications.edit')->with('success', 'Prévia do resumo enfileirada; a entrega ocorre em até 1 minuto.');
    }

    public function test(Request $request, NotificationSettings $settings, NotificationManager $manager, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        $current = $settings->get();
        if (! $current->bot_token || ! $current->chat_id) {
            return back()->withErrors(['enabled' => 'Salve o token e o Chat ID antes de testar.']);
        }
        $manager->enqueue('test', null, 'info', 'Teste do Backup Manager', 'Se você recebeu esta mensagem, o canal Telegram está funcionando.');
        $audit->record('notifications.test_queued', 'notification_settings', '1', 'Notificações Telegram', 'success', [], $request->user()->id, $request->ip());

        return redirect()->route('settings.notifications.edit')
            ->with('success', 'Teste enfileirado; a entrega ocorre em até 1 minuto.');
    }
}
