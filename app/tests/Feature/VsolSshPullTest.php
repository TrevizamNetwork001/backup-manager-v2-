<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\EngineJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * VSOL OLT ended up needing ssh_pull (show running-config), not ftp_push —
 * this hardware's `copy startup-config` only accepts tftp://, which this
 * system does not provision infrastructure for (see docs/CORE_STATUS.md,
 * engine/drivers/vsol_ssh.py). Unlike Huawei OLT (never SSH-managed), VSOL
 * OLT is the one platform=olt exception allowed to use ssh_pull.
 */
class VsolSshPullTest extends TestCase
{
    use RefreshDatabase;

    public function test_huawei_olt_is_still_blocked_from_ssh_pull(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'OLT Huawei', 'management_ip' => '192.0.2.30', 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        $credential = $this->sshCredential($device);
        $policy = BackupPolicy::create(['name' => 'SSH Diario', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-policies.associations.store', $policy), [
                'device_id' => $device->id, 'credential_id' => $credential->id, 'is_active' => 1,
            ])->assertSessionHasErrors('device_id');
    }

    public function test_vsol_olt_can_create_ssh_pull_association_and_complete_a_job(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'OLT VSOL', 'management_ip' => '192.0.2.31', 'vendor' => 'VSOL', 'platform' => 'olt', 'is_active' => true]);
        $credential = $this->sshCredential($device);
        $policy = BackupPolicy::create(['name' => 'SSH Diario VSOL', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-policies.associations.store', $policy), [
                'device_id' => $device->id, 'credential_id' => $credential->id, 'is_active' => 1,
            ])->assertSessionHasNoErrors();

        $association = DeviceBackupPolicy::firstOrFail();
        $execution = BackupExecution::createManual($association);
        $execution->transitionTo('queued');
        $engine = app(EngineJobService::class);
        $engine->claim();

        $relative = $engine->relativePath($execution);
        $this->assertStringEndsWith('.cfg', $relative);
        $root = sys_get_temp_dir().'/vsol-ssh-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        $path = $root.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        try {
            $contents = "hostname OLT-VSOL-LAB\ninterface gpon 0/1\n description uplink\n!\n";
            file_put_contents($path, $contents);
            $artifact = $engine->complete($execution->id, $relative);
            $this->assertSame('succeeded', $execution->fresh()->status);
            $this->assertSame(hash('sha256', $contents), $artifact->sha256);
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

    private function sshCredential(Device $device): Credential
    {
        $credential = new Credential(['device_id' => $device->id, 'name' => 'Gerencia SSH',
            'type' => 'ssh', 'username' => 'backup_manager', 'port' => 22, 'is_active' => true]);
        $credential->secret = 'synthetic-only-secret';
        $credential->save();

        return $credential;
    }
}
