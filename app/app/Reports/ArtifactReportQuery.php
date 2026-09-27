<?php

namespace App\Reports;

use App\Models\BackupArtifact;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * DB-metadata only — never a filesystem scan per request (V1 lesson
 * confirmed good: its storage/artifact reports are also DB-only; see
 * docs/REPORTS.md "Comparação com V1").
 */
class ArtifactReportQuery
{
    public function filtered(array $filters): Builder
    {
        [$from, $to] = ReportPeriod::resolve($filters['period'] ?? null, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return BackupArtifact::query()
            ->with(['device:id,name,site_id', 'device.site:id,name'])
            ->when($from, fn ($q, $v) => $q->where('backup_artifacts.created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('backup_artifacts.created_at', '<=', $v))
            ->when($filters['site_id'] ?? null, fn ($q, $v) => $q->whereHas('device', fn ($d) => $d->where('site_id', $v)))
            ->when($filters['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['min_size'] ?? null, fn ($q, $v) => $q->where('size_bytes', '>=', (int) $v))
            ->when($filters['max_size'] ?? null, fn ($q, $v) => $q->where('size_bytes', '<=', (int) $v))
            ->orderByDesc('backup_artifacts.id');
    }

    public function exportRows(array $filters): \Illuminate\Support\LazyCollection
    {
        return $this->filtered($filters)->reorder('backup_artifacts.id')->lazy(500);
    }
}
