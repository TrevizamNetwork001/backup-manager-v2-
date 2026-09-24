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
    public const STATUSES = ['pending', 'queued', 'running', 'succeeded', 'failed', 'cancelled'];
    public const ORIGINS = ['manual', 'scheduler', 'ftp_received'];
    private const TRANSITIONS = [
        'pending' => ['queued', 'cancelled'],
        'queued' => ['running', 'cancelled'],
        'running' => ['succeeded', 'failed'],
    ];

    protected $fillable = [
        'device_backup_policy_id', 'backup_policy_id', 'device_id', 'credential_id',
        'origin', 'status', 'attempt', 'started_at', 'finished_at', 'scheduled_for',
        'ftp_account_id', 'ftp_claim_token', 'received_filename', 'received_at', 'processing_at',
        'claimed_at', 'heartbeat_at', 'worker_id',
    ];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'claimed_at' => 'datetime', 'heartbeat_at' => 'datetime', 'scheduled_for' => 'datetime',
            'received_at' => 'datetime', 'processing_at' => 'datetime'];
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
            if (Schema::hasTable('ftp_accounts')) $relations[] = 'device.ftpAccount';
            $association = DeviceBackupPolicy::query()->with($relations)
                ->lockForUpdate()->findOrFail($association->id);
            if (! $association->is_active || ! $association->backupPolicy->is_active ||
                ! $association->device->is_active ||
                ($association->backupPolicy->method === 'ssh_pull' && ! $association->credential?->is_active) ||
                ($association->backupPolicy->method === 'ftp_push' &&
                    ($association->backupPolicy->schedule_type !== 'manual' || ! Schema::hasTable('ftp_accounts') || ! $association->device->ftpAccount?->is_active || $association->device->platform !== 'olt' || mb_strtolower(trim($association->device->vendor)) !== 'huawei'))) {
                throw ValidationException::withMessages(['association' => 'A associação, política, equipamento e credencial devem estar ativos.']);
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
            if (in_array($next, ['running', 'succeeded', 'failed'], true)) {
                throw ValidationException::withMessages(['status' => 'Transição reservada ao engine.']);
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
