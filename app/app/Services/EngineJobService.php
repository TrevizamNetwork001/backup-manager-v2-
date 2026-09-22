<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngineJobService
{
    public function claim(): ?BackupExecution
    {
        return DB::transaction(function () {
            $job = BackupExecution::query()->where('status', 'queued')->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $job) return null;
            $job->status = 'running';
            $job->started_at = now();
            $job->save();
            return $job;
        });
    }

    public function job(int $id): array
    {
        $job = BackupExecution::with(['device', 'backupPolicy', 'credential', 'association'])->findOrFail($id);
        if ($job->status !== 'running') throw new \RuntimeException('Job não está em execução.');
        return [
            'id' => $job->id, 'device_id' => $job->device_id, 'policy_id' => $job->backup_policy_id,
            'host' => $job->device->management_ip, 'vendor' => $job->device->vendor,
            'method' => $job->backupPolicy->method, 'artifact_mode' => $job->backupPolicy->artifact_mode,
            'port' => $job->credential->port ?: 22, 'username' => $job->credential->username,
            'relative_path' => $this->relativePath($job),
            'eligible' => $job->association->is_active && $job->device->is_active &&
                $job->backupPolicy->is_active && $job->credential->is_active &&
                $job->credential->device_id === $job->device_id &&
                $job->association->credential_id === $job->credential_id &&
                $job->association->device_id === $job->device_id &&
                $job->association->backup_policy_id === $job->backup_policy_id &&
                $job->credential->type === 'ssh',
        ];
    }

    public function secret(int $id): string
    {
        $job = BackupExecution::with('credential')->findOrFail($id);
        if (! $this->job($id)['eligible']) {
            throw new \RuntimeException('Credencial indisponível.');
        }
        return $job->credential->secret;
    }

    public function relativePath(BackupExecution $job): string
    {
        return $job->device_id.'/'.($job->created_at?->format('Y/m/d') ?? now()->format('Y/m/d')).
            '/execution-'.$job->id.'-config.rsc';
    }

    public function resolvePath(string $relative): string
    {
        if (! preg_match('~\A[1-9][0-9]*/[0-9]{4}/[0-9]{2}/[0-9]{2}/execution-[1-9][0-9]*-config\.rsc\z~D', $relative)) {
            throw new \RuntimeException('Caminho inválido.');
        }
        $root = realpath(config('backup.storage_root'));
        if (! $root || $root === '/') throw new \RuntimeException('Raiz de armazenamento indisponível.');
        $file = realpath($root.'/'.$relative);
        if (! $file || ! str_starts_with($file, $root.'/') || ! is_file($file) || is_link($root.'/'.$relative)) {
            throw new \RuntimeException('Arquivo fora da raiz ou ausente.');
        }
        return $file;
    }

    public function complete(int $id, string $relative): BackupArtifact
    {
        return DB::transaction(function () use ($id, $relative) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || $relative !== $this->relativePath($job) || $job->artifact()->exists()) {
                throw ValidationException::withMessages(['status' => 'Execução indisponível para conclusão.']);
            }
            $payload = $this->job($id);
            if (! $payload['eligible'] || $payload['method'] !== 'ssh_pull' ||
                $payload['artifact_mode'] !== 'config' || mb_strtolower(trim($payload['vendor'])) !== 'mikrotik') {
                throw ValidationException::withMessages(['status' => 'Job não é elegível para conclusão.']);
            }
            $path = $this->resolvePath($relative);
            $size = filesize($path);
            if (! $size || $size > config('backup.max_artifact_bytes')) {
                throw ValidationException::withMessages(['artifact' => 'Tamanho inválido.']);
            }
            $contents = file_get_contents($path);
            if ($contents === false || ! mb_check_encoding($contents, 'UTF-8') ||
                str_contains($contents, "\0") || ! preg_match('/^\/[a-z]/mi', substr($contents, 0, 4096))) {
                throw ValidationException::withMessages(['artifact' => 'Export de configuração inválido.']);
            }
            $artifact = BackupArtifact::create([
                'backup_execution_id' => $job->id, 'device_id' => $job->device_id,
                'backup_policy_id' => $job->backup_policy_id, 'type' => 'config', 'storage' => 'local',
                'relative_path' => $relative, 'original_filename' => basename($relative),
                'size_bytes' => $size, 'sha256' => hash_file('sha256', $path), 'validated_at' => now(),
            ]);
            $job->status = 'succeeded';
            $job->finished_at = now();
            $job->save();
            return $artifact;
        });
    }

    public function fail(int $id, string $code): void
    {
        $messages = [
            'SSH_CONNECT_FAILED' => 'Conexão SSH falhou.', 'SSH_AUTH_FAILED' => 'Autenticação SSH falhou.',
            'SSH_CONNECTION_REFUSED' => 'Conexão SSH recusada.', 'SSH_NEGOTIATION_FAILED' => 'Negociação SSH incompatível.',
            'SSH_TIMEOUT' => 'Tempo limite SSH excedido.', 'UNSUPPORTED_VENDOR' => 'Vendor não suportado.',
            'UNSUPPORTED_POLICY' => 'Política não suportada.', 'CREDENTIAL_INVALID' => 'Credencial incompatível ou inativa.',
            'EXPORT_FAILED' => 'Export de configuração falhou.', 'ARTIFACT_INVALID' => 'Artefato inválido.',
            'STORAGE_FAILED' => 'Falha no armazenamento local.', 'ENGINE_FAILED' => 'Falha interna do engine.',
        ];
        if (! isset($messages[$code])) $code = 'ENGINE_FAILED';
        DB::transaction(function () use ($id, $code, $messages) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running') return;
            $job->status = 'failed';
            $job->finished_at = now();
            $job->error_code = $code;
            $job->error_message = $messages[$code];
            $job->save();
        });
    }
}
