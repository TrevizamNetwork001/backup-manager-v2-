<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Services\EngineJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngineRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function running(?string $worker = null): BackupExecution
    {
        $site = Site::create(['name' => 'Laboratório', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.1', 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Config', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'senha-super-secreta';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
        $job = BackupExecution::createManual($association);
        $job->transitionTo('queued');
        app(EngineJobService::class)->claim($worker ?? str_repeat('a', 32));
        return $job->fresh();
    }

    public function test_stale_heartbeat_is_requeued_as_retry_when_attempts_remain(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        config()->set('backup.engine_execution_timeout_seconds', 1800);
        $job = $this->running();
        DB::table('backup_executions')->where('id', $job->id)->update(['heartbeat_at' => now()->subSeconds(301)]);
        $this->assertSame(1, app(EngineJobService::class)->recoverStale());
        $job->refresh();
        $this->assertSame('retry_wait', $job->status);
        $this->assertSame(2, $job->attempt);
        $this->assertSame('ENGINE_STALE', $job->error_code);
    }

    public function test_fresh_heartbeat_but_execution_over_overall_timeout_becomes_timed_out(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        config()->set('backup.engine_execution_timeout_seconds', 60);
        $job = $this->running();
        // Heartbeat is fresh (the worker is alive and heartbeating) but the
        // execution has been running far longer than its overall budget.
        DB::table('backup_executions')->where('id', $job->id)
            ->update(['heartbeat_at' => now(), 'started_at' => now()->subSeconds(61), 'max_attempts' => 1]);
        $this->assertSame(1, app(EngineJobService::class)->recoverStale());
        $job->refresh();
        $this->assertSame('timed_out', $job->status);
        $this->assertSame('ENGINE_TIMEOUT', $job->error_code);
        $this->assertNotNull($job->finished_at);
    }

    public function test_execution_timeout_retries_before_terminalizing_as_timed_out(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        config()->set('backup.engine_execution_timeout_seconds', 60);
        $job = $this->running();
        DB::table('backup_executions')->where('id', $job->id)
            ->update(['heartbeat_at' => now(), 'started_at' => now()->subSeconds(61)]);
        app(EngineJobService::class)->recoverStale();
        $job->refresh();
        $this->assertSame('retry_wait', $job->status);
        $this->assertSame(2, $job->attempt);
        $this->assertSame('ENGINE_TIMEOUT', $job->error_code);
    }

    public function test_a_job_stale_while_cancellation_was_requested_ends_as_cancelled_not_retried(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        $job = $this->running();
        app(EngineJobService::class)->requestCancel($job->id);
        DB::table('backup_executions')->where('id', $job->id)->update(['heartbeat_at' => now()->subSeconds(301)]);
        $this->assertSame(1, app(EngineJobService::class)->recoverStale());
        $job->refresh();
        $this->assertSame('cancelled', $job->status);
        $this->assertSame(1, $job->attempt);
    }

    public function test_recovery_is_audited_as_recovered_when_retried_and_as_terminal_event_when_exhausted(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        $job = $this->running();
        DB::table('backup_executions')->where('id', $job->id)->update(['heartbeat_at' => now()->subSeconds(301)]);
        app(EngineJobService::class)->recoverStale();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.recovered', 'resource_id' => (string) $job->id, 'result' => 'success',
        ]);
        DB::table('backup_executions')->where('id', $job->id)
            ->update(['status' => 'running', 'heartbeat_at' => now()->subSeconds(301), 'max_attempts' => 2]);
        app(EngineJobService::class)->recoverStale();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.failed', 'resource_id' => (string) $job->id, 'result' => 'failure',
        ]);
    }

    public function test_engine_health_snapshot_counts_reflect_lifecycle(): void
    {
        $job = $this->running();
        $health = app(\App\Services\EngineHealth::class)->snapshot();
        $this->assertSame(1, $health['running']);
        $this->assertSame(0, $health['retry_wait']);
        DB::table('backup_executions')->where('id', $job->id)->update(['heartbeat_at' => now()->subSeconds(301)]);
        config()->set('backup.engine_stale_seconds', 300);
        app(EngineJobService::class)->recoverStale();
        $health = app(\App\Services\EngineHealth::class)->snapshot();
        $this->assertSame(1, $health['retry_wait']);
        $this->assertSame(0, $health['running']);
    }
}
