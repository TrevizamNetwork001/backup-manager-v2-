<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceBackupPolicy extends Model
{
    protected $fillable = ['device_id', 'backup_policy_id', 'credential_id', 'is_active', 'archived_at'];

    public static function hasActiveMethod(int $deviceId, string $method, ?int $exceptAssociationId = null): bool
    {
        return self::query()
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->when($exceptAssociationId, fn ($query) => $query->where('id', '!=', $exceptAssociationId))
            ->whereHas('backupPolicy', fn ($query) => $query->where('method', $method)->where('is_active', true)->whereNull('archived_at'))
            ->exists();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archived_at' => 'datetime'];
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

    public function backupExecutions(): HasMany
    {
        return $this->hasMany(BackupExecution::class);
    }
}
