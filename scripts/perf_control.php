<?php

/** Synthetic PERF-1 control plane. Refuses application and non-temporary databases. */
use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Services\BackupRetention;
use App\Services\BackupScheduler;
use App\Services\EngineJobService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = getenv('PERF_WORKSPACE');
if (! is_string($root) || ! preg_match('~\A/tmp/bm-perf-1-[a-zA-Z0-9_-]+\z~D', $root) || ! is_dir($root)
    || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== $root.'/database.sqlite' || getenv('BACKUP_STORAGE_ROOT') !== $root.'/backups') {
    throw new RuntimeException('PERF requires an isolated temporary SQLite workspace.');
}
require __DIR__.'/../app/vendor/autoload.php';
$app = require __DIR__.'/../app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $root.'/database.sqlite'
    || config('backup.storage_root') !== $root.'/backups') {
    throw new RuntimeException('Cached configuration is not isolated.');
}
$mode = $argv[1] ?? 'jobs';
if (! in_array($mode, ['jobs', 'same_device', 'status', 'scheduler', 'retention', 'stale', 'retry', 'cancel'], true)) {
    throw new InvalidArgumentException('Unknown PERF scenario.');
}
$count = (int) ($argv[2] ?? 20);
if ($count < 1 || $count > 10000) {
    throw new InvalidArgumentException('PERF count outside 1..10000.');
}
if ($mode === 'status') {
    echo json_encode(['statuses' => BackupExecution::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        'artifacts' => BackupArtifact::count(), 'retries' => BackupExecution::where('attempt', '>', 1)->count()]).PHP_EOL;
    exit;
}
if (file_exists($root.'/seeded')) {
    throw new RuntimeException('Use a fresh PERF workspace for each scenario.');
}
Artisan::call('migrate', ['--force' => true]);
$clock = CarbonImmutable::parse('2026-09-28 12:00:00', 'UTC');
$site = Site::create(['name' => 'PERF LAB', 'is_active' => true]);
$policy = BackupPolicy::create(['name' => 'PERF', 'method' => 'ssh_pull', 'artifact_mode' => 'config',
    'schedule_type' => $mode === 'scheduler' ? 'daily' : 'manual', 'schedule_time' => '09:00',
    'retention_count' => $mode === 'retention' ? 10 : null, 'is_active' => true]);
$associations = [];
$devices = in_array($mode, ['retention', 'same_device'], true) ? 1 : $count;
for ($i = 0; $i < $devices; $i++) {
    $device = Device::create(['site_id' => $site->id, 'name' => 'PERF-'.$i, 'management_ip' => '198.18.'.intdiv($i, 250).'.'.($i % 250 + 1),
        'vendor' => 'MikroTik', 'platform' => 'network', 'is_active' => true]);
    $credential = new Credential(['device_id' => $device->id, 'name' => 'PERF', 'type' => 'ssh',
        'username' => 'synthetic', 'is_active' => true]);
    $credential->secret = 'perf-synthetic-only';
    $credential->save();
    $associations[] = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
        'credential_id' => $credential->id, 'is_active' => true]);
}
$engine = app(EngineJobService::class);
$jobs = [];
if ($mode !== 'scheduler') {
    for ($i = 0; $i < $count; $i++) {
        $association = $associations[$i % count($associations)];
        $job = BackupExecution::create(['device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id,
            'device_id' => $association->device_id, 'credential_id' => $association->credential_id,
            'origin' => 'manual', 'status' => $mode === 'retention' ? 'succeeded' : 'queued', 'attempt' => 1,
            'max_attempts' => 3]);
        if ($mode === 'retention') {
            DB::table('backup_executions')->where('id', $job->id)->update(['created_at' => $clock->subSeconds($count - $i)]);
            $job = $job->fresh();
        }
        $jobs[] = $job->id;
        if ($mode === 'retention') {
            $relative = $engine->relativePath($job);
            $path = $root.'/backups/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            $content = str_repeat('# synthetic configuration\n', 160);
            file_put_contents($path, $content);
            BackupArtifact::create(['backup_execution_id' => $job->id, 'device_id' => $job->device_id,
                'backup_policy_id' => $policy->id, 'type' => 'config', 'storage' => 'local',
                'relative_path' => $relative, 'original_filename' => basename($path), 'size_bytes' => strlen($content),
                'sha256' => hash('sha256', $content), 'validated_at' => $clock]);
        }
    }
}
touch($root.'/seeded');
$queries = [];
DB::listen(function ($event) use (&$queries): void {
    $queries[] = ['ms' => $event->time, 'sql' => $event->sql];
});
$start = hrtime(true);
$result = null;
if ($mode === 'scheduler') {
    $created = app(BackupScheduler::class)->run($clock);
    $duplicate = app(BackupScheduler::class)->run($clock);
    if ($created !== $count || $duplicate !== 0) {
        throw new RuntimeException('Scheduler lost or duplicated occurrences.');
    }
    $result = compact('created', 'duplicate');
} elseif ($mode === 'retention') {
    $result = app(BackupRetention::class)->run(false, $clock);
    if ($result['scanned'] !== $count || $result['candidates'] !== max(0, $count - 10) || $result['deleted'] !== 0) {
        throw new RuntimeException('Retention baseline mismatch.');
    }
} elseif ($mode === 'stale') {
    DB::table('backup_executions')->update(['status' => 'running', 'worker_id' => str_repeat('a', 32),
        'started_at' => now()->subSeconds(400), 'heartbeat_at' => now()->subSeconds(400)]);
    $batches = [];
    do {
        $batches[] = $engine->recoverStale();
    } while (end($batches) !== 0);
    $result = ['stale_count' => array_sum($batches), 'batches' => $batches,
        'retry_count' => BackupExecution::where('status', 'retry_wait')->count()];
    if ($result['stale_count'] !== $count || $result['retry_count'] !== $count) {
        throw new RuntimeException('Stale recovery lost jobs.');
    }
} elseif ($mode === 'retry') {
    $worker = str_repeat('b', 32);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            DB::table('backup_executions')->where('status', 'retry_wait')->update(['next_attempt_at' => now()->subSecond()]);
        }
        for ($i = 0; $i < $count; $i++) {
            $job = $engine->claim($worker);
            if (! $job) {
                throw new RuntimeException('Due retry not claimable.');
            }
            $engine->fail($job->id, 'SSH_TIMEOUT', $worker);
        }
        if ($attempt < 3 && $engine->claim($worker) !== null) {
            throw new RuntimeException('Backoff was bypassed.');
        }
    }
    $result = ['attempts' => BackupExecution::sum('attempt'), 'failures' => BackupExecution::where('status', 'failed')->count()];
} elseif ($mode === 'cancel') {
    foreach ($jobs as $id) {
        $engine->requestCancel($id);
    }
    $result = ['cancelled' => BackupExecution::where('status', 'cancelled')->count()];
}
$seconds = (hrtime(true) - $start) / 1e9;
usort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);
echo json_encode(['mode' => $mode, 'count' => $count, 'seconds' => $seconds, 'query_count' => count($queries),
    'query_ms' => array_sum(array_column($queries, 'ms')), 'slowest' => array_slice($queries, 0, 3),
    'peak_php_bytes' => memory_get_peak_usage(true), 'result' => $result, 'job_ids' => $jobs]).PHP_EOL;
