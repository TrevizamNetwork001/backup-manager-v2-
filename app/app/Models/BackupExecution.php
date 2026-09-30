<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BackupExecution extends Model
{
    // 'retry_wait' and 'timed_out' were added in ENGINE-2. A job in
    // 'retry_wait' is functionally "waiting to be reclaimed," exactly like
    // 'queued', except claim() also checks next_attempt_at for it — no
    // separate "claimed" state was introduced because claim() already moves
    // straight to 'running' atomically (see EngineJobService::claim()).
    public const STATUSES = ['pending', 'queued', 'running', 'succeeded', 'failed', 'retry_wait', 'timed_out', 'cancelled'];

    // A device with an execution in any of these statuses is "busy" — used to
    // reject a second concurrent execution for the same device (manual or
    // scheduled). Lesson from V1 (backup_manager/jobs.py queue_run): reject
    // the duplicate at creation time, not only at claim time.
    public const LIVE_STATUSES = ['pending', 'queued', 'running', 'retry_wait'];

    public const ORIGINS = ['manual', 'scheduler', 'ftp_received'];

    private const TRANSITIONS = [
        'pending' => ['queued', 'cancelled'],
        'queued' => ['running', 'cancelled'],
        'running' => ['succeeded', 'failed', 'timed_out', 'cancelled'],
        'failed' => ['retry_wait'],
        'timed_out' => ['retry_wait'],
        'retry_wait' => ['running', 'cancelled'],
    ];

    protected $fillable = [
        'device_backup_policy_id', 'backup_policy_id', 'device_id', 'credential_id',
        'origin', 'status', 'attempt', 'max_attempts', 'started_at', 'finished_at', 'scheduled_for',
        'ftp_account_id', 'ftp_claim_token', 'received_filename', 'received_at', 'processing_at',
        'claimed_at', 'heartbeat_at', 'worker_id', 'next_attempt_at', 'cancellation_requested_at',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $execution): void {
            if (in_array($execution->status, ['queued', 'retry_wait'], true) &&
                $execution->isDirty('status') && $execution->origin !== 'ftp_received' &&
                $execution->getConnection()->getDriverName() === 'pgsql') {
                $execution->getConnection()->statement('NOTIFY backup_engine_queue');
            }
        });
    }

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'max_attempts' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'claimed_at' => 'datetime', 'heartbeat_at' => 'datetime', 'scheduled_for' => 'datetime',
            'received_at' => 'datetime', 'processing_at' => 'datetime',
            'next_attempt_at' => 'datetime', 'cancellation_requested_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(DeviceBackupPolicy::class, 'device_backup_policy_id');
    }

    public function backupPolicy(): BelongsTo
    {
        return $this->belongsTo(BackupPolicy::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function artifact(): HasOne
    {
        return $this->hasOne(BackupArtifact::class);
    }

    public static function createManual(DeviceBackupPolicy $association): self
    {
        return DB::transaction(function () use ($association) {
            $relations = ['backupPolicy:id,is_active,method,schedule_type', 'device', 'credential:id,is_active'];
            if (Schema::hasTable('ftp_accounts')) {
                $relations[] = 'device.ftpAccount';
            }
            $association = DeviceBackupPolicy::query()->with($relations)
                ->lockForUpdate()->findOrFail($association->id);
            if (! $association->is_active || ! $association->backupPolicy->is_active ||
                ! $association->device->is_active ||
                ($association->backupPolicy->method === 'ssh_pull' && ! $association->credential?->is_active) ||
                ($association->backupPolicy->method === 'ftp_push' &&
                    ($association->backupPolicy->schedule_type !== 'manual' || ! Schema::hasTable('ftp_accounts') || ! $association->device->ftpAccount?->is_active || ! $association->device->isHuaweiFtpEligible()))) {
                throw ValidationException::withMessages(['association' => 'A associação, política, equipamento e credencial devem estar ativos.']);
            }
            $busy = self::query()->where('device_id', $association->device_id)
                ->whereIn('status', self::LIVE_STATUSES)->lockForUpdate()->exists();
            if ($busy) {
                throw ValidationException::withMessages(['association' => 'Este equipamento já possui uma execução em andamento ou pendente.']);
            }

            return self::create([
                'device_backup_policy_id' => $association->id,
                'backup_policy_id' => $association->backup_policy_id,
                'device_id' => $association->device_id,
                'credential_id' => $association->credential_id,
                'origin' => 'manual', 'status' => 'pending', 'attempt' => 1,
            ]);
        });
    }

    public function transitionTo(string $next): void
    {
        DB::transaction(function () use ($next) {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            if (! in_array($next, self::TRANSITIONS[$current->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Transição de estado inválida.']);
            }
            if (in_array($next, ['running', 'succeeded', 'failed', 'timed_out', 'retry_wait'], true)) {
                throw ValidationException::withMessages(['status' => 'Transição reservada ao engine.']);
            }
            if ($next === 'cancelled' && $current->status === 'running') {
                // A running execution cannot be force-cancelled synchronously — the
                // worker holds the SSH/FTP session. See EngineJobService::requestCancel().
                throw ValidationException::withMessages(['status' => 'Cancelamento de execução em andamento requer solicitação ao engine.']);
            }
            $current->status = $next;
            if ($next === 'running') {
                $current->started_at ??= now();
            }
            if (in_array($next, ['succeeded', 'failed', 'cancelled'], true)) {
                $current->finished_at = now();
            }
            $current->save();
            $this->setRawAttributes($current->getAttributes(), true);
        });
    }
}
