<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\Device;
use App\Models\FtpAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EngineJobService
{
    // Mirrors engine/errors.py RETRYABLE_CODES (see docs/ENGINE_QUEUE.md for why
    // this can't be a single shared source of truth across PHP/Python) plus two
    // codes that only ever originate on this side (ENGINE_STALE, ENGINE_TIMEOUT
    // — see recoverStale()). Keep both lists in sync by hand when either changes.
    // Public: FailureReportQuery (FEATURES-FINAL-1) reuses this as the single
    // canonical "is this retryable" list rather than declaring a third copy.
    public const RETRYABLE_CODES = [
        'SSH_TIMEOUT', 'SSH_CONNECTION_REFUSED', 'SSH_CONNECT_FAILED', 'SSH_NEGOTIATION_FAILED',
        'FTP_RECEIVE_TIMEOUT', 'STORAGE_FAILED', 'ENGINE_FAILED', 'ENGINE_STALE', 'ENGINE_TIMEOUT',
    ];

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
                if (in_array($existing->status, ['running', 'retry_wait'], true)) {
                    $existing->status = 'running';
                    $existing->worker_id = $workerId;
                    $existing->started_at = now();
                    $existing->claimed_at = now();
                    $existing->heartbeat_at = now();
                    $existing->next_attempt_at = null;
                    $existing->save();
                }

                return ['id' => $existing->id, 'status' => $existing->status,
                    'relative_path' => $this->relativePath($existing), 'ftp_account_id' => $existing->ftp_account_id];
            }
            $account = FtpAccount::query()->where('device_id', $deviceId)->lockForUpdate()->first();
            if (! $account || ($account->purpose ?? 'backup') !== 'backup' || ! $account->is_active || ! $account->provisioned_at || $account->sync_error !== null || $account->deletion_mode) {
                return ['status' => 'rejected', 'error_code' => 'invalid_account', 'ftp_account_id' => $account?->id];
            }
            $device = Device::query()->find($deviceId);
            if (! $device || ! $device->is_active || ! $device->isHuaweiFtpEligible()) {
                return ['status' => 'rejected', 'error_code' => 'unsupported_device', 'ftp_account_id' => $account->id];
            }
            $policyService = app(HuaweiFtpBackupPolicy::class);
            $association = $policyService->active($device);
            if (! $association) {
                $hasFtpPolicy = $device->deviceBackupPolicies()->whereHas('backupPolicy',
                    fn ($query) => $query->where('method', 'ftp_push'))->exists();

                return ['status' => 'rejected', 'error_code' => $hasFtpPolicy ? 'invalid_backup_policy' : 'missing_backup_policy',
                    'ftp_account_id' => $account->id];
            }
            $now = now();
            $job = BackupExecution::create([
                'device_backup_policy_id' => $association->id, 'backup_policy_id' => $association->backup_policy_id,
                'device_id' => $deviceId, 'credential_id' => null, 'ftp_account_id' => $account->id,
                'ftp_claim_token' => $token, 'received_filename' => $filename,
                'received_at' => CarbonImmutable::createFromTimestamp($receivedAt, 'UTC'),
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
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $workerId)) {
            throw new \InvalidArgumentException('Worker inválido.');
        }
        $job = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $job = DB::transaction(function () use ($workerId) {
                    $job = BackupExecution::query()
                        // FTP receipts are completed by the receiver, not by the generic SSH/FTP-push worker.
                        ->where('origin', '!=', 'ftp_received')
                        ->where(function ($query) {
                            $query->where('status', 'queued')
                                ->orWhere(function ($query) {
                                    // A retry becomes claimable once its backoff window has elapsed.
                                    $query->where('status', 'retry_wait')->where('next_attempt_at', '<=', now());
                                });
                        })
                        ->whereNotExists(function ($query) {
                            $query->selectRaw('1')->from('backup_executions as running_jobs')
                                ->whereColumn('running_jobs.device_id', 'backup_executions.device_id')
                                ->where('running_jobs.status', 'running');
                        })->orderBy('id')
                        ->lock('FOR UPDATE SKIP LOCKED')->first();
                    if (! $job) {
                        return null;
                    }
                    $job->status = 'running';
                    $job->started_at = now();
                    $job->claimed_at = now();
                    $job->heartbeat_at = now();
                    $job->worker_id = $workerId;
                    $job->next_attempt_at = null;
                    $job->save();

                    return $job;
                });
                break;
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '23505' ||
                    ! str_contains($exception->getMessage(), 'backup_executions_one_running_device')) {
                    throw $exception;
                }
                // Rollback precedes a fresh claim: the winning device is now excluded.
            }
        }
        if ($job) {
            $this->audit('backup_execution.claimed', $job->id, 'success', ['attempt' => $job->attempt, 'origin' => $job->origin]);
        }

        return $job;
    }

    public function job(int $id): array
    {
        $hasFtp = Schema::hasTable('ftp_accounts');
        $relations = ['device', 'backupPolicy', 'credential', 'association'];
        if ($hasFtp) {
            $relations[] = 'device.ftpAccount';
        }
        $job = BackupExecution::with($relations)->findOrFail($id);
        if ($job->status !== 'running') {
            throw new \RuntimeException('Job não está em execução.');
        }

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
            'ftp_home' => $hasFtp ? $job->device->ftpAccount?->homePath() : null,
            'ftp_account_available' => $hasFtp && (bool) ($job->device->ftpAccount?->is_active && $job->device->ftpAccount?->provisioned_at && $job->device->ftpAccount?->sync_error === null),
            'ftp_host' => app(FtpServerSettings::class)->get()['host'],
            'ftp_filename' => 'bm-exec-'.$job->id.'.cfg',
            'eligible' => $job->association->is_active && $job->device->is_active &&
                $job->backupPolicy->is_active &&
                $job->association->credential_id === $job->credential_id &&
                $job->association->device_id === $job->device_id &&
                $job->association->backup_policy_id === $job->backup_policy_id &&
                ($job->backupPolicy->method === 'ftp_push'
                    ? $hasFtp && in_array($job->origin, ['manual', 'ftp_received'], true) &&
                        ($job->origin === 'ftp_received' || $job->device->platform === 'olt') &&
                        $job->backupPolicy->schedule_type === 'manual' && $job->credential_id === null &&
                        $job->device->isHuaweiFtpEligible() && (bool) $job->device->ftpAccount?->is_active
                    : $job->credential?->is_active && $job->credential?->device_id === $job->device_id && $job->credential?->type === 'ssh'),
        ];
    }

    public function secret(int $id, ?string $workerId = null): string
    {
        $job = BackupExecution::with('credential')->findOrFail($id);
        if ($workerId !== null && $job->worker_id !== $workerId) {
            throw new \RuntimeException('Worker inválido.');
        }
        $payload = $this->job($id);
        if (! $payload['eligible'] || $job->credential === null || $payload['method'] !== 'ssh_pull') {
            throw new \RuntimeException('Credencial indisponível.');
        }

        return $job->credential->secret;
    }

    public function relativePath(BackupExecution $job): string
    {
        $vendor = mb_strtolower(trim($job->device->vendor));
        $extension = in_array($vendor, ['huawei', 'vsol'], true) ? 'cfg' : 'rsc';
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

    /**
     * @return array{updated: bool, cancel_requested: bool}
     */
    public function heartbeat(int $id, string $workerId): array
    {
        $updated = BackupExecution::query()->where('id', $id)->where('status', 'running')
            ->where('worker_id', $workerId)->update(['heartbeat_at' => now()]);
        if (! $updated) {
            return ['updated' => false, 'cancel_requested' => false];
        }
        $cancelRequested = BackupExecution::query()->where('id', $id)->where('status', 'running')
            ->where('worker_id', $workerId)->value('cancellation_requested_at') !== null;

        return ['updated' => true, 'cancel_requested' => $cancelRequested];
    }

    /**
     * User-facing cancellation request. pending/queued/retry_wait executions
     * are cancelled immediately (nothing is running yet). A running execution
     * cannot be force-stopped synchronously — this only flags the intent; the
     * worker observes it on its next heartbeat and self-reports via
     * cancelAck() once it has stopped (see docs/ENGINE_QUEUE.md — this is the
     * deliberate improvement over V1, which never signalled a live worker at
     * all, see "Comparação com V1").
     */
    public function requestCancel(int $id, ?int $actorId = null): void
    {
        $outcome = DB::transaction(function () use ($id) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if (in_array($job->status, ['pending', 'queued', 'retry_wait'], true)) {
                $job->status = 'cancelled';
                $job->finished_at = now();
                $job->next_attempt_at = null;
                $job->save();

                return 'cancelled';
            }
            if ($job->status !== 'running') {
                throw ValidationException::withMessages(['status' => 'Execução não pode ser cancelada neste estado.']);
            }
            if ($job->cancellation_requested_at === null) {
                $job->cancellation_requested_at = now();
                $job->save();
            }

            return 'cancel_requested';
        });
        $this->audit('backup_execution.'.$outcome, $id, 'success', [], $actorId);
    }

    /**
     * Worker-initiated: the engine observed cancellation_requested_at (via
     * heartbeat) or its own cooperative check and stopped. Only the owning
     * worker of a still-running, still-cancellation-pending job may call this
     * — mirrors the ownership guard already used by fail()/complete().
     */
    public function cancelAck(int $id, string $workerId): void
    {
        DB::transaction(function () use ($id, $workerId) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || $job->worker_id !== $workerId || $job->cancellation_requested_at === null) {
                throw new \RuntimeException('Cancelamento indisponível.');
            }
            $job->status = 'cancelled';
            $job->finished_at = now();
            $job->worker_id = null;
            $job->save();
        });
        $this->audit('backup_execution.cancelled', $id, 'success', ['worker_id' => $workerId]);
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
            if ($device->management_ip !== $host) {
                throw new \RuntimeException('Endereço do equipamento alterado.');
            }
            $device->ssh_observed_algorithm = $algorithm;
            $device->ssh_observed_fingerprint = $fingerprint;
            $device->ssh_observed_at = now();
            $device->save();
        });
    }

    /**
     * Reclaims executions abandoned by a dead/restarted worker (heartbeat
     * expired) and executions that exceeded their overall wall-clock budget
     * even while still heartbeating (a stuck-but-alive worker). Each is either
     * requeued as retry_wait (retryable code, attempts remain) or terminalized
     * — see scheduleRetryOrFail(). Meant to run every minute (see
     * routes/console.php Schedule::command('engine:recover-stale')).
     */
    public function recoverStale(): int
    {
        $staleSeconds = (int) config('backup.engine_stale_seconds');
        $timeoutSeconds = (int) config('backup.engine_execution_timeout_seconds');
        if ($staleSeconds < 1 || $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Threshold de stale/timeout inválido.');
        }
        $staleCutoff = now()->subSeconds($staleSeconds);
        $timeoutCutoff = now()->subSeconds($timeoutSeconds);
        $events = [];
        $recovered = DB::transaction(function () use ($staleCutoff, $timeoutCutoff, &$events) {
            $jobs = BackupExecution::query()->where('status', 'running')
                ->where(function ($query) use ($staleCutoff, $timeoutCutoff) {
                    $query->where('heartbeat_at', '<', $staleCutoff)
                        ->orWhere(function ($query) use ($staleCutoff) {
                            $query->whereNull('heartbeat_at')->where('started_at', '<', $staleCutoff);
                        })
                        ->orWhere('started_at', '<', $timeoutCutoff);
                })->orderBy('id')->limit(100)->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($jobs as $job) {
                $heartbeatStale = $job->heartbeat_at ? $job->heartbeat_at->lt($staleCutoff) : $job->started_at->lt($staleCutoff);
                if ($job->cancellation_requested_at !== null) {
                    $job->status = 'cancelled';
                    $job->finished_at = now();
                    $job->worker_id = null;
                    $job->save();
                    $events[] = ['backup_execution.cancelled', $job->id, 'success', ['reason' => 'stale_while_cancelling']];
                } elseif ($heartbeatStale) {
                    $outcome = $this->scheduleRetryOrFail($job, 'ENGINE_STALE', 'Execução interrompida: heartbeat expirado.', 'failed');
                    $events[] = $this->recoveryEvent($job->id, $outcome, 'stale_worker');
                } else {
                    $outcome = $this->scheduleRetryOrFail($job, 'ENGINE_TIMEOUT', 'Execução excedeu o tempo máximo permitido.', 'timed_out');
                    $events[] = $this->recoveryEvent($job->id, $outcome, 'execution_timeout');
                }
            }

            return $jobs->count();
        });
        foreach ($events as [$action, $id, $result, $metadata]) {
            $this->audit($action, $id, $result, $metadata);
        }

        return $recovered;
    }

    /**
     * Shared by fail() and recoverStale(): applies the retry/backoff policy
     * (RETRYABLE_CODES + attempt vs max_attempts) or terminalizes the job.
     * $job must already be locked (SELECT ... FOR UPDATE) by the caller.
     * Returns 'retry_scheduled' or the terminal status that was applied.
     */
    private function scheduleRetryOrFail(BackupExecution $job, string $code, string $message, string $terminalStatus): string
    {
        if (in_array($code, self::RETRYABLE_CODES, true) && $job->attempt < $job->max_attempts) {
            $nextAttempt = $job->attempt + 1;
            $job->attempt = $nextAttempt;
            $job->status = 'retry_wait';
            $job->next_attempt_at = now()->addSeconds($this->backoffSeconds($nextAttempt));
            $job->error_code = $code;
            $job->error_message = $message;
            $job->worker_id = null;
            $job->save();

            return 'retry_scheduled';
        }
        $job->status = $terminalStatus;
        $job->finished_at = now();
        $job->error_code = $code;
        $job->error_message = $message;
        $job->worker_id = null;
        $job->save();

        return $terminalStatus;
    }

    private function backoffSeconds(int $nextAttempt): int
    {
        $schedule = config('backup.engine_retry_backoff_seconds');
        if (isset($schedule[$nextAttempt])) {
            return (int) $schedule[$nextAttempt];
        }

        return (int) $schedule[max(array_keys($schedule))];
    }

    private function recoveryEvent(int $id, string $outcome, string $reason): array
    {
        return $outcome === 'retry_scheduled'
            ? ['backup_execution.recovered', $id, 'success', ['reason' => $reason]]
            : ['backup_execution.'.$outcome, $id, 'failure', ['reason' => $reason]];
    }

    private function audit(string $action, int $executionId, string $result, array $metadata, ?int $actorId = null): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }
        app(AuditEvents::class)->record($action, 'backup_execution', (string) $executionId, null, $result, $metadata, $actorId);
    }

    public function resolvePath(string $relative): string
    {
        if (! preg_match('~\ABackup Manager/[A-Z0-9-]+/[A-Z0-9-]+/[0-9]{2}-[0-9]{2}-[0-9]{4}/[A-Z0-9-]+_[0-9]{14}(?:-exec-[1-9][0-9]*)?\.(?:rsc|cfg|dat)\z~D', $relative)) {
            throw new \RuntimeException('Caminho inválido.');
        }
        $root = realpath(config('backup.storage_root'));
        if (! $root || $root === '/') {
            throw new \RuntimeException('Raiz de armazenamento indisponível.');
        }
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new \RuntimeException('Link simbólico no caminho.');
            }
        }
        $file = realpath($root.'/'.$relative);
        if (! $file || ! str_starts_with($file, $root.'/') || ! is_file($file) || is_link($root.'/'.$relative)) {
            throw new \RuntimeException('Arquivo fora da raiz ou ausente.');
        }

        return $file;
    }

    public function complete(int $id, string $relative, ?string $workerId = null): BackupArtifact
    {
        $analysisData = null;
        $analysisVendor = null;
        $analysisPlatform = null;
        $artifact = DB::transaction(function () use ($id, $relative, $workerId, &$analysisData, &$analysisVendor, &$analysisPlatform) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || ($workerId !== null && $job->worker_id !== $workerId) ||
                ! $this->matchesFinalPath($job, $relative) || $job->artifact()->exists()) {
                throw ValidationException::withMessages(['status' => 'Execução indisponível para conclusão.']);
            }
            $payload = $this->job($id);
            $vendor = mb_strtolower(trim($payload['vendor']));
            // ftp_push eligibility is never re-derived here from vendor/platform
            // literals — that duplication is exactly what let VSOL silently fail
            // this check after Device::isHuaweiFtpEligible() was extended to
            // cover it (caught by VsolOltFtpTest). Single source of truth.
            $supported = $payload['method'] === 'ssh_pull' && (
                ($payload['platform'] === 'network' && in_array($vendor, ['mikrotik', 'huawei'], true))
                || ($payload['platform'] === 'olt' && $vendor === 'vsol')
            )
                || $payload['method'] === 'ftp_push' && $payload['ftp_account_available'] && $job->device->isHuaweiFtpEligible();
            if (! $payload['eligible'] || ! $supported || $payload['artifact_mode'] !== 'config') {
                throw ValidationException::withMessages(['status' => 'Job não é elegível para conclusão.']);
            }
            $path = $this->resolvePath($relative);
            $before = lstat($path);
            $size = $before['size'] ?? 0;
            $limit = $payload['method'] === 'ftp_push' ? min(64 * 1024 * 1024, max(1, (int) config('backup.ftp_max_bytes'))) : config('backup.max_artifact_bytes');
            if (! $size || $size > $limit || ! is_file($path) || is_link($path) || ($before['nlink'] ?? 0) !== 1) {
                throw ValidationException::withMessages(['artifact' => 'Tamanho inválido.']);
            }
            $contents = file_get_contents($path);
            clearstatcache(true, $path);
            $after = lstat($path);
            foreach (['dev', 'ino', 'size', 'mtime', 'nlink', 'mode'] as $field) {
                if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                    throw ValidationException::withMessages(['artifact' => 'Arquivo mudou durante a leitura.']);
                }
            }
            if ($contents === false || strlen($contents) !== $size) {
                throw ValidationException::withMessages(['artifact' => 'Leitura incompleta.']);
            }
            if ($payload['method'] === 'ssh_pull' && (str_contains($contents, "\0") || preg_match('/^\s*(?:error:|%\s*(?:error|unrecognized|unknown)|authentication failed|unrecognized command|unknown command|incomplete command|<!doctype html\b|<html\b)/mi', $contents) ||
                preg_match('/(?:-{3,}\s*more\s*-{3,}|\bmore\s*:\s*|press\s+(?:any key|space))/i', $contents))) {
                throw ValidationException::withMessages(['artifact' => 'Comando SSH falhou.']);
            }
            $artifact = BackupArtifact::create([
                'backup_execution_id' => $job->id, 'device_id' => $job->device_id,
                'backup_policy_id' => $job->backup_policy_id, 'type' => 'config', 'storage' => 'local',
                'relative_path' => $relative, 'original_filename' => $job->origin === 'ftp_received' ? $job->received_filename : basename($relative),
                'size_bytes' => $size, 'sha256' => hash('sha256', $contents), 'validated_at' => now(),
            ]);
            if ($payload['method'] === 'ftp_push' || ($vendor === 'mikrotik' && $payload['platform'] === 'network')) {
                $analysisData = $contents;
                $analysisVendor = $vendor;
                $analysisPlatform = $payload['platform'];
            }
            $job->status = 'succeeded';
            $job->finished_at = now();
            $job->worker_id = null;
            $job->save();

            return $artifact;
        });
        $this->audit('backup_execution.succeeded', $id, 'success', []);
        if ($analysisData !== null) {
            try {
                $analysis = $this->analyzeContent($analysisData, $analysisVendor, $analysisPlatform);
                if (Schema::hasTable('audit_events')) {
                    app(AuditEvents::class)->record('backup.content_analyzed', 'backup_execution', (string) $id,
                        null, $analysis['status'], $analysis);
                }
            } catch (\Throwable $error) {
                // Analysis is informational; a parser or audit failure cannot undo a stored backup.
            }
        }

        return $artifact;
    }

    private function analyzeContent(string $contents, string $vendor, string $platform): array
    {
        if ($vendor === 'mikrotik' && $platform === 'network' && str_starts_with(ltrim($contents), '#')) {
            $header = '';
            foreach (preg_split('/\R/', substr($contents, 0, 4096)) as $line) {
                if (! str_starts_with(ltrim($line), '#')) {
                    break;
                }
                $header .= $line."\n";
            }
            $analysis = ['status' => 'recognized', 'message' => 'routeros_export'];
            if (preg_match('/^#\s*[^\r\n]{0,120}\bby RouterOS\s+([0-9]+(?:\.[0-9]+){1,3}(?:[A-Za-z0-9._-]{0,16})?)\b/mi', $header, $matches)) {
                $analysis['version'] = $matches[1];
            }

            return $analysis;
        }
        if ($vendor === 'huawei' && $platform === 'olt' && mb_check_encoding($contents, 'UTF-8') &&
            str_contains($contents, '[!Software Version MA5800') && str_contains($contents, '[Saving time:') &&
            str_contains($contents, '[global-config]') && str_contains($contents, '<global-config>')) {
            $hasSysname = (bool) preg_match('/^\s*sysname\s+\S+/mi', $contents);

            return ['status' => $hasSysname ? 'recognized' : 'warning',
                'message' => $hasSysname ? 'ma5800_config' : 'ma5800_without_sysname'];
        }

        return ['status' => 'unknown', 'message' => 'unrecognized_format'];
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
            'VSOL_PROMPT_FAILED' => 'Prompt da OLT VSOL não reconhecido.',
            'VSOL_PRIVILEGED_MODE_FAILED' => 'A OLT VSOL não liberou o modo privilegiado.',
            'VSOL_EXPORT_FAILED' => 'Export de configuração da OLT VSOL falhou.',
            'FTP_ACCOUNT_UNAVAILABLE' => 'Conta FTP indisponível.',
            'FTP_TRIGGER_FAILED' => 'Disparo do backup FTP falhou.',
            'FTP_RECEIVE_TIMEOUT' => 'Arquivo FTP não recebido no prazo.',
            'FTP_FILE_INVALID' => 'Arquivo FTP inválido.',
            'FTP_FILE_UNCORRELATED' => 'Arquivo FTP sem execução correspondente.',
            'FTP_STORAGE_FAILED' => 'Falha ao armazenar arquivo FTP.',
            'FTP_QUARANTINED' => 'Arquivo FTP movido para quarentena.',
        ];
        if (! isset($messages[$code])) {
            $code = 'ENGINE_FAILED';
        }
        $outcome = DB::transaction(function () use ($id, $code, $messages, $workerId) {
            $job = BackupExecution::query()->lockForUpdate()->findOrFail($id);
            if ($job->status !== 'running' || ($workerId !== null && $job->worker_id !== $workerId)) {
                return null;
            }
            if ($job->cancellation_requested_at !== null) {
                $job->status = 'cancelled';
                $job->finished_at = now();
                $job->worker_id = null;
                $job->save();

                return 'cancelled';
            }

            return $this->scheduleRetryOrFail($job, $code, $messages[$code], 'failed');
        });
        if ($outcome === null) {
            return;
        }
        if ($outcome === 'retry_scheduled') {
            $this->audit('backup_execution.retry_scheduled', $id, 'success', ['code' => $code]);
        } elseif ($outcome === 'cancelled') {
            $this->audit('backup_execution.cancelled', $id, 'success', ['code' => $code]);
        } else {
            $this->audit('backup_execution.failed', $id, 'failure', ['code' => $code]);
        }
    }
}
