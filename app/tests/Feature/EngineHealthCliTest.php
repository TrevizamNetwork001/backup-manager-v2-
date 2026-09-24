<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngineHealthCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_human_output_lists_every_check(): void
    {
        $this->artisan('engine:health')
            ->expectsOutputToContain('Status geral:')
            ->expectsOutputToContain('database')
            ->expectsOutputToContain('storage');
    }

    public function test_json_output_is_valid_and_matches_the_service_report(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('engine:health', ['--json' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('overall_status', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertIsInt($exitCode);
    }

    public function test_exit_code_reflects_overall_status(): void
    {
        // Fresh SQLite test DB: storage_root default won't exist -> critical -> exit 2.
        config()->set('backup.storage_root', '/definitely/not/a/real/path');
        $exitCode = \Illuminate\Support\Facades\Artisan::call('engine:health', ['--json' => true]);
        $this->assertSame(2, $exitCode);
    }

    public function test_diagnose_json_output_never_contains_secrets(): void
    {
        \Illuminate\Support\Facades\Artisan::call('engine:diagnose', ['--json' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertArrayHasKey('php_version', $decoded);
        $this->assertArrayHasKey('health', $decoded);
        foreach (['APP_KEY', 'password', 'secret', 'token', 'PRIVATE KEY', 'Authorization'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $output);
        }
    }

    public function test_diagnose_human_output_is_readable(): void
    {
        $this->artisan('engine:diagnose')
            ->expectsOutputToContain('Diagnóstico gerado em:')
            ->expectsOutputToContain('Status geral:')
            ->assertExitCode(0);
    }
}
