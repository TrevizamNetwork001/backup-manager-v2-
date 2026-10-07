<?php

use App\Models\BackupExecution;
use App\Models\FtpAccount;
use App\Services\BackupRetention;
use App\Services\BackupScheduler;
use App\Services\EngineDiagnosticSnapshot;
use App\Services\EngineHealth;
use App\Services\EngineJobService;
use App\Services\FtpAccessSettings;
use App\Services\FtpAccountDeletionService;
use App\Services\InstanceTimezone;
use App\Services\RecoveryCheck;
use App\Services\RetentionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('engine:claim {worker}', function (EngineJobService $engine) {
    $job = $engine->claim($this->argument('worker'));
    $this->line(json_encode($job ? $engine->job($job->id) : null));
});

Artisan::command('a10:expected {id}', function (EngineJobService $engine) {
    $id = (int) $this->argument('id');
    $job = BackupExecution::query()->with(['device', 'backupPolicy', 'artifact'])->find($id);
    if (! config('backup.a10_enabled') || ! $job || $job->status !== 'running' || $job->cancellation_requested_at || $job->artifact ||
        $job->backupPolicy?->method !== 'a10_system' || $job->backupPolicy->artifact_mode !== 'binary' ||
        $job->device?->platform !== 'network' || mb_strtolower(trim($job->device->vendor)) !== 'a10 networks') {
        $this->line('{}');

        return;
    }
    $payload = $engine->job($id);
    if (! $payload['eligible']) {
        $this->line('{}');

        return;
    }
    $slug = rtrim(substr(strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($job->device->name)), '-')), 0, 40), '-');
    if ($slug === '') {
        $this->line('{}');

        return;
    }
    $filename = $slug.'_'.$job->created_at->copy()->utc()->format('YmdHis').'-exec-'.$id.'.tar.gz';
    $this->line(json_encode(['filename' => $filename]));
});

Artisan::command('engine:secret {id} {worker}', function (EngineJobService $engine) {
    $fd = getenv('ENGINE_SECRET_FD');
    if (! ctype_digit((string) $fd) || (int) $fd < 3) {
        throw new RuntimeException('Pipe de segredo ausente.');
    }
    $secret = $engine->secret((int) $this->argument('id'), $this->argument('worker'));
    $pipe = fopen('php://fd/'.$fd, 'wb');
    if (! $pipe) {
        throw new RuntimeException('Pipe de segredo indisponível.');
    }
    fwrite($pipe, $secret);
    fclose($pipe);
});

Artisan::command('ftp:accounts', function () {
    $ready = Schema::hasColumn('ftp_accounts', 'account_uuid');
    $columns = $ready
        ? ['id', 'device_id', 'account_uuid', 'home_layout', 'purpose', 'username', 'is_active', 'provisioned_at', 'sync_error', 'updated_at']
        : ['id', 'device_id', 'username', 'is_active', 'provisioned_at', 'sync_error', 'updated_at'];
    if (Schema::hasColumn('ftp_accounts', 'deletion_mode')) {
        $columns[] = 'deletion_mode';
    }
    $accounts = FtpAccount::query()->orderBy('id')->get($columns);
    $this->line($accounts->map(fn ($account) => [
        'id' => $account->id,
        'device_id' => $account->device_id,
        'account_uuid' => $account->account_uuid,
        'home_layout' => $account->home_layout ?? 'legacy',
        'purpose' => $account->purpose ?? 'backup',
        'home' => $account->homePath(),
        'username' => $account->username,
        'is_active' => $account->is_active,
        'ready_for_receive' => (bool) ($account->is_active && $account->provisioned_at && $account->sync_error === null),
        'deletion_mode' => $account->deletion_mode ?? null,
        'updated_at' => $account->getRawOriginal('updated_at'),
    ])->toJson());
});

Artisan::command('ftp:expected', function () {
    $jobs = BackupExecution::query()->with('backupPolicy:id,method')
        ->whereIn('status', ['queued', 'running'])->get(['id', 'device_id', 'backup_policy_id']);
    $this->line($jobs->filter(fn ($job) => $job->backupPolicy?->method === 'ftp_push')
        ->map(fn ($job) => ['device_id' => $job->device_id, 'filename' => 'bm-exec-'.$job->id.'.cfg'])
        ->values()->toJson());
});

Artisan::command('ftp:receive {device} {token} {filename} {received} {worker}', function (EngineJobService $engine) {
    $encoded = $this->argument('filename');
    $filename = is_string($encoded) && str_starts_with($encoded, 'n')
        ? base64_decode(strtr(substr($encoded, 1), '-_', '+/'), true) : false;
    if ($filename === false) {
        throw new InvalidArgumentException('Nome FTP inválido.');
    }
    $this->line(json_encode($engine->receiveFtp((int) $this->argument('device'), $this->argument('token'),
        $filename, (int) $this->argument('received'), $this->argument('worker'))));
});

Artisan::command('ftp:receipt {account} {token} {filename} {received} {status} {size} {hash} {path} {error}', function () {
    if (! Schema::hasTable('ftp_received_files')) {
        return;
    }
    $account = FtpAccount::findOrFail((int) $this->argument('account'));
    $token = $this->argument('token');
    $encoded = $this->argument('filename');
    $filename = is_string($encoded) && str_starts_with($encoded, 'n')
        ? base64_decode(strtr(substr($encoded, 1), '-_', '+/'), true) : false;
    if (! preg_match('/\A[a-f0-9]{32}\z/D', $token) || $filename === false || strlen($filename) > 255 ||
        ! preg_match('/\A[^\/\\\\\x00-\x1f\x7f]+\z/D', $filename) || in_array($filename, ['.', '..'], true)) {
        throw new InvalidArgumentException('Recebimento FTP inválido.');
    }
    $status = $this->argument('status');
    if (! in_array($status, ['processing', 'stored', 'quarantined'], true)) {
        throw new InvalidArgumentException('Status inválido.');
    }
    $receivedAt = CarbonImmutable::createFromTimestamp((int) $this->argument('received'), 'UTC');
    $values = [
        'status' => $status, 'size_bytes' => (int) $this->argument('size'),
        'sha256' => $this->argument('hash') === '-' ? null : $this->argument('hash'),
        'relative_path' => $this->argument('path') === '-' ? null : $this->argument('path'),
        'error_code' => $this->argument('error') === '-' ? null : $this->argument('error'),
        'updated_at' => now(),
    ];
    DB::transaction(function () use ($account, $token, $filename, $status, $values, $receivedAt) {
        $table = DB::table('ftp_received_files');
        $existing = $table->where('claim_token', $token)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->ftp_account_id !== $account->id || $existing->original_filename !== $filename) {
                throw new RuntimeException('Claim FTP inconsistente.');
            }
            if ($status === 'processing' && in_array($existing->status, ['stored', 'quarantined'], true)) {
                return;
            }
            $table->where('claim_token', $token)->update($values);

            return;
        }
        $table->insert($values + [
            'ftp_account_id' => $account->id, 'claim_token' => $token, 'original_filename' => $filename,
            'received_at' => $receivedAt,
            'created_at' => now(),
        ]);
    });
});

Artisan::command('ftp:secret {id}', function () {
    $fd = getenv('ENGINE_SECRET_FD');
    if (! ctype_digit((string) $fd) || (int) $fd < 3) {
        throw new RuntimeException('Pipe de segredo ausente.');
    }
    $account = FtpAccount::query()->where('is_active', true)->findOrFail((int) $this->argument('id'));
    $pipe = fopen('php://fd/'.$fd, 'wb');
    if (! $pipe) {
        throw new RuntimeException('Pipe de segredo indisponível.');
    }
    fwrite($pipe, $account->secret);
    fclose($pipe);
});

Artisan::command('ftp:provisioned {id} {version}', function () {
    $id = (int) $this->argument('id');
    $version = $this->argument('version');
    $account = FtpAccount::findOrFail($id);
    if (! $account->is_active || $account->getRawOriginal('updated_at') !== $version) {
        throw new RuntimeException('Conta alterada durante provisionamento.');
    }
    $updated = DB::table('ftp_accounts')->where('id', $id)
        ->where('is_active', true)->where('updated_at', $version)
        ->update(['provisioned_at' => now(), 'sync_error' => null]);
    if ($updated !== 1) {
        throw new RuntimeException('Conta alterada durante provisionamento.');
    }
});

Artisan::command('ftp:sync-failed', function () {
    DB::table('ftp_accounts')->where('is_active', true)
        ->where(fn ($query) => $query->whereNull('provisioned_at')
            ->orWhereColumn('provisioned_at', '<', 'updated_at'))
        ->update(['sync_error' => 'Não foi possível sincronizar a conta no PureDB.']);
    if (Schema::hasColumn('ftp_accounts', 'deletion_mode')) {
        DB::table('ftp_accounts')->whereNotNull('deletion_mode')
            ->update(['deletion_error' => 'Falha ao sincronizar revogação no PureDB. A exclusão será tentada novamente.']);
    }
});

Artisan::command('ftp:inspection-requests', function () {
    if (! Schema::hasTable('audit_events')) {
        $this->line('[]');

        return;
    }
    $events = DB::table('audit_events')
        ->whereIn('action', ['ftp.physical.request', 'ftp.physical.inspect'])
        ->where('created_at', '>=', now()->subMinutes(2))->orderBy('id')->get();
    $latest = [];
    foreach ($events as $event) {
        $latest[$event->resource_id] = $event->action;
    }
    $this->line(json_encode(array_map('intval', array_keys(array_filter($latest,
        fn ($action) => $action === 'ftp.physical.request')))));
});

Artisan::command('ftp:physical-report {id} {version} {payload}', function (FtpAccountDeletionService $service) {
    $service->physicalReport((int) $this->argument('id'), $this->argument('version'), $this->argument('payload'));
});

Artisan::command('ftp:puredb-revoked {id}', function (FtpAccountDeletionService $service) {
    $service->puredbRevoked((int) $this->argument('id'));
});

Artisan::command('ftp:acl-export', function (FtpAccessSettings $settings) {
    $current = $settings->get();
    $this->line(json_encode(['cidrs' => $current['cidrs'], 'revision' => $current['revision']], JSON_THROW_ON_ERROR));
});

Artisan::command('ftp:acl-applied {revision}', function (FtpAccessSettings $settings) {
    $settings->markApplied((int) $this->argument('revision'));
});

Artisan::command('ftp:physical-failed {id} {code}', function (FtpAccountDeletionService $service) {
    $service->physicalFailed((int) $this->argument('id'), $this->argument('code'));
});

Artisan::command('ftp:finalize-deletions {id} {payload}', function (FtpAccountDeletionService $service) {
    $account = FtpAccount::findOrFail((int) $this->argument('id'));
    $service->finalize($account, $this->argument('payload'));
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
    $result = $engine->heartbeat((int) $this->argument('id'), $this->argument('worker'));
    $this->line(json_encode($result));
});

Artisan::command('engine:cancel-ack {id} {worker}', function (EngineJobService $engine) {
    $engine->cancelAck((int) $this->argument('id'), $this->argument('worker'));
});

Artisan::command('engine:recover-stale', function (EngineJobService $engine) {
    $this->line((string) $engine->recoverStale());
});

Artisan::command('engine:health {--json}', function (EngineHealth $health) {
    $report = $health->report();
    if ($this->option('json')) {
        $this->line(json_encode($report));
    } else {
        $this->line('Status geral: '.strtoupper($report['overall_status']));
        foreach ($report['checks'] as $check) {
            $this->line(sprintf('- %-16s %-10s %s', $check['check'], strtoupper($check['status']), $check['message']));
        }
        if ($report['alerts'] !== []) {
            $this->line('Alertas: '.implode(', ', $report['alerts']));
        }
    }

    return match ($report['overall_status']) {
        'critical' => 2,
        'warning' => 1,
        default => 0,
    };
});

Artisan::command('engine:diagnose {--json}', function (EngineDiagnosticSnapshot $diagnostics) {
    $snapshot = $diagnostics->build();
    if ($this->option('json')) {
        $this->line(json_encode($snapshot));

        return 0;
    }
    $this->line('Diagnóstico gerado em: '.$snapshot['timestamp']);
    $this->line('Commit: '.($snapshot['app_commit'] ?? 'desconhecido'));
    $this->line('PHP: '.$snapshot['php_version'].' | Laravel: '.$snapshot['laravel_version']);
    $this->line('Status geral: '.strtoupper($snapshot['health']['overall_status']));
    foreach ($snapshot['health']['checks'] as $check) {
        $this->line(sprintf('- %-16s %-10s %s', $check['check'], strtoupper($check['status']), $check['message']));
    }

    return 0;
});

Artisan::command('backups:schedule', function (BackupScheduler $scheduler) {
    $count = $scheduler->run();
    try {
        Cache::store('redis')->put('health:scheduler:last_tick', now()->timestamp, now()->addHours(1));
    } catch (Throwable $e) {
        // Scheduler health degrades to 'unknown' without this — never fails the actual scheduling run over it.
    }
    $this->line((string) $count);
});

Artisan::command('backups:retention {--dry-run} {--apply} {--scheduled}', function (BackupRetention $retention, RetentionSettings $settings) {
    if ($this->option('dry-run') && ($this->option('apply') || $this->option('scheduled'))) {
        $this->error('Use apenas --dry-run, --apply ou --scheduled.');

        return 1;
    }
    if ($this->option('scheduled') && ! $settings->enabled()) {
        $this->line('disabled');

        return 0;
    }
    $apply = (bool) ($this->option('apply') || $this->option('scheduled'));
    $summary = $retention->run($apply);
    $this->line(($apply ? 'apply' : 'dry-run').' '.collect($summary)
        ->map(fn ($value, $key) => $key.'='.$value)->implode(' '));

    return $summary['errors'] > 0 ? 1 : 0;
});

Artisan::command('system:recovery-check {--json}', function (RecoveryCheck $check) {
    $report = $check->run();
    if ($this->option('json')) {
        $this->line(json_encode($report));
    } else {
        $this->line('Status geral: '.strtoupper($report['overall_status']));
        foreach ($report['checks'] as $item) {
            $this->line(sprintf('- %-10s %-10s %s', $item['check'], strtoupper($item['status']), $item['message']));
        }
    }

    return match ($report['overall_status']) {
        'critical' => 2,
        'warning' => 1,
        default => 0,
    };
});

Schedule::command('backups:schedule')->everyMinute()->withoutOverlapping();
Schedule::command('engine:recover-stale')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:run')->everyMinute()->withoutOverlapping();
Schedule::command('telegram-backup:run')->everyMinute()->withoutOverlapping();
$time = config('backup.retention_time');
if (! is_string($time) || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)) {
    throw new InvalidArgumentException('Horário de retenção inválido.');
}
Schedule::command('backups:retention --scheduled')->dailyAt($time)
    ->timezone(Schema::hasTable('application_settings') ? app(InstanceTimezone::class)->get() : InstanceTimezone::DEFAULT)
    ->withoutOverlapping();
