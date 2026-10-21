<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\MikrotikFtpBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MikrotikFtpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('backup.ftp_host', '192.0.2.80');
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create([
            'site_id' => $site->id, 'name' => 'MK-BORDA-01', 'management_ip' => '192.0.2.61',
            'vendor' => 'MikroTik', 'platform' => 'network', 'os_version' => '6.x', 'is_active' => true,
        ]);
        $device->forceFill([
            'ssh_host_key_algorithm' => 'ssh-rsa',
            'ssh_host_key_fingerprint' => 'SHA256:'.str_repeat('a', 43),
        ])->save();
        $sshPolicy = BackupPolicy::create([
            'name' => 'MK SSH', 'method' => 'ssh_pull', 'artifact_mode' => 'config',
            'schedule_type' => 'daily', 'schedule_time' => '03:00:00', 'is_active' => true,
        ]);
        $ftpPolicy = BackupPolicy::create([
            'name' => 'ZTE e MikroTik FTP', 'method' => 'ftp_push', 'artifact_mode' => 'config',
            'schedule_type' => 'manual', 'is_active' => true,
        ]);
        $credential = new Credential([
            'device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh',
            'username' => 'admin', 'port' => 22, 'is_active' => true,
        ]);
        $credential->secret = 'SyntheticSshPass123!';
        $credential->save();
        DeviceBackupPolicy::create([
            'device_id' => $device->id, 'backup_policy_id' => $sshPolicy->id,
            'credential_id' => $credential->id, 'is_active' => true,
        ]);
        $ftpAssociation = DeviceBackupPolicy::create([
            'device_id' => $device->id, 'backup_policy_id' => $ftpPolicy->id,
            'credential_id' => null, 'is_active' => true,
        ]);
        $account = new FtpAccount([
            'device_id' => $device->id, 'account_uuid' => (string) Str::uuid(),
            'home_layout' => 'account', 'purpose' => 'backup', 'username' => 'mkbackup', 'is_active' => true,
        ]);
        $account->secret = 'SyntheticFtpPass123!';
        $account->provisioned_at = now();
        $account->save();

        return [$device, $ftpPolicy, $ftpAssociation, $account];
    }

    public function test_one_button_installs_tests_then_disappears_only_after_validated_backup(): void
    {
        [$device, $policy, $association, $account] = $this->fixture();
        $bridge = Mockery::mock(MikrotikFtpBridge::class);
        $bridge->shouldReceive('run')->once()->withArgs(fn ($payload) => $payload['action'] === 'install_test' && $payload['schedule_time'] === '23:00' &&
            $payload['ftp_secret'] === 'SyntheticFtpPass123!' && $payload['ssh_secret'] === 'SyntheticSshPass123!'
        )->andReturn(['success' => true, 'code' => 'waiting_receipt', 'filename' => 'bm-v2-test-1-abcdef123456.rsc']);
        $bridge->shouldReceive('run')->once()->withArgs(fn ($payload) => $payload['action'] === 'enable')
            ->andReturn(['success' => true, 'code' => 'enabled']);
        $this->app->instance(MikrotikFtpBridge::class, $bridge);
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('ftp.show', $account))->assertOk()
            ->assertSee('Integrar FTP no MikroTik')->assertDontSee('SyntheticFtpPass123!');
        $this->postJson(route('ftp.mikrotik.integrate', $account))->assertOk()->assertJsonPath('status', 'testing')
            ->assertDontSee('SyntheticFtpPass123!');
        $this->postJson(route('ftp.mikrotik.integrate', $account))->assertOk()->assertJsonPath('status', 'in_progress');
        $this->assertNull($account->fresh()->mikrotik_setup_completed_at);

        $execution = BackupExecution::create([
            'device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id,
            'device_id' => $device->id, 'credential_id' => null, 'origin' => 'ftp_received',
            'status' => 'succeeded', 'ftp_account_id' => $account->id,
            'received_filename' => 'bm-v2-test-1-abcdef123456.rsc', 'received_at' => now(),
        ]);
        BackupArtifact::create([
            'backup_execution_id' => $execution->id, 'device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'type' => 'config', 'storage' => 'local',
            'relative_path' => 'mikrotik/integration-test.rsc', 'original_filename' => $execution->received_filename,
            'size_bytes' => 50, 'sha256' => str_repeat('a', 64), 'validated_at' => now(),
        ]);

        $this->postJson(route('ftp.mikrotik.status', $account))->assertOk()->assertJsonPath('status', 'completed');
        $this->get(route('ftp.show', $account))->assertOk()
            ->assertSee('Envio diário às 23:00 ativado no MikroTik')->assertDontSee('Integrar FTP no MikroTik');
        $this->postJson(route('ftp.mikrotik.integrate', $account))->assertOk()->assertJsonPath('status', 'completed');
        $this->assertNotNull($account->fresh()->mikrotik_setup_completed_at);
    }

    public function test_failed_ssh_preflight_allows_retry_without_claiming_success(): void
    {
        [, , , $account] = $this->fixture();
        $bridge = Mockery::mock(MikrotikFtpBridge::class);
        $bridge->shouldReceive('run')->once()->andReturn(['success' => false, 'code' => 'LEGACY_INTEGRATION_PRESENT']);
        $this->app->instance(MikrotikFtpBridge::class, $bridge);
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson(route('ftp.mikrotik.integrate', $account))->assertOk()->assertJsonPath('status', 'failed')
            ->assertDontSee('SyntheticFtpPass123!');
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('Integrar FTP no MikroTik');
        $this->assertNull($account->fresh()->mikrotik_setup_completed_at);
    }

    public function test_viewer_cannot_start_or_poll_integration(): void
    {
        [, , , $account] = $this->fixture();
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('ftp.show', $account))->assertOk()->assertDontSee('Integrar FTP no MikroTik');
        $this->postJson(route('ftp.mikrotik.integrate', $account))->assertForbidden();
        $this->postJson(route('ftp.mikrotik.status', $account))->assertForbidden();
    }

    public function test_rejected_receipt_does_not_complete_integration(): void
    {
        [, , , $account] = $this->fixture();
        $account->forceFill([
            'mikrotik_setup_status' => 'testing', 'mikrotik_setup_started_at' => now()->subMinute(),
            'mikrotik_test_filename' => 'bm-v2-test-1-failed.rsc', 'mikrotik_schedule_time' => '23:00',
        ])->save();
        DB::table('ftp_received_files')->insert([
            'ftp_account_id' => $account->id, 'claim_token' => str_repeat('a', 32),
            'original_filename' => 'bm-v2-test-1-failed.rsc', 'status' => 'quarantined',
            'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson(route('ftp.mikrotik.status', $account))->assertOk()->assertJsonPath('status', 'failed');
        $this->assertNull($account->fresh()->mikrotik_setup_completed_at);
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('Integrar FTP no MikroTik');
    }

    public function test_rotating_ftp_password_reopens_integration(): void
    {
        [, , , $account] = $this->fixture();
        $account->forceFill([
            'mikrotik_setup_status' => 'completed', 'mikrotik_setup_completed_at' => now(),
            'mikrotik_schedule_time' => '23:00',
        ])->save();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('ftp.rotate', $account), [
            'password' => 'NewSyntheticPassword123!',
            'password_confirmation' => 'NewSyntheticPassword123!',
        ])->assertOk();
        $this->assertSame('failed', $account->fresh()->mikrotik_setup_status);
        $this->assertSame('CREDENTIAL_ROTATED', $account->fresh()->mikrotik_setup_error);
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('Integrar FTP no MikroTik');
    }

    public function test_stale_installation_reopens_the_button(): void
    {
        [, , , $account] = $this->fixture();
        $account->forceFill([
            'mikrotik_setup_status' => 'running', 'mikrotik_setup_started_at' => now()->subMinutes(6),
        ])->save();
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson(route('ftp.mikrotik.status', $account))->assertOk()->assertJsonPath('status', 'failed');
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('Integrar FTP no MikroTik');
    }
}
