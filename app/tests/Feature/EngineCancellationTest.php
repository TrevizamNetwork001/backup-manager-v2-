<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\EngineJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EngineCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): BackupExecution
    {
        static $suffix = 0;
        $suffix++;
        $site = Site::firstOrCreate(['name' => 'Laboratório'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK '.$suffix, 'management_ip' => '192.0.2.'.$suffix, 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Config', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'senha-super-secreta';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
        return BackupExecution::createManual($association);
    }

    public function test_pending_and_queued_are_cancelled_immediately(): void
    {
        $engine = app(EngineJobService::class);
        $pending = $this->pending();
        $engine->requestCancel($pending->id);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->finished_at);

        $queued = $this->pending();
        $queued->transitionTo('queued');
        $engine->requestCancel($queued->id);
        $this->assertSame('cancelled', $queued->fresh()->status);
    }

    public function test_running_only_flags_the_request_until_worker_acknowledges(): void
    {
        $job = $this->pending();
        $job->transitionTo('queued');
        $engine = app(EngineJobService::class);
        $claimed = $engine->claim(str_repeat('a', 32));
        $engine->requestCancel($claimed->id);
        $this->assertSame('running', $claimed->fresh()->status);
        $this->assertNotNull($claimed->fresh()->cancellation_requested_at);
        // Heartbeat now reports the pending cancellation to the worker.
        $result = $engine->heartbeat($claimed->id, str_repeat('a', 32));
        $this->assertTrue($result['updated']);
        $this->assertTrue($result['cancel_requested']);
        // The worker acknowledges and stops.
        $engine->cancelAck($claimed->id, str_repeat('a', 32));
        $this->assertSame('cancelled', $claimed->fresh()->status);
        $this->assertNull($claimed->fresh()->worker_id);
    }

    public function test_cancel_ack_requires_matching_worker_and_a_pending_request(): void
    {
        $job = $this->pending();
        $job->transitionTo('queued');
        $engine = app(EngineJobService::class);
        $claimed = $engine->claim(str_repeat('a', 32));
        $this->expectException(\RuntimeException::class);
        $engine->cancelAck($claimed->id, str_repeat('a', 32)); // no cancellation was requested
    }

    public function test_a_terminal_execution_cannot_be_cancelled(): void
    {
        $job = $this->pending();
        $job->transitionTo('queued');
        $engine = app(EngineJobService::class);
        $engine->claim(str_repeat('a', 32));
        $engine->fail($job->id, 'SSH_AUTH_FAILED');
        $this->expectException(ValidationException::class);
        $engine->requestCancel($job->id);
    }

    public function test_fail_reported_after_cancellation_was_requested_still_ends_as_cancelled(): void
    {
        $job = $this->pending();
        $job->transitionTo('queued');
        $engine = app(EngineJobService::class);
        $claimed = $engine->claim(str_repeat('a', 32));
        $engine->requestCancel($claimed->id);
        // The worker raced and reported a driver failure instead of cancel-ack —
        // cancellation intent still wins over turning it into a retry/failure.
        $engine->fail($claimed->id, 'SSH_TIMEOUT');
        $this->assertSame('cancelled', $claimed->fresh()->status);
    }

    public function test_cancel_requires_run_permission(): void
    {
        $job = $this->pending();
        $viewer = User::factory()->viewer()->create();
        $this->actingAs($viewer)
            ->post(route('backup-executions.cancel', $job))->assertForbidden();
        $this->assertSame('pending', $job->fresh()->status);

        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)
            ->post(route('backup-executions.cancel', $job))->assertRedirect(route('backup-executions.show', $job));
        $this->assertSame('cancelled', $job->fresh()->status);
    }

    public function test_cancellation_requests_and_acks_are_audited(): void
    {
        $job = $this->pending();
        $job->transitionTo('queued');
        $engine = app(EngineJobService::class);
        $claimed = $engine->claim(str_repeat('a', 32));
        $engine->requestCancel($claimed->id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.cancel_requested', 'resource_id' => (string) $claimed->id, 'result' => 'success',
        ]);
        $engine->cancelAck($claimed->id, str_repeat('a', 32));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'backup_execution.cancelled', 'resource_id' => (string) $claimed->id, 'result' => 'success',
        ]);
    }
}
