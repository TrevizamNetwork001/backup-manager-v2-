<?php

namespace App\Services;

use App\Models\BackupArtifact;
use PharData;
use Throwable;

class A10ConfigurationViewer
{
    private const MAX_ARCHIVE_BYTES = 8 * 1024 * 1024;

    private const MAX_INNER_BYTES = 16 * 1024 * 1024;

    private const MAX_CONFIG_BYTES = 512 * 1024;

    public function __construct(private ArtifactStorage $storage) {}

    public function read(BackupArtifact $artifact, string $slot): ?string
    {
        if (! in_array($slot, ['pri', 'sec'], true) || $artifact->type !== 'binary' ||
            $artifact->status !== 'available' || $artifact->backupPolicy?->method !== 'a10_system' ||
            $artifact->size_bytes > self::MAX_ARCHIVE_BYTES) {
            return null;
        }

        $verified = $this->storage->verify($artifact);
        if ($verified['result'] !== 'valid') {
            return null;
        }

        try {
            $outer = new PharData($verified['path']);
            if (! isset($outer['backup_system.tar']) || ! $outer['backup_system.tar']->isFile() ||
                $outer['backup_system.tar']->isLink() || $outer['backup_system.tar']->getSize() > self::MAX_INNER_BYTES) {
                return null;
            }
            $innerBytes = $outer['backup_system.tar']->getContent();
            if (strlen($innerBytes) > self::MAX_INNER_BYTES) {
                return null;
            }

            $temporaryDirectory = sys_get_temp_dir().'/bm-a10-'.bin2hex(random_bytes(12));
            if (! mkdir($temporaryDirectory, 0700)) {
                return null;
            }
            $temporaryPath = $temporaryDirectory.'/backup_system.tar';
            try {
                if (file_put_contents($temporaryPath, $innerBytes) !== strlen($innerBytes)) {
                    return null;
                }
                chmod($temporaryPath, 0600);
                $inner = new PharData($temporaryPath);
                $name = 'a10data/etc/startup-config.'.$slot;
                if (! isset($inner[$name]) || ! $inner[$name]->isFile() || $inner[$name]->isLink() ||
                    $inner[$name]->getSize() > self::MAX_CONFIG_BYTES) {
                    return null;
                }
                $content = $inner[$name]->getContent();
                if (! mb_check_encoding($content, 'UTF-8') ||
                    preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
                    return null;
                }

                return $content;
            } finally {
                @unlink($temporaryPath);
                @rmdir($temporaryDirectory);
            }
        } catch (Throwable) {
            return null;
        }
    }
}
