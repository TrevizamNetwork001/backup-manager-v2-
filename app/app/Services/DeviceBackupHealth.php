<?php

namespace App\Services;

use App\Models\Device;
use App\Support\HealthStatus;
use Illuminate\Support\Facades\DB;

/**
 * Per-device backup health (ENGINE-3). Deliberately separate from
 * EngineHealth: this classifies individual devices, not the system as a
 * whole, and is meant to be reused later by a real per-device "Backup
 * Health" page — see docs/ENGINE_HEALTH.md.
 *
 * V1 lesson (backup_manager/observability.py backup_status()): don't judge a
 * manual-only device by the same freshness clock as a scheduled one, and
 * distinguish "never ran because nothing was scheduled" from "should have
 * run and didn't" — V1 tracked the schedule-mode distinction only on a
 * per-device drill-down page, never in its main dashboard classification.
 * Here it's built into the summary from the start.
 */
class DeviceBackupHealth
{
    public function summary(?int $limit = 20): array
    {
        $rows = $this->rows();
        $counts = ['healthy' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0];
        $problems = [];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
            if ($row['status'] !== HealthStatus::Healthy->value) {
                $problems[] = ['device_id' => $row['device_id'], 'name' => $row['name'],
                    'status' => $row['status'], 'reason' => $row['reason']];
            }
        }

        usort($problems, fn ($a, $b) => HealthStatus::from($b['status'])->severity() <=> HealthStatus::from($a['status'])->severity());

        return [
            'counts' => $counts,
            'problem_devices' => $limit === null ? $problems : array_slice($problems, 0, $limit),
            'problem_devices_total' => count($problems),
        ];
    }

    public function failedDeviceCount(): int
    {
        $latestResult = DB::table('backup_executions')
            ->select(['device_id', 'status'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY id DESC) AS result_rank')
            ->whereIn('status', ['succeeded', 'failed', 'timed_out', 'retry_wait', 'cancelled']);

        return DB::query()->fromSub($latestResult, 'latest_result')
            ->join('devices', 'devices.id', '=', 'latest_result.device_id')
            ->where('devices.is_active', true)
            ->where('latest_result.result_rank', 1)
            ->whereIn('latest_result.status', ['failed', 'timed_out', 'retry_wait'])
            ->count();
    }

    /**
     * Full per-device rows (FEATURES-FINAL-1 device report reuses this —
     * "a fonte deve ser o mesmo serviço usado pelo health", never a second
     * copy of the classify/streak logic). One row per active device, with
     * last backup (any status), last success, last failure, artifact size,
     * consecutive-failure streak and the same health classification as
     * summary().
     *
     * @return list<array{device_id:int,name:string,site_id:?int,vendor:?string,model:?string,
     *   method:?string,policy_name:?string,last_backup_at:?string,last_success_at:?string,
     *   last_failure_at:?string,latest_artifact_size:?int,consecutive_failures:int,status:string,reason:string}>
     */
    public function rows(): array
    {
        $devices = Device::query()->where('is_active', true)
            ->with(['deviceBackupPolicies' => fn ($q) => $q->where('is_active', true)
                ->with('backupPolicy:id,is_active,schedule_type,method,name')])
            ->get(['id', 'name', 'site_id', 'vendor', 'model']);

        if ($devices->isEmpty()) {
            return [];
        }

        $latestSuccess = DB::table('backup_executions')->select('device_id', DB::raw('MAX(created_at) as at'))
            ->where('status', 'succeeded')->groupBy('device_id')->pluck('at', 'device_id');
        $latestExecution = collect(DB::select(<<<'SQL'
            SELECT device_id, created_at, method, policy_name FROM (
                SELECT be.device_id, be.created_at, bp.method, bp.name AS policy_name,
                       ROW_NUMBER() OVER (PARTITION BY be.device_id ORDER BY be.id DESC) AS rn
                FROM backup_executions be
                JOIN backup_policies bp ON bp.id = be.backup_policy_id
            ) ranked WHERE rn = 1
        SQL))->keyBy('device_id');
        $latestFailure = DB::table('backup_executions')->select('device_id', DB::raw('MAX(created_at) as at'))
            ->whereIn('status', ['failed', 'timed_out'])->groupBy('device_id')->pluck('at', 'device_id');
        $latestArtifact = collect(DB::select("
            SELECT device_id, size_bytes FROM (
                SELECT device_id, size_bytes,
                       ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY id DESC) AS rn
                FROM backup_artifacts WHERE status = 'available'
            ) ranked WHERE rn = 1
        "))->keyBy('device_id');

        $sample = (int) config('health.device_recent_executions_sample');
        $recentByDevice = collect(DB::select('
            SELECT device_id, status FROM (
                SELECT device_id, status,
                       ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY id DESC) AS rn
                FROM backup_executions
                WHERE status IN (\'succeeded\', \'failed\', \'timed_out\', \'retry_wait\', \'cancelled\')
            ) ranked WHERE rn <= ?
            ORDER BY device_id, rn
        ', [$sample]))->groupBy('device_id');

        $rows = [];
        foreach ($devices as $device) {
            $association = $device->deviceBackupPolicies->first();
            $cadence = $this->dominantCadence($device->deviceBackupPolicies);
            $streak = $this->consecutiveFailureStreak($recentByDevice->get($device->id, collect()));
            [$status, $reason] = $this->classify($cadence, $latestSuccess[$device->id] ?? null, $streak);
            $latestDeviceExecution = $latestExecution->get($device->id);

            $rows[] = [
                'device_id' => $device->id, 'name' => $device->name, 'site_id' => $device->site_id,
                'vendor' => $device->vendor, 'model' => $device->model,
                'method' => $latestDeviceExecution->method ?? $association?->backupPolicy?->method,
                'policy_name' => $latestDeviceExecution->policy_name ?? $association?->backupPolicy?->name,
                'has_policy' => $association !== null,
                'last_backup_at' => $latestDeviceExecution->created_at ?? null,
                'last_success_at' => $latestSuccess[$device->id] ?? null,
                'last_failure_at' => $latestFailure[$device->id] ?? null,
                'latest_artifact_size' => $latestArtifact[$device->id]->size_bytes ?? null,
                'consecutive_failures' => $streak,
                'status' => $status->value,
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    private function dominantCadence($associations): ?string
    {
        $types = $associations->pluck('backupPolicy.schedule_type')->filter()->all();
        if (in_array('daily', $types, true)) {
            return 'daily';
        }
        if (in_array('weekly', $types, true)) {
            return 'weekly';
        }

        return null;
    }

    private function consecutiveFailureStreak($recentRows): int
    {
        $streak = 0;
        foreach ($recentRows as $row) {
            if (! in_array($row->status, ['failed', 'timed_out', 'retry_wait'], true)) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /** @return array{0: HealthStatus, 1: string} */
    private function classify(?string $cadence, ?string $lastSuccessAt, int $streak): array
    {
        $criticalStreak = (int) config('health.device_consecutive_failures_critical');
        if ($streak >= $criticalStreak) {
            return [HealthStatus::Critical, 'consecutive_failures'];
        }

        if ($cadence === null) {
            return $lastSuccessAt !== null
                ? [HealthStatus::Healthy, 'manual_with_history']
                : [HealthStatus::Unknown, 'manual_never_backed_up'];
        }

        if ($lastSuccessAt === null) {
            return [HealthStatus::Critical, 'scheduled_never_succeeded'];
        }

        $ageHours = abs(now()->diffInHours($lastSuccessAt));
        $warningHours = (int) config("health.device_{$cadence}_warning_hours");
        $criticalHours = (int) config("health.device_{$cadence}_critical_hours");

        if ($ageHours >= $criticalHours) {
            return [HealthStatus::Critical, 'backup_stale'];
        }
        if ($ageHours >= $warningHours) {
            return [HealthStatus::Warning, 'backup_delayed'];
        }

        return [HealthStatus::Healthy, 'recent_success'];
    }
}
