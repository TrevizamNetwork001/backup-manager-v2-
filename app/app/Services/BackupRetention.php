<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Models\DeviceBackupPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackupRetention
{
    public function __construct(private ArtifactStorage $storage) {}

    public function run(bool $apply = false, ?CarbonInterface $now = null): array
    {
        $nowUtc = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');
        $totals = array_fill_keys(['scanned', 'candidates', 'protected_latest', 'missing', 'deleted', 'anomalies', 'errors'], 0);

        DeviceBackupPolicy::query()->with('backupPolicy')->orderBy('id')->chunkById(100,
            function ($associations) use ($apply, $nowUtc, &$totals) {
                foreach ($associations as $association) {
                    $this->retainSource($association->id, $apply, $nowUtc, $totals);
                }
            });

        // Summary-level record for EngineHealth's retention check (ENGINE-3) —
        // reuses the existing audit_events table rather than a new migration
        // (see docs/ENGINE_HEALTH.md, item 21/43).
        if (Schema::hasTable('audit_events')) {
            app(AuditEvents::class)->record('backup_retention.completed', 'system', null, null,
                $totals['errors'] > 0 ? 'warning' : 'success', $totals + ['mode' => $apply ? 'apply' : 'dry_run']);
        }

        return $totals;
    }

    private function retainSource(int $sourceId, bool $apply, CarbonImmutable $nowUtc, array &$totals): void
    {
        $sourceQuery = DeviceBackupPolicy::query()->with('backupPolicy');
        $source = $sourceQuery->find($sourceId);
        if (! $source || ! $source->backupPolicy) {
            return;
        }
        $policy = $source->backupPolicy;
        if (! $policy->retention_days && ! $policy->retention_count) {
            return;
        }
        $ids = BackupArtifact::query()->where('status', 'available')
            ->whereHas('backupExecution', fn ($q) => $q
                ->where('device_backup_policy_id', $source->id)->where('status', 'succeeded'))
            ->whereNotNull('validated_at')->orderByDesc('created_at')->orderByDesc('id')->pluck('id');
        $validCount = 0;
        $protected = [];
        $cutoff = $policy->retention_days ? $nowUtc->subDays($policy->retention_days) : null;
        foreach ($ids->chunk(500) as $batch) {
            $retainBatch = function () use ($source, $policy, $apply, $nowUtc, $cutoff, $batch, &$validCount, &$protected, &$totals): bool {
                if ($apply) {
                    $current = DeviceBackupPolicy::query()->lockForUpdate()->find($source->id);
                    $currentPolicy = $current ? $current->backupPolicy()->lockForUpdate()->first() : null;
                    if (! $currentPolicy || $current->backup_policy_id !== $source->backup_policy_id ||
                        $currentPolicy->retention_days !== $policy->retention_days ||
                        $currentPolicy->retention_count !== $policy->retention_count) {
                        return false;
                    }
                    if ($protected) {
                        $keepers = BackupArtifact::query()->with('backupExecution:id,device_id,backup_policy_id')
                            ->whereIn('id', $protected)->where('status', 'available')->lockForUpdate()->get();
                        $latest = $keepers->firstWhere('id', $protected[0]);
                        if ($keepers->count() !== count($protected) || ! $latest ||
                            $this->storage->verify($latest)['result'] !== 'valid') {
                            return false;
                        }
                    }
                }
                $query = BackupArtifact::query()->with('backupExecution:id,device_id,backup_policy_id')
                    ->whereIn('id', $batch)
                    ->where('status', 'available')->whereHas('backupExecution', fn ($q) => $q
                    ->where('device_backup_policy_id', $source->id)->where('status', 'succeeded'))
                    ->whereNotNull('validated_at')->orderByDesc('created_at')->orderByDesc('id');
                if ($apply) {
                    $query->lockForUpdate();
                }
                $artifacts = $query->get();
                $missing = [];
                $deleted = [];
                foreach ($artifacts as $artifact) {
                    $totals['scanned']++;
                    $check = $this->storage->verify($artifact);
                    if ($check['result'] === 'missing') {
                        $totals['missing']++;
                        $this->audit($artifact, 'missing', null, $apply);
                        $missing[] = $artifact->id;

                        continue;
                    }
                    if ($check['result'] !== 'valid') {
                        $totals['anomalies']++;
                        $this->audit($artifact, $check['result'], null, $apply);

                        continue;
                    }
                    $index = $validCount++;
                    $days = $policy->retention_days && $artifact->created_at->lt($cutoff);
                    $count = $policy->retention_count && $index >= $policy->retention_count;
                    if ($index === 0 || (! $days && ! $count)) {
                        $protected[] = $artifact->id;
                    }
                    if (! $days && ! $count) {
                        continue;
                    }
                    if ($index === 0) {
                        $totals['protected_latest']++;

                        continue;
                    }
                    $reason = $days && $count ? 'retention_days_and_count' : ($days ? 'retention_days' : 'retention_count');
                    $totals['candidates']++;
                    if (! $apply) {
                        continue;
                    }
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
                    $deleted[$reason][] = $artifact->id;
                    $totals['deleted']++;
                    $this->audit($artifact, 'deleted', $reason, true);
                }
                if ($apply && $missing) {
                    BackupArtifact::query()->whereIn('id', $missing)->update([
                        'status' => 'missing', 'missing_at' => $nowUtc, 'updated_at' => now(),
                    ]);
                }
                foreach ($deleted as $reason => $ids) {
                    BackupArtifact::query()->whereIn('id', $ids)->update([
                        'status' => 'deleted', 'deleted_at' => $nowUtc, 'deletion_reason' => $reason, 'updated_at' => now(),
                    ]);
                }

                return true;
            };
            if (! ($apply ? DB::transaction($retainBatch) : $retainBatch())) {
                break;
            }
        }
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
