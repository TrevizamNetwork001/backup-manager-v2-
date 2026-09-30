<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
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
 * FTP push, previously OLT-only, is now also available to Huawei network
 * devices (router/switch) as an alternative to SSH pull — VRP firmware can
 * push its own saved-configuration via `set save-configuration
 * backup-to-server` (FEATURES-FINAL "validar fluxo FTP em roteador/switch
 * Huawei"). This mirrors HuaweiOltFtpTest but for platform=network, and the
 * wizard's "wait for the next automatic push" variant of the test step
 * (OltFtpWizard::snapshot() — VRP has no on-demand "send now with this
 * filename" command, unlike the OLT's manual `ftp set` + `backup
 * configuration` console flow).
 */
class HuaweiNetworkFtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_isHuaweiFtpEligible_covers_network_and_olt_but_not_other_vendors(): void
    {
        [$device] = $this->fixture();
        $this->assertTrue($device->isHuaweiFtpEligible());

        $olt = Device::create(['site_id' => $device->site_id, 'name' => 'OLT', 'management_ip' => '192.0.2.20', 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        $this->assertTrue($olt->isHuaweiFtpEligible());

        $mikrotik = Device::create(['site_id' => $device->site_id, 'name' => 'MK', 'management_ip' => '192.0.2.30', 'vendor' => 'MikroTik', 'platform' => 'network', 'is_active' => true]);
        $this->assertFalse($mikrotik->isHuaweiFtpEligible());
    }

    public function test_ftp_account_can_be_created_for_a_network_device(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('devices.ftp-account.store', $device), [
                'username' => 'bmdev'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345',
            ])->assertOk()->assertSee('Senha FTP.');

        $this->assertNotNull($device->ftpAccount()->first());
        $this->assertNotNull(app(HuaweiFtpBackupPolicy::class)->active($device->fresh()->load('deviceBackupPolicies.backupPolicy')));
    }

    public function test_mikrotik_network_device_is_still_rejected_from_ftp_account_creation(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.40', 'vendor' => 'MikroTik', 'platform' => 'network', 'is_active' => true]);
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('devices.ftp-account.store', $device), [
                'username' => 'bmdev'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345',
            ])->assertStatus(422);
    }

    public function test_receive_and_complete_flow_works_for_a_network_device(): void
    {
        [$device] = $this->fixture();
        app(HuaweiFtpBackupPolicy::class)->ensure($device);
        $engine = app(EngineJobService::class);
        $root = sys_get_temp_dir().'/network-ftp-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        try {
            $worker = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(16));
            $data = "some VRP export the wizard can't parse but must still store\n";
            $received = $engine->receiveFtp($device->id, $token, 'AR2220_auto_20260930.cfg', time(), $worker);
            $this->assertSame('running', $received['status']);
            $path = $root.'/'.$received['relative_path'];
            mkdir(dirname($path), 0700, true);
            file_put_contents($path, $data);
            $artifact = $engine->complete($received['id'], $received['relative_path'], $worker);
            $this->assertSame('succeeded', BackupExecution::findOrFail($received['id'])->status);
            $this->assertSame('AR2220_auto_20260930.cfg', $artifact->original_filename);
            $this->assertNotNull($artifact->validated_at);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    public function test_wizard_reaches_operational_by_watching_for_the_next_spontaneous_push_not_a_named_test(): void
    {
        [$device] = $this->fixture();
        app(FtpServerSettings::class)->set('ftp.example.test', null, 21);
        DB::table('ftp_accounts')->where('device_id', $device->id)->update(['provisioned_at' => now(), 'sync_error' => null]);
        $wizard = app(OltFtpWizard::class);

        $wizard->confirm($device->fresh());
        $snapshot = $wizard->snapshot($device->fresh());
        $this->assertTrue($snapshot['confirmed']);
        $this->assertNull($snapshot['execution']);
        $this->assertSame(5, $snapshot['current_step']);

        // No on-demand "startTest" for network devices — VRP has no
        // "send now with this exact filename" command.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $wizard->startTest($device->fresh());
    }

    public function test_wizard_turns_operational_once_a_spontaneous_push_is_validated(): void
    {
        [$device] = $this->fixture();
        app(FtpServerSettings::class)->set('ftp.example.test', null, 21);
        DB::table('ftp_accounts')->where('device_id', $device->id)->update(['provisioned_at' => now(), 'sync_error' => null]);
        $wizard = app(OltFtpWizard::class);
        $wizard->confirm($device->fresh());

        $engine = app(EngineJobService::class);
        $root = sys_get_temp_dir().'/network-wizard-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        try {
            $worker = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(16));
            $received = $engine->receiveFtp($device->id, $token, 'switch-auto.cfg', time(), $worker);
            $path = $root.'/'.$received['relative_path'];
            mkdir(dirname($path), 0700, true);
            file_put_contents($path, "auto push content\n");
            $engine->complete($received['id'], $received['relative_path'], $worker);

            $snapshot = $wizard->snapshot($device->fresh());
            $this->assertTrue($snapshot['operational']);
            $this->assertSame(6, $snapshot['current_step']);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    private function fixture(bool $account = true): array
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'Switch', 'management_ip' => '192.0.2.10', 'vendor' => 'Huawei', 'platform' => 'network', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Huawei FTP', 'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_count' => 2, 'is_active' => true]);
        if ($account) {
            $ftp = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
            $ftp->secret = 'synthetic-only-secret';
            $ftp->save();
            DB::table('ftp_accounts')->where('id', $ftp->id)->update(['provisioned_at' => now()->addSecond()]);
        }

        return [$device, $policy];
    }
}
