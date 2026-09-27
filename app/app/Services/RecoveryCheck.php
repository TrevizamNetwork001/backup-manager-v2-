<?php

namespace App\Services;

use App\Support\HealthCheckResult;
use App\Support\HealthStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STABILIZATION-1 (Part D). Read-only disaster-recovery readiness check.
 *
 * The Backup Manager's own encrypted `credentials.secret` column is
 * unreadable without the exact APP_KEY that encrypted it — recovering the
 * app WITHOUT that key permanently loses every stored device credential
 * (see docs/DISASTER_RECOVERY.md). This never prints or otherwise exposes
 * APP_KEY; it only reports whether one is present/plausible and a
 * non-reversible fingerprint (sha256 of the raw key bytes, truncated) an
 * operator can compare against a securely-stored reference value to confirm,
 * during a real recovery, that the restored .env has the *same* key the
 * backup was encrypted with — without ever needing to display either key.
 */
class RecoveryCheck
{
    public function run(): array
    {
        $checks = [
            $this->checkAppKey(),
            $this->checkDatabase(),
            $this->checkEssentialTables(),
            $this->checkStorageRoot(),
            $this->checkEssentialConfig(),
        ];

        return [
            'overall_status' => HealthStatus::worst(array_map(fn ($c) => $c->status, $checks))->value,
            'checked_at' => now()->toIso8601String(),
            'checks' => array_map(fn ($c) => $c->toArray(), $checks),
        ];
    }

    private function checkAppKey(): HealthCheckResult
    {
        $key = config('app.key');
        if (! $key) {
            return HealthCheckResult::make('app_key', HealthStatus::Critical, 'missing',
                'APP_KEY não está definida. Credenciais criptografadas não podem ser lidas.', []);
        }
        if (! str_starts_with($key, 'base64:')) {
            return HealthCheckResult::make('app_key', HealthStatus::Warning, 'unexpected_format',
                'APP_KEY não está no formato base64: esperado pelo Laravel.', []);
        }
        $raw = base64_decode(substr($key, 7), true);
        if ($raw === false || strlen($raw) !== 32) {
            return HealthCheckResult::make('app_key', HealthStatus::Critical, 'implausible',
                'APP_KEY não decodifica para uma chave de 256 bits plausível.', []);
        }
        // Never the key itself — a one-way fingerprint safe to write down/compare.
        $fingerprint = substr(hash('sha256', $raw), 0, 16);

        return HealthCheckResult::make('app_key', HealthStatus::Healthy, 'present',
            'APP_KEY presente e com formato plausível.', ['fingerprint_sha256_16' => $fingerprint]);
    }

    private function checkDatabase(): HealthCheckResult
    {
        try {
            DB::select('select 1');

            return HealthCheckResult::make('database', HealthStatus::Healthy, 'connected',
                'Banco de dados acessível.', ['driver' => DB::connection()->getDriverName()]);
        } catch (\Throwable $e) {
            return HealthCheckResult::make('database', HealthStatus::Critical, 'connection_failed',
                'Não foi possível conectar ao banco de dados.', []);
        }
    }

    private function checkEssentialTables(): HealthCheckResult
    {
        $required = ['users', 'sites', 'devices', 'credentials', 'backup_policies',
            'device_backup_policies', 'backup_executions', 'backup_artifacts', 'application_settings'];
        $missing = array_values(array_filter($required, fn ($table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            return HealthCheckResult::make('schema', HealthStatus::Critical, 'missing_tables',
                'Tabelas essenciais ausentes — migrations pendentes ou banco incorreto.', ['missing' => $missing]);
        }

        return HealthCheckResult::make('schema', HealthStatus::Healthy, 'ok', 'Tabelas essenciais presentes.', []);
    }

    private function checkStorageRoot(): HealthCheckResult
    {
        $root = config('backup.storage_root');
        if (! $root || ! is_dir($root)) {
            return HealthCheckResult::make('storage', HealthStatus::Critical, 'missing_root',
                'Raiz de armazenamento de backups não existe ou não está montada.', []);
        }
        if (! is_readable($root)) {
            return HealthCheckResult::make('storage', HealthStatus::Critical, 'unreadable',
                'Raiz de armazenamento existe mas não é legível pelo processo atual.', []);
        }

        return HealthCheckResult::make('storage', HealthStatus::Healthy, 'ok', 'Raiz de armazenamento acessível.', []);
    }

    private function checkEssentialConfig(): HealthCheckResult
    {
        $missing = [];
        if (! config('app.url')) $missing[] = 'APP_URL';
        if (! config('database.default')) $missing[] = 'DB_CONNECTION';
        if ($missing !== []) {
            return HealthCheckResult::make('config', HealthStatus::Warning, 'missing_values',
                'Configurações essenciais ausentes.', ['missing' => $missing]);
        }

        return HealthCheckResult::make('config', HealthStatus::Healthy, 'ok', 'Configuração essencial presente.', []);
    }
}
