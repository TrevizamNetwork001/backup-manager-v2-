<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_policies_are_seeded_once_without_creating_a_user(): void
    {
        $this->seed();
        $this->assertDatabaseCount('backup_policies', 5);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('backup_policies', ['name' => 'Huawei FTP', 'method' => 'ftp_push']);

        BackupPolicy::query()->where('name', 'Huawei FTP')->update(['name' => 'FTP personalizado']);
        $this->seed();
        $this->assertDatabaseCount('backup_policies', 5);
        $this->assertDatabaseHas('backup_policies', ['name' => 'FTP personalizado']);
    }

    private function policy(array $changes = []): BackupPolicy
    {
        return BackupPolicy::create($this->payload($changes));
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'MikroTik Diário', 'method' => 'ssh_pull', 'artifact_mode' => 'config',
            'schedule_type' => 'daily', 'schedule_time' => '03:00', 'schedule_weekday' => null,
            'retention_days' => 30, 'retention_count' => null, 'notes' => null, 'is_active' => '1',
        ], $changes);
    }

    private function device(string $ip = '192.0.2.10'): Device
    {
        $site = Site::firstOrCreate(['name' => 'POP Teste'], ['is_active' => true]);

        return Device::create([
            'site_id' => $site->id, 'name' => 'Equipamento '.$ip,
            'management_ip' => $ip, 'vendor' => 'Generic', 'is_active' => true,
        ]);
    }

    private function credential(Device $device, string $type = 'ssh'): Credential
    {
        $credential = new Credential([
            'device_id' => $device->id, 'name' => 'Acesso '.$type,
            'type' => $type, 'username' => 'operador', 'port' => 2222, 'is_active' => true,
        ]);
        $credential->secret = 'segredo-confidencial-123';
        $credential->save();

        return $credential;
    }

    private function attach(BackupPolicy $policy, Device $device, Credential $credential): array
    {
        return ['device_id' => $device->id, 'credential_id' => $credential->id, 'is_active' => '1'];
    }

    public function test_guest_cannot_access_policy_routes(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        $credential = $this->credential($device);

        $this->get('/backup-policies')->assertRedirect('/login');
        $this->get('/backup-policies/create')->assertRedirect('/login');
        $this->get("/backup-policies/{$policy->id}/edit")->assertRedirect('/login');
        $this->post('/backup-policies', $this->payload())->assertRedirect('/login');
        $this->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $device, $credential))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_create_edit_and_delete_unassigned_policy(): void
    {
        // Destroy is admin-only (backup_policies.delete) since ADMIN-3.
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/backup-policies')->assertOk()->assertSee('Políticas de Backup');

        $this->post('/backup-policies', $this->payload())
            ->assertRedirect();
        $policy = BackupPolicy::firstOrFail();
        $this->assertDatabaseHas('backup_policies', ['id' => $policy->id, 'name' => 'MikroTik Diário']);

        $this->put("/backup-policies/{$policy->id}", $this->payload(['name' => 'MikroTik Semanal', 'schedule_type' => 'weekly', 'schedule_weekday' => 2]))
            ->assertRedirect(route('backup-policies.index'));
        $this->assertDatabaseHas('backup_policies', ['id' => $policy->id, 'name' => 'MikroTik Semanal', 'schedule_weekday' => 2]);

        $this->delete("/backup-policies/{$policy->id}")->assertRedirect(route('backup-policies.index'));
        $this->assertDatabaseMissing('backup_policies', ['id' => $policy->id]);
    }

    public function test_ftp_policy_with_historical_vsol_association_can_be_renamed_without_changing_method(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $device = $this->device();
        $device->update(['vendor' => 'VSOL', 'platform' => 'olt']);
        $policy = $this->policy(['name' => 'Huawei FTP Manual', 'method' => 'ftp_push', 'schedule_type' => 'manual', 'schedule_time' => null]);
        $policy->deviceBackupPolicies()->create(['device_id' => $device->id, 'credential_id' => null, 'is_active' => false]);

        $this->put(route('backup-policies.update', $policy), $this->payload([
            'name' => 'Huawei FTP', 'method' => 'ftp_push', 'schedule_type' => 'manual', 'schedule_time' => null,
        ]))->assertRedirect(route('backup-policies.index'));

        $this->assertDatabaseHas('backup_policies', ['id' => $policy->id, 'name' => 'Huawei FTP']);
        $this->get(route('backup-policies.edit', $policy))->assertOk()->assertSee('Dia da semana (somente semanal)');
    }

    public function test_policy_is_reused_and_associated_policy_cannot_be_removed(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $policy = $this->policy();
        $first = $this->device();
        $second = $this->device('192.0.2.11');
        $this->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $first, $this->credential($first)))
            ->assertRedirect(route('backup-policies.edit', $policy));
        $this->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $second, $this->credential($second)))
            ->assertRedirect(route('backup-policies.edit', $policy));

        $this->assertDatabaseCount('device_backup_policies', 2);
        $this->assertSame(2, $policy->deviceBackupPolicies()->count());
        $this->get('/backup-policies')->assertSee('MikroTik Diário')->assertSee('2');
        $this->delete("/backup-policies/{$policy->id}")
            ->assertRedirect(route('backup-policies.index'))->assertSessionHas('warning');
        $this->assertDatabaseHas('backup_policies', ['id' => $policy->id]);
    }

    public function test_device_actions_manage_policy_association_and_return_to_the_device(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $device = $this->device();
        $credential = $this->credential($device);
        $returnUrl = route('devices.index');

        $this->get(route('devices.index'))->assertOk()
            ->assertSee('data-open-device-policy="'.$device->id.'"', false)
            ->assertSee('id="device-policy-'.$device->id.'"', false)
            ->assertSee($policy->name)
            ->assertSee($credential->name);
        $this->get(route('backup-policies.edit', $policy))->assertOk()
            ->assertSee('Equipamentos → Ações → Política de backup')
            ->assertDontSee('Associar equipamento');

        $this->post(route('backup-policies.associations.store', $policy), $this->attach($policy, $device, $credential) + ['return_to' => 'devices'])
            ->assertRedirect($returnUrl)->assertSessionHas('success', "Política {$policy->name} associada ao equipamento {$device->name}.");
        $association = $policy->deviceBackupPolicies()->firstOrFail();
        $this->policy(['name' => 'Outra política SSH']);
        $this->get(route('devices.index'))->assertOk()->assertSee('Políticas vinculadas')->assertSee('Desativar')
            ->assertDontSee('Adicionar outra política');

        $this->patch(route('backup-policies.associations.update', [$policy, $association]), ['is_active' => '0', 'return_to' => 'devices'])
            ->assertRedirect($returnUrl.'#device-policy-'.$device->id)
            ->assertSessionHas('policy_device_id', $device->id)
            ->assertSessionHas('success', "Política {$policy->name} desativada para o equipamento.");
        $this->assertDatabaseHas('device_backup_policies', ['id' => $association->id, 'is_active' => false]);

        $this->patch(route('backup-policies.associations.update', [$policy, $association]), ['is_active' => '1', 'return_to' => 'devices'])
            ->assertRedirect($returnUrl.'#device-policy-'.$device->id)
            ->assertSessionHas('policy_device_id', $device->id)
            ->assertSessionHas('success', "Política {$policy->name} ativada para o equipamento.");
        $this->get($returnUrl)->assertOk()->assertSee('Política '.$policy->name.' ativada para o equipamento.');
        $this->assertDatabaseHas('device_backup_policies', ['id' => $association->id, 'is_active' => true]);

        $this->delete(route('backup-policies.associations.destroy', [$policy, $association]), ['return_to' => 'devices'])
            ->assertRedirect($returnUrl.'#device-policy-'.$device->id)
            ->assertSessionHas('success', "Política {$policy->name} removida do equipamento.");
        $this->assertDatabaseMissing('device_backup_policies', ['id' => $association->id]);
    }

    public function test_removing_a_policy_with_history_hides_its_link_and_preserves_executions(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $policy = $this->policy(['name' => 'Teste de integração']);
        $device = $this->device();
        $credential = $this->credential($device);
        $association = $policy->deviceBackupPolicies()->create([
            'device_id' => $device->id, 'credential_id' => $credential->id, 'is_active' => false,
        ]);
        $execution = BackupExecution::create([
            'device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id,
            'device_id' => $device->id, 'credential_id' => $credential->id,
            'origin' => 'manual', 'status' => 'succeeded', 'attempt' => 1,
        ]);

        $this->get(route('devices.index'))->assertOk()->assertSee('Teste de integração')->assertSee('Remover');
        $this->delete(route('backup-policies.associations.destroy', [$policy, $association]), ['return_to' => 'devices'])
            ->assertRedirect(route('devices.index').'#device-policy-'.$device->id)
            ->assertSessionHas('success', "Política {$policy->name} removida do equipamento; histórico preservado.");

        $this->assertNotNull($association->fresh()->archived_at);
        $this->assertFalse($association->fresh()->is_active);
        $this->assertDatabaseHas('backup_executions', ['id' => $execution->id, 'device_backup_policy_id' => $association->id]);
        $this->get(route('devices.index'))->assertOk()->assertDontSee('>Teste de integração</strong>', false);
        $this->patch(route('backup-policies.associations.update', [$policy, $association]), ['is_active' => 1])->assertNotFound();

        $this->get(route('backup-policies.index'))->assertOk()
            ->assertSee('Teste de integração')
            ->assertSee('data-label="Equipamentos">0</td>', false);
        $this->delete(route('backup-policies.destroy', $policy))
            ->assertRedirect(route('backup-policies.index'))
            ->assertSessionHas('success', 'Política removida do catálogo; histórico preservado.');
        $this->assertNotNull($policy->fresh()->archived_at);
        $this->assertDatabaseHas('backup_executions', ['id' => $execution->id, 'backup_policy_id' => $policy->id]);
        $this->get(route('backup-policies.index'))->assertOk()->assertDontSee('Teste de integração');
    }

    public function test_device_policy_modal_filters_out_incompatible_ftp_policies(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        $this->credential($device);
        $sshPolicy = $this->policy();
        $ftpPolicy = $this->policy(['name' => 'Huawei FTP', 'method' => 'ftp_push', 'schedule_type' => 'manual', 'schedule_time' => null]);

        $this->get(route('devices.index'))->assertOk()
            ->assertSee('value="'.$sshPolicy->id.'" data-method="ssh_pull"', false)
            ->assertDontSee('value="'.$ftpPolicy->id.'" data-method="ftp_push"', false);
    }

    public function test_policy_edit_uses_the_create_modal_layout_and_preserves_the_list_page(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy(['name' => 'MikroTik Produção', 'schedule_time' => '01:30']);

        $this->get(route('backup-policies.edit', [$policy, 'page' => 2]))->assertOk()
            ->assertSee('class="modal form-create-modal" id="policy-edit-dialog"', false)
            ->assertSee('name="name" type="text" maxlength="255" value="MikroTik Produção"', false)
            ->assertSee('name="schedule_time" type="time" value="01:30"', false)
            ->assertSee('data-close-policy-edit', false)
            ->assertSee('name="return_page" value="2"', false);

        $this->put(route('backup-policies.update', $policy), $this->payload([
            'name' => 'MikroTik Produção', 'schedule_time' => '01:30', 'return_page' => 2,
        ]))->assertRedirect(route('backup-policies.index', ['page' => 2]));
    }

    public function test_duplicate_association_and_foreign_credential_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $first = $this->device();
        $second = $this->device('192.0.2.11');
        $credential = $this->credential($first);
        $this->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $first, $credential));

        $this->from(route('backup-policies.edit', $policy))
            ->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $first, $credential))
            ->assertSessionHasErrors('device_id');
        $this->from(route('backup-policies.edit', $policy))
            ->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $second, $credential))
            ->assertSessionHasErrors('credential_id');
        $this->assertDatabaseCount('device_backup_policies', 1);
    }

    public function test_only_one_policy_of_each_method_can_be_active_for_a_device(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        $credential = $this->credential($device);
        $first = $this->policy(['name' => 'SSH principal']);
        $second = $this->policy(['name' => 'SSH alternativo']);

        $this->post(route('backup-policies.associations.store', $first), $this->attach($first, $device, $credential))
            ->assertSessionHasNoErrors();
        $this->post(route('backup-policies.associations.store', $second), $this->attach($second, $device, $credential))
            ->assertSessionHasErrors('is_active');
        $this->assertDatabaseCount('device_backup_policies', 1);

        $this->post(route('backup-policies.associations.store', $second), [
            'device_id' => $device->id, 'credential_id' => $credential->id, 'is_active' => '0',
        ])->assertSessionHasNoErrors();
        $alternative = $second->deviceBackupPolicies()->firstOrFail();
        $this->patch(route('backup-policies.associations.update', [$second, $alternative]), ['is_active' => '1'])
            ->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('device_backup_policies', ['id' => $alternative->id, 'is_active' => false]);

        $original = $first->deviceBackupPolicies()->firstOrFail();
        $this->patch(route('backup-policies.associations.update', [$first, $original]), ['is_active' => '0'])
            ->assertSessionHasNoErrors();
        $this->patch(route('backup-policies.associations.update', [$second, $alternative]), ['is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_backup_policies', ['id' => $alternative->id, 'is_active' => true]);
    }

    public function test_reactivating_a_global_policy_cannot_create_a_duplicate_method(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        $credential = $this->credential($device);
        $active = $this->policy(['name' => 'SSH ativo']);
        $inactive = $this->policy(['name' => 'SSH global inativo', 'is_active' => false]);
        $active->deviceBackupPolicies()->create($this->attach($active, $device, $credential));
        $inactive->deviceBackupPolicies()->create($this->attach($inactive, $device, $credential));

        $this->put(route('backup-policies.update', $inactive), $this->payload([
            'name' => $inactive->name, 'is_active' => '1',
        ]))->assertSessionHasErrors('method');
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_ssh_and_ftp_can_coexist_during_a_huawei_router_migration(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        $device->update(['vendor' => 'Huawei', 'platform' => 'network']);
        $sshPolicy = $this->policy(['name' => 'Huawei SSH']);
        $ftpPolicy = $this->policy([
            'name' => 'Huawei FTP', 'method' => 'ftp_push', 'schedule_type' => 'manual', 'schedule_time' => null,
        ]);
        $ftp = new FtpAccount([
            'device_id' => $device->id, 'account_uuid' => (string) Str::uuid(),
            'purpose' => 'backup', 'home_layout' => 'account', 'username' => 'router-ftp', 'is_active' => true,
        ]);
        $ftp->secret = 'test-password-only';
        $ftp->save();

        $this->post(route('backup-policies.associations.store', $sshPolicy),
            $this->attach($sshPolicy, $device, $this->credential($device)))->assertSessionHasNoErrors();
        $this->post(route('backup-policies.associations.store', $ftpPolicy), [
            'device_id' => $device->id, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $device->deviceBackupPolicies()->where('is_active', true)->count());
    }

    public function test_credential_type_is_checked_for_both_methods(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        $ssh = $this->credential($device, 'ssh');
        $ftp = $this->credential($device, 'ftp');
        $sshPolicy = $this->policy();
        $ftpPolicy = $this->policy(['name' => 'FTP Manual', 'method' => 'ftp_push', 'schedule_type' => 'manual', 'schedule_time' => null]);

        $this->post("/backup-policies/{$sshPolicy->id}/associations", $this->attach($sshPolicy, $device, $ftp))
            ->assertSessionHasErrors('credential_id');
        $this->post("/backup-policies/{$ftpPolicy->id}/associations", $this->attach($ftpPolicy, $device, $ssh))
            ->assertSessionHasErrors('credential_id');
        $this->post("/backup-policies/{$sshPolicy->id}/associations", $this->attach($sshPolicy, $device, $ssh))
            ->assertSessionHasNoErrors();
        $this->post("/backup-policies/{$ftpPolicy->id}/associations", $this->attach($ftpPolicy, $device, $ftp))
            ->assertSessionHasErrors('credential_id');
        $this->assertDatabaseCount('device_backup_policies', 1);
    }

    public function test_inactive_device_cannot_receive_new_association(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $device = $this->device();
        $credential = $this->credential($device);
        $device->update(['is_active' => false]);

        $this->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $device, $credential))
            ->assertSessionHasErrors('device_id');
        $this->assertDatabaseCount('device_backup_policies', 0);
    }

    public function test_inactive_credential_cannot_receive_new_association(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $device = $this->device();
        $credential = $this->credential($device);
        $credential->update(['is_active' => false]);

        $this->post("/backup-policies/{$policy->id}/associations", $this->attach($policy, $device, $credential))
            ->assertSessionHasErrors('credential_id');
        $this->assertDatabaseCount('device_backup_policies', 0);
    }

    public function test_existing_association_is_preserved_when_device_becomes_inactive(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        $credential = $this->credential($device);
        $association = $policy->deviceBackupPolicies()->create($this->attach($policy, $device, $credential));

        $device->update(['is_active' => false]);

        $this->assertDatabaseHas('device_backup_policies', [
            'id' => $association->id,
            'device_id' => $device->id,
            'credential_id' => $credential->id,
            'is_active' => true,
        ]);
    }

    public function test_existing_association_is_preserved_when_credential_becomes_inactive(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        $credential = $this->credential($device);
        $association = $policy->deviceBackupPolicies()->create($this->attach($policy, $device, $credential));

        $credential->update(['is_active' => false]);

        $this->assertDatabaseHas('device_backup_policies', [
            'id' => $association->id,
            'device_id' => $device->id,
            'credential_id' => $credential->id,
            'is_active' => true,
        ]);
    }

    public function test_credential_type_matches_supported_methods(): void
    {
        $this->assertSame('ssh', (new BackupPolicy(['method' => 'ssh_pull']))->credentialType());
        $this->assertSame('none', (new BackupPolicy(['method' => 'ftp_push']))->credentialType());
    }

    public function test_credential_type_rejects_unexpected_method(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Método de backup inválido.');

        (new BackupPolicy(['method' => 'unexpected']))->credentialType();
    }

    public function test_policy_pages_only_show_credential_metadata(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $device = $this->device();
        $ssh = $this->credential($device);
        $ftp = $this->credential($device, 'ftp');
        $policy->deviceBackupPolicies()->create($this->attach($policy, $device, $ssh));
        $encrypted = DB::table('credentials')->where('id', $ssh->id)->value('secret');

        $this->get("/backup-policies/{$policy->id}/edit")
            ->assertOk()->assertSee('Acesso ssh')->assertSee('operador')
            ->assertDontSee('Acesso ftp')->assertDontSee('2222')
            ->assertDontSee('segredo-confidencial-123')->assertDontSee($encrypted);
        $this->get('/backup-policies')->assertOk()
            ->assertDontSee('2222')->assertDontSee('segredo-confidencial-123')->assertDontSee($encrypted);
    }

    public function test_device_and_credential_in_use_cannot_be_removed_or_moved(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $policy = $this->policy();
        $device = $this->device();
        $other = $this->device('192.0.2.11');
        $credential = $this->credential($device);
        $policy->deviceBackupPolicies()->create($this->attach($policy, $device, $credential));

        $this->delete("/devices/{$device->id}")->assertSessionHas('warning');
        $this->delete("/credentials/{$credential->id}")->assertSessionHas('warning');
        $this->put("/credentials/{$credential->id}", [
            'device_id' => $other->id, 'name' => $credential->name, 'type' => 'ftp',
            'username' => $credential->username, 'is_active' => '1',
        ])->assertSessionHasErrors('type')->assertSessionMissing('_old_input.secret');
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id, 'device_id' => $device->id, 'type' => 'ssh']);
    }

    public function test_association_can_be_disabled_and_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $device = $this->device();
        $association = $policy->deviceBackupPolicies()->create($this->attach($policy, $device, $this->credential($device)));

        $this->patch("/backup-policies/{$policy->id}/associations/{$association->id}", ['is_active' => '0'])
            ->assertRedirect(route('backup-policies.edit', $policy));
        $this->assertDatabaseHas('device_backup_policies', ['id' => $association->id, 'is_active' => false]);
        $this->delete("/backup-policies/{$policy->id}/associations/{$association->id}")
            ->assertRedirect(route('backup-policies.edit', $policy));
        $this->assertDatabaseMissing('device_backup_policies', ['id' => $association->id]);
    }

    public function test_weekly_schedule_requires_valid_weekday_and_manual_allows_null_time(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([null, 0, 8] as $day) {
            $this->post('/backup-policies', $this->payload(['schedule_type' => 'weekly', 'schedule_weekday' => $day]))
                ->assertSessionHasErrors('schedule_weekday');
        }
        $this->post('/backup-policies', $this->payload(['schedule_type' => 'manual', 'schedule_time' => null, 'schedule_weekday' => null]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('backup_policies', ['schedule_type' => 'manual', 'schedule_time' => null]);
    }

    public function test_invalid_retention_and_missing_retention_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            ['retention_days' => 0, 'retention_count' => null],
            ['retention_days' => -1, 'retention_count' => null],
            ['retention_days' => null, 'retention_count' => 0],
            ['retention_days' => null, 'retention_count' => null],
        ] as $retention) {
            $this->post('/backup-policies', $this->payload($retention))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('backup_policies', 0);
    }

    public function test_method_change_rejects_existing_incompatible_credentials(): void
    {
        $this->actingAs(User::factory()->create());
        $policy = $this->policy();
        $device = $this->device();
        $policy->deviceBackupPolicies()->create($this->attach($policy, $device, $this->credential($device)));

        $this->put("/backup-policies/{$policy->id}", $this->payload(['method' => 'ftp_push', 'schedule_type' => 'manual']))
            ->assertSessionHasErrors('method');
        $this->assertSame('ssh_pull', $policy->fresh()->method);
    }
}
