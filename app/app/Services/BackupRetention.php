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
                            $check = $this->verify($artifact);
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
                            // Recheck immediately before unlink, while the database row is locked.
                            $fresh = $this->verify($artifact);
                            if ($fresh['result'] !== 'valid' || $fresh['inode'] !== $check['inode']) {
                                $totals['anomalies']++;
                                $this->audit($artifact, 'changed_before_delete', $reason, true);
                                continue;
                            }
                            if (! @unlink($fresh['path'])) {
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

    public function verify(BackupArtifact $artifact): array
    {
        $job = $artifact->backupExecution;
        $relative = $artifact->relative_path;
        $expected = $job ? app(EngineJobService::class)->relativePath($job) : null;
        // Historical artifacts retain their original extension if the device vendor changes.
        $expectedStem = $expected ? substr($expected, 0, strrpos($expected, '.')) : null;
        $validPaths = [];
        if ($job) {
            foreach (['rsc', 'cfg', 'dat'] as $extension) {
                $validPaths[] = $expectedStem.'.'.$extension;
                $validPaths[] = $expectedStem.'-exec-'.$job->id.'.'.$extension;
                // Existing backups keep the path used before friendly names were introduced.
                if ($extension !== 'dat') {
                    $validPaths[] = $job->device_id.'/'.($job->created_at?->format('Y/m/d') ?? now()->format('Y/m/d')).
                        '/execution-'.$job->id.'-config.'.$extension;
                }
            }
        }
        if (! $job || $artifact->device_id !== $job->device_id ||
            $artifact->backup_policy_id !== $job->backup_policy_id ||
            $artifact->storage !== 'local' || $artifact->type !== 'config' ||
            ! in_array($relative, $validPaths, true) ||
            ! preg_match('~\A(?:Backup Manager/[A-Z0-9-]+/[A-Z0-9-]+/[0-9]{2}-[0-9]{2}-[0-9]{4}/[A-Z0-9-]+_[0-9]{14}(?:-exec-[1-9][0-9]*)?\.(?:rsc|cfg|dat)|[1-9][0-9]*/[0-9]{4}/[0-9]{2}/[0-9]{2}/execution-[1-9][0-9]*-config\.(?:rsc|cfg))\z~D', $relative)) {
            return ['result' => 'invalid_path'];
        }
        $root = realpath(config('backup.storage_root'));
        if (! $root || $root === '/' || is_link(config('backup.storage_root'))) return ['result' => 'invalid_root'];
        $parts = explode('/', $relative);
        $path = $root;
        foreach ($parts as $part) {
            $path .= '/'.$part;
            if (is_link($path)) return ['result' => 'invalid_path'];
        }
        $parent = realpath(dirname($path));
        if (! $parent) return ['result' => 'missing'];
        if (! str_starts_with($parent.'/', $root.'/') || $parent.'/'.basename($path) !== $path) {
            return ['result' => 'invalid_path'];
        }
        clearstatcache(true, $path);
        if (! file_exists($path)) return ['result' => 'missing'];
        $stat = @lstat($path);
        if (! $stat || ! is_file($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            return ['result' => 'invalid_file'];
        }
        $handle = @fopen($path, 'rb');
        if (! $handle) return ['result' => 'unreadable'];
        try {
            $opened = fstat($handle);
            if (! $opened || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] ||
                $opened['size'] !== $artifact->size_bytes) return ['result' => 'size_mismatch'];
            $context = hash_init('sha256');
            hash_update_stream($context, $handle);
            if (! hash_equals(strtolower($artifact->sha256), hash_final($context))) return ['result' => 'hash_mismatch'];
            clearstatcache(true, $path);
            $again = @lstat($path);
            if (! $again || $again['ino'] !== $stat['ino'] || $again['dev'] !== $stat['dev'] ||
                $again['size'] !== $stat['size'] || $again['mtime'] !== $stat['mtime']) {
                return ['result' => 'changed_file'];
            }
            return ['result' => 'valid', 'path' => $path, 'inode' => $stat['ino']];
        } finally {
            fclose($handle);
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
