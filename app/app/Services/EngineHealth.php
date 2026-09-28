<?php

namespace App\Services;

use App\Models\BackupExecution;
use App\Support\AlertCondition;
use App\Support\HealthCheckResult;
use App\Support\HealthStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Central operational health aggregator (ENGINE-3). `snapshot()` is the
 * ENGINE-2-era lifecycle counter (kept as-is, still used by recovery code and
 * its own tests); `report()` is the full structured multi-check report behind
 * `engine:health`/`engine:diagnose`/`/system/health` — see docs/ENGINE_HEALTH.md.
 *
 * Every check in report() is wrapped so its own failure can never take down
 * the rest of the report (fail-soft — see docs/ENGINE_HEALTH.md "Fail-soft").
 */
class EngineHealth
{
    private ?array $engineSnapshotCache = null;

    private bool $engineSnapshotLoaded = false;

    public function __construct(private readonly DeviceBackupHealth $deviceHealth) {}

    public function snapshot(): array
    {
        $counts = BackupExecution::query()->whereIn('status', BackupExecution::STATUSES)
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'queued' => (int) ($counts['queued'] ?? 0),
            'running' => (int) ($counts['running'] ?? 0),
            'retry_wait' => (int) ($counts['retry_wait'] ?? 0),
            'timed_out' => (int) ($counts['timed_out'] ?? 0),
            'oldest_pending_seconds' => $this->oldestAgeSeconds(['pending', 'queued', 'retry_wait']),
            'last_success_at' => BackupExecution::query()->where('status', 'succeeded')
                ->max('finished_at'),
            'stale_running_count' => BackupExecution::query()->where('status', 'running')
                ->where('heartbeat_at', '<', now()->subSeconds((int) config('backup.engine_stale_seconds')))
                ->count(),
        ];
    }

    /**
     * @return array{overall_status: string, checked_at: string, checks: array, alerts: list<string>}
     */
    public function report(): array
    {
        $checks = [
            $this->safely('database', fn () => $this->checkDatabase()),
            $this->safely('redis', fn () => $this->checkRedis()),
            $this->safely('engine', fn () => $this->checkEngine()),
            $this->safely('driver_registry', fn () => $this->checkDriverRegistry()),
            $this->safely('worker', fn () => $this->checkWorker()),
            $this->safely('scheduler', fn () => $this->checkScheduler()),
            $this->safely('queue', fn () => $this->checkQueue()),
            $this->safely('stale_jobs', fn () => $this->checkStaleJobs()),
            $this->safely('retry', fn () => $this->checkRetry()),
            $this->safely('failure', fn () => $this->checkFailure()),
            $this->safely('devices', fn () => $this->checkDevices()),
            $this->safely('storage', fn () => $this->checkStorage()),
            $this->safely('ftp', fn () => $this->checkFtp()),
            $this->safely('file_server', fn () => $this->checkFileServer()),
            $this->safely('retention', fn () => $this->checkRetention()),
        ];

        return [
            'overall_status' => HealthStatus::worst(array_map(fn ($c) => $c->status, $checks))->value,
            'checked_at' => now()->toIso8601String(),
            'checks' => array_map(fn ($c) => $c->toArray(), $checks),
            'alerts' => $this->deriveAlerts($checks),
        ];
    }

    private function safely(string $name, \Closure $check): HealthCheckResult
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            report($e);

            return HealthCheckResult::unknown($name, 'check_failed', 'Falha ao executar esta verificação.');
        }
    }

    private function checkDatabase(): HealthCheckResult
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 1);

            return HealthCheckResult::make('database', HealthStatus::Healthy, 'connected',
                'Conexão com o banco de dados OK.', ['latency_ms' => $latencyMs]);
        } catch (\Throwable $e) {
            return HealthCheckResult::make('database', HealthStatus::Critical, 'connection_failed',
                'Não foi possível conectar ao banco de dados.', []);
        }
    }

    private function checkRedis(): HealthCheckResult
    {
        try {
            $start = microtime(true);
            Redis::connection()->ping();
            $latencyMs = round((microtime(true) - $start) * 1000, 1);

            return HealthCheckResult::make('redis', HealthStatus::Healthy, 'connected',
                'Conexão com o Redis OK.', ['latency_ms' => $latencyMs]);
        } catch (\Throwable $e) {
            // Redis is transient coordination only (docs/ENGINE_QUEUE.md) — losing
            // it never loses execution state, so this is a warning, not critical.
            return HealthCheckResult::make('redis', HealthStatus::Warning, 'connection_failed',
                'Não foi possível conectar ao Redis. O estado das execuções não é afetado.', []);
        }
    }

    private function engineSnapshot(): ?array
    {
        if ($this->engineSnapshotLoaded) {
            return $this->engineSnapshotCache;
        }
        $this->engineSnapshotLoaded = true;
        $path = config('backup.engine_health_snapshot_path');
        if (! $path || is_link($path) || ! is_file($path)) {
            return $this->engineSnapshotCache = null;
        }
        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return $this->engineSnapshotCache = is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return $this->engineSnapshotCache = null;
        }
    }

    private function checkEngine(): HealthCheckResult
    {
        $snapshot = $this->engineSnapshot();
        if ($snapshot === null) {
            return HealthCheckResult::make('engine', HealthStatus::Unknown, 'no_snapshot',
                'Ainda não foi encontrado um registro de estado do motor de backup.', []);
        }
        $generatedAt = $snapshot['generated_at'] ?? null;
        if (! is_int($generatedAt)) {
            return HealthCheckResult::make('engine', HealthStatus::Unknown, 'invalid_snapshot',
                'Registro de estado do motor de backup sem data válida.', []);
        }
        $ageSeconds = now()->timestamp - $generatedAt;
        $staleThreshold = (int) config('backup.engine_health_snapshot_stale_seconds');
        if ($ageSeconds > $staleThreshold) {
            return HealthCheckResult::make('engine', HealthStatus::Critical, 'stale_snapshot',
                'O motor de backup não atualiza seu estado há mais tempo que o esperado — provavelmente parado.',
                ['age_seconds' => $ageSeconds]);
        }

        return HealthCheckResult::make('engine', HealthStatus::Healthy, 'alive', 'Motor de backup ativo e reportando.', [
            'age_seconds' => $ageSeconds,
            'engine_version' => $snapshot['engine_version'] ?? null,
            'python_version' => $snapshot['python_version'] ?? null,
            'worker_id' => $snapshot['worker_id'] ?? null,
            'workspace_writable' => $snapshot['workspace_writable'] ?? null,
        ]);
    }

    private function checkDriverRegistry(): HealthCheckResult
    {
        $snapshot = $this->engineSnapshot();
        if ($snapshot === null) {
            return HealthCheckResult::make('driver_registry', HealthStatus::Unknown, 'no_snapshot',
                'Sem dados do engine para avaliar os drivers.', []);
        }
        $drivers = $snapshot['drivers'] ?? [];
        $broken = array_values(array_filter($drivers, fn ($d) => isset($d['error'])));
        $expectedVendors = ['mikrotik', 'huawei'];
        $seenVendors = array_unique(array_column($drivers, 'vendor'));
        $missing = array_values(array_diff($expectedVendors, $seenVendors));
        if ($broken !== [] || $missing !== []) {
            return HealthCheckResult::make('driver_registry', HealthStatus::Warning, 'incomplete_registry',
                'Um ou mais drivers esperados não carregaram corretamente.',
                ['broken' => $broken, 'missing_vendors' => $missing, 'driver_count' => count($drivers)]);
        }

        return HealthCheckResult::make('driver_registry', HealthStatus::Healthy, 'ok',
            'Todos os drivers esperados estão carregados.', ['driver_count' => count($drivers)]);
    }

    private function checkWorker(): HealthCheckResult
    {
        $running = BackupExecution::query()->where('status', 'running')->get(['id', 'heartbeat_at', 'started_at']);
        if ($running->isEmpty()) {
            return HealthCheckResult::make('worker', HealthStatus::Unknown, 'idle',
                'Nenhuma execução em andamento no momento.', []);
        }
        $staleCutoff = now()->subSeconds((int) config('backup.engine_stale_seconds'));
        $stale = $running->filter(fn ($job) => ($job->heartbeat_at ?? $job->started_at)?->lt($staleCutoff) ?? true)->count();
        if ($stale > 0) {
            return HealthCheckResult::make('worker', HealthStatus::Critical, 'stale_heartbeat',
                'Existem execuções em andamento sem sinal de atividade recente.',
                ['running' => $running->count(), 'stale' => $stale]);
        }

        return HealthCheckResult::make('worker', HealthStatus::Healthy, 'ok',
            'O sinal de atividade das execuções em andamento está em dia.', ['running' => $running->count()]);
    }

    private function checkScheduler(): HealthCheckResult
    {
        try {
            $lastTick = Cache::store('redis')->get('health:scheduler:last_tick');
        } catch (\Throwable $e) {
            return HealthCheckResult::make('scheduler', HealthStatus::Unknown, 'redis_unavailable',
                'Não foi possível ler o sinal de atividade do agendador (Redis indisponível).', []);
        }
        // Redis returns un-serialized numeric cache values as strings.
        if (is_string($lastTick) && ctype_digit($lastTick)) {
            $lastTick = filter_var($lastTick, FILTER_VALIDATE_INT);
        }
        if (! is_int($lastTick) || $lastTick <= 0) {
            return HealthCheckResult::make('scheduler', HealthStatus::Unknown, 'never_ticked',
                'O agendador ainda não registrou nenhuma execução.', []);
        }
        $ageMinutes = abs(now()->diffInMinutes(CarbonImmutable::createFromTimestamp($lastTick)));
        $warn = (int) config('health.scheduler_warning_minutes');
        $crit = (int) config('health.scheduler_critical_minutes');
        $status = $ageMinutes >= $crit ? HealthStatus::Critical : ($ageMinutes >= $warn ? HealthStatus::Warning : HealthStatus::Healthy);

        return HealthCheckResult::make('scheduler', $status, 'tick_age',
            $status === HealthStatus::Healthy ? 'Agendador executando normalmente.' : 'O agendador está atrasado ou parado.',
            ['age_minutes' => $ageMinutes]);
    }

    private function checkQueue(): HealthCheckResult
    {
        $counts = BackupExecution::query()->whereIn('status', ['pending', 'queued', 'retry_wait'])
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $backlog = (int) ($counts['pending'] ?? 0) + (int) ($counts['queued'] ?? 0) + (int) ($counts['retry_wait'] ?? 0);
        $warn = (int) config('health.backlog_warning');
        $crit = (int) config('health.backlog_critical');
        $status = $backlog >= $crit ? HealthStatus::Critical : ($backlog >= $warn ? HealthStatus::Warning : HealthStatus::Healthy);

        return HealthCheckResult::make('queue', $status, 'backlog', "Backlog atual: {$backlog}.", [
            'pending' => (int) ($counts['pending'] ?? 0), 'queued' => (int) ($counts['queued'] ?? 0),
            'retry_wait' => (int) ($counts['retry_wait'] ?? 0), 'backlog' => $backlog,
        ]);
    }

    private function checkStaleJobs(): HealthCheckResult
    {
        $staleSeconds = (int) config('backup.engine_stale_seconds');
        $cutoff = now()->subSeconds($staleSeconds);
        $stale = BackupExecution::query()->where('status', 'running')
            ->where(fn ($q) => $q->where('heartbeat_at', '<', $cutoff)
                ->orWhere(fn ($q2) => $q2->whereNull('heartbeat_at')->where('started_at', '<', $cutoff)))
            ->orderBy('started_at')->limit(10)->get(['id', 'device_id', 'started_at']);
        if ($stale->isEmpty()) {
            return HealthCheckResult::make('stale_jobs', HealthStatus::Healthy, 'none', 'Nenhuma execução travada.', ['count' => 0]);
        }
        $oldestAgeSeconds = abs(now()->diffInSeconds($stale->first()->started_at));
        $status = $oldestAgeSeconds > $staleSeconds * 2 ? HealthStatus::Critical : HealthStatus::Warning;

        return HealthCheckResult::make('stale_jobs', $status, 'stale_detected',
            'Existem execuções travadas aguardando recuperação automática (engine:recover-stale).', [
                'count' => $stale->count(), 'oldest_execution_id' => $stale->first()->id,
                'oldest_age_seconds' => $oldestAgeSeconds,
                'affected_device_ids' => $stale->pluck('device_id')->unique()->values()->all(),
            ]);
    }

    private function checkRetry(): HealthCheckResult
    {
        $retryWait = BackupExecution::query()->where('status', 'retry_wait')->count();
        $nearMax = BackupExecution::query()->where('status', 'retry_wait')
            ->whereColumn('attempt', '>=', 'max_attempts')->count();
        $topCodes = BackupExecution::query()->where('status', 'retry_wait')->whereNotNull('error_code')
            ->select('error_code', DB::raw('count(*) as total'))->groupBy('error_code')
            ->orderByDesc('total')->limit(5)->pluck('total', 'error_code');
        $status = $retryWait === 0 ? HealthStatus::Healthy : HealthStatus::Warning;

        return HealthCheckResult::make('retry', $status, 'retry_wait_count',
            "{$retryWait} execução(ões) aguardando nova tentativa.", [
                'retry_wait' => $retryWait, 'near_max_attempts' => $nearMax,
                'top_error_codes' => $topCodes->toArray(),
            ]);
    }

    private function checkFailure(): HealthCheckResult
    {
        $windowHours = (int) config('health.recent_window_hours');
        $since = now()->subHours($windowHours);
        $counts = BackupExecution::query()->where('created_at', '>=', $since)
            ->whereIn('status', ['succeeded', 'failed', 'timed_out'])
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $succeeded = (int) ($counts['succeeded'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0) + (int) ($counts['timed_out'] ?? 0);
        $total = $succeeded + $failed;
        if ($total === 0) {
            return HealthCheckResult::make('failure', HealthStatus::Unknown, 'no_data',
                'Sem execuções terminadas na janela recente para avaliar.', ['window_hours' => $windowHours]);
        }
        $rate = round($succeeded / $total * 100, 1);
        $warn = (int) config('health.success_rate_warning_percent');
        $crit = (int) config('health.success_rate_critical_percent');
        $status = $rate < $crit ? HealthStatus::Critical : ($rate < $warn ? HealthStatus::Warning : HealthStatus::Healthy);
        $topCodes = BackupExecution::query()->where('created_at', '>=', $since)
            ->whereIn('status', ['failed', 'timed_out'])->whereNotNull('error_code')
            ->select('error_code', DB::raw('count(*) as total'))->groupBy('error_code')
            ->orderByDesc('total')->limit(5)->pluck('total', 'error_code');

        return HealthCheckResult::make('failure', $status, 'success_rate',
            "Taxa de sucesso nas últimas {$windowHours}h: {$rate}%.", [
                'succeeded' => $succeeded, 'failed' => $failed, 'success_rate_percent' => $rate,
                'top_error_codes' => $topCodes->toArray(), 'window_hours' => $windowHours,
            ]);
    }

    private function checkDevices(): HealthCheckResult
    {
        $summary = $this->deviceHealth->summary();
        $counts = $summary['counts'];
        $status = match (true) {
            $counts['critical'] > 0 => HealthStatus::Critical,
            $counts['warning'] > 0 => HealthStatus::Warning,
            $counts['healthy'] > 0 => HealthStatus::Healthy,
            default => HealthStatus::Unknown,
        };

        return HealthCheckResult::make('devices', $status, 'summary',
            "{$counts['critical']} crítico(s), {$counts['warning']} em atenção.", $summary);
    }

    private function checkStorage(): HealthCheckResult
    {
        $root = config('backup.storage_root');
        if (! is_dir($root)) {
            return HealthCheckResult::make('storage', HealthStatus::Critical, 'missing_root',
                'Diretório raiz de armazenamento não existe.', []);
        }
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free === false || $total === false || $total <= 0) {
            return HealthCheckResult::make('storage', HealthStatus::Unknown, 'unavailable',
                'Não foi possível consultar o espaço em disco.', []);
        }
        $usedPercent = round((1 - $free / $total) * 100, 1);
        $warn = (int) config('health.storage_warning_percent');
        $crit = (int) config('health.storage_critical_percent');
        $status = $usedPercent >= $crit ? HealthStatus::Critical : ($usedPercent >= $warn ? HealthStatus::Warning : HealthStatus::Healthy);

        return HealthCheckResult::make('storage', $status, 'disk_usage', "Uso de disco: {$usedPercent}%.", [
            'used_percent' => $usedPercent, 'free_bytes' => $free, 'total_bytes' => $total,
        ]);
    }

    private function checkFtp(): HealthCheckResult
    {
        return $this->ftpStats('ftp', 'backup');
    }

    private function checkFileServer(): HealthCheckResult
    {
        return $this->ftpStats('file_server', 'file_server');
    }

    private function ftpStats(string $checkName, string $purpose): HealthCheckResult
    {
        if (! Schema::hasTable('ftp_received_files')) {
            return HealthCheckResult::make($checkName, HealthStatus::Unknown, 'not_configured',
                'FTP ainda não está configurado.', []);
        }
        $windowHours = (int) config('health.recent_window_hours');
        $since = now()->subHours($windowHours);
        $counts = DB::table('ftp_received_files')
            ->join('ftp_accounts', 'ftp_accounts.id', '=', 'ftp_received_files.ftp_account_id')
            ->where('ftp_accounts.purpose', $purpose)
            ->where('ftp_received_files.received_at', '>=', $since)
            ->select('ftp_received_files.status', DB::raw('count(*) as total'))
            ->groupBy('ftp_received_files.status')->pluck('total', 'status');
        $staleMinutes = (int) config('health.ftp_processing_stale_minutes');
        $staleProcessing = DB::table('ftp_received_files')
            ->join('ftp_accounts', 'ftp_accounts.id', '=', 'ftp_received_files.ftp_account_id')
            ->where('ftp_accounts.purpose', $purpose)
            ->where('ftp_received_files.status', 'processing')
            ->where('ftp_received_files.updated_at', '<', now()->subMinutes($staleMinutes))
            ->count();
        $status = match (true) {
            $staleProcessing >= 5 => HealthStatus::Critical,
            $staleProcessing > 0 => HealthStatus::Warning,
            default => HealthStatus::Healthy,
        };

        return HealthCheckResult::make($checkName, $status, 'receipts',
            $staleProcessing > 0 ? "{$staleProcessing} recebimento(s) presos em processamento." : 'Recebimentos fluindo normalmente.',
            [
                'stored' => (int) ($counts['stored'] ?? 0), 'quarantined' => (int) ($counts['quarantined'] ?? 0),
                'processing_stale' => $staleProcessing, 'window_hours' => $windowHours,
            ]);
    }

    private function checkRetention(): HealthCheckResult
    {
        if (! Schema::hasTable('audit_events')) {
            return HealthCheckResult::make('retention', HealthStatus::Unknown, 'no_audit_table',
                'Auditoria indisponível para avaliar a retenção.', []);
        }
        $event = DB::table('audit_events')->where('action', 'backup_retention.completed')->orderByDesc('id')->first();
        if (! $event) {
            return HealthCheckResult::make('retention', HealthStatus::Unknown, 'never_ran',
                'A retenção ainda não foi executada.', []);
        }
        $metadata = json_decode($event->metadata, true) ?: [];
        $ranAt = CarbonImmutable::parse($event->created_at);
        $status = ($metadata['errors'] ?? 0) > 0 ? HealthStatus::Warning : HealthStatus::Healthy;

        return HealthCheckResult::make('retention', $status, 'last_run', 'Última execução da retenção registrada.',
            array_merge($metadata, ['ran_at' => $ranAt->toIso8601String(), 'age_hours' => abs(now()->diffInHours($ranAt))]));
    }

    /** @param HealthCheckResult[] $checks @return list<string> */
    private function deriveAlerts(array $checks): array
    {
        $byName = collect($checks)->keyBy(fn ($c) => $c->check);
        $status = fn (string $name) => $byName[$name]->status ?? HealthStatus::Unknown;
        $alerts = [];

        if ($status('engine') === HealthStatus::Critical) {
            $alerts[] = AlertCondition::EngineDown->value;
        }
        if ($status('worker') === HealthStatus::Critical) {
            $alerts[] = AlertCondition::WorkerStale->value;
        }
        if (in_array($status('scheduler'), [HealthStatus::Warning, HealthStatus::Critical], true)) {
            $alerts[] = AlertCondition::SchedulerStale->value;
        }
        if (in_array($status('queue'), [HealthStatus::Warning, HealthStatus::Critical], true)) {
            $alerts[] = AlertCondition::QueueBacklog->value;
        }
        if ($status('stale_jobs') !== HealthStatus::Healthy) {
            $alerts[] = AlertCondition::StaleJobs->value;
        }
        if ($status('storage') === HealthStatus::Warning) {
            $alerts[] = AlertCondition::StorageWarning->value;
        }
        if ($status('storage') === HealthStatus::Critical) {
            $alerts[] = AlertCondition::StorageCritical->value;
        }
        if ($status('ftp') !== HealthStatus::Healthy || $status('file_server') !== HealthStatus::Healthy) {
            $alerts[] = AlertCondition::FtpProcessingStale->value;
        }
        if ($status('retention') === HealthStatus::Warning) {
            $alerts[] = AlertCondition::RetentionFailed->value;
        }
        $repeatedFailures = collect($byName['devices']->metadata['problem_devices'] ?? [])
            ->contains(fn ($d) => $d['reason'] === 'consecutive_failures');
        if ($repeatedFailures) {
            $alerts[] = AlertCondition::RepeatedDeviceFailures->value;
        }

        return $alerts;
    }

    private function oldestAgeSeconds(array $statuses): ?int
    {
        $oldest = BackupExecution::query()->whereIn('status', $statuses)->min('created_at');

        return $oldest ? abs(now()->diffInSeconds($oldest)) : null;
    }
}
