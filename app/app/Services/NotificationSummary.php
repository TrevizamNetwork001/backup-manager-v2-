<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily/weekly digest. Periods are whole civil days in the instance
 * timezone (daily = yesterday, weekly = the 7 days before today), and each
 * period has a stable key so a restart can never send the same digest twice.
 */
class NotificationSummary
{
    private const MAX_DEVICES = 10;

    /**
     * Cópia de backups no Telegram no período. Falhas definitivas aparecem aqui, no resumo, e não como alerta.
     *
     * @return list<string>
     */
    private function telegramCopyLines($from, $to): array
    {
        $enabled = (bool) DB::table('notification_settings')->where('id', 1)->value('backup_copy_enabled');
        $sent = DB::table('telegram_backup_sends')->where('status', 'sent')
            ->where('sent_at', '>=', $from)->where('sent_at', '<', $to)->count();
        $failed = DB::table('telegram_backup_sends as s')
            ->leftJoin('backup_artifacts as a', 'a.id', '=', 's.backup_artifact_id')
            ->leftJoin('devices as d', 'd.id', '=', 'a.device_id')
            ->where('s.status', 'failed')->where('s.updated_at', '>=', $from)->where('s.updated_at', '<', $to)
            ->orderBy('s.id')->get(['d.name as device', 's.error_code']);
        if (! $enabled && $sent === 0 && $failed->isEmpty()) {
            return [];
        }

        $lines = ["📨 Cópia no Telegram: {$sent} enviado(s), {$failed->count()} com falha"];
        foreach ($failed->take(self::MAX_DEVICES) as $row) {
            $lines[] = '  • '.($row->device ?? 'equipamento removido').' — '
                .(TelegramBackupCopy::ERRORS[$row->error_code] ?? 'falha no envio');
        }
        if ($failed->count() > self::MAX_DEVICES) {
            $lines[] = '  … e mais '.($failed->count() - self::MAX_DEVICES);
        }

        return $lines;
    }

    public function __construct(private readonly InstanceTimezone $timezone, private readonly DeviceBackupHealth $devices) {}

    /** @return array{0:string,1:string,2:string} title, body, period key */
    public function build(string $kind, CarbonImmutable $localNow): array
    {
        $end = $localNow->startOfDay();
        $start = $kind === 'weekly' ? $end->subDays(7) : $end->subDay();
        $key = $kind === 'weekly' ? 'summary:weekly:'.$end->format('Y-m-d') : 'summary:daily:'.$start->format('Y-m-d');
        [$from, $to] = [$start->setTimezone('UTC'), $end->setTimezone('UTC')];

        $executions = fn () => DB::table('backup_executions')->where('finished_at', '>=', $from)->where('finished_at', '<', $to);
        $succeeded = $executions()->where('status', 'succeeded')->count();
        $viaFtp = $executions()->where('status', 'succeeded')->where('origin', 'ftp_received')->count();
        $failed = $executions()->whereIn('status', ['failed', 'timed_out'])->count();
        $rejected = DB::table('ftp_received_files')->where('status', 'quarantined')
            ->where('received_at', '>=', $from)->where('received_at', '<', $to)->count();
        $removed = DB::table('backup_artifacts')->where('deleted_at', '>=', $from)->where('deleted_at', '<', $to)->count();

        $attention = collect($this->devices->rows())->filter(fn ($r) => in_array($r['status'], ['warning', 'critical'], true));
        $lines = [
            'Período: '.$start->format('d/m H:i').' a '.$end->format('d/m H:i'),
            "✔ Backups concluídos: {$succeeded}".($viaFtp ? " ({$viaFtp} por FTP)" : ''),
            "✖ Falhas: {$failed}",
            "⚠ Arquivos FTP rejeitados: {$rejected}",
            "🗑 Backups removidos por retenção: {$removed}",
            ...$this->telegramCopyLines($from, $to),
            '🔧 Equipamentos que precisam de atenção agora: '.$attention->count(),
        ];
        foreach ($attention->take(self::MAX_DEVICES) as $row) {
            $lines[] = "  • {$row['name']} — ".(NotificationManager::DEVICE_REASONS[$row['reason']] ?? $row['reason']);
        }
        if ($attention->count() > self::MAX_DEVICES) {
            $lines[] = '  … e mais '.($attention->count() - self::MAX_DEVICES);
        }

        return [$kind === 'weekly' ? 'Resumo semanal' : 'Resumo diário', implode("\n", $lines), $key];
    }
}
