<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceBackupPolicy extends Model
{
    protected $fillable = ['device_id', 'backup_policy_id', 'credential_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
