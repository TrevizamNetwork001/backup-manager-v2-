<?php

namespace App\Services;

use App\Models\DeviceBackupPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class BackupScheduler
{
    public function __construct(private readonly InstanceTimezone $timezone) {}

    public function run(?CarbonInterface $now = null): int
    {
        $nowUtc = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');
        $localNow = $nowUtc->setTimezone($this->timezone->get());
        $grace = (int) config('backup.scheduler_grace_minutes');
        if ($grace < 1 || $grace > 60) {
            throw new \InvalidArgumentException('Janela do scheduler inválida.');
        }

        $created = 0;
        DeviceBackupPolicy::query()->with(['backupPolicy', 'device', 'credential'])
            ->where('is_active', true)
            ->whereHas('backupPolicy', fn ($q) => $q->where('is_active', true)->whereIn('schedule_type', ['daily', 'weekly']))
            ->whereHas('device', fn ($q) => $q->where('is_active', true))
            ->whereHas('credential', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')->chunkById(100, function ($associations) use ($localNow, $nowUtc, $grace, &$created) {
                foreach ($associations as $association) {
                    $policy = $association->backupPolicy;
                    if (! $policy->schedule_time ||
                        $association->credential->device_id !== $association->device_id ||
                        $association->credential->type !== $policy->credentialType()) {
                        continue;
                    }

                    $time = substr($policy->schedule_time, 0, 5);
                    foreach ([$localNow, $localNow->subDay()] as $localDate) {
                        if ($policy->schedule_type === 'weekly' && $policy->schedule_weekday !== $localDate->dayOfWeekIso) {
                            continue;
                        }
                        $dateTime = $localDate->format('Y-m-d').' '.$time;
                        $occurrence = CarbonImmutable::parse($dateTime, $localNow->timezone);
                        // A local time skipped by a DST jump is not a valid occurrence.
                        if ($occurrence->format('Y-m-d H:i') !== $dateTime) {
                            continue;
                        }
                        $delay = $occurrence->diffInSeconds($localNow, false);
                        if ($delay < 0 || $delay >= $grace * 60) {
                            continue;
                        }

                        $created += DB::table('backup_executions')->insertOrIgnore([
                            'device_backup_policy_id' => $association->id,
                            'backup_policy_id' => $association->backup_policy_id,
                            'device_id' => $association->device_id,
                            'credential_id' => $association->credential_id,
                            'origin' => 'scheduler', 'status' => 'queued', 'attempt' => 1,
                            'scheduled_for' => $occurrence->utc()->format('Y-m-d H:i:s'),
                            'created_at' => $nowUtc->format('Y-m-d H:i:s'),
                            'updated_at' => $nowUtc->format('Y-m-d H:i:s'),
                        ]);
                    }
                }
            });

        return $created;
    }
}
