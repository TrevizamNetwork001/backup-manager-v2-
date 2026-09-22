<?php

namespace Tests\Feature;

use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupPolicyTest extends TestCase
{
    use RefreshDatabase;

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
        $this->actingAs(User::factory()->create());
        $this->get('/backup-policies')->assertOk()->assertSee('Políticas de Backup');

        $this->post('/backup-policies', $this->payload())
            ->assertRedirect();
        $policy = BackupPolicy::firstOrFail();
        $this->assertDatabaseHas('backup_policies', ['id' => $policy->id, 'name' => 'MikroTik Diário']);

        $this->put("/backup-policies/{$policy->id}", $this->payload(['name' => 'MikroTik Semanal', 'schedule_type' => 'weekly', 'schedule_weekday' => 2]))
            ->assertRedirect(route('backup-policies.edit', $policy));
        $this->assertDatabaseHas('backup_policies', ['id' => $policy->id, 'name' => 'MikroTik Semanal', 'schedule_weekday' => 2]);

        $this->delete("/backup-policies/{$policy->id}")->assertRedirect(route('backup-policies.index'));
        $this->assertDatabaseMissing('backup_policies', ['id' => $policy->id]);
    }

    public function test_policy_is_reused_and_associated_policy_cannot_be_removed(): void
    {
        $this->actingAs(User::factory()->create());
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

    public function test_credential_type_is_checked_for_both_methods(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        $ssh = $this->credential($device, 'ssh');
        $ftp = $this->credential($device, 'ftp');
        $sshPolicy = $this->policy();
        $ftpPolicy = $this->policy(['name' => 'FTP Diário', 'method' => 'ftp_push']);

        $this->post("/backup-policies/{$sshPolicy->id}/associations", $this->attach($sshPolicy, $device, $ftp))
            ->assertSessionHasErrors('credential_id');
        $this->post("/backup-policies/{$ftpPolicy->id}/associations", $this->attach($ftpPolicy, $device, $ssh))
            ->assertSessionHasErrors('credential_id');
        $this->post("/backup-policies/{$sshPolicy->id}/associations", $this->attach($sshPolicy, $device, $ssh))
            ->assertSessionHasNoErrors();
        $this->post("/backup-policies/{$ftpPolicy->id}/associations", $this->attach($ftpPolicy, $device, $ftp))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('device_backup_policies', 2);
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
        $this->assertSame('ftp', (new BackupPolicy(['method' => 'ftp_push']))->credentialType());
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
        $this->actingAs(User::factory()->create());
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

        $this->put("/backup-policies/{$policy->id}", $this->payload(['method' => 'ftp_push']))
            ->assertSessionHasErrors('method');
        $this->assertSame('ssh_pull', $policy->fresh()->method);
    }
}
