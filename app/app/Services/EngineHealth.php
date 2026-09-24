<?php

namespace App\Services;

use App\Models\BackupExecution;
use Illuminate\Support\Facades\DB;

/**
 * Read-only lifecycle snapshot. No UI in ENGINE-2 (deferred to a future
 * ENGINE-3 dashboard) — this exists so `engine:health` and any future
 * consumer have one place to ask "is the queue healthy right now."
 */
class EngineHealth
{
    public function snapshot(): array
    {
        $counts = BackupExecution::query()->whereIn('status', BackupExecution::STATUSES)
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'queued' => (int) ($counts['queued'] ?? 0),
            'running' => (int) ($counts['running'] ?? 0),
            'retry_wait' => (int) ($counts['retry_wait'] ?? 0),
            'timed_out' => (int) ($counts['timed_out'] ?? 0),
            'oldest_pending_seconds' => $this->oldestAgeSeconds(['pending', 'queued', 'retry_wait']),
            'last_success_at' => BackupExecution::query()->where('status', 'succeeded')
                ->max('finished_at'),
            'stale_running_count' => BackupExecution::query()->where('status', 'running')
                ->where('heartbeat_at', '<', now()->subSeconds((int) config('backup.engine_stale_seconds')))
                ->count(),
        ];
    }

    private function oldestAgeSeconds(array $statuses): ?int
    {
        $oldest = BackupExecution::query()->whereIn('status', $statuses)->min('created_at');

        return $oldest ? now()->diffInSeconds($oldest) : null;
    }
}
