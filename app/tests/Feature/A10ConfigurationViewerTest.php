<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Phar;
use PharData;
use Tests\TestCase;

class A10ConfigurationViewerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/a10-view-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config()->set('backup.storage_root', $this->root);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function artifact(): BackupArtifact
    {
        $site = Site::create(['name' => 'TEST', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'A10-TEST', 'management_ip' => '192.0.2.10',
            'vendor' => 'A10 Networks', 'platform' => 'network', 'a10_transfer_interface' => 'management', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'A10', 'method' => 'a10_system', 'artifact_mode' => 'binary',
            'schedule_type' => 'manual', 'retention_days' => 30, 'is_active' => true]);
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'is_active' => true]);
        $execution = BackupExecution::create(['device_backup_policy_id' => $association->id,
            'backup_policy_id' => $policy->id, 'device_id' => $device->id,
            'origin' => 'manual', 'status' => 'succeeded', 'attempt' => 1]);

        $inner = new PharData($this->root.'/inner.tar');
        $inner->addFromString('a10data/etc/startup-config.pri', "hostname A10-TEST\nremark <script>alert(1)</script>\n");
        $inner->addFromString('a10data/etc/startup-config.sec', "hostname A10-SECONDARY\n");
        $outer = new PharData($this->root.'/outer.tar');
        $outer->addFile($this->root.'/inner.tar', 'backup_system.tar');
        $outer->compress(Phar::GZ);
        $relative = 'Backup Manager/TEST/A10-TEST/02-10-2026/A10-TEST_20261002190000-exec-'.$execution->id.'.tar.gz';
        $path = $this->root.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        copy($this->root.'/outer.tar.gz', $path);

        return BackupArtifact::create(['backup_execution_id' => $execution->id, 'device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'type' => 'binary', 'storage' => 'local',
            'relative_path' => $relative, 'original_filename' => basename($relative),
            'size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'validated_at' => now()]);
    }

    public function test_authorized_user_can_view_both_configs_without_exposing_html_or_caching(): void
    {
        $artifact = $this->artifact();
        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('backup-artifacts.a10-configuration', [$artifact, 'slot' => 'pri']))
            ->assertOk()->assertSee('hostname A10-TEST')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('backup-artifacts.a10-configuration', [$artifact, 'slot' => 'sec']))
            ->assertOk()->assertSee('hostname A10-SECONDARY');
    }

    public function test_auditor_and_tampered_archive_cannot_read_config(): void
    {
        $artifact = $this->artifact();
        $this->actingAs(User::factory()->create(['role' => 'auditor']));
        $this->get(route('backup-artifacts.a10-configuration', [$artifact, 'slot' => 'pri']))->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create());
        file_put_contents($this->root.'/'.$artifact->relative_path, 'corrupt');
        $this->get(route('backup-artifacts.a10-configuration', [$artifact, 'slot' => 'pri']))->assertNotFound();
    }

    public function test_a10_saved_config_versions_can_be_compared(): void
    {
        $first = $this->artifact();
        $first->created_at = now()->subHours(2);
        $first->save();

        $inner = new PharData($this->root.'/updated-inner.tar');
        $inner->addFromString('a10data/etc/startup-config.pri', "hostname A10-UPDATED\n");
        $outer = new PharData($this->root.'/updated-outer.tar');
        $outer->addFile($this->root.'/updated-inner.tar', 'backup_system.tar');
        $outer->compress(Phar::GZ);
        $execution = BackupExecution::create(['device_backup_policy_id' => $first->backupExecution->device_backup_policy_id,
            'backup_policy_id' => $first->backup_policy_id, 'device_id' => $first->device_id,
            'origin' => 'manual', 'status' => 'succeeded', 'attempt' => 1]);
        $relative = 'Backup Manager/TEST/A10-TEST/02-10-2026/A10-TEST_20261002210000-exec-'.$execution->id.'.tar.gz';
        $path = $this->root.'/'.$relative;
        copy($this->root.'/updated-outer.tar.gz', $path);
        $second = BackupArtifact::create(['backup_execution_id' => $execution->id, 'device_id' => $first->device_id,
            'backup_policy_id' => $first->backup_policy_id, 'type' => 'binary', 'storage' => 'local',
            'relative_path' => $relative, 'original_filename' => basename($relative),
            'size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'validated_at' => now()]);

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('backup-artifacts.versions', $second))->assertOk()
            ->assertSee('A10-UPDATED')->assertSee('A10-TEST')
            ->assertSee('startup-config.pri');
    }
}
