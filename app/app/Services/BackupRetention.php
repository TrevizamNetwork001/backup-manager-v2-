<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Models\DeviceBackupPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackupRetention
{
    public function __construct(private ArtifactStorage $storage)
    {
    }

    public function run(bool $apply = false, ?CarbonInterface $now = null): array
    {
        $nowUtc = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');
        $totals = array_fill_keys(['scanned', 'candidates', 'protected_latest', 'missing', 'deleted', 'anomalies', 'errors'], 0);

        DeviceBackupPolicy::query()->with('backupPolicy')->orderBy('id')->chunkById(100,
            function ($associations) use ($apply, $nowUtc, &$totals) {
                foreach ($associations as $association) {
                    // The association row serializes retention workers for one logical source.
                    DB::transaction(function () use ($association, $apply, $nowUtc, &$totals) {
                        $source = DeviceBackupPolicy::query()->with('backupPolicy')->lockForUpdate()->find($association->id);
                        if (! $source || ! $source->backupPolicy) return;
                        $policy = $source->backupPolicy;
                        if (! $policy->retention_days && ! $policy->retention_count) return;
                        $artifacts = BackupArtifact::query()->with('backupExecution')
                            ->where('status', 'available')->whereHas('backupExecution', fn ($q) => $q
                                ->where('device_backup_policy_id', $source->id)->where('status', 'succeeded'))
                            ->whereNotNull('validated_at')->orderByDesc('created_at')->orderByDesc('id')
                            ->lockForUpdate()->get();
                        $valid = [];
                        foreach ($artifacts as $artifact) {
                            $totals['scanned']++;
                            $check = $this->storage->verify($artifact);
                            if ($check['result'] === 'missing') {
                                $totals['missing']++;
                                $this->audit($artifact, 'missing', null, $apply);
                                if ($apply) {
                                    $artifact->status = 'missing';
                                    $artifact->missing_at = $nowUtc;
                                    $artifact->save();
                                }
                            } elseif ($check['result'] !== 'valid') {
                                $totals['anomalies']++;
                                $this->audit($artifact, $check['result'], null, $apply);
                            } else {
                                $valid[] = [$artifact, $check];
                            }
                        }
                        foreach ($valid as $index => [$artifact, $check]) {
                            $days = $policy->retention_days && $artifact->created_at->lt($nowUtc->subDays($policy->retention_days));
                            $count = $policy->retention_count && $index >= $policy->retention_count;
                            if (! $days && ! $count) continue;
                            if ($index === 0) {
                                $totals['protected_latest']++;
                                continue;
                            }
                            $reason = $days && $count ? 'retention_days_and_count' : ($days ? 'retention_days' : 'retention_count');
                            $totals['candidates']++;
                            if (! $apply) continue;
                            // The primitive rechecks (TOCTOU) and unlinks in one step, while the row is locked.
                            $removal = $this->storage->remove($artifact, $check['inode']);
                            if ($removal['result'] === 'changed_file') {
                                $totals['anomalies']++;
                                $this->audit($artifact, 'changed_before_delete', $reason, true);
                                continue;
                            }
                            if ($removal['result'] !== 'deleted') {
                                $totals['errors']++;
                                $this->audit($artifact, 'unlink_failed', $reason, true);
                                continue;
                            }
                            $artifact->status = 'deleted';
                            $artifact->deleted_at = $nowUtc;
                            $artifact->deletion_reason = $reason;
                            $artifact->save();
                            $totals['deleted']++;
                            $this->audit($artifact, 'deleted', $reason, true);
                        }
                    });
                }
            });

        return $totals;
    }

    private function audit(BackupArtifact $artifact, string $result, ?string $reason, bool $apply): void
    {
        Log::info('backup_retention', [
            'artifact_id' => $artifact->id, 'execution_id' => $artifact->backup_execution_id,
            'device_id' => $artifact->device_id, 'size' => $artifact->size_bytes,
            'reason' => $reason, 'result' => $result, 'mode' => $apply ? 'apply' : 'dry_run',
        ]);
    }
}
