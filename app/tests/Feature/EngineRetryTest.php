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

class EngineRetryTest extends TestCase
{
    use RefreshDatabase;

    private function queued(): BackupExecution
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
        return $job;
    }

    public function test_retryable_code_schedules_retry_with_backoff_and_is_not_claimable_early(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $engine->fail($job->id, 'SSH_TIMEOUT');
        $job->refresh();
        $this->assertSame('retry_wait', $job->status);
        $this->assertSame(2, $job->attempt);
        $this->assertSame('SSH_TIMEOUT', $job->error_code);
        $this->assertNull($job->finished_at);
        $this->assertNotNull($job->next_attempt_at);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($job->next_attempt_at), 2);
        // Backoff window has not elapsed yet — not claimable.
        $this->assertNull($engine->claim());
        DB::table('backup_executions')->where('id', $job->id)->update(['next_attempt_at' => now()->subSecond()]);
        $claimed = $engine->claim();
        $this->assertSame($job->id, $claimed->id);
        $this->assertSame('running', $claimed->status);
    }

    public function test_second_attempt_uses_longer_backoff_and_third_failure_exhausts_attempts(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $engine->fail($job->id, 'SSH_TIMEOUT'); // attempt 1 -> 2, +60s
        DB::table('backup_executions')->where('id', $job->id)->update(['next_attempt_at' => now()->subSecond()]);
        $engine->claim();
        $engine->fail($job->id, 'SSH_TIMEOUT'); // attempt 2 -> 3, +300s
        $job->refresh();
        $this->assertSame('retry_wait', $job->status);
        $this->assertSame(3, $job->attempt);
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($job->next_attempt_at), 2);
        DB::table('backup_executions')->where('id', $job->id)->update(['next_attempt_at' => now()->subSecond()]);
        $engine->claim();
        $engine->fail($job->id, 'SSH_TIMEOUT'); // attempt 3 == max_attempts -> terminal
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(3, $job->attempt);
        $this->assertNotNull($job->finished_at);
    }

    public function test_non_retryable_code_fails_immediately_regardless_of_attempts_remaining(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $engine->fail($job->id, 'SSH_AUTH_FAILED');
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(1, $job->attempt);
        $this->assertNotNull($job->finished_at);
    }

    public function test_retry_scheduled_and_terminal_failure_are_audited(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $engine->fail($job->id, 'SSH_TIMEOUT');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.retry_scheduled', 'resource_type' => 'backup_execution',
            'resource_id' => (string) $job->id, 'result' => 'success',
        ]);
        DB::table('backup_executions')->where('id', $job->id)->update(['next_attempt_at' => now()->subSecond(), 'max_attempts' => 2]);
        $engine->claim();
        $engine->fail($job->id, 'SSH_TIMEOUT');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.failed', 'resource_type' => 'backup_execution',
            'resource_id' => (string) $job->id, 'result' => 'failure',
        ]);
    }

    public function test_claim_is_audited(): void
    {
        $job = $this->queued();
        app(EngineJobService::class)->claim();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.claimed', 'resource_type' => 'backup_execution',
            'resource_id' => (string) $job->id, 'result' => 'success',
        ]);
    }
}
