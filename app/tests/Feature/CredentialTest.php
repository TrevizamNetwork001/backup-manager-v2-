<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\SshCredentialProbe;
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
            ->assertSee('a senha atual')
            ->assertSee('name="secret" type="password" value=""', false)
            ->assertSee('data-toggle-credential-secret', false)
            ->assertDontSee('segredo-de-teste-123');

        $this->assertArrayNotHasKey('secret', $credential->toArray());
        $this->assertStringNotContainsString('segredo-de-teste-123', $credential->toJson());
    }

    public function test_saved_secret_is_revealed_only_on_authorized_request_and_is_audited(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $url = route('credentials.secret.reveal', $credential);

        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->viewer()->create())
            ->postJson($url)->assertForbidden();

        $manager = User::factory()->operator()->create();
        $this->actingAs($manager)->postJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('secret', 'segredo-de-teste-123');

        $event = DB::table('audit_events')->where('action', 'credential.secret_revealed')->first();
        $this->assertSame($manager->id, $event->actor_user_id);
        $this->assertSame((string) $credential->id, $event->resource_id);
        $this->assertStringNotContainsString('segredo-de-teste-123', $event->metadata);
    }

    public function test_edit_uses_the_create_modal_layout_and_reopens_after_validation_error(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $this->actingAs(User::factory()->create());
        $editUrl = route('credentials.edit', ['credential' => $credential, 'page' => 1]);

        $this->get($editUrl)->assertOk()
            ->assertSee('id="credential-edit-dialog"', false)
            ->assertSee('class="modal form-create-modal"', false)
            ->assertSee('value="Acesso principal"', false)
            ->assertSee('data-close-credential-edit', false)
            ->assertDontSee('id="credential-create-dialog"', false)
            ->assertDontSee('segredo-de-teste-123');

        $this->from($editUrl)
            ->put(route('credentials.update', ['credential' => $credential, 'page' => 1]), $this->payload($device, [
                'name' => 'Acesso corrigido',
                'type' => 'invalid',
                'secret' => '',
            ]))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('type');

        $this->get($editUrl)->assertOk()
            ->assertSee('id="credential-edit-dialog"', false)
            ->assertSee('value="Acesso corrigido"', false)
            ->assertDontSee('id="credential-create-dialog"', false);
    }

    public function test_ssh_probe_uses_unsaved_form_values_and_keeps_the_credential_secret_private(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $this->actingAs(User::factory()->create());
        $this->mock(SshCredentialProbe::class, function ($mock) use ($device) {
            $mock->shouldReceive('test')->once()
                ->withArgs(fn ($selectedDevice, $username, $secret, $port) => $selectedDevice->id === $device->id && $username === 'novo-usuario' && $secret === 'senha-digitada' && $port === 2222)
                ->andReturn(['success' => true, 'code' => 'ok', 'latency_ms' => 25]);
        });

        $this->postJson(route('credentials.ssh-test'), [
            'device_id' => $device->id, 'type' => 'ssh', 'username' => 'novo-usuario',
            'secret' => 'senha-digitada', 'port' => 2222,
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonMissing(['secret' => 'senha-digitada']);

        $this->assertSame('segredo-de-teste-123', $credential->fresh()->secret);
    }

    public function test_ssh_probe_can_use_the_saved_secret_on_edit_and_rejects_other_types(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $this->actingAs(User::factory()->create());
        $this->mock(SshCredentialProbe::class, function ($mock) use ($device) {
            $mock->shouldReceive('test')->once()
                ->withArgs(fn ($selectedDevice, $username, $secret, $port) => $selectedDevice->id === $device->id && $username === 'admin' && $secret === 'segredo-de-teste-123' && $port === 22)
                ->andReturn(['success' => false, 'code' => 'SSH_AUTH_FAILED', 'latency_ms' => 25]);
        });

        $this->postJson(route('credentials.ssh-test'), [
            'credential_id' => $credential->id, 'device_id' => $device->id,
            'type' => 'ssh', 'username' => 'admin', 'secret' => '',
        ])->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Usuário ou senha SSH incorretos.');

        $this->postJson(route('credentials.ssh-test'), [
            'device_id' => $device->id, 'type' => 'ftp', 'username' => 'admin', 'secret' => 'senha',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_ssh_host_key_management_is_available_from_credentials(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $device->ssh_observed_algorithm = 'ssh-rsa';
        $device->ssh_observed_fingerprint = 'SHA256:'.str_repeat('A', 43);
        $device->save();
        $this->actingAs(User::factory()->operator()->create());

        $this->get(route('credentials.index'))->assertOk()
            ->assertSee('data-open-credential-ssh="'.$credential->id.'"', false)
            ->assertSee('id="credential-ssh-dialog-'.$credential->id.'"', false)
            ->assertSee('Confiar nesta chave observada')
            ->assertDontSee('segredo-de-teste-123');

        $this->post(route('devices.ssh-host-key.trust', $device), ['return_to' => 'credentials'])
            ->assertRedirect(route('credentials.index'))->assertSessionHas('success');
        $this->assertSame($device->ssh_observed_fingerprint, $device->fresh()->ssh_host_key_fingerprint);
    }

    public function test_ssh_test_links_to_the_observed_key_approval_screen(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $device->ssh_observed_algorithm = 'ssh-rsa';
        $device->ssh_observed_fingerprint = 'SHA256:'.str_repeat('A', 43);
        $device->save();
        $this->actingAs(User::factory()->operator()->create());
        $this->mock(SshCredentialProbe::class, function ($mock) {
            $mock->shouldReceive('test')->once()
                ->andReturn(['success' => false, 'code' => 'SSH_HOST_KEY_UNKNOWN', 'latency_ms' => 20]);
        });

        $url = route('credentials.edit', ['credential' => $credential, 'ssh_security' => $credential->id]);
        $this->postJson(route('credentials.ssh-test'), [
            'credential_id' => $credential->id, 'device_id' => $device->id,
            'type' => 'ssh', 'username' => $credential->username,
        ])->assertOk()->assertJsonPath('trust_url', $url);

        $this->get($url)->assertOk()
            ->assertSee('id="credential-ssh-dialog-'.$credential->id.'"', false)
            ->assertSee('Confiar nesta chave observada')
            ->assertDontSee('segredo-de-teste-123');
    }

    public function test_trusting_the_key_returns_to_the_credential_test_modal(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $device->ssh_observed_algorithm = 'ssh-rsa';
        $device->ssh_observed_fingerprint = 'SHA256:'.str_repeat('A', 43);
        $device->save();
        $this->actingAs(User::factory()->operator()->create());

        $url = route('credentials.edit', ['credential' => $credential, 'ssh_test' => 1]);
        $this->post(route('devices.ssh-host-key.trust', $device), [
            'return_to' => 'credential_test', 'credential_id' => $credential->id,
        ])->assertRedirect($url)
            ->assertSessionHas('success', 'Chave SSH confiada. Faça agora o teste da conexão SSH.');

        $this->get($url)->assertOk()
            ->assertSee('Faça agora o teste da conexão SSH.')
            ->assertSee('data-run-credential-ssh-test', false)
            ->assertDontSee('segredo-de-teste-123');
        $this->assertSame($device->ssh_observed_fingerprint, $device->fresh()->ssh_host_key_fingerprint);
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

    public function test_new_credentials_offer_ssh_and_telnet_only(): void
    {
        $device = $this->device();
        $this->actingAs(User::factory()->create());

        $this->get(route('credentials.index'))->assertOk()
            ->assertSee('<option value="ssh"', false)
            ->assertSee('<option value="telnet"', false)
            ->assertDontSee('<option value="ftp"', false)
            ->assertDontSee('<option value="sftp"', false)
            ->assertDontSee('<option value="api"', false);

        $this->post(route('credentials.store'), $this->payload($device, ['type' => 'telnet']))
            ->assertRedirect(route('credentials.index'));
        $this->assertDatabaseHas('credentials', ['device_id' => $device->id, 'type' => 'telnet']);

        foreach (['ftp', 'sftp', 'api'] as $type) {
            $this->post(route('credentials.store'), $this->payload($device, ['type' => $type]))
                ->assertSessionHasErrors('type');
        }
        $this->assertDatabaseCount('credentials', 1);
    }

    public function test_existing_legacy_credential_keeps_its_type_when_edited(): void
    {
        $device = $this->device();
        $credential = $this->credential($device);
        $credential->type = 'ftp';
        $credential->save();
        $this->actingAs(User::factory()->create());

        $this->get(route('credentials.edit', $credential))->assertOk()
            ->assertSee('<option value="ftp" selected>FTP (legado)</option>', false);

        $this->put(route('credentials.update', $credential), $this->payload($device, [
            'type' => 'ftp', 'name' => 'Acesso legado', 'secret' => '',
        ]))->assertRedirect(route('credentials.index'));

        $this->assertSame('ftp', $credential->fresh()->type);
        $this->assertSame('Acesso legado', $credential->fresh()->name);
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
