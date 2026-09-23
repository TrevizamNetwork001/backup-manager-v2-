<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupPolicy extends Model
{
    public const METHODS = ['ssh_pull', 'ftp_push'];
    public const ARTIFACT_MODES = ['config', 'binary', 'both'];
    public const SCHEDULE_TYPES = ['manual', 'daily', 'weekly'];
    public const WEEKDAYS = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];

    protected $fillable = [
        'name', 'method', 'artifact_mode', 'schedule_type', 'schedule_time',
        'schedule_weekday', 'retention_days', 'retention_count', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schedule_weekday' => 'integer',
            'retention_days' => 'integer',
            'retention_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deviceBackupPolicies(): HasMany
    {
        return $this->hasMany(DeviceBackupPolicy::class);
    }

    public function credentialType(): string
    {
        return match ($this->method) {
            'ssh_pull' => 'ssh',
            'ftp_push' => 'none',
            default => throw new \LogicException('Método de backup inválido.'),
        };
    }
}
