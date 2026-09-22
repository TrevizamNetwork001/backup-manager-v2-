<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\EngineJobService;

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
