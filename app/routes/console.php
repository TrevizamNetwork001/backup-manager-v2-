<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\EngineJobService;
use App\Services\BackupScheduler;
use App\Services\BackupRetention;
use App\Services\InstanceTimezone;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('engine:claim {worker}', function (EngineJobService $engine) {
    $job = $engine->claim($this->argument('worker'));
    $this->line(json_encode($job ? $engine->job($job->id) : null));
});

Artisan::command('engine:secret {id} {worker}', function (EngineJobService $engine) {
    $fd = getenv('ENGINE_SECRET_FD');
    if (! ctype_digit((string) $fd) || (int) $fd < 3) throw new RuntimeException('Pipe de segredo ausente.');
    $secret = $engine->secret((int) $this->argument('id'), $this->argument('worker'));
    $pipe = fopen('php://fd/'.$fd, 'wb');
    if (! $pipe) throw new RuntimeException('Pipe de segredo indisponível.');
    fwrite($pipe, $secret);
    fclose($pipe);
});

Artisan::command('ftp:accounts', function () {
    $accounts = \App\Models\FtpAccount::query()->orderBy('id')->get(['id', 'device_id', 'username', 'is_active', 'updated_at']);
    $this->line($accounts->map(fn ($account) => [
        'id' => $account->id,
        'device_id' => $account->device_id,
        'username' => $account->username,
        'is_active' => $account->is_active,
        'updated_at' => $account->getRawOriginal('updated_at'),
    ])->toJson());
});

Artisan::command('ftp:expected', function () {
    $jobs = \App\Models\BackupExecution::query()->with('backupPolicy:id,method')
        ->whereIn('status', ['queued', 'running'])->get(['id', 'device_id', 'backup_policy_id']);
    $this->line($jobs->filter(fn ($job) => $job->backupPolicy?->method === 'ftp_push')
        ->map(fn ($job) => ['device_id' => $job->device_id, 'filename' => 'bm-exec-'.$job->id.'.cfg'])
        ->values()->toJson());
});

Artisan::command('ftp:secret {id}', function () {
    $fd = getenv('ENGINE_SECRET_FD');
    if (! ctype_digit((string) $fd) || (int) $fd < 3) throw new RuntimeException('Pipe de segredo ausente.');
    $account = \App\Models\FtpAccount::query()->where('is_active', true)->findOrFail((int) $this->argument('id'));
    $pipe = fopen('php://fd/'.$fd, 'wb');
    if (! $pipe) throw new RuntimeException('Pipe de segredo indisponível.');
    fwrite($pipe, $account->secret);
    fclose($pipe);
});

Artisan::command('ftp:provisioned {id} {version}', function () {
    $id = (int) $this->argument('id');
    $version = $this->argument('version');
    $account = \App\Models\FtpAccount::findOrFail($id);
    if (! $account->is_active || $account->getRawOriginal('updated_at') !== $version) {
        throw new RuntimeException('Conta alterada durante provisionamento.');
    }
    $updated = \Illuminate\Support\Facades\DB::table('ftp_accounts')->where('id', $id)
        ->where('is_active', true)->where('updated_at', $version)
        ->update(['provisioned_at' => now(), 'sync_error' => null]);
    if ($updated !== 1) {
        throw new RuntimeException('Conta alterada durante provisionamento.');
    }
});

Artisan::command('ftp:sync-failed', function () {
    \Illuminate\Support\Facades\DB::table('ftp_accounts')->where('is_active', true)
        ->where(fn ($query) => $query->whereNull('provisioned_at')
            ->orWhereColumn('provisioned_at', '<', 'updated_at'))
        ->update(['sync_error' => 'Não foi possível sincronizar a conta no PureDB.']);
});

Artisan::command('engine:complete {id} {relative} {worker}', function (EngineJobService $engine) {
    $engine->complete((int) $this->argument('id'), $this->argument('relative'), $this->argument('worker'));
});

Artisan::command('engine:fail {id} {code} {worker}', function (EngineJobService $engine) {
    $engine->fail((int) $this->argument('id'), $this->argument('code'), $this->argument('worker'));
});

Artisan::command('engine:observe-host-key {id} {worker} {host} {algorithm} {fingerprint}', function (EngineJobService $engine) {
    $engine->observeHostKey((int) $this->argument('id'), $this->argument('worker'),
        $this->argument('host'), $this->argument('algorithm'), $this->argument('fingerprint'));
});

Artisan::command('engine:heartbeat {id} {worker}', function (EngineJobService $engine) {
    if (! $engine->heartbeat((int) $this->argument('id'), $this->argument('worker'))) {
        throw new RuntimeException('Execução indisponível para heartbeat.');
    }
});

Artisan::command('engine:recover-stale', function (EngineJobService $engine) {
    $this->line((string) $engine->recoverStale());
});

Artisan::command('backups:schedule', function (BackupScheduler $scheduler) {
    $this->line((string) $scheduler->run());
});

Artisan::command('backups:retention {--dry-run} {--apply}', function (BackupRetention $retention) {
    if ($this->option('dry-run') && $this->option('apply')) {
        $this->error('Use apenas --dry-run ou --apply.');
        return 1;
    }
    $apply = (bool) $this->option('apply');
    $summary = $retention->run($apply);
    $this->line(($apply ? 'apply' : 'dry-run').' '.collect($summary)
        ->map(fn ($value, $key) => $key.'='.$value)->implode(' '));
    return $summary['errors'] > 0 ? 1 : 0;
});

Schedule::command('backups:schedule')->everyMinute()->withoutOverlapping();
Schedule::command('engine:recover-stale')->everyMinute()->withoutOverlapping();
if (config('backup.retention_enabled')) {
    $time = config('backup.retention_time');
    if (! is_string($time) || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)) {
        throw new InvalidArgumentException('Horário de retenção inválido.');
    }
    Schedule::command('backups:retention --apply')->dailyAt($time)
        ->timezone(app(InstanceTimezone::class)->get())->withoutOverlapping();
}
