<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        $site = Site::create(['name' => 'POP Teste', 'is_active' => true]);

        return Device::create([
            'site_id' => $site->id,
            'name' => 'Roteador Teste',
            'management_ip' => '192.0.2.15',
            'vendor' => 'Generic',
            'is_active' => true,
        ]);
    }

    private function payload(Device $device, array $changes = []): array
    {
        return array_replace([
            'device_id' => $device->id,
            'name' => 'Acesso principal',
            'type' => 'ssh',
            'username' => 'admin',
            'secret' => 'segredo-de-teste-123',
            'port' => '22',
            'notes' => 'Uso interno',
            'is_active' => '1',
        ], $changes);
    }

    private function credential(Device $device): Credential
    {
        $attributes = $this->payload($device);
        $secret = $attributes['secret'];
        unset($attributes['secret']);

        $credential = new Credential($attributes);
        $credential->secret = $secret;
        $credential->save();

        return $credential;
    }

    public function test_guest_cannot_access_credentials(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);

        $this->get('/credentials')->assertRedirect('/login');
        $this->get('/credentials/create')->assertRedirect('/login');
        $this->get("/credentials/{$credential->id}/edit")->assertRedirect('/login');
        $this->post('/credentials', $this->payload($device))->assertRedirect('/login');
        $this->put("/credentials/{$credential->id}", $this->payload($device))->assertRedirect('/login');
        $this->delete("/credentials/{$credential->id}")->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_credentials_and_secret_is_absent(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $this->actingAs(User::factory()->create());

        $this->get('/credentials')->assertOk()
            ->assertSee('Acesso principal')
            ->assertSee('Roteador Teste')
            ->assertDontSee('segredo-de-teste-123');

        $this->get("/credentials/{$credential->id}/edit")->assertOk()
            ->assertSee('Deixe em branco para manter o segredo atual.')
            ->assertSee('name="secret" type="password" value=""', false)
            ->assertDontSee('segredo-de-teste-123');

        $this->assertArrayNotHasKey('secret', $credential->toArray());
        $this->assertStringNotContainsString('segredo-de-teste-123', $credential->toJson());
    }

    public function test_creation_encrypts_secret_and_model_can_recover_it(): void
    {
        $device = $this->device();
        $this->actingAs(User::factory()->create())
            ->post('/credentials', $this->payload($device))
            ->assertRedirect(route('credentials.index'));

        $credential = Credential::firstOrFail();
        $stored = DB::table('credentials')->where('id', $credential->id)->value('secret');

        $this->assertNotSame('segredo-de-teste-123', $stored);
        $this->assertSame('segredo-de-teste-123', $credential->secret);
        $this->assertSame($device->id, $credential->device->id);
        $this->assertCount(1, $device->credentials);
    }

    public function test_secret_is_not_mass_assignable(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);

        $this->assertNotContains('secret', $credential->getFillable());

        $credential->fill(['name' => 'Nome alterado', 'secret' => 'segredo-indevido']);
        $credential->save();

        $this->assertSame('Nome alterado', $credential->fresh()->name);
        $this->assertSame('segredo-de-teste-123', $credential->fresh()->secret);
    }

    public function test_empty_secret_on_update_preserves_previous_secret(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $stored = DB::table('credentials')->where('id', $credential->id)->value('secret');

        $this->actingAs(User::factory()->create())
            ->put("/credentials/{$credential->id}", $this->payload($device, [
                'name' => 'Acesso atualizado',
                'secret' => '',
            ]))
            ->assertRedirect(route('credentials.index'));

        $this->assertSame('segredo-de-teste-123', $credential->fresh()->secret);
        $this->assertSame($stored, DB::table('credentials')->where('id', $credential->id)->value('secret'));
        $this->assertSame('Acesso atualizado', $credential->fresh()->name);
    }

    public function test_new_secret_on_update_replaces_previous_secret(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);

        $this->actingAs(User::factory()->create())
            ->put("/credentials/{$credential->id}", $this->payload($device, [
                'secret' => 'novo-segredo-456',
            ]))
            ->assertRedirect(route('credentials.index'));

        $this->assertSame('novo-segredo-456', $credential->fresh()->secret);
        $this->assertNotSame('novo-segredo-456', DB::table('credentials')->where('id', $credential->id)->value('secret'));
    }

    public function test_invalid_type_and_out_of_range_ports_are_rejected(): void
    {
        $device = $this->device();
        $this->actingAs(User::factory()->create());

        foreach ([
            ['type' => 'http', 'error' => 'type'],
            ['port' => '0', 'error' => 'port'],
            ['port' => '65536', 'error' => 'port'],
        ] as $case) {
            $this->from('/credentials/create')
                ->post('/credentials', $this->payload($device, [
                    array_key_first($case) => reset($case),
                ]))
                ->assertRedirect('/credentials/create')
                ->assertSessionHasErrors($case['error']);
        }

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_validation_does_not_flash_secret_to_session(): void
    {
        $device = $this->device();

        $this->actingAs(User::factory()->create())
            ->from('/credentials/create')
            ->post('/credentials', $this->payload($device, ['type' => 'invalid']))
            ->assertSessionHasErrors('type')
            ->assertSessionMissing('_old_input.secret');
    }

    public function test_deletion_works_and_device_with_credentials_is_protected(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        // Destroy is admin-only (credentials.disable) since ADMIN-3.
        $this->actingAs(User::factory()->admin()->create());

        $this->delete("/devices/{$device->id}")
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('warning');
        $this->assertDatabaseHas('devices', ['id' => $device->id]);

        $this->delete("/credentials/{$credential->id}")
            ->assertRedirect(route('credentials.index'));
        $this->assertDatabaseMissing('credentials', ['id' => $credential->id]);
    }
}
