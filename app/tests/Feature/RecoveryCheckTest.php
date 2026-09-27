<?php

namespace Tests\Feature;

use App\Services\RecoveryCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecoveryCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_environment_reports_healthy_and_never_prints_the_key(): void
    {
        config()->set('backup.storage_root', sys_get_temp_dir());
        $report = app(RecoveryCheck::class)->run();
        $this->assertSame('healthy', $report['overall_status']);
        $checks = collect($report['checks'])->keyBy('check');
        $this->assertSame('healthy', $checks['app_key']['status']);
        $this->assertArrayHasKey('fingerprint_sha256_16', $checks['app_key']['metadata']);
        $this->assertSame(16, strlen($checks['app_key']['metadata']['fingerprint_sha256_16']));
        $encoded = json_encode($report);
        $this->assertStringNotContainsString(config('app.key'), $encoded);
        $this->assertStringNotContainsString(substr(config('app.key'), 7), $encoded);
    }

    public function test_missing_app_key_is_critical(): void
    {
        config()->set('app.key', '');
        $checks = collect(app(RecoveryCheck::class)->run()['checks'])->keyBy('check');
        $this->assertSame('critical', $checks['app_key']['status']);
    }

    public function test_implausible_app_key_is_critical(): void
    {
        config()->set('app.key', 'base64:dG9vc2hvcnQ=');
        $checks = collect(app(RecoveryCheck::class)->run()['checks'])->keyBy('check');
        $this->assertSame('critical', $checks['app_key']['status']);
    }

    public function test_missing_storage_root_is_critical(): void
    {
        config()->set('backup.storage_root', '/definitely/not/a/real/path');
        $checks = collect(app(RecoveryCheck::class)->run()['checks'])->keyBy('check');
        $this->assertSame('critical', $checks['storage']['status']);
    }

    public function test_cli_json_output_never_contains_the_key(): void
    {
        Artisan::call('system:recovery-check', ['--json' => true]);
        $output = Artisan::output();
        json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(substr(config('app.key'), 7), $output);
    }

    public function test_cli_exit_code_reflects_overall_status(): void
    {
        config()->set('backup.storage_root', '/definitely/not/a/real/path');
        $exitCode = Artisan::call('system:recovery-check', ['--json' => true]);
        $this->assertSame(2, $exitCode);
    }
}
