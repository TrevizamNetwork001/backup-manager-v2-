<?php

namespace Tests\Feature;

use App\Console\Commands\EngineSession;
use App\Services\InstanceTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngineSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_uses_existing_commands(): void
    {
        $session = new EngineSession;
        foreach (['ftp:accounts', 'ftp:expected'] as $command) {
            Artisan::call($command);
            $this->assertSame(['ok' => true, 'output' => Artisan::output()],
                $session->dispatch(['command' => $command, 'args' => []]));
        }
    }

    public function test_session_cannot_execute_arbitrary_commands_or_return_secrets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EngineSession)->dispatch(['command' => 'engine:secret', 'args' => ['1', str_repeat('a', 32)]]);
    }

    public function test_session_refreshes_settings_that_were_cached_by_a_previous_command(): void
    {
        $session = new EngineSession;
        app(InstanceTimezone::class)->get();
        DB::table('application_settings')->where('id', 1)->update(['timezone' => 'UTC']);
        $session->dispatch(['command' => 'ftp:accounts', 'args' => []]);
        $this->assertSame('UTC', app(InstanceTimezone::class)->get());
    }

    public function test_session_rejects_wrong_arguments_before_invoking_a_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EngineSession)->dispatch(['command' => 'ftp:receipt', 'args' => []]);
    }
}
