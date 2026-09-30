<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    use HasFactory;

    public const VENDORS = [
        'C-DATA', 'Cisco', 'Datacom', 'FiberHome', 'Huawei', 'Intelbras',
        'Juniper', 'MikroTik', 'Parks', 'Ubiquiti', 'VSOL', 'ZTE',
    ];

    public static function normalizeVendor(string $vendor): string
    {
        $vendor = trim($vendor);
        foreach (self::VENDORS as $canonical) {
            if (mb_strtolower($vendor) === mb_strtolower($canonical)) {
                return $canonical;
            }
        }

        return $vendor;
    }

    /** @return list<string> */
    public static function vendorOptions(?string $legacyVendor = null): array
    {
        $vendors = self::VENDORS;
        $legacyVendor = self::normalizeVendor($legacyVendor ?? '');
        if ($legacyVendor !== '' && ! in_array($legacyVendor, $vendors, true)) {
            $vendors[] = $legacyVendor;
        }

        return $vendors;
    }

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

    public function technicalHostnameForDisplay(): ?string
    {
        $hostname = trim((string) $this->hostname);

        return $hostname !== '' && mb_strtolower($hostname) !== mb_strtolower(trim($this->name))
            ? $hostname
            : null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function ftpAccount(): HasOne
    {
        return $this->hasOne(FtpAccount::class);
    }

    public function oltFtpIntegration(): HasOne
    {
        return $this->hasOne(OltFtpIntegration::class);
    }

    public function deviceBackupPolicies(): HasMany
    {
        return $this->hasMany(DeviceBackupPolicy::class);
    }
}
