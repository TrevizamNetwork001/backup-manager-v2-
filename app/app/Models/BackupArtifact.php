<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupArtifact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'validated_at' => 'datetime'];
    }

    public function backupExecution(): BelongsTo { return $this->belongsTo(BackupExecution::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function backupPolicy(): BelongsTo { return $this->belongsTo(BackupPolicy::class); }
}
