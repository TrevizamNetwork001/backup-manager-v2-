<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BackupExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function association(string $suffix = '1'): DeviceBackupPolicy
    {
        $site = Site::firstOrCreate(['name' => 'POP Teste'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'Equipamento '.$suffix, 'management_ip' => '192.0.2.'.$suffix, 'vendor' => 'Generic', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Política '.$suffix, 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_days' => 30, 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'Acesso '.$suffix, 'type' => 'ssh', 'username' => 'operador', 'is_active' => true]);
        $credential->secret = 'segredo-confidencial-123';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
    }

    private function createViaHttp(DeviceBackupPolicy $association): void
    {
        $this->post(route('backup-policies.associations.executions.store', [$association->backup_policy_id, $association->id]));
    }

    public function test_guest_cannot_access_executions_or_actions(): void
    {
        $association = $this->association();
        $execution = BackupExecution::createManual($association);
        $this->get(route('backup-executions.index'))->assertRedirect('/login');
        $this->get(route('backup-executions.show', $execution))->assertRedirect('/login');
        $this->post(route('backup-policies.associations.executions.store', [$association->backup_policy_id, $association]))->assertRedirect('/login');
        $this->post(route('backup-executions.queue', $execution))->assertRedirect('/login');
    }

    public function test_manual_creation_copies_structural_ids_and_initial_values(): void
    {
        $this->actingAs(User::factory()->create());
        $association = $this->association();
        $this->createViaHttp($association);
        $execution = BackupExecution::firstOrFail();
        $this->assertSame([$association->id, $association->backup_policy_id, $association->device_id, $association->credential_id], [$execution->device_backup_policy_id, $execution->backup_policy_id, $execution->device_id, $execution->credential_id]);
        $this->assertSame('manual', $execution->origin);
        $this->assertSame('pending', $execution->status);
        $this->assertSame(1, $execution->attempt);
        $this->assertNull($execution->started_at);
        $this->assertNull($execution->finished_at);
        $this->assertArrayNotHasKey('secret', $execution->getAttributes());
    }

    public function test_inactive_parts_reject_manual_creation(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['association', 'backupPolicy', 'device', 'credential'] as $part) {
            $association = $this->association((string) (count(DeviceBackupPolicy::all()) + 1));
            $target = $part === 'association' ? $association : $association->$part;
            $target->update(['is_active' => false]);
            $this->createViaHttp($association);
            $this->assertDatabaseCount('backup_executions', 0);
            $this->assertTrue(session()->has('errors'));
        }
    }

    public function test_state_machine_sets_timestamps_and_rejects_reopening(): void
    {
        $execution = BackupExecution::createManual($this->association());
        // max_attempts=1: this test is about terminal-state timestamps and the
        // reopening guard, not the ENGINE-2 retry policy (see EngineRetryTest).
        $execution->update(['max_attempts' => 1]);
        $execution->transitionTo('queued');
        app(\App\Services\EngineJobService::class)->claim();
        $execution->refresh();
        $this->assertNotNull($execution->started_at);
        app(\App\Services\EngineJobService::class)->fail($execution->id, 'ENGINE_FAILED');
        $execution->refresh();
        $this->assertNotNull($execution->finished_at);
        $this->expectException(ValidationException::class);
        $execution->transitionTo('running');
    }

    public function test_invalid_transitions_and_cancel_rules(): void
    {
        $execution = BackupExecution::createManual($this->association());
        foreach (['running', 'succeeded', 'failed'] as $status) {
            try { $execution->transitionTo($status); $this->fail('Transition accepted'); }
            catch (ValidationException) { $this->assertSame('pending', $execution->fresh()->status); }
        }
        $execution->transitionTo('cancelled');
        $this->assertNotNull($execution->finished_at);
        try { $execution->transitionTo('running'); $this->fail('Terminal state reopened'); }
        catch (ValidationException) { $this->assertSame('cancelled', $execution->fresh()->status); }

        $queued = BackupExecution::createManual($this->association('2'));
        $queued->transitionTo('queued');
        $queued->transitionTo('cancelled');
        $this->assertSame('cancelled', $queued->status);
    }

    public function test_engine_failure_uses_fixed_sanitized_error_and_pages_do_not_expose_secret(): void
    {
        $this->actingAs(User::factory()->create());
        $execution = BackupExecution::createManual($this->association());
        $execution->transitionTo('queued');
        app(\App\Services\EngineJobService::class)->claim();
        app(\App\Services\EngineJobService::class)->fail($execution->id, 'SSH_AUTH_FAILED');
        $execution->refresh();
        $this->assertSame('SSH_AUTH_FAILED', $execution->error_code);
        $this->assertNotNull($execution->finished_at);
        $this->assertStringNotContainsString('segredo-confidencial-123', $execution->error_message);
        $encrypted = DB::table('credentials')->where('id', $execution->credential_id)->value('secret');
        foreach ([route('backup-executions.index'), route('backup-executions.show', $execution)] as $url) {
            $this->get($url)->assertOk()->assertDontSee('segredo-confidencial-123')->assertDontSee($encrypted);
        }
    }

    public function test_web_actions_cannot_simulate_engine_result(): void
    {
        $this->actingAs(User::factory()->create());
        $execution = BackupExecution::createManual($this->association());
        $this->get(route('backup-executions.show', $execution))->assertOk()->assertSee('Execução manual');
        $this->post('/backup-executions/'.$execution->id.'/start')->assertNotFound();
        $this->assertSame('pending', $execution->fresh()->status);
        $this->post(route('backup-executions.queue', $execution), ['status' => 'succeeded'])->assertRedirect();
        $this->assertSame('queued', $execution->fresh()->status);
        app(\App\Services\EngineJobService::class)->claim();
        $this->assertSame('running', $execution->fresh()->status);
        $this->post('/backup-executions/'.$execution->id.'/succeed')->assertNotFound();
        $this->assertSame('running', $execution->fresh()->status);
    }

    public function test_filters_and_history_restrictions(): void
    {
        $this->actingAs(User::factory()->create());
        $association = $this->association();
        $other = $this->association('2');
        $execution = BackupExecution::createManual($association);
        $otherExecution = BackupExecution::createManual($other);
        $otherExecution->transitionTo('cancelled');
        $this->get(route('backup-executions.index', ['status' => 'pending', 'origin' => 'manual', 'device_id' => $association->device_id]))
            ->assertOk()->assertSee(route('backup-executions.show', $execution))->assertDontSee(route('backup-executions.show', $otherExecution));
        $this->delete(route('backup-policies.associations.destroy', [$association->backup_policy_id, $association]))->assertSessionHas('warning');
        $this->assertDatabaseHas('device_backup_policies', ['id' => $association->id]);
        foreach (['device_backup_policies' => $association->id, 'backup_policies' => $association->backup_policy_id, 'devices' => $association->device_id, 'credentials' => $association->credential_id] as $table => $id) {
            try { DB::table($table)->where('id', $id)->delete(); $this->fail('Historical FK deleted'); }
            catch (QueryException) { $this->assertDatabaseHas('backup_executions', ['id' => $execution->id]); }
        }
    }
}
