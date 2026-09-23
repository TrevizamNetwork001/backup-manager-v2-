<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'hostname',
        'management_ip',
        'vendor',
        'platform',
        'model',
        'os_version',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ssh_host_key_trusted_at' => 'datetime',
            'ssh_observed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function ftpAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FtpAccount::class);
    }

    public function deviceBackupPolicies(): HasMany
    {
        return $this->hasMany(DeviceBackupPolicy::class);
    }
}
