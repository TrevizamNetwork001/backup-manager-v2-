<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltFtpIntegration extends Model
{
    protected $fillable = ['device_id', 'test_association_id', 'test_execution_id', 'olt_confirmed_at', 'account_updated_at', 'ftp_host', 'management_ip'];

    protected function casts(): array
    {
        return ['olt_confirmed_at' => 'datetime', 'account_updated_at' => 'datetime'];
    }

    public function testExecution(): BelongsTo
    {
        return $this->belongsTo(BackupExecution::class, 'test_execution_id');
    }
}
