<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\IntegrationToken;
use App\Models\Site;
use App\Models\User;
use App\Services\IntegrationApiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(IntegrationApiSettings::class)->save(true, ['127.0.0.1/32']);
        $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1']);
    }

    public function test_api_is_disabled_by_default_and_web_login_still_works(): void
    {
        app(IntegrationApiSettings::class)->save(false, ['127.0.0.1/32']);

        $this->getJson('/api/v1/health')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_https_ip_token_scope_expiry_and_revocation_are_enforced_and_audited(): void
    {
        [$plain, $token] = IntegrationToken::issue('Provider Ops', 30);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertNotSame($plain, $token->token_hash);

        $this->getJson('/api/v1/summary')->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/v1/summary')->assertUnauthorized();
        $this->getJson('/api/v1/summary?token='.$plain)->assertUnauthorized();
        $this->withServerVariables(['HTTPS' => 'off', 'SERVER_PORT' => 80, 'REMOTE_ADDR' => '127.0.0.1']);
        $this->withToken($plain)->getJson('http://localhost/api/v1/summary')->assertForbidden()->assertJsonPath('error.code', 'https_required');
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.20']);
        $this->getJson('https://localhost/api/v1/summary')->assertForbidden()->assertJsonPath('error.code', 'ip_not_allowed');
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '127.0.0.1']);

        $this->getJson('/api/v1/summary')->assertOk()->assertJsonPath('data.sites', 0);
        $token->forceFill(['scope' => 'write'])->save();
        $this->getJson('/api/v1/summary')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $token->forceFill(['scope' => IntegrationToken::SCOPE_READ, 'expires_at' => now()->subMinute()])->save();
        $this->getJson('/api/v1/summary')->assertUnauthorized();
        $token->forceFill(['expires_at' => now()->addDay()])->save();
        $token->revoke();
        $this->getJson('/api/v1/summary')->assertUnauthorized();

        $this->assertGreaterThanOrEqual(6, DB::table('audit_events')->where('action', 'api.integration.read')->count());
        $this->assertDatabaseMissing('audit_events', ['resource_label' => $plain]);
    }

    public function test_read_endpoints_paginate_and_never_return_secrets_or_backup_contents(): void
    {
        [$plain] = IntegrationToken::issue('Provider Ops', 30);
        $this->withToken($plain);
        $this->postJson('/api/v1/devices', ['name' => 'Não permitido'])->assertStatus(405);
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        foreach (range(1, 3) as $number) {
            Device::create([
                'site_id' => $site->id, 'name' => 'Router '.$number,
                'management_ip' => '192.0.2.'.$number, 'vendor' => 'MikroTik',
                'notes' => 'never-return-device-notes', 'is_active' => true,
            ]);
        }
        $device = Device::query()->firstOrFail();
        $policy = BackupPolicy::create(['name' => 'SSH', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'never-return-credential-secret';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
        foreach (['succeeded', 'succeeded', 'failed'] as $status) {
            $execution = BackupExecution::create([
                'device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id,
                'device_id' => $device->id, 'credential_id' => $credential->id,
                'origin' => 'manual', 'status' => $status, 'attempt' => 1,
            ]);
            $execution->forceFill(['error_code' => $status === 'failed' ? 'SSH_TIMEOUT' : null,
                'error_message' => 'never-return-error-message'])->save();
            if ($status === 'succeeded') {
                BackupArtifact::create([
                    'device_id' => $device->id, 'backup_policy_id' => $policy->id,
                    'backup_execution_id' => $execution->id, 'type' => 'config', 'storage' => 'local',
                    'relative_path' => 'never-return-backup-path-'.$execution->id,
                    'original_filename' => 'never-return-filename.cfg', 'size_bytes' => 123,
                    'sha256' => str_repeat('a', 64), 'validated_at' => now(), 'status' => 'available',
                ]);
            }
        }

        $this->getJson('/api/v1/devices?per_page=2&page=2&token=never-return-query-token')
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data')
            ->assertDontSee('never-return-query-token');
        $this->getJson('/api/v1/backups/recent?per_page=1&page=2')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/backups/recent?per_page=1&period=30d&token=never-return-query-token')
            ->assertOk()->assertSee('period=30d')->assertDontSee('never-return-query-token');
        $this->getJson('/api/v1/failures?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.error_code', 'SSH_TIMEOUT');
        $this->getJson('/api/v1/summary')->assertOk()->assertJsonPath('data.devices', 3);
        $this->getJson('/api/v1/health')->assertOk()->assertJsonStructure(['data' => ['status', 'checked_at']]);

        foreach (['devices', 'backups/recent', 'failures', 'summary', 'health'] as $endpoint) {
            $body = $this->getJson('/api/v1/'.$endpoint)->assertOk()->getContent();
            foreach (['never-return-credential-secret', 'never-return-error-message', 'never-return-backup-path',
                'never-return-filename', 'never-return-device-notes', $plain, 'token_hash'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->getJson('/api/v1/devices?per_page=101')->assertUnprocessable();
    }

    public function test_rate_limit_and_token_commands(): void
    {
        Artisan::call('integration:token-issue', ['name' => 'Provider Ops', '--days' => 30]);
        preg_match('/bmiv1_[a-f0-9]{64}/', Artisan::output(), $matches);
        $this->assertCount(1, $matches);
        $plain = $matches[0];
        $token = IntegrationToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($token));

        $this->withToken($plain);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson('/api/v1/devices')->assertOk();
        }
        $this->getJson('/api/v1/devices')->assertStatus(429);
        Artisan::call('integration:token-revoke', ['id' => $token->id]);
        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_admin_can_configure_api_from_settings_and_revoke_token(): void
    {
        app(IntegrationApiSettings::class)->save(false, []);
        $this->actingAs(User::factory()->admin()->create());
        $settings = app(IntegrationApiSettings::class);
        $page = $this->get(route('settings.edit'))->assertOk()->assertSee('API de integração')->assertSee('Desativada');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $panel = (new \DOMXPath($document))->query('//details[@id="integration-api"]')->item(0);
        $this->assertNotNull($panel);
        $this->assertFalse($panel->hasAttribute('open'));

        $this->put(route('settings.integration-api.update'), [
            'enabled' => '1', 'integration_api_allowed_ips' => '192.0.2.10',
        ])->assertSessionHasErrors('integration_api_allowed_ips');
        $page = $this->get(route('settings.edit'))->assertOk();
        @$document->loadHTML($page->getContent());
        $this->assertTrue((new \DOMXPath($document))->query('//details[@id="integration-api"]')->item(0)->hasAttribute('open'));
        $this->assertFalse($settings->enabled());

        $response = $this->post(route('settings.integration-api.tokens.issue'), [
            'name' => 'Sistema de operações', 'days' => 30,
        ])->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        preg_match('/bmiv1_[a-f0-9]{64}/', $response->getContent(), $matches);
        $this->assertCount(1, $matches);
        $plain = $matches[0];
        $token = IntegrationToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        $this->get(route('settings.edit'))->assertOk()->assertDontSee($plain);
        $this->put(route('settings.integration-api.update'), [
            'enabled' => '1', 'integration_api_allowed_ips' => "127.0.0.1\n192.0.2.10",
        ])->assertRedirect();
        $this->assertTrue($settings->enabled());
        $this->assertSame(['127.0.0.1/32', '192.0.2.10/32'], $settings->get()['allowed_ips']);
        $this->withToken($plain)->getJson('/api/v1/health')->assertOk();

        $this->delete(route('settings.integration-api.tokens.revoke', $token))->assertRedirect();
        $this->assertFalse($settings->enabled());
        $this->getJson('/api/v1/health')->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'api.integration.settings_changed']);
    }

    public function test_only_admin_can_manage_api_and_ip_list_is_validated(): void
    {
        app(IntegrationApiSettings::class)->save(false, []);
        $this->actingAs(User::factory()->operator()->create());
        $this->put(route('settings.integration-api.update'), ['enabled' => '1', 'integration_api_allowed_ips' => '192.0.2.10'])
            ->assertForbidden();
        $this->post(route('settings.integration-api.tokens.issue'), ['name' => 'Teste', 'days' => 30])
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->withServerVariables(['HTTPS' => 'off', 'SERVER_PORT' => 80, 'REMOTE_ADDR' => '127.0.0.1']);
        $this->post('http://localhost/settings/integration-api/tokens', ['name' => 'Teste', 'days' => 30])
            ->assertSessionHasErrors('integration_token');
        $this->assertDatabaseCount('integration_tokens', 0);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '127.0.0.1']);
        foreach (['0.0.0.0/0', '2001:db8::1', 'invalid'] as $address) {
            $this->put(route('settings.integration-api.update'), ['enabled' => '0', 'integration_api_allowed_ips' => $address])
                ->assertSessionHasErrors('integration_api_allowed_ips');
        }
        $this->assertSame([], app(IntegrationApiSettings::class)->get()['allowed_ips']);
    }
}
