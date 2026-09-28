<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Services\AuditEvents;
use App\Services\EngineHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngineHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function association(string $suffix = '1'): DeviceBackupPolicy
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK '.$suffix,
            'management_ip' => '192.0.2.'.$suffix, 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Config '.$suffix, 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'segredo-nunca-exposto';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
    }

    public function test_report_shape_is_stable_and_well_formed_when_everything_is_quiet(): void
    {
        $report = app(EngineHealth::class)->report();
        $this->assertArrayHasKey('overall_status', $report);
        $this->assertArrayHasKey('checked_at', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('alerts', $report);
        $this->assertContains($report['overall_status'], ['healthy', 'warning', 'critical', 'unknown']);
        $names = collect($report['checks'])->pluck('check')->all();
        foreach (['database', 'redis', 'engine', 'driver_registry', 'worker', 'scheduler', 'queue',
            'stale_jobs', 'retry', 'failure', 'devices', 'storage', 'ftp', 'file_server', 'retention'] as $expected) {
            $this->assertContains($expected, $names, "missing check: {$expected}");
        }
    }

    public function test_database_check_is_healthy_against_the_real_test_connection(): void
    {
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('healthy', $checks['database']['status']);
    }

    public function test_scheduler_accepts_integer_timestamps_returned_as_strings_by_redis(): void
    {
        $this->freezeTime();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::forgetDriver('redis');

        foreach ([0 => 'healthy', 4 => 'warning', 11 => 'critical'] as $minutes => $expected) {
            foreach ([now()->subMinutes($minutes)->timestamp, (string) now()->subMinutes($minutes)->timestamp] as $tick) {
                Cache::store('redis')->put('health:scheduler:last_tick', $tick);
                $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
                $this->assertSame($expected, $checks['scheduler']['status']);
            }
        }
    }

    public function test_invalid_or_absent_scheduler_timestamps_remain_unknown(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::forgetDriver('redis');

        foreach ([null, '', 'invalid', '123abc', '999999999999999999999999', '0', -1, true, 1.5] as $tick) {
            Cache::store('redis')->put('health:scheduler:last_tick', $tick);
            $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
            $this->assertSame('unknown', $checks['scheduler']['status']);
        }
    }

    public function test_backlog_thresholds_drive_the_queue_check(): void
    {
        config()->set('health.backlog_warning', 2);
        config()->set('health.backlog_critical', 4);
        for ($i = 1; $i <= 3; $i++) {
            $association = $this->association((string) $i);
            BackupExecution::createManual($association)->transitionTo('queued');
        }
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('warning', $checks['queue']['status']);
        $this->assertSame(3, $checks['queue']['metadata']['backlog']);
    }

    public function test_stale_running_job_is_detected(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        $association = $this->association();
        $job = BackupExecution::createManual($association);
        $job->transitionTo('queued');
        DB::table('backup_executions')->where('id', $job->id)->update([
            'status' => 'running', 'started_at' => now()->subSeconds(600),
            'heartbeat_at' => now()->subSeconds(600), 'worker_id' => str_repeat('a', 32),
        ]);
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('critical', $checks['worker']['status']);
        $this->assertNotSame('healthy', $checks['stale_jobs']['status']);
    }

    public function test_success_rate_reflects_recent_terminal_executions(): void
    {
        $association = $this->association();
        for ($i = 0; $i < 3; $i++) {
            $job = BackupExecution::createManual($this->association((string) ($i + 10)));
            $job->update(['status' => 'succeeded', 'finished_at' => now()]);
        }
        $failing = BackupExecution::createManual($this->association('99'));
        // error_code/error_message are intentionally not mass-assignable (only
        // EngineJobService writes them, via direct property assignment).
        $failing->status = 'failed';
        $failing->finished_at = now();
        $failing->error_code = 'SSH_AUTH_FAILED';
        $failing->save();
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame(75.0, $checks['failure']['metadata']['success_rate_percent']);
        $this->assertArrayHasKey('SSH_AUTH_FAILED', $checks['failure']['metadata']['top_error_codes']);
    }

    public function test_no_recent_executions_is_unknown_not_healthy(): void
    {
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('unknown', $checks['failure']['status']);
    }

    public function test_storage_missing_root_is_critical(): void
    {
        config()->set('backup.storage_root', '/definitely/not/a/real/path');
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('critical', $checks['storage']['status']);
    }

    public function test_storage_thresholds_classify_disk_usage(): void
    {
        config()->set('backup.storage_root', sys_get_temp_dir());
        config()->set('health.storage_warning_percent', 0);
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertContains($checks['storage']['status'], ['warning', 'critical']);
    }

    public function test_retention_never_ran_is_unknown(): void
    {
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('unknown', $checks['retention']['status']);
    }

    public function test_retention_summary_event_feeds_the_check(): void
    {
        app(AuditEvents::class)->record('backup_retention.completed', 'system', null, null, 'success',
            ['scanned' => 5, 'deleted' => 1, 'errors' => 0, 'mode' => 'apply']);
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('healthy', $checks['retention']['status']);
        $this->assertSame(1, $checks['retention']['metadata']['deleted']);
    }

    public function test_retention_failures_are_surfaced_as_warning(): void
    {
        app(AuditEvents::class)->record('backup_retention.completed', 'system', null, null, 'warning',
            ['scanned' => 5, 'deleted' => 0, 'errors' => 2, 'mode' => 'apply']);
        $checks = collect(app(EngineHealth::class)->report()['checks'])->keyBy('check');
        $this->assertSame('warning', $checks['retention']['status']);
    }

    public function test_overall_status_is_the_worst_of_all_checks(): void
    {
        config()->set('backup.storage_root', '/definitely/not/a/real/path');
        $report = app(EngineHealth::class)->report();
        $this->assertSame('critical', $report['overall_status']);
        $this->assertContains('storage_critical', $report['alerts']);
    }

    public function test_a_broken_check_becomes_unknown_and_does_not_break_the_report(): void
    {
        config()->set('health.device_recent_executions_sample', 'not-a-number-but-still-castable');
        // Even with an odd config value the report must still return a full,
        // well-formed structure rather than throwing.
        $report = app(EngineHealth::class)->report();
        $this->assertArrayHasKey('overall_status', $report);
        $this->assertCount(15, $report['checks']);
    }

    public function test_snapshot_method_from_engine_2_is_unchanged(): void
    {
        $snapshot = app(EngineHealth::class)->snapshot();
        $this->assertArrayHasKey('pending', $snapshot);
        $this->assertArrayHasKey('stale_running_count', $snapshot);
    }

    /**
     * Regression test for a bug found while building ENGINE-3: Carbon's
     * diffInX() returns a *signed* value (other - this) when not told
     * otherwise, so a past timestamp produced a negative "age" here — this
     * had shipped in ENGINE-2's snapshot() completely untested (no prior
     * test asserted a value for oldest_pending_seconds). Fixed with abs().
     */
    public function test_oldest_pending_seconds_is_a_positive_age_not_a_signed_diff(): void
    {
        $job = BackupExecution::createManual($this->association());
        DB::table('backup_executions')->where('id', $job->id)->update(['created_at' => now()->subMinutes(10)]);
        $snapshot = app(EngineHealth::class)->snapshot();
        $this->assertGreaterThanOrEqual(590, $snapshot['oldest_pending_seconds']);
    }
}
