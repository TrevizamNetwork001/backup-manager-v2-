<?php

namespace Tests\Feature;

use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\EngineJobService;
use App\Services\FtpServerSettings;
use App\Services\HuaweiFtpBackupPolicy;
use App\Services\OltFtpWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VSOL OLT FTP push, over the same manual "named test" wizard pattern
 * already validated for Huawei OLT (console access + copy startup-config
 * ftp://... command) — no new engine driver, just a second OLT vendor
 * recognized by Device::isHuaweiFtpEligible(). See docs/CORE_STATUS.md.
 */
class VsolOltFtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_isHuaweiFtpEligible_covers_vsol_olt_but_not_vsol_network(): void
    {
        [$device] = $this->fixture();
        $this->assertTrue($device->isHuaweiFtpEligible());

        $vsolNetwork = Device::create(['site_id' => $device->site_id, 'name' => 'VSOL Switch', 'management_ip' => '192.0.2.20', 'vendor' => 'VSOL', 'platform' => 'network', 'is_active' => true]);
        $this->assertFalse($vsolNetwork->isHuaweiFtpEligible());
    }

    public function test_ftp_account_can_be_created_for_a_vsol_olt(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('devices.ftp-account.store', $device), [
                'username' => 'vsol'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345',
            ])->assertOk()->assertSee('Senha FTP.');

        $this->assertNotNull($device->ftpAccount()->first());
        $this->assertNotNull(app(HuaweiFtpBackupPolicy::class)->active($device->fresh()));
    }

    public function test_wizard_shows_vsol_command_and_reaches_operational_via_named_test(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->admin()->create());
        app(FtpServerSettings::class)->set('192.0.2.99', null, 21);

        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('Configuração manual da OLT VSOL')
            ->assertSee('O comando exato de envio');

        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $execution = $device->fresh()->oltFtpIntegration->testExecution;
        $this->assertSame('queued', $execution->status);
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('copy startup-config ftp://vsol'.$device->id.':&lt;senha FTP&gt;@192.0.2.99/bm-exec-'.$execution->id.'.cfg', false)
            ->assertDontSee('synthetic-only-secret');

        app(EngineJobService::class)->claim();
        $root = sys_get_temp_dir().'/vsol-olt-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        $relative = app(EngineJobService::class)->relativePath($execution);
        $path = $root.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        try {
            file_put_contents($path, "hostname OLT-VSOL-LAB\ninterface gpon 0/1\n!\n");
            app(EngineJobService::class)->complete($execution->id, $relative);
            $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'operational');
            $this->get(route('devices.edit', $device))->assertSee('Integração operacional.');
        } finally {
            unlink($path);
            $dir = dirname($path);
            while ($dir !== $root) {
                rmdir($dir);
                $dir = dirname($dir);
            }
            rmdir($root);
        }
    }

    private function fixture(bool $account = true): array
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'OLT VSOL', 'management_ip' => '192.0.2.10', 'vendor' => 'VSOL', 'platform' => 'olt', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'FTP Push Manual', 'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_count' => 2, 'is_active' => true]);
        if ($account) {
            $ftp = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'vsol'.$device->id, 'is_active' => true]);
            $ftp->secret = 'synthetic-only-secret';
            $ftp->save();
            DB::table('ftp_accounts')->where('id', $ftp->id)->update(['provisioned_at' => now()->addSecond()]);
        }

        return [$device, $policy];
    }
}
