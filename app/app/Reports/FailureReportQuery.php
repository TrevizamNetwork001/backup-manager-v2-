<?php

namespace App\Reports;

use App\Models\BackupExecution;
use App\Services\EngineJobService;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Groups by error_code (never by free-text message — codes are the stable,
 * canonical vocabulary, see engine/errors.py) within the period, plus
 * "equipamentos mais afetados". Reuses EngineJobService::RETRYABLE_CODES so
 * "retryable?" never drifts from what the engine itself actually retries.
 */
class FailureReportQuery
{
    private function baseQuery(array $filters)
    {
        [$from, $to] = ReportPeriod::resolve($filters['period'] ?? null, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return BackupExecution::query()
            ->whereIn('status', ExecutionReportQuery::TERMINAL_FAILURE)
            ->whereNotNull('error_code')
            ->when($from, fn ($q, $v) => $q->where('backup_executions.created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('backup_executions.created_at', '<=', $v))
            ->when($filters['site_id'] ?? null, fn ($q, $v) => $q->whereHas('device', fn ($d) => $d->where('site_id', $v)))
            ->when($filters['vendor'] ?? null, fn ($q, $v) => $q->whereHas('device', fn ($d) => $d->where('vendor', $v)));
    }

    public function byErrorCode(array $filters): array
    {
        $rows = $this->baseQuery($filters)
            ->select('error_code', DB::raw('count(*) as total'),
                DB::raw('max(created_at) as last_seen_at'), DB::raw('min(created_at) as first_seen_at'))
            ->groupBy('error_code')->orderByDesc('total')->limit(50)->get();

        return $rows->map(fn ($row) => [
            'error_code' => $row->error_code,
            'total' => $row->total,
            'last_seen_at' => $row->last_seen_at,
            'first_seen_at' => $row->first_seen_at,
            'retryable' => in_array($row->error_code, EngineJobService::RETRYABLE_CODES, true),
        ])->all();
    }

    public function byDevice(array $filters, int $limit = 20): array
    {
        return $this->baseQuery($filters)
            ->join('devices', 'devices.id', '=', 'backup_executions.device_id')
            ->select('devices.id as device_id', 'devices.name', 'devices.vendor',
                DB::raw('count(*) as total'), DB::raw('max(backup_executions.created_at) as last_seen_at'))
            ->groupBy('devices.id', 'devices.name', 'devices.vendor')
            ->orderByDesc('total')->limit($limit)->get()->all();
    }
}
