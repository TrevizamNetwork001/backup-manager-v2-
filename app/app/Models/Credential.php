<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credential extends Model
{
    public const TYPES = ['ssh', 'telnet', 'ftp', 'sftp', 'api'];

    public const SELECTABLE_TYPES = ['ssh', 'telnet'];

    protected $fillable = [
        'device_id',
        'name',
        'type',
        'username',
        'port',
        'notes',
        'is_active',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'port' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceBackupPolicies(): HasMany
    {
        return $this->hasMany(DeviceBackupPolicy::class);
    }
}
