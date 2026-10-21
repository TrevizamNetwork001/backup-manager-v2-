<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\EngineJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MikrotikFtpTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.61',
            'vendor' => 'MikroTik', 'platform' => 'network', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'ZTE e MikroTik FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_days' => 30, 'is_active' => true]);
        $account = new FtpAccount(['device_id' => $device->id, 'account_uuid' => (string) Str::uuid(),
            'home_layout' => 'account', 'purpose' => 'backup', 'username' => 'mkbackup', 'is_active' => true]);
        $account->secret = 'SyntheticPassword123!';
        $account->provisioned_at = now();
        $account->save();

        return [$device, $policy, $account];
    }

    public function test_mikrotik_and_zte_require_their_own_ftp_policies(): void
    {
        [$device, $policy, $account] = $this->fixture();
        $this->assertTrue($device->isBackupFtpEligible());
        $this->assertFalse($device->isHuaweiFtpEligible());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-policies.associations.store', $policy), [
                'device_id' => $device->id, 'is_active' => 1,
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_backup_policies', [
            'device_id' => $device->id, 'backup_policy_id' => $policy->id, 'is_active' => true,
        ]);
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('Conta apta para receber backups.');

        $zte = Device::create(['site_id' => $device->site_id, 'name' => 'ZTE', 'management_ip' => '192.0.2.62',
            'vendor' => 'ZTE', 'platform' => 'olt', 'is_active' => true]);
        $zteAccount = new FtpAccount(['device_id' => $zte->id, 'account_uuid' => (string) Str::uuid(),
            'home_layout' => 'account', 'purpose' => 'backup', 'username' => 'ztebackup', 'is_active' => true]);
        $zteAccount->secret = 'SyntheticPassword123!';
        $zteAccount->provisioned_at = now();
        $zteAccount->save();
        $this->assertTrue($zte->isBackupFtpEligible());
        $this->assertFalse($zte->acceptsFtpPolicy($policy));
        $ztePolicy = BackupPolicy::create(['name' => 'ZTE FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_days' => 30, 'is_active' => true]);
        $this->post(route('backup-policies.associations.store', $policy), [
            'device_id' => $zte->id, 'is_active' => 1,
        ])->assertSessionHasErrors('device_id');
        $worker = str_repeat('a', 32);
        $result = app(EngineJobService::class)->receiveFtp($zte->id, str_repeat('b', 32), 'startrun.sav', time(), $worker);
        $this->assertSame('missing_backup_policy', $result['error_code']);
        $this->post(route('backup-policies.associations.store', $ztePolicy), [
            'device_id' => $zte->id, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $result = app(EngineJobService::class)->receiveFtp($zte->id, str_repeat('c', 32), 'startrun.sav', time(), $worker);
        $this->assertSame('running', $result['status']);
        $this->assertStringEndsWith('.sav', $result['relative_path']);
        $rejected = app(EngineJobService::class)->receiveFtp($zte->id, str_repeat('d', 32), 'unexpected.rsc', time(), $worker);
        $this->assertSame('invalid_file', $rejected['error_code']);
    }

    public function test_rsc_upload_becomes_a_backup_and_binary_upload_is_rejected(): void
    {
        [$device, $policy] = $this->fixture();
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true]);
        $engine = app(EngineJobService::class);
        $worker = str_repeat('c', 32);
        $binary = $engine->receiveFtp($device->id, str_repeat('d', 32), 'mk.backup', time(), $worker);
        $this->assertSame('invalid_file', $binary['error_code']);

        $root = sys_get_temp_dir().'/mikrotik-ftp-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        try {
            foreach ([
                '6' => "# oct/21/2026 17:00:00 by RouterOS 6.49\n/interface bridge\nadd name=bridge1\n",
                '7' => "# 2026-10-21 17:00:00 by RouterOS 7.18\n/interface bridge\nadd name=bridge1\n",
            ] as $version => $contents) {
                $filename = 'mk-routeros-'.$version.'.rsc';
                $received = $engine->receiveFtp($device->id, bin2hex(random_bytes(16)), $filename, time(), $worker);
                $this->assertSame('running', $received['status']);
                $relative = substr($received['relative_path'], 0, -4).'-exec-'.$received['id'].'.rsc';
                $path = $root.'/'.$relative;
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                file_put_contents($path, $contents);
                $artifact = $engine->complete($received['id'], $relative, $worker);
                $this->assertSame('succeeded', BackupExecution::findOrFail($received['id'])->status);
                $this->assertSame($filename, $artifact->original_filename);
                $this->assertSame(hash('sha256', $contents), $artifact->sha256);
                $this->assertSame('config', $artifact->type);
            }
            $this->assertSame(2, BackupArtifact::query()->count());
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    public function test_rsc_extension_alone_does_not_make_invalid_content_a_backup(): void
    {
        [$device, $policy] = $this->fixture();
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true]);
        $engine = app(EngineJobService::class);
        $root = sys_get_temp_dir().'/mikrotik-invalid-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        try {
            $received = $engine->receiveFtp($device->id, str_repeat('f', 32), 'fake.rsc', time(), str_repeat('a', 32));
            $path = $root.'/'.$received['relative_path'];
            mkdir(dirname($path), 0700, true);
            file_put_contents($path, "This is not a RouterOS export.\n");

            $this->expectException(ValidationException::class);
            $engine->complete($received['id'], $received['relative_path'], str_repeat('a', 32));
        } finally {
            $this->assertSame(0, BackupArtifact::query()->count());
            exec('rm -rf '.escapeshellarg($root));
        }
    }
}
