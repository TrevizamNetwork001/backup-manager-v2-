<?php

namespace App\Services;

use App\Models\BackupArtifact;

/**
 * Single primitive for touching an artifact's physical file. Both
 * BackupRetention (automatic) and manual deletion (BackupArtifactController)
 * must go through verify()/unlink() here — never duplicate path-safety logic.
 *
 * verify() re-derives the expected path from the artifact's own execution,
 * confines it to config('backup.storage_root'), rejects symlinks/traversal at
 * every path segment, requires a regular file with a single hard link, and
 * confirms the on-disk content still matches the stored sha256/size before
 * declaring the file "valid" to remove.
 */
class ArtifactStorage
{
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

    /**
     * Re-verifies (defends against TOCTOU between preview and confirmation)
     * and, only if still valid, unlinks the file. Never uses shell/rm -rf.
     */
    public function remove(BackupArtifact $artifact, ?int $expectedInode = null): array
    {
        $check = $this->verify($artifact);
        if ($check['result'] === 'missing') {
            return ['result' => 'missing'];
        }
        if ($check['result'] !== 'valid') {
            return $check;
        }
        if ($expectedInode !== null && $check['inode'] !== $expectedInode) {
            return ['result' => 'changed_file'];
        }
        if (! @unlink($check['path'])) {
            return ['result' => 'unlink_failed'];
        }

        return ['result' => 'deleted', 'inode' => $check['inode']];
    }
}
