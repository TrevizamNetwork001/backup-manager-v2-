<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtpAccount extends Model
{
    protected $fillable = ['device_id', 'account_uuid', 'purpose', 'home_layout', 'username', 'is_active', 'sync_error'];
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'is_active' => 'boolean', 'provisioned_at' => 'datetime', 'credential_changed_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function homePath(): string
    {
        $root = rtrim(config('backup.ftp_root'), '/');
        return $this->home_layout === null || $this->home_layout === 'legacy'
            ? $root.'/'.$this->device_id.'/incoming'
            : $root.'/accounts/'.$this->account_uuid.'/incoming';
    }
}
