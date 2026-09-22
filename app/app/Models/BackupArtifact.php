<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupArtifact extends Model
{
    public const STATUSES = ['available', 'deleted', 'missing'];
    public const DELETION_REASONS = ['retention_days', 'retention_count', 'retention_days_and_count'];

    public function statusLabel(): string
    {
        return match ($this->status) {
            'deleted' => 'Removido', 'missing' => 'Ausente', default => 'Disponível',
        };
    }

    public function deletionReasonLabel(): ?string
    {
        return match ($this->deletion_reason) {
            'retention_days' => 'Retenção em dias',
            'retention_count' => 'Retenção em quantidade',
            'retention_days_and_count' => 'Retenção em dias e quantidade',
            default => null,
        };
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'validated_at' => 'datetime',
            'deleted_at' => 'datetime', 'missing_at' => 'datetime'];
    }

    public function backupExecution(): BelongsTo { return $this->belongsTo(BackupExecution::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function backupPolicy(): BelongsTo { return $this->belongsTo(BackupPolicy::class); }
}
