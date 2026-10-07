<?php

namespace App\Services;

use App\Support\AlertCondition;
use App\Support\ErrorCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns the health vocabulary (EngineHealth alerts + per-device backup health)
 * into Telegram messages. Producers never talk to Telegram: conditions are
 * evaluated into a persistent queue and a separate step delivers it with retry.
 * Detection → alert, persistence → cooldown re-alert, disappearance → recovery.
 */
class NotificationManager
{
    private const MAX_ATTEMPTS = 5;

    /** From this many items in one run, alerts/recoveries go out as a single grouped message. */
    private const GROUP_MIN = 3;

    private const GROUP_MAX_LINES = 15;

    private const SYSTEM_LABELS = [
        'engine_down' => ['Motor de backup parado', 'critical'],
        'worker_stale' => ['Worker do motor sem resposta', 'critical'],
        'scheduler_stale' => ['Agendador atrasado', 'warning'],
        'queue_backlog' => ['Fila de backups acumulada', 'warning'],
        'stale_jobs' => ['Execuções travadas', 'warning'],
        'storage_warning' => ['Armazenamento acima de 80%', 'warning'],
        'storage_critical' => ['Armazenamento quase cheio', 'critical'],
        'ftp_processing_stale' => ['Processamento de arquivos FTP travado', 'warning'],
        'retention_failed' => ['Falha na limpeza por retenção', 'warning'],
    ];

    public const DEVICE_REASONS = [
        'consecutive_failures' => 'falhas consecutivas',
        'scheduled_never_succeeded' => 'agendado e nunca concluiu com sucesso',
        'latest_backup_failed' => 'o último backup falhou',
        'backup_stale' => 'backup muito atrasado',
        'backup_delayed' => 'backup atrasado',
        'ftp_never_received' => 'nenhum arquivo recebido por FTP',
        'ftp_backup_stale' => 'envio FTP muito atrasado',
        'ftp_backup_delayed' => 'envio FTP atrasado',
    ];

    public function __construct(
        private readonly NotificationSettings $settings,
        private readonly EngineHealth $health,
        private readonly DeviceBackupHealth $devices,
        private readonly InstanceTimezone $timezone,
        private readonly NotificationSummary $summary,
        private readonly FtpAuthFailures $ftpAuth,
        private readonly FtpAlertSources $ftpSources,
    ) {}

    /** @return array{queued:int,sent:int,failed:int} */
    public function run(): array
    {
        $queued = $this->settings->ready() ? $this->evaluate() + $this->summaries() + $this->retentionNotices() : 0;

        return ['queued' => $queued] + $this->deliver();
    }

    /** @return array<string, array{label:string,severity:string,reason:string,detail:string}> */
    public function conditions(): array
    {
        $conditions = [];
        foreach ($this->health->report()['alerts'] as $code) {
            if (isset(self::SYSTEM_LABELS[$code])) {
                [$label, $severity] = self::SYSTEM_LABELS[$code];
                $conditions["system:{$code}"] = ['label' => $label, 'severity' => $severity, 'reason' => $code, 'detail' => ''];
            }
        }
        foreach ($this->devices->rows() as $row) {
            if (! in_array($row['status'], ['warning', 'critical'], true)) {
                continue;
            }
            $reason = self::DEVICE_REASONS[$row['reason']] ?? $row['reason'];
            $conditions["device:{$row['device_id']}"] = [
                'label' => "Backup com problema: {$row['name']}", 'severity' => $row['status'],
                'reason' => $row['reason'], 'detail' => ucfirst($reason).'.',
            ];
        }

        return $conditions + $this->ftpAuthConditions() + $this->ftpConditions();
    }

    /**
     * Arquivos FTP rejeitados (quarentena) e servidor FTP fora do ar. Um erro de leitura propaga: evaluate()
     * descarta a rodada inteira em vez de inventar "normalizado".
     *
     * @return array<string, array{label:string,severity:string,reason:string,detail:string}>
     */
    private function ftpConditions(): array
    {
        $conditions = [];
        $hours = (int) config('backup.ftp_rejected_window_hours');
        foreach ($this->ftpSources->rejected() as $username => $rejected) {
            $conditions["ftp-rejected:{$username}"] = [
                'label' => "Arquivo FTP rejeitado: {$rejected['label']}",
                'severity' => 'warning',
                'reason' => 'ftp_file_rejected',
                'detail' => "{$rejected['count']} arquivo(s) rejeitado(s) nas últimas {$hours} h. Motivo: "
                    .ErrorCodes::message($rejected['error_code']).' O equipamento enviou, mas o arquivo não passou na validação.',
            ];
        }
        if ($this->ftpSources->serverDown()) {
            $conditions['system:ftp_server_down'] = [
                'label' => 'Servidor FTP fora do ar', 'severity' => 'critical', 'reason' => 'ftp_server_down',
                'detail' => 'A porta do FTP não respondeu em duas tentativas seguidas. Os equipamentos não conseguem enviar backups por FTP.',
            ];
        }

        return $conditions;
    }

    /**
     * Aviso (informativo) de limpeza por retenção que removeu backups, uma vez por execução. Só aplica
     * remoções de verdade, olha as últimas 24 h e espera o fim da manutenção.
     */
    public function retentionNotices(): int
    {
        if ($this->settings->inMaintenance($this->timezone->localNow())) {
            return 0;
        }
        $queued = 0;
        $events = DB::table('audit_events')->where('action', 'backup_retention.completed')
            ->where('created_at', '>=', now()->subDay())->orderBy('id')->get(['id', 'metadata']);
        foreach ($events as $event) {
            $metadata = json_decode($event->metadata, true) ?: [];
            $deleted = (int) ($metadata['deleted'] ?? 0);
            if (($metadata['mode'] ?? '') !== 'apply' || $deleted < 1) {
                continue;
            }
            $key = "retention:{$event->id}";
            if (DB::table('notification_queue')->where('kind', 'notice')->where('condition_key', $key)->exists()) {
                continue;
            }
            $errors = (int) ($metadata['errors'] ?? 0);
            $this->enqueue('notice', $key, 'info', 'Limpeza por retenção concluída',
                "{$deleted} backup(s) antigo(s) removido(s) pela política de retenção."
                .($errors > 0 ? " {$errors} erro(s) durante a limpeza." : ''));
            $queued++;
        }

        return $queued;
    }

    /**
     * Accounts whose FTP logins keep being refused (typically a device whose
     * stored password drifted from the panel's). If the log cannot be read, the
     * conditions already active are carried over so a read failure never
     * fabricates a "normalized" message.
     *
     * @return array<string, array{label:string,severity:string,reason:string,detail:string}>
     */
    private function ftpAuthConditions(): array
    {
        $active = $this->ftpAuth->active();
        if ($active === null) {
            return DB::table('notification_states')->where('active', true)
                ->where('condition_key', 'like', 'ftp-auth:%')->get()
                ->mapWithKeys(fn ($s) => [$s->condition_key => [
                    'label' => $s->label, 'severity' => 'warning', 'reason' => (string) $s->reason, 'detail' => '',
                ]])->all();
        }

        $window = (int) config('backup.ftp_auth_window_minutes');
        $conditions = [];
        foreach ($active as $username => $failure) {
            $conditions["ftp-auth:{$username}"] = [
                'label' => "Login FTP recusado: {$failure['label']}",
                'severity' => 'warning',
                'reason' => 'ftp_auth_refused',
                'detail' => "{$failure['count']} recusas nos últimos {$window} min (último IP {$failure['last_ip']}). "
                    .'A senha configurada no equipamento pode estar diferente da conta no servidor: rotacione a conta no painel e reconfigure o equipamento.',
            ];
        }

        return $conditions;
    }

    public function evaluate(): int
    {
        try {
            $current = $this->conditions();
        } catch (Throwable) {
            return 0; // never fabricate recoveries from a failed evaluation
        }

        $now = CarbonImmutable::now('UTC');
        $maintenance = $this->settings->inMaintenance($now->setTimezone($this->timezone->get()));
        $cooldown = (int) $this->settings->get()->cooldown_minutes;
        $queued = 0;
        $alerts = [];

        foreach ($current as $key => $c) {
            if ($maintenance && $c['severity'] !== 'critical') {
                continue; // stays unnotified; sent after the window if still active
            }
            $state = DB::table('notification_states')->where('condition_key', $key)->first();
            $due = ! $state || ! $state->active || $state->reason !== $c['reason']
                || ! $state->last_notified_at
                || CarbonImmutable::parse($state->last_notified_at, 'UTC')->addMinutes($cooldown)->lte($now);
            if (! $due) {
                continue;
            }
            $alerts[] = ['key' => $key] + $c;
            DB::table('notification_states')->updateOrInsert(['condition_key' => $key], [
                'active' => true, 'reason' => $c['reason'], 'label' => $c['label'],
                'last_notified_at' => $now, 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
        $queued += $this->enqueueAlerts($alerts);

        $recovered = [];
        foreach (DB::table('notification_states')->where('active', true)->get() as $state) {
            if (isset($current[$state->condition_key])) {
                continue;
            }
            $recovered[] = ['key' => $state->condition_key, 'label' => $state->label];
            DB::table('notification_states')->where('condition_key', $state->condition_key)
                ->update(['active' => false, 'updated_at' => $now]);
        }
        $queued += $this->enqueueRecoveries($recovered);

        return $queued;
    }

    /** @param list<array{key:string,label:string,severity:string,detail:string}> $alerts */
    private function enqueueAlerts(array $alerts): int
    {
        if (count($alerts) < self::GROUP_MIN) {
            foreach ($alerts as $a) {
                $this->enqueue('alert', $a['key'], $a['severity'], $a['label'], $a['detail']);
            }

            return count($alerts);
        }
        $lines = array_map(fn ($a) => '• '.$a['label'].($a['detail'] !== '' ? ' — '.$a['detail'] : ''), $alerts);
        $severity = in_array('critical', array_column($alerts, 'severity'), true) ? 'critical' : 'warning';
        $this->enqueue('alert', null, $severity, count($alerts).' alertas novos', $this->lines($lines));

        return 1;
    }

    /** @param list<array{key:string,label:string}> $recovered */
    private function enqueueRecoveries(array $recovered): int
    {
        if (count($recovered) < self::GROUP_MIN) {
            foreach ($recovered as $r) {
                $this->enqueue('recovery', $r['key'], 'info', 'Normalizado: '.$r['label'], 'A condição não está mais ativa.');
            }

            return count($recovered);
        }
        $lines = array_map(fn ($r) => '• '.$r['label'], $recovered);
        $this->enqueue('recovery', null, 'info', count($recovered).' condições normalizadas', $this->lines($lines));

        return 1;
    }

    /** @param list<string> $lines */
    private function lines(array $lines): string
    {
        $extra = count($lines) - self::GROUP_MAX_LINES;

        return implode("\n", array_slice($lines, 0, self::GROUP_MAX_LINES)).($extra > 0 ? "\n… e mais {$extra}" : '');
    }

    /** Queues the daily/weekly digest once its scheduled time has passed (idempotent per period). */
    public function summaries(): int
    {
        $settings = $this->settings->get();
        $local = $this->timezone->localNow();
        $queued = 0;
        foreach (['daily', 'weekly'] as $kind) {
            if (! $settings->{$kind.'_enabled'}) {
                continue;
            }
            if ($kind === 'weekly' && $local->dayOfWeekIso - 1 !== (int) $settings->weekly_day) {
                continue;
            }
            if ($local->format('H:i') < $settings->{$kind.'_time'}) {
                continue;
            }
            [$title, $body, $key] = $this->summary->build($kind, $local);
            if (DB::table('notification_queue')->where('kind', 'summary')->where('condition_key', $key)->exists()) {
                continue;
            }
            $this->enqueue('summary', $key, 'summary', $title, $body);
            $queued++;
        }

        return $queued;
    }

    public function enqueue(string $kind, ?string $key, string $severity, string $title, string $body): int
    {
        return DB::table('notification_queue')->insertGetId([
            'kind' => $kind, 'condition_key' => $key, 'severity' => $severity, 'title' => mb_substr($title, 0, 200),
            'body' => $body, 'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{sent:int,failed:int} */
    public function deliver(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $settings = $this->settings->get();
        if (! $settings->bot_token || ! $settings->chat_id) {
            return $result;
        }
        // Tests always go out; automatic items only while the channel is enabled.
        $items = DB::table('notification_queue')->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())
            ->when(! $settings->enabled, fn ($q) => $q->where('kind', 'test'))
            ->orderBy('id')->limit(20)->get();
        if ($items->isEmpty()) {
            return $result;
        }
        $token = $this->settings->token();

        foreach ($items as $item) {
            $error = $this->send($token, $settings->chat_id, $settings->thread_id, $item, $retryAfter);
            $attempts = $item->attempts + 1;
            if ($error === null) {
                DB::table('notification_queue')->where('id', $item->id)->update([
                    'status' => 'sent', 'attempts' => $attempts, 'sent_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
                $result['sent']++;
            } elseif ($attempts >= self::MAX_ATTEMPTS || $error === 'TELEGRAM_UNAUTHORIZED') {
                DB::table('notification_queue')->where('id', $item->id)->update([
                    'status' => 'failed', 'attempts' => $attempts, 'last_error' => $error, 'updated_at' => now(),
                ]);
                $result['failed']++;
            } else {
                $delay = max($retryAfter ?? 0, min(3600, 60 * (2 ** ($attempts - 1))));
                DB::table('notification_queue')->where('id', $item->id)->update([
                    'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => now()->addSeconds($delay), 'updated_at' => now(),
                ]);
            }
        }

        return $result;
    }

    /** @return string|null sanitized error code, null on success */
    private function send(string $token, string $chatId, ?int $threadId, object $item, ?int &$retryAfter = null): ?string
    {
        $retryAfter = null;
        $icon = match ($item->severity) {
            'critical' => '🔴', 'warning' => '🟠', 'info' => '🟢', 'summary' => '📊', default => '🔔',
        };
        $when = CarbonImmutable::now($this->timezone->get())->format('d/m/Y H:i');
        $text = "<b>{$icon} ".e($item->title).'</b>'
            .($item->body !== '' ? "\n".e($item->body) : '')
            ."\n<i>{$when}</i>";

        try {
            $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            ] + ($threadId ? ['message_thread_id' => $threadId] : []));
        } catch (Throwable) {
            return 'TELEGRAM_UNREACHABLE';
        }
        if ($response->successful()) {
            return null;
        }
        $retryAfter = (int) ($response->json('parameters.retry_after') ?? 0) ?: null;

        return match ($response->status()) {
            401 => 'TELEGRAM_UNAUTHORIZED',
            400, 403, 404 => 'TELEGRAM_DESTINATION_REJECTED',
            429 => 'TELEGRAM_RATE_LIMITED',
            default => 'TELEGRAM_HTTP_ERROR',
        };
    }
}
