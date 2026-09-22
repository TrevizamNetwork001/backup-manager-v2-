<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\EngineJobService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('engine:claim', function (EngineJobService $engine) {
    $job = $engine->claim();
    $this->line(json_encode($job ? $engine->job($job->id) : null));
});

Artisan::command('engine:secret {id}', function (EngineJobService $engine) {
    $fd = getenv('ENGINE_SECRET_FD');
    if (! ctype_digit((string) $fd) || (int) $fd < 3) throw new RuntimeException('Pipe de segredo ausente.');
    $secret = $engine->secret((int) $this->argument('id'));
    $pipe = fopen('php://fd/'.$fd, 'wb');
    if (! $pipe) throw new RuntimeException('Pipe de segredo indisponível.');
    fwrite($pipe, $secret);
    fclose($pipe);
});

Artisan::command('engine:complete {id} {relative}', function (EngineJobService $engine) {
    $engine->complete((int) $this->argument('id'), $this->argument('relative'));
});

Artisan::command('engine:fail {id} {code}', function (EngineJobService $engine) {
    $engine->fail((int) $this->argument('id'), $this->argument('code'));
});
