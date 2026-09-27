<?php

namespace App\Reports;

use App\Services\DeviceBackupHealth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Wraps DeviceBackupHealth::rows() (the same computation ENGINE-3's health
 * check and the dashboard already use — never a second copy of the
 * classify/streak logic, per docs/REPORTS.md) with the report's own
 * filters/pagination.
 */
class DeviceReportQuery
{
    public function __construct(private readonly DeviceBackupHealth $health)
    {
    }

    public function filtered(array $filters): Collection
    {
        $rows = collect($this->health->rows());

        return $rows
            ->when($filters['site_id'] ?? null, fn ($c, $v) => $c->where('site_id', (int) $v))
            ->when($filters['vendor'] ?? null, fn ($c, $v) => $c->where('vendor', $v))
            ->when($filters['status'] ?? null, fn ($c, $v) => $c->where('status', $v))
            ->when(($filters['policy'] ?? null) === 'with', fn ($c) => $c->where('has_policy', true))
            ->when(($filters['policy'] ?? null) === 'without', fn ($c) => $c->where('has_policy', false))
            ->when(($filters['freshness'] ?? null) === 'never', fn ($c) => $c->whereNull('last_backup_at'))
            ->when(($filters['freshness'] ?? null) === 'delayed', fn ($c) => $c->whereIn('status', ['warning', 'critical']))
            ->sortBy('name')->values();
    }

    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $rows = $this->filtered($filters);
        $page = max(1, (int) request('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}
