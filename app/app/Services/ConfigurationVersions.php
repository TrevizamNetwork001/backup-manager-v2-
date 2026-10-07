<?php

namespace App\Services;

use App\Models\BackupArtifact;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use ZipArchive;

class ConfigurationVersions
{
    public const WINDOW_MINUTES = 60;

    private const MAX_TEXT_BYTES = 2 * 1024 * 1024;

    public function __construct(private ArtifactStorage $storage, private A10ConfigurationViewer $a10Viewer) {}

    /** @return Collection<int, array{artifact: BackupArtifact, first_at: mixed, last_at: mixed, captures: int, fingerprint: string, text_available: bool}> */
    public function forArtifact(BackupArtifact $selected): Collection
    {
        $artifacts = BackupArtifact::query()
            ->where('device_id', $selected->device_id)
            ->where('backup_policy_id', $selected->backup_policy_id)
            ->where('type', $selected->type)->where('status', 'available')
            ->whereHas('backupExecution', fn ($query) => $query->where('status', 'succeeded'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()->reverse();

        $versions = collect();
        foreach ($artifacts as $artifact) {
            $content = $this->text($artifact);
            $fingerprint = $content === null ? $artifact->sha256 : hash('sha256', $content);
            $last = $versions->last();
            $sameWindow = $last && $last['last_at']->diffInMinutes($artifact->created_at) <= self::WINDOW_MINUTES;
            if ($sameWindow) {
                $versions->pop();
                $versions->push([
                    'artifact' => $artifact, 'first_at' => $last['first_at'], 'last_at' => $artifact->created_at,
                    'captures' => $last['captures'] + 1, 'fingerprint' => $fingerprint,
                    'text_available' => $content !== null,
                ]);
            } else {
                $versions->push([
                    'artifact' => $artifact, 'first_at' => $artifact->created_at, 'last_at' => $artifact->created_at,
                    'captures' => 1, 'fingerprint' => $fingerprint, 'text_available' => $content !== null,
                ]);
            }
        }

        return $versions->reverse()->values();
    }

    public function diff(BackupArtifact $old, BackupArtifact $new): ?array
    {
        $before = $this->text($old);
        $after = $this->text($new);
        if ($before === null || $after === null) {
            return null;
        }
        if ($before === $after) {
            return ['changed' => false, 'text' => ''];
        }

        $first = tempnam(sys_get_temp_dir(), 'bm-before-');
        $second = tempnam(sys_get_temp_dir(), 'bm-after-');
        if ($first === false || $second === false) {
            if ($first !== false) {
                @unlink($first);
            }
            if ($second !== false) {
                @unlink($second);
            }
            throw new \RuntimeException('Não foi possível preparar a comparação.');
        }
        try {
            chmod($first, 0600);
            chmod($second, 0600);
            file_put_contents($first, $before);
            file_put_contents($second, $after);
            $process = new Process(['diff', '-u', '--label', 'Versão anterior', '--label', 'Versão atual', $first, $second]);
            $process->setTimeout(15);
            $process->run();
            if ($process->getExitCode() !== 1) {
                throw new \RuntimeException('Falha ao comparar as configurações.');
            }
            $output = $process->getOutput();

            return ['changed' => true, 'text' => mb_strcut($output, 0, 200000),
                'truncated' => strlen($output) > 200000];
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    private function text(BackupArtifact $artifact): ?string
    {
        if ($artifact->type === 'binary' && $artifact->backupPolicy?->method === 'a10_system') {
            return $this->a10Viewer->read($artifact, 'pri');
        }
        if ($artifact->type !== 'config' || $artifact->status !== 'available' || $artifact->size_bytes > self::MAX_TEXT_BYTES) {
            return null;
        }
        $check = $this->storage->verify($artifact);
        if ($check['result'] !== 'valid') {
            return null;
        }
        $path = $check['path'];
        if (str_ends_with(strtolower((string) $artifact->original_filename), '.zip')) {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
            try {
                $candidate = null;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    if (! $stat || ! preg_match('/\.cfg\z/i', $stat['name']) ||
                        str_contains($stat['name'], '..') || str_starts_with($stat['name'], '/') ||
                        $stat['size'] > self::MAX_TEXT_BYTES) {
                        continue;
                    }
                    if ($candidate !== null) {
                        return null;
                    }
                    $candidate = $index;
                }
                if ($candidate === null) {
                    return null;
                }
                $value = $zip->getFromIndex($candidate);
            } finally {
                $zip->close();
            }
        } else {
            $value = file_get_contents($path);
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            return null;
        }

        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
