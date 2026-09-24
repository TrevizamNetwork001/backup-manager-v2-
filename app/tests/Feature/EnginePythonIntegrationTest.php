<?php

namespace Tests\Feature;

use App\Services\EngineHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ENGINE-1 left a documented debt: no real Laravel<->Python integration test
 * existed — everything was mocked at the module boundary. This closes it,
 * without touching real equipment/credentials/network: it runs the actual
 * `engine/health_snapshot.py` CLI (real Python interpreter, real driver
 * registry, real JSON serialization) and feeds its real output through
 * EngineHealth's real parsing path. If Python ever renames a field, breaks
 * serialization, or a driver fails to import, this test fails — exactly the
 * class of bug ENGINE-1's debt note called out.
 */
class EnginePythonIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function enginePath(): string
    {
        return base_path('../engine/health_snapshot.py');
    }

    private function pythonBinary(): string
    {
        return env('ENGINE_TEST_PYTHON_BIN', 'python3');
    }

    public function test_the_real_python_engine_produces_a_snapshot_laravel_can_parse(): void
    {
        if (! is_file($this->enginePath())) {
            $this->markTestSkipped('engine/ directory not available in this checkout.');
        }
        $result = Process::path(dirname($this->enginePath()))->timeout(15)
            ->run([$this->pythonBinary(), $this->enginePath()]);

        $this->assertTrue($result->successful(), 'health_snapshot.py exited non-zero: '.$result->errorOutput());

        $decoded = json_decode($result->output(), true);
        $this->assertIsArray($decoded, 'engine output was not valid JSON: '.$result->output());

        // The exact contract EngineHealth::checkEngine()/checkDriverRegistry() rely on.
        foreach (['engine_version', 'python_version', 'worker_id', 'pid', 'driver_count', 'drivers', 'generated_at'] as $field) {
            $this->assertArrayHasKey($field, $decoded, "engine dropped/renamed field: {$field}");
        }
        $this->assertIsInt($decoded['generated_at']);
        $this->assertGreaterThan(0, $decoded['driver_count']);
        foreach ($decoded['drivers'] as $driver) {
            $this->assertArrayHasKey('vendor', $driver);
            $this->assertArrayHasKey('capabilities', $driver);
        }
        $vendors = array_column($decoded['drivers'], 'vendor');
        $this->assertContains('mikrotik', $vendors);
        $this->assertContains('huawei', $vendors);

        // Now prove Laravel's own parser is happy with this exact real payload,
        // not a hand-written fixture that could drift from reality.
        $snapshotPath = storage_path('app/test-engine-health-snapshot.json');
        file_put_contents($snapshotPath, $result->output());
        config()->set('backup.engine_health_snapshot_path', $snapshotPath);
        try {
            $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
            $this->assertSame('healthy', $checks['engine']['status']);
            $this->assertSame('healthy', $checks['driver_registry']['status']);
            $this->assertSame($decoded['engine_version'], $checks['engine']['metadata']['engine_version']);
        } finally {
            @unlink($snapshotPath);
        }
    }

    public function test_a_stale_snapshot_is_reported_critical_not_silently_trusted(): void
    {
        $snapshotPath = storage_path('app/test-engine-health-snapshot-stale.json');
        file_put_contents($snapshotPath, json_encode([
            'engine_version' => '3.0.0', 'python_version' => '3.13.0', 'worker_id' => 'x',
            'pid' => 1, 'driver_count' => 0, 'drivers' => [], 'workspace_writable' => null,
            'generated_at' => now()->subMinutes(10)->timestamp,
        ]));
        config()->set('backup.engine_health_snapshot_path', $snapshotPath);
        config()->set('backup.engine_health_snapshot_stale_seconds', 90);
        try {
            $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
            $this->assertSame('critical', $checks['engine']['status']);
        } finally {
            @unlink($snapshotPath);
        }
    }

    public function test_a_malformed_snapshot_is_unknown_not_a_crash(): void
    {
        $snapshotPath = storage_path('app/test-engine-health-snapshot-broken.json');
        file_put_contents($snapshotPath, '{not valid json');
        config()->set('backup.engine_health_snapshot_path', $snapshotPath);
        try {
            $report = app(EngineHealth::class)->report();
            $checks = collect($report['checks'])->keyBy('check');
            $this->assertSame('unknown', $checks['engine']['status']);
            $this->assertArrayHasKey('overall_status', $report);
        } finally {
            @unlink($snapshotPath);
        }
    }

    public function test_a_missing_snapshot_is_unknown_not_a_crash(): void
    {
        config()->set('backup.engine_health_snapshot_path', storage_path('app/does-not-exist.json'));
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('unknown', $checks['engine']['status']);
        $this->assertSame('unknown', $checks['driver_registry']['status']);
    }
}
