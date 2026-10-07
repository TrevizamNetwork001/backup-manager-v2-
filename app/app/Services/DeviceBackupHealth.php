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
                    'status' => $row['status'], 'reason' => $row['reason'],
                    'last_ftp_success_at' => $row['last_ftp_success_at']];
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
     *   method:?string,methods:list<string>,policy_name:?string,last_backup_at:?string,last_success_at:?string,
     *   last_failure_at:?string,latest_artifact_size:?int,consecutive_failures:int,status:string,reason:string}>
     */
    public function rows(): array
    {
        $devices = Device::query()->where('is_active', true)
            ->with(['deviceBackupPolicies' => fn ($q) => $q->where('is_active', true)
                ->whereNull('archived_at')
                ->whereHas('backupPolicy', fn ($policy) => $policy->where('is_active', true)->whereNull('archived_at'))
                ->with('backupPolicy:id,is_active,schedule_type,method,name,archived_at')])
            ->get(['id', 'name', 'site_id', 'vendor', 'platform', 'model', 'expected_ftp_interval_hours']);

        if ($devices->isEmpty()) {
            return [];
        }

        $latestSuccess = DB::table('backup_executions')->select('device_id', DB::raw('MAX(created_at) as at'))
            ->where('status', 'succeeded')->groupBy('device_id')->pluck('at', 'device_id');
        $latestFtpSuccess = DB::table('backup_executions')
            ->join('backup_policies', 'backup_policies.id', '=', 'backup_executions.backup_policy_id')
            ->where('backup_executions.status', 'succeeded')
            ->where('backup_policies.method', 'ftp_push')
            ->groupBy('backup_executions.device_id')
            ->select('backup_executions.device_id', DB::raw('MAX(backup_executions.created_at) as at'))
            ->pluck('at', 'device_id');
        $latestExecution = collect(DB::select(<<<'SQL'
            SELECT device_id, created_at FROM (
                SELECT be.device_id, be.created_at,
                       ROW_NUMBER() OVER (PARTITION BY be.device_id ORDER BY be.id DESC) AS rn
                FROM backup_executions be
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
            $methods = $device->deviceBackupPolicies->pluck('backupPolicy.method')->unique()->values()->all();
            $cadence = $this->dominantCadence($device->deviceBackupPolicies);
            $streak = $this->consecutiveFailureStreak($recentByDevice->get($device->id, collect()));
            [$status, $reason] = $this->classify($cadence, $latestSuccess[$device->id] ?? null, $streak);
            $hasActiveFtp = $device->deviceBackupPolicies->contains(fn ($item) => $item->backupPolicy?->method === 'ftp_push');
            // Change-driven FTP push (Huawei VRP network gear): no file just means no change, never an alert.
            if ($hasActiveFtp && $device->expected_ftp_interval_hours !== null
                && ! Device::pushesOnlyOnConfigChange($device->vendor, $device->platform)) {
                [$ftpStatus, $ftpReason] = $this->classifyFtp(
                    $device->expected_ftp_interval_hours,
                    $latestFtpSuccess[$device->id] ?? null,
                );
                if ($ftpStatus->severity() > $status->severity()) {
                    [$status, $reason] = [$ftpStatus, $ftpReason];
                }
            }
            $latestDeviceExecution = $latestExecution->get($device->id);

            $rows[] = [
                'device_id' => $device->id, 'name' => $device->name, 'site_id' => $device->site_id,
                'vendor' => $device->vendor, 'model' => $device->model,
                'method' => $association?->backupPolicy?->method,
                'methods' => $methods,
                'policy_name' => $association?->backupPolicy?->name,
                'has_policy' => $association !== null,
                'last_backup_at' => $latestDeviceExecution->created_at ?? null,
                'last_success_at' => $latestSuccess[$device->id] ?? null,
                'last_ftp_success_at' => $latestFtpSuccess[$device->id] ?? null,
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

        if ($cadence !== null && $lastSuccessAt === null) {
            return [HealthStatus::Critical, 'scheduled_never_succeeded'];
        }

        if ($streak > 0) {
            return [HealthStatus::Warning, 'latest_backup_failed'];
        }

        if ($cadence === null) {
            return $lastSuccessAt !== null
                ? [HealthStatus::Healthy, 'manual_with_history']
                : [HealthStatus::Unknown, 'manual_never_backed_up'];
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

    /** @return array{0: HealthStatus, 1: string} */
    private function classifyFtp(int $expectedHours, ?string $lastSuccessAt): array
    {
        if ($lastSuccessAt === null) {
            return [HealthStatus::Critical, 'ftp_never_received'];
        }

        $ageHours = abs(now()->diffInHours($lastSuccessAt));
        if ($ageHours >= $expectedHours + (int) config('health.device_ftp_critical_grace_hours')) {
            return [HealthStatus::Critical, 'ftp_backup_stale'];
        }
        if ($ageHours >= $expectedHours + (int) config('health.device_ftp_warning_grace_hours')) {
            return [HealthStatus::Warning, 'ftp_backup_delayed'];
        }

        return [HealthStatus::Healthy, 'ftp_recent_success'];
    }
}
