<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RetentionSettings
{
    public function available(): bool
    {
        return Schema::hasColumn('application_settings', 'retention_enabled');
    }

    public function enabled(): bool
    {
        if (! $this->available()) {
            return (bool) config('backup.retention_enabled');
        }

        return (bool) DB::table('application_settings')->where('id', 1)->value('retention_enabled');
    }

    public function setEnabled(bool $enabled): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Atualização do banco pendente para configurar a retenção.');
        }

        DB::table('application_settings')->where('id', 1)->update([
            'retention_enabled' => $enabled,
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
