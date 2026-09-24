<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\Device;
use App\Models\FtpAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EngineJobService
{
    public function receiveFtp(int $deviceId, string $token, string $filename, int $receivedAt, string $workerId): ?array
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $token) ||
            ! preg_match('/\A[a-f0-9]{32}\z/D', $workerId) ||
            strlen($filename) > 255 || ! preg_match('/\A[^\/\\\\\x00-\x1f\x7f]+\z/D', $filename) ||
            in_array($filename, ['.', '..'], true) || $receivedAt < 1) {
            throw new \InvalidArgumentException('Identidade FTP inválida.');
        }
        return DB::transaction(function () use ($deviceId, $token, $filename, $receivedAt, $workerId) {
            $existing = BackupExecution::query()->where('ftp_claim_token', $token)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->device_id !== $deviceId || $existing->received_filename !== $filename) {
                    throw new \RuntimeException('Claim FTP inconsistente.');
                }
                if ($existing->status === 'running') {
                    $existing->worker_id = $workerId;
                    $existing->heartbeat_at = now();
                    $existing->save();
                }
                return ['id' => $existing->id, 'status' => $existing->status,
                    'relative_path' => $this->relativePath($existing), 'ftp_account_id' => $existing->ftp_account_id];
            }
            $account = FtpAccount::query()->where('device_id', $deviceId)->lockForUpdate()->first();
            if (! $account || ! $account->is_active || ! $account->provisioned_at || $account->sync_error !== null) return null;
            $device = Device::query()->find($deviceId);
            if (! $device || ! $device->is_active || $device->platform !== 'olt' || mb_strtolower(trim($device->vendor)) !== 'huawei') return null;
            $association = $device->deviceBackupPolicies()->with('backupPolicy')
                ->where('is_active', true)->orderBy('id')->get()->first(fn ($item) =>
                    $item->backupPolicy->is_active && $item->backupPolicy->method === 'ftp_push' &&
                    $item->backupPolicy->artifact_mode === 'config' && $item->backupPolicy->schedule_type === 'manual' &&
                    $item->credential_id === null);
            if (! $association) return null;
            $now = now();
            $job = BackupExecution::create([
                'device_backup_policy_id' => $association->id, 'backup_policy_id' => $association->backup_policy_id,
                'device_id' => $deviceId, 'credential_id' => null, 'ftp_account_id' => $account->id,
                'ftp_claim_token' => $token, 'received_filename' => $filename,
                'received_at' => \Carbon\CarbonImmutable::createFromTimestamp($receivedAt, 'UTC'),
                'processing_at' => $now, 'started_at' => $now, 'claimed_at' => $now,
                'heartbeat_at' => $now, 'worker_id' => $workerId,
                'origin' => 'ftp_received', 'status' => 'running', 'attempt' => 1,
            ]);
            return ['id' => $job->id, 'status' => $job->status, 'relative_path' => $this->relativePath($job),
                'ftp_account_id' => $account->id];
        });
    }

    public function claim(?string $workerId = null): ?BackupExecution
    {
        $workerId ??= bin2hex(random_bytes(16));
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $workerId)) throw new \InvalidArgumentException('Worker inválido.');
        return DB::transaction(function () use ($workerId) {
            $job = BackupExecution::query()->where('status', 'queued')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('backup_executions as running_jobs')
                        ->whereColumn('running_jobs.device_id', 'backup_executions.device_id')
                        ->where('running_jobs.status', 'running');
                })->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $job) return null;
            $job->status = 'running';
            $job->started_at = now();
            $job->claimed_at = now();
            $job->heartbeat_at = now();
            $job->worker_id = $workerId;
            $job->save();
            return $job;
        });
    }

    public function job(int $id): array
    {
        $hasFtp = Schema::hasTable('ftp_accounts');
        $relations = ['device', 'backupPolicy', 'credential', 'association'];
        if ($hasFtp) $relations[] = 'device.ftpAccount';
        $job = BackupExecution::with($relations)->findOrFail($id);
        if ($job->status !== 'running') throw new \RuntimeException('Job não está em execução.');
        return [
            'id' => $job->id, 'device_id' => $job->device_id, 'policy_id' => $job->backup_policy_id,
            'host' => $job->device->management_ip, 'vendor' => $job->device->vendor,
            'platform' => $job->device->platform ?? 'network',
            'ssh_host_key_algorithm' => $job->device->ssh_host_key_algorithm,
            'ssh_host_key_fingerprint' => $job->device->ssh_host_key_fingerprint,
            'method' => $job->backupPolicy->method, 'artifact_mode' => $job->backupPolicy->artifact_mode,
            'schedule_type' => $job->backupPolicy->schedule_type, 'origin' => $job->origin,
            'port' => $job->credential?->port ?: 22, 'username' => $job->credential?->username,
            'relative_path' => $this->relativePath($job),
            'ftp_username' => $hasFtp ? $job->device->ftpAccount?->username : null,
            'ftp_account_available' => $hasFtp && (bool) ($job->device->ftpAccount?->is_active && $job->device->ftpAccount?->provisioned_at && $job->device->ftpAccount?->sync_error === null),
            'ftp_host' => app(FtpServerSettings::class)->get()['host'],
            'ftp_filename' => 'bm-exec-'.$job->id.'.cfg',
            'eligible' => $job->association->is_active && $job->device->is_active &&
                $job->backupPolicy->is_active &&
                $job->association->credential_id === $job->credential_id &&
                $job->association->device_id === $job->device_id &&
                $job->association->backup_policy_id === $job->backup_policy_id &&
                ($job->backupPolicy->method === 'ftp_push'
                    ? $hasFtp && in_array($job->origin, ['manual', 'ftp_received'], true) && $job->backupPolicy->schedule_type === 'manual' && $job->credential_id === null && $job->device->platform === 'olt' && mb_strtolower(trim($job->device->vendor)) === 'huawei' && (bool) $job->device->ftpAccount?->is_active
                    : $job->credential?->is_active && $job->credential?->device_id === $job->device_id && $job->credential?->type === 'ssh'),
        ];
    }

    public function secret(int $id, ?string $workerId = null): string
    {
        $job = BackupExecution::with('credential')->findOrFail($id);
        if ($workerId !== null && $job->worker_id !== $workerId) throw new \RuntimeException('Worker inválido.');
        if (! $this->job($id)['eligible'] || $job->credential === null || $this->job($id)['method'] !== 'ssh_pull') {
            throw new \RuntimeException('Credencial indisponível.');
        }
        return $job->credential->secret;
    }

    public function relativePath(BackupExecution $job): string
    {
        $vendor = mb_strtolower(trim($job->device->vendor));
        $extension = $vendor === 'huawei' ? 'cfg' : 'rsc';
        $device = $job->device;
        $site = $device->site;
        $siteName = $this->safePathName($site->name, 'SITE-'.$site->id);
        $deviceName = $this->safePathName($device->name, 'EQUIPAMENTO-'.$device->id);
        $timestamp = app(InstanceTimezone::class)->localNow($job->created_at)->format('YmdHis');
        $date = app(InstanceTimezone::class)->localNow($job->created_at)->format('d-m-Y');
        return 'Backup Manager/'.$siteName.'/'.$deviceName.'/'.$date.'/'.$deviceName.'_'.$timestamp.'.'.$extension;
    }

    private function safePathName(string $name, string $fallback): string
    {
        $safe = trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($name)), '-');
        return $safe === '' ? $fallback : rtrim(substr(strtoupper($safe), 0, 80), '-');
    }

    public function matchesFinalPath(BackupExecution $job, string $relative): bool
    {
        $base = $this->relativePath($job);
        $stem = substr($base, 0, strrpos($base, '.'));
        $extension = substr($base, strrpos($base, '.'));
        return $relative === $base || $relative === $stem.'-exec-'.$job->id.$extension;
    }

    public function heartbeat(int $id, string $workerId): bool
    {
        return BackupExecution::query()->whereKey($id)->where('status', 'running')
            ->where('worker_id', $workerId)->update(['heartbeat_at' => now()]) === 1;
    }

    public function observeHostKey(int $id, string $workerId, string $host, string $algorithm, string $fingerprint): void
    {
        if (! preg_match('/\A[a-zA-Z0-9@._+-]{1,100}\z/D', $algorithm) ||
            ! preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint)) {
            throw new \InvalidArgumentException('Identidade SSH inválida.');
        }
        DB::transaction(function () use ($id, $workerId, $host, $algorithm, $fingerprint) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || $job->worker_id !== $workerId) {
                throw new \RuntimeException('Execução indisponível.');
            }
            $device = Device::query()->lockForUpdate()->findOrFail($job->device_id);
            if ($device->management_ip !== $host) throw new \RuntimeException('Endereço do equipamento alterado.');
            $device->ssh_observed_algorithm = $algorithm;
            $device->ssh_observed_fingerprint = $fingerprint;
            $device->ssh_observed_at = now();
            $device->save();
        });
    }

    public function recoverStale(): int
    {
        $seconds = (int) config('backup.engine_stale_seconds');
        if ($seconds < 1) throw new \InvalidArgumentException('Threshold de stale inválido.');
        $cutoff = now()->subSeconds($seconds);
        return DB::transaction(function () use ($cutoff) {
            $jobs = BackupExecution::query()->where('status', 'running')
                ->where(function ($query) use ($cutoff) {
                    $query->where('heartbeat_at', '<', $cutoff)
                        ->orWhere(function ($query) use ($cutoff) {
                            $query->whereNull('heartbeat_at')->where('started_at', '<', $cutoff);
                        });
                })->orderBy('id')->limit(100)->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($jobs as $job) {
                $job->status = 'failed';
                $job->finished_at = now();
                $job->error_code = 'ENGINE_STALE';
                $job->error_message = 'Execução interrompida: heartbeat expirado.';
                $job->worker_id = null;
                $job->save();
            }
            return $jobs->count();
        });
    }

    public function resolvePath(string $relative): string
    {
        if (! preg_match('~\ABackup Manager/[A-Z0-9-]+/[A-Z0-9-]+/[0-9]{2}-[0-9]{2}-[0-9]{4}/[A-Z0-9-]+_[0-9]{14}(?:-exec-[1-9][0-9]*)?\.(?:rsc|cfg|dat)\z~D', $relative)) {
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

    public function complete(int $id, string $relative, ?string $workerId = null): BackupArtifact
    {
        return DB::transaction(function () use ($id, $relative, $workerId) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || ($workerId !== null && $job->worker_id !== $workerId) ||
                ! $this->matchesFinalPath($job, $relative) || $job->artifact()->exists()) {
                throw ValidationException::withMessages(['status' => 'Execução indisponível para conclusão.']);
            }
            $payload = $this->job($id);
            $vendor = mb_strtolower(trim($payload['vendor']));
            $supported = $payload['method'] === 'ssh_pull' && $payload['platform'] === 'network' && in_array($vendor, ['mikrotik', 'huawei'], true)
                || $payload['method'] === 'ftp_push' && $payload['platform'] === 'olt' && $vendor === 'huawei' && $payload['ftp_account_available'];
            if (! $payload['eligible'] || ! $supported || $payload['artifact_mode'] !== 'config') {
                throw ValidationException::withMessages(['status' => 'Job não é elegível para conclusão.']);
            }
            $path = $this->resolvePath($relative);
            $size = filesize($path);
            $limit = $payload['method'] === 'ftp_push' ? min(64 * 1024 * 1024, max(1, (int) config('backup.ftp_max_bytes'))) : config('backup.max_artifact_bytes');
            if (! $size || $size > $limit) {
                throw ValidationException::withMessages(['artifact' => 'Tamanho inválido.']);
            }
            $contents = file_get_contents($path);
            $preview = substr($contents ?: '', 0, 4096);
            $validContent = $vendor === 'mikrotik'
                ? (bool) preg_match('/^\/[a-z]/mi', $preview)
                : ($payload['method'] === 'ftp_push'
                    ? $this->validHuaweiOltConfig($contents ?: '')
                    : strlen($contents ?: '') >= 32 && preg_match('/^#\s*$/m', $preview) &&
                    preg_match('/^(?:sysname|interface|vlan(?: batch)?|ip route-static|aaa|user-interface|stelnet server|snmp-agent)\b/mi', $preview) &&
                    ! preg_match('/^\s*(?:Error:|%\s*(?:Error|Unrecognized|Unknown)|Unrecognized command|Unknown command|Incomplete command)/mi', $contents) &&
                    ! preg_match('/(?:-{3,}\s*more\s*-{3,}|\bmore\s*:\s*|press\s+(?:any key|space))/i', $contents));
            if ($contents === false || ! mb_check_encoding($contents, 'UTF-8') ||
                str_contains($contents, "\0") || ! $validContent) {
                throw ValidationException::withMessages(['artifact' => 'Export de configuração inválido.']);
            }
            $artifact = BackupArtifact::create([
                'backup_execution_id' => $job->id, 'device_id' => $job->device_id,
                'backup_policy_id' => $job->backup_policy_id, 'type' => 'config', 'storage' => 'local',
                'relative_path' => $relative, 'original_filename' => $job->origin === 'ftp_received' ? $job->received_filename : basename($relative),
                'size_bytes' => $size, 'sha256' => hash_file('sha256', $path), 'validated_at' => now(),
            ]);
            $job->status = 'succeeded';
            $job->finished_at = now();
            $job->worker_id = null;
            $job->save();
            return $artifact;
        });
    }

    private function validHuaweiOltConfig(string $contents): bool
    {
        if (strlen($contents) < 32 || ! str_ends_with($contents, "\n") ||
            preg_match('/^\s*(?:error:|%\s*error|authentication failed|backing up files is fail|<!doctype html\b|<html\b)/mi', $contents) ||
            ! preg_match('/^#[ \t]*\r?$/m', $contents)) return false;

        $preview = substr($contents, 0, 4096);
        if (! preg_match('/^\[!Software Version MA5800[^\]\r\n]*\]\r?$/m', $preview, $header, PREG_OFFSET_CAPTURE) ||
            ! preg_match('/^\[Saving time: [^\]\r\n]+\]\r?$/m', $preview, $savingTime, PREG_OFFSET_CAPTURE) ||
            ! preg_match('/^\[global-config\][ \t]*\r?$/m', $contents, $section, PREG_OFFSET_CAPTURE) ||
            ! preg_match('/^[ \t]*<global-config>[ \t]*\r?$/m', $contents, $block, PREG_OFFSET_CAPTURE) ||
            ! preg_match('/^[ \t]*sysname[ \t]+\S[^\r\n]*\r?$/mi', $contents, $sysname, PREG_OFFSET_CAPTURE)) return false;

        if (! preg_match('/^#[ \t]*\r?$/m', $contents, offset: $sysname[0][1] + strlen($sysname[0][0]))) return false;

        return $header[0][1] < $savingTime[0][1] && $savingTime[0][1] < $section[0][1] &&
            $section[0][1] < $block[0][1] && $block[0][1] < $sysname[0][1];
    }

    public function fail(int $id, string $code, ?string $workerId = null): void
    {
        $messages = [
            'SSH_CONNECT_FAILED' => 'Conexão SSH falhou.', 'SSH_AUTH_FAILED' => 'Autenticação SSH falhou.',
            'SSH_CONNECTION_REFUSED' => 'Conexão SSH recusada.', 'SSH_NEGOTIATION_FAILED' => 'Negociação SSH incompatível.',
            'SSH_TIMEOUT' => 'Tempo limite SSH excedido.', 'UNSUPPORTED_VENDOR' => 'Vendor não suportado.',
            'UNSUPPORTED_POLICY' => 'Política não suportada.', 'CREDENTIAL_INVALID' => 'Credencial incompatível ou inativa.',
            'EXPORT_FAILED' => 'Export de configuração falhou.', 'ARTIFACT_INVALID' => 'Artefato inválido.',
            'STORAGE_FAILED' => 'Falha no armazenamento local.', 'ENGINE_FAILED' => 'Falha interna do engine.',
            'SSH_HOST_KEY_UNKNOWN' => 'Chave SSH desconhecida; aprove a chave observada no equipamento.',
            'SSH_HOST_KEY_MISMATCH' => 'Chave SSH diferente da confiada; verifique e aprove explicitamente.',
            'HUAWEI_PROMPT_FAILED' => 'Prompt Huawei não reconhecido.',
            'HUAWEI_PAGING_FAILED' => 'Paginação Huawei não pôde ser desativada.',
            'HUAWEI_EXPORT_FAILED' => 'Export de configuração Huawei falhou.',
            'FTP_ACCOUNT_UNAVAILABLE' => 'Conta FTP indisponível.',
            'FTP_TRIGGER_FAILED' => 'Disparo do backup FTP falhou.',
            'FTP_RECEIVE_TIMEOUT' => 'Arquivo FTP não recebido no prazo.',
            'FTP_FILE_INVALID' => 'Arquivo FTP inválido.',
            'FTP_FILE_UNCORRELATED' => 'Arquivo FTP sem execução correspondente.',
            'FTP_STORAGE_FAILED' => 'Falha ao armazenar arquivo FTP.',
            'FTP_QUARANTINED' => 'Arquivo FTP movido para quarentena.',
        ];
        if (! isset($messages[$code])) $code = 'ENGINE_FAILED';
        DB::transaction(function () use ($id, $code, $messages, $workerId) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || ($workerId !== null && $job->worker_id !== $workerId)) return;
            $job->status = 'failed';
            $job->finished_at = now();
            $job->error_code = $code;
            $job->error_message = $messages[$code];
            $job->worker_id = null;
            $job->save();
        });
    }
}
