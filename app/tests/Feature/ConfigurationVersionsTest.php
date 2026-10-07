<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\ArtifactStorage;
use App\Services\ConfigurationVersions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationVersionsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/config-versions-'.bin2hex(random_bytes(8));
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

    private function source(): DeviceBackupPolicy
    {
        $site = Site::create(['name' => 'TEST', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'ROUTER', 'management_ip' => '192.0.2.1', 'vendor' => 'Huawei', 'platform' => 'network', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Backup FTP', 'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_days' => 30, 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'test-secret';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
    }

    private function artifact(DeviceBackupPolicy $source, string $at, string $text): BackupArtifact
    {
        $job = BackupExecution::create(['device_backup_policy_id' => $source->id, 'backup_policy_id' => $source->backup_policy_id,
            'device_id' => $source->device_id, 'credential_id' => $source->credential_id,
            'origin' => 'ftp_received', 'status' => 'succeeded', 'attempt' => 1]);
        $time = CarbonImmutable::parse($at, 'UTC');
        $relative = 'Backup Manager/TEST/ROUTER/02-10-2026/ROUTER_'.$time->format('YmdHis').'-exec-'.$job->id.'.cfg';
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $text);
        $artifact = BackupArtifact::create(['backup_execution_id' => $job->id, 'device_id' => $job->device_id,
            'backup_policy_id' => $job->backup_policy_id, 'type' => 'config', 'storage' => 'local',
            'relative_path' => $relative, 'original_filename' => basename($relative), 'size_bytes' => strlen($text),
            'sha256' => hash('sha256', $text), 'validated_at' => $time]);
        $artifact->created_at = $time;
        $artifact->save();

        return $artifact->fresh();
    }

    public function test_captures_within_one_hour_are_grouped_and_text_diff_is_visible(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $source = $this->source();
        $first = $this->artifact($source, '2026-10-02 10:00:00', "interface A\n ip address 192.0.2.1\n");
        $second = $this->artifact($source, '2026-10-02 10:40:00', "interface A\n ip address 192.0.2.2\n");
        $third = $this->artifact($source, '2026-10-02 12:00:00', "interface A\n ip address 192.0.2.3\n");

        $versions = app(ConfigurationVersions::class)->forArtifact($third);
        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions[0]['captures']);
        $this->assertSame(2, $versions[1]['captures']);
        $this->assertSame($second->id, $versions[1]['artifact']->id);

        $this->get(route('backup-artifacts.versions', $third))->assertOk()
            ->assertSee('Capturas agrupadas')->assertSee('ip address 192.0.2.3')
            ->assertSee('ip address 192.0.2.2')->assertDontSee('ip address 192.0.2.1');
        $this->assertFileExists($this->root.'/'.$first->relative_path);
    }

    public function test_guest_cannot_read_configuration_differences(): void
    {
        $artifact = $this->artifact($this->source(), '2026-10-02 10:00:00', "secret-value\n");

        $this->get(route('backup-artifacts.versions', $artifact))->assertRedirect('/login');
    }

    public function test_huawei_zip_with_one_config_file_can_be_compared(): void
    {
        $source = $this->source();
        $first = $this->artifact($source, '2026-10-02 10:00:00', "sysname old\n");
        $second = $this->artifact($source, '2026-10-02 12:00:00', "temporary\n");
        $path = $this->root.'/'.$second->relative_path;
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE));
        $this->assertTrue($zip->addFromString('configuration.cfg', "sysname new\n"));
        $this->assertTrue($zip->close());
        $second->original_filename = 'huawei.zip';
        $second->size_bytes = filesize($path);
        $second->sha256 = hash_file('sha256', $path);
        $second->save();

        $this->assertSame('valid', app(ArtifactStorage::class)->verify($first)['result']);
        $this->assertSame('valid', app(ArtifactStorage::class)->verify($second)['result']);
        $inspection = new \ZipArchive;
        $this->assertTrue($inspection->open($path, \ZipArchive::RDONLY));
        $this->assertSame(1, $inspection->numFiles);
        $this->assertSame("sysname new\n", $inspection->getFromIndex(0));
        $inspection->close();
        $difference = app(ConfigurationVersions::class)->diff($first, $second);
        $this->assertTrue($difference['changed']);
        $this->assertStringContainsString('+sysname new', $difference['text']);
        $this->assertStringContainsString('-sysname old', $difference['text']);
    }
}
