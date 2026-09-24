<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_devices(): void
    {
        $this->get('/devices')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_devices(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/devices')
            ->assertOk()
            ->assertSee('Equipamentos');
    }

    public function test_authenticated_user_can_create_device(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'code' => 'POP-01',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/devices', [
                'site_id' => $site->id,
                'name' => 'CCR Borda',
                'hostname' => 'mk-borda-01',
                'management_ip' => '192.0.2.10',
                'vendor' => 'MikroTik',
                'model' => 'CCR2004',
                'os_version' => 'RouterOS 7',
                'notes' => 'Equipamento de teste',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('devices.index'));

        $this->assertDatabaseHas('devices', [
            'site_id' => $site->id,
            'name' => 'CCR Borda',
            'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik',
            'is_active' => true,
        ]);
    }

    public function test_management_ip_must_be_unique(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        Device::create([
            'site_id' => $site->id,
            'name' => 'Device 1',
            'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/devices/create')
            ->post('/devices', [
                'site_id' => $site->id,
                'name' => 'Device 2',
                'management_ip' => '192.0.2.10',
                'vendor' => 'Huawei',
                'is_active' => '1',
            ]);

        $response
            ->assertRedirect('/devices/create')
            ->assertSessionHasErrors('management_ip');
    }

    public function test_device_requires_valid_ip(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/devices/create')
            ->post('/devices', [
                'site_id' => $site->id,
                'name' => 'Device inválido',
                'management_ip' => '999.999.999.999',
                'vendor' => 'Generic',
                'is_active' => '1',
            ]);

        $response
            ->assertRedirect('/devices/create')
            ->assertSessionHasErrors('management_ip');
    }

    public function test_authenticated_user_can_update_device(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'Device Antigo',
            'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->put("/devices/{$device->id}", [
                'site_id' => $site->id,
                'name' => 'Device Atualizado',
                'management_ip' => '192.0.2.11',
                'vendor' => 'Huawei',
                'model' => 'NE40',
                'is_active' => '0',
            ])
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Device Atualizado',
            'management_ip' => '192.0.2.11',
            'vendor' => 'Huawei',
            'is_active' => false,
        ]);
    }

    public function test_authenticated_user_can_delete_device(): void
    {
        // Destroy is admin-only (devices.delete) since ADMIN-3.
        $user = User::factory()->admin()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'Device Temporário',
            'management_ip' => '192.0.2.20',
            'vendor' => 'Generic',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->delete("/devices/{$device->id}")
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseMissing('devices', [
            'id' => $device->id,
        ]);
    }

    public function test_ip_change_discards_observation_and_empty_trust_is_rejected(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik', 'is_active' => true]);
        $device->ssh_observed_algorithm = 'ssh-rsa';
        $device->ssh_observed_fingerprint = 'SHA256:'.str_repeat('A', 43);
        $device->ssh_observed_at = now();
        $device->save();
        $this->actingAs(User::factory()->create())->put(route('devices.update', $device), [
            'site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.11',
            'vendor' => 'MikroTik', 'is_active' => '1',
        ])->assertRedirect(route('devices.index'));
        $this->assertNull($device->fresh()->ssh_observed_fingerprint);
        $this->post(route('devices.ssh-host-key.trust', $device), ['ssh_host_key_fingerprint' => 'SHA256:'.str_repeat('B', 43)])
            ->assertSessionHasErrors('ssh_host_key');
        $this->assertNull($device->fresh()->ssh_host_key_fingerprint);
    }
}
