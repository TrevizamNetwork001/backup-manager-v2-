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

    /**
     * Single source of truth for "can this device use the FTP-push flow"
     * (originally Huawei-OLT-only, hence the name; now covers Huawei network
     * devices — routers/switches, VRP `save-configuration backup-to-server`
     * — and VSOL OLT — `copy startup-config ftp://...` over its SSH console.
     * Same shape either way: an operator-run push into an FTP account the
     * engine only ever receives from, never connects out to configure).
     */
    public function isHuaweiFtpEligible(): bool
    {
        $vendor = mb_strtolower(trim($this->vendor));

        return ($vendor === 'huawei' && in_array($this->platform, ['olt', 'network'], true))
            || ($vendor === 'vsol' && $this->platform === 'olt');
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

    public function oltFtpIntegration(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OltFtpIntegration::class);
    }

    public function deviceBackupPolicies(): HasMany
    {
        return $this->hasMany(DeviceBackupPolicy::class);
    }
}
