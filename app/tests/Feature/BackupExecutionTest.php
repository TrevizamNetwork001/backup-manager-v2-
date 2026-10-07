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
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $this->get(route('backup-executions.status', $execution))->assertRedirect('/login');
        $this->post(route('backup-policies.associations.executions.store', [$association->backup_policy_id, $association]))->assertRedirect('/login');
        $this->post(route('backup-policies.associations.run-a10', [$association->backup_policy_id, $association]))->assertRedirect('/login');
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

    public function test_a10_run_now_from_panel_creates_and_queues_one_execution(): void
    {
        config()->set('backup.a10_enabled', true);
        $this->actingAs(User::factory()->create());
        $association = $this->association();
        $association->device->update(['name' => 'CGNAT-A10', 'vendor' => 'A10 Networks', 'platform' => 'network', 'a10_transfer_interface' => 'management']);
        $association->backupPolicy->update(['method' => 'a10_system', 'artifact_mode' => 'binary']);
        $url = route('backup-policies.associations.run-a10', [$association->backupPolicy, $association]);

        $this->get(route('devices.index'))->assertOk()->assertSee('Executar backup A10')->assertSee($url, false);
        $this->get(route('backup-policies.edit', $association->backupPolicy))->assertOk()
            ->assertSee('Executar backup A10')->assertSee($url, false);
        $this->post($url)->assertRedirect();
        $execution = BackupExecution::sole();
        $this->assertSame('queued', $execution->status);
        $this->assertSame('manual', $execution->origin);
        $this->get(route('devices.index'))->assertOk()->assertSee('Acompanhar backup')
            ->assertDontSee('Executar backup A10');
        $this->get(route('backup-executions.show', $execution))->assertOk()
            ->assertSee('Exportação A10 em andamento');

        $this->post($url)->assertSessionHasErrors('association');
        $this->assertDatabaseCount('backup_executions', 1);
    }

    public function test_a10_run_now_is_hidden_when_integration_is_disabled_or_policy_is_not_a10(): void
    {
        $this->actingAs(User::factory()->create());
        $association = $this->association();
        $url = route('backup-policies.associations.run-a10', [$association->backupPolicy, $association]);
        $this->post($url)->assertNotFound();
        config()->set('backup.a10_enabled', true);
        $this->post($url)->assertNotFound();
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_a10_run_now_requires_execution_permission(): void
    {
        config()->set('backup.a10_enabled', true);
        $this->actingAs(User::factory()->viewer()->create());
        $association = $this->association();
        $association->device->update(['vendor' => 'A10 Networks', 'platform' => 'network', 'a10_transfer_interface' => 'management']);
        $association->backupPolicy->update(['method' => 'a10_system', 'artifact_mode' => 'binary']);

        $this->post(route('backup-policies.associations.run-a10', [$association->backupPolicy, $association]))
            ->assertForbidden();
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_scp_receiver_only_accepts_a_running_a10_execution(): void
    {
        config()->set('backup.a10_enabled', true);
        $association = $this->association();
        $association->device->update(['name' => 'CGNAT A10', 'vendor' => 'A10 Networks', 'platform' => 'network', 'a10_transfer_interface' => 'management']);
        $association->backupPolicy->update(['method' => 'a10_system', 'artifact_mode' => 'binary']);
        $job = BackupExecution::createManual($association);

        Artisan::call('a10:expected', ['id' => $job->id]);
        $this->assertSame('{}', trim(Artisan::output()));

        $job->transitionTo('queued');
        app(EngineJobService::class)->claim();
        $this->assertSame('segredo-confidencial-123', app(EngineJobService::class)->secret($job->id, $job->fresh()->worker_id));
        Artisan::call('a10:expected', ['id' => $job->id]);
        $response = json_decode(Artisan::output(), true);
        $this->assertMatchesRegularExpression('/\ACGNAT-A10_[0-9]{14}-exec-'.$job->id.'\.tar\.gz\z/', $response['filename']);

        $job->refresh()->update(['cancellation_requested_at' => now()]);
        Artisan::call('a10:expected', ['id' => $job->id]);
        $this->assertSame('{}', trim(Artisan::output()));
    }

    public function test_dashboard_shows_execution_duration_and_pending_placeholder(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $association = $this->association();
        $finished = BackupExecution::createManual($association);
        $finished->update([
            'status' => 'succeeded',
            'started_at' => '2026-09-26 01:00:00',
            'finished_at' => '2026-09-26 01:02:14',
        ]);
        BackupExecution::createManual($association);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Duração')
            ->assertSee('134s')
            ->assertSee('—');
    }

    public function test_ftp_duration_starts_at_receipt_and_history_shows_only_attempt_number(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $execution = BackupExecution::createManual($this->association());
        $execution->update([
            'origin' => 'ftp_received',
            'status' => 'succeeded',
            'attempt' => 2,
            'max_attempts' => 3,
            'received_at' => '2026-10-01 13:47:54',
            'started_at' => '2026-10-01 13:48:00',
            'finished_at' => '2026-10-01 13:48:00',
        ]);

        $this->assertSame(6, $execution->fresh()->durationSeconds());
        $this->get(route('backup-executions.index'))->assertOk()
            ->assertSee('6s')
            ->assertSee('data-label="Tentativa">2</td>', false)
            ->assertDontSee('2 / 3')
            ->assertDontSee('<th>Erro</th>', false);
        $this->get(route('dashboard'))->assertOk()->assertSee('6s');
    }

    public function test_history_displays_ftp_method_for_ftp_policy(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $association = $this->association();
        BackupExecution::createManual($association);
        $association->backupPolicy->update(['method' => 'ftp_push']);

        $this->get(route('backup-executions.index'))->assertOk()
            ->assertSee('data-label="Política">Política 1</td>', false)
            ->assertSee('data-label="Método">Envio via FTP</td>', false)
            ->assertDontSee('data-label="Método">Coleta via SSH</td>', false);
    }

    public function test_history_shows_portuguese_error_only_when_present(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $execution = BackupExecution::createManual($this->association());
        $execution->forceFill([
            'status' => 'failed',
            'error_code' => 'SSH_AUTH_FAILED',
            'error_message' => 'Autenticação SSH falhou.',
        ])->save();

        $this->get(route('backup-executions.index'))->assertOk()
            ->assertSee('<th>Erro</th>', false)
            ->assertSee('Autenticação SSH falhou.')
            ->assertDontSee('<code>SSH_AUTH_FAILED</code>', false);
    }

    public function test_dashboard_chart_accepts_presets_and_historical_dates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));

        try {
            $this->actingAs(User::factory()->admin()->create());
            $association = $this->association();
            $execution = BackupExecution::createManual($association);
            $execution->update(['status' => 'succeeded']);
            $execution->created_at = CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC');
            $execution->save();

            $this->get(route('dashboard'))->assertOk()
                ->assertSee('Últimos 7 dias')
                ->assertDontSee('10/08: 1 concluídas');
            $this->get(route('dashboard', ['period' => '14d']))->assertOk()
                ->assertSee('Últimos 14 dias');
            $this->get(route('dashboard', [
                'period' => 'custom', 'start_date' => '2026-08-09', 'end_date' => '2026-08-11',
            ]))->assertOk()
                ->assertSee('09/08/2026 a 11/08/2026')
                ->assertSee('10/08: 1 concluídas');

            $this->get(route('dashboard', [
                'period' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-09-02',
            ]))->assertSessionHasErrors('end_date');
        } finally {
            CarbonImmutable::setTestNow();
        }
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
        app(EngineJobService::class)->claim();
        $execution->refresh();
        $this->assertNotNull($execution->started_at);
        app(EngineJobService::class)->fail($execution->id, 'ENGINE_FAILED');
        $execution->refresh();
        $this->assertNotNull($execution->finished_at);
        $this->expectException(ValidationException::class);
        $execution->transitionTo('running');
    }

    public function test_invalid_transitions_and_cancel_rules(): void
    {
        $execution = BackupExecution::createManual($this->association());
        foreach (['running', 'succeeded', 'failed'] as $status) {
            try {
                $execution->transitionTo($status);
                $this->fail('Transition accepted');
            } catch (ValidationException) {
                $this->assertSame('pending', $execution->fresh()->status);
            }
        }
        $execution->transitionTo('cancelled');
        $this->assertNotNull($execution->finished_at);
        try {
            $execution->transitionTo('running');
            $this->fail('Terminal state reopened');
        } catch (ValidationException) {
            $this->assertSame('cancelled', $execution->fresh()->status);
        }

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
        app(EngineJobService::class)->claim();
        app(EngineJobService::class)->fail($execution->id, 'SSH_AUTH_FAILED');
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
        $this->post(route('backup-executions.queue', $execution), ['status' => 'succeeded'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Execução adicionada à fila. O backup começará automaticamente quando houver disponibilidade para processamento.')
            ->assertSessionHas('success_persistent', true);
        $this->assertSame('queued', $execution->fresh()->status);
        app(EngineJobService::class)->claim();
        $this->assertSame('running', $execution->fresh()->status);
        $this->post('/backup-executions/'.$execution->id.'/succeed')->assertNotFound();
        $this->assertSame('running', $execution->fresh()->status);
    }

    public function test_execution_detail_translates_status_and_exposes_polling_endpoint(): void
    {
        $this->actingAs(User::factory()->create());
        $execution = BackupExecution::createManual($this->association());
        $execution->transitionTo('queued');

        $this->get(route('backup-executions.show', $execution))->assertOk()
            ->assertSee('Na fila')
            ->assertSee(route('backup-executions.status', $execution), false)
            ->assertDontSee('>QUEUED<', false);
        $this->get(route('backup-executions.status', $execution))->assertOk()
            ->assertExactJson(['status' => 'queued'])
            ->assertHeader('Cache-Control', 'no-store, private');
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
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail('Historical FK deleted');
            } catch (QueryException) {
                $this->assertDatabaseHas('backup_executions', ['id' => $execution->id]);
            }
        }
    }
}
