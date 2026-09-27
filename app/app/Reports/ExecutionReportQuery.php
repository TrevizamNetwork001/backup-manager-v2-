<?php

namespace App\Reports;

use App\Models\BackupExecution;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of filter logic for the executions report — both the UI
 * table and the CSV export call filtered() so they can never drift apart
 * (FEATURES-FINAL-1, Part J).
 *
 * "Taxa de sucesso" only ever counts TERMINAL, pertinent states: succeeded
 * vs. failed/timed_out. pending/queued/running/retry_wait/cancelled never
 * count as failure — cancelled is an operator decision, not an error; the
 * others simply haven't finished yet (see docs/REPORTS.md).
 */
class ExecutionReportQuery
{
    public const TERMINAL_FAILURE = ['failed', 'timed_out'];

    public const TERMINAL_ALL = ['succeeded', 'failed', 'timed_out', 'cancelled'];

    public function filtered(array $filters): Builder
    {
        [$from, $to] = ReportPeriod::resolve($filters['period'] ?? null, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return BackupExecution::query()
            ->with(['device:id,name,site_id,vendor', 'device.site:id,name', 'backupPolicy:id,name,method', 'artifact:id,backup_execution_id,size_bytes'])
            ->when($from, fn ($q, $v) => $q->where('backup_executions.created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('backup_executions.created_at', '<=', $v))
            ->when($filters['site_id'] ?? null, fn ($q, $v) => $q->whereHas('device', fn ($d) => $d->where('site_id', $v)))
            ->when($filters['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($filters['vendor'] ?? null, fn ($q, $v) => $q->whereHas('device', fn ($d) => $d->where('vendor', $v)))
            ->when($filters['backup_policy_id'] ?? null, fn ($q, $v) => $q->where('backup_policy_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->whereHas('backupPolicy', fn ($p) => $p->where('method', $v)))
            ->when($filters['error_code'] ?? null, fn ($q, $v) => $q->where('error_code', $v))
            ->orderByDesc('backup_executions.id');
    }

    public function summary(array $filters): array
    {
        [$from, $to] = ReportPeriod::resolve($filters['period'] ?? null, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $base = $this->filtered($filters)->reorder();
        $counts = (clone $base)->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $succeeded = (int) ($counts['succeeded'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0) + (int) ($counts['timed_out'] ?? 0);
        $terminal = $succeeded + $failed;

        return [
            'total' => (int) $counts->sum(),
            'succeeded' => $succeeded,
            'failed' => (int) ($counts['failed'] ?? 0),
            'timed_out' => (int) ($counts['timed_out'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'pending_or_running' => (int) ($counts['pending'] ?? 0) + (int) ($counts['queued'] ?? 0)
                + (int) ($counts['running'] ?? 0) + (int) ($counts['retry_wait'] ?? 0),
            'success_rate_percent' => $terminal > 0 ? round($succeeded / $terminal * 100, 1) : null,
            'period_label' => \App\Support\ReportPeriod::label($filters['period'] ?? null),
        ];
    }

    /** Lazily iterates every matching row (no pagination) for CSV export — flat memory regardless of row count. */
    public function exportRows(array $filters): \Illuminate\Support\LazyCollection
    {
        return $this->filtered($filters)->reorder('backup_executions.id')->lazy(500);
    }
}
