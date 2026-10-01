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
use App\Services\ArtifactDeletionService;
use App\Services\ArtifactStorage;
use App\Services\BackupRetention;
use App\Services\EngineJobService;
use App\Support\DestructiveMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArtifactDeletionTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private CarbonImmutable $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/artifact-delete-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config()->set('backup.storage_root', $this->root);
        $this->clock = CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function source(): DeviceBackupPolicy
    {
        $site = Site::firstOrCreate(['name' => 'Teste'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'Router '.uniqid(),
            'management_ip' => '192.0.2.'.(Device::count() + 1), 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Policy '.uniqid(), 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_days' => 30, 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh',
            'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'secret-never-display';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
    }

    private function artifact(DeviceBackupPolicy $source, string $executionStatus = 'succeeded'): BackupArtifact
    {
        $job = BackupExecution::create(['device_backup_policy_id' => $source->id, 'backup_policy_id' => $source->backup_policy_id,
            'device_id' => $source->device_id, 'credential_id' => $source->credential_id, 'origin' => 'manual',
            'status' => $executionStatus, 'attempt' => 1]);
        $relative = app(EngineJobService::class)->relativePath($job);
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $content = "/interface bridge\nadd name=bridge{$job->id}\n";
        file_put_contents($path, $content);
        $artifact = BackupArtifact::create(['backup_execution_id' => $job->id, 'device_id' => $job->device_id,
            'backup_policy_id' => $job->backup_policy_id, 'type' => 'config', 'storage' => 'local',
            'relative_path' => $relative, 'original_filename' => basename($relative),
            'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'validated_at' => $this->clock]);

        return $artifact->fresh();
    }

    private function path(BackupArtifact $artifact): string
    {
        return $this->root.'/'.$artifact->relative_path;
    }

    private function confirmation(BackupArtifact $artifact): string
    {
        return DestructiveMode::confirmationPhrase(DestructiveMode::DELETE, $artifact->original_filename);
    }

    public function test_authorized_roles_can_download_a_verified_artifact(): void
    {
        $artifact = $this->artifact($this->source());

        foreach (['admin', 'operator', 'viewer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('backup-artifacts.download', $artifact))
                ->assertOk()
                ->assertDownload($artifact->original_filename)
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        $this->assertFileExists($this->path($artifact));
    }

    public function test_auditor_cannot_download_an_artifact(): void
    {
        $artifact = $this->artifact($this->source());
        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('backup-artifacts.download', $artifact))->assertForbidden();
        $this->assertFileExists($this->path($artifact));
    }

    public function test_guest_and_inactive_user_cannot_download_an_artifact(): void
    {
        $artifact = $this->artifact($this->source());

        $this->get(route('backup-artifacts.download', $artifact))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->admin()->create(['is_active' => false]));
        $this->get(route('backup-artifacts.download', $artifact))->assertRedirect(route('login'));
        $this->assertFileExists($this->path($artifact));
    }

    public function test_download_rejects_missing_traversal_and_symlink_files(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        $path = $this->path($artifact);
        $content = file_get_contents($path);
        unlink($path);
        $this->get(route('backup-artifacts.download', $artifact))->assertNotFound();

        $outside = $this->root.'/outside.rsc';
        file_put_contents($outside, $content);
        symlink($outside, $path);
        $this->get(route('backup-artifacts.download', $artifact))->assertNotFound();
        unlink($path);

        $artifact->update(['relative_path' => '../outside.rsc']);
        $this->get(route('backup-artifacts.download', $artifact))->assertNotFound();
        $this->assertSame($content, file_get_contents($outside));
    }

    public function test_download_rejects_a_tampered_artifact_without_removing_it(): void
    {
        $artifact = $this->artifact($this->source());
        file_put_contents($this->path($artifact), 'tampered');
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('backup-artifacts.download', $artifact))->assertNotFound();
        $this->assertFileExists($this->path($artifact));
    }

    // AUTH

    public function test_admin_can_delete_artifact(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
            ->assertRedirect(route('backup-artifacts.show', $artifact));

        $this->assertFileDoesNotExist($this->path($artifact));
        $this->assertSame('deleted', $artifact->fresh()->status);
    }

    public function test_operator_cannot_delete_artifact(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
            ->assertForbidden();
        $this->assertFileExists($this->path($artifact));
    }

    public function test_viewer_cannot_delete_artifact(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
            ->assertForbidden();
        $this->assertFileExists($this->path($artifact));
    }

    public function test_auditor_cannot_delete_artifact(): void
    {
        $this->actingAs(User::factory()->auditor()->create());
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
            ->assertForbidden();
        $this->assertFileExists($this->path($artifact));
    }

    public function test_viewer_does_not_see_delete_button(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $artifact = $this->artifact($this->source());

        $this->get(route('backup-artifacts.show', $artifact))->assertOk()
            ->assertDontSee('Excluir artefato')
            ->assertSee('Baixar arquivo');
    }

    public function test_auditor_does_not_see_download_action(): void
    {
        $this->actingAs(User::factory()->auditor()->create());
        $artifact = $this->artifact($this->source());

        $this->get(route('backup-artifacts.show', $artifact))->assertOk()
            ->assertDontSee('Baixar arquivo');
    }

    // PREVIEW

    public function test_preview_shows_expected_data(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());

        $response = $this->get(route('backup-artifacts.show', $artifact));

        $response->assertOk()
            ->assertSee($artifact->original_filename)
            ->assertSee(number_format($artifact->size_bytes))
            ->assertSee($this->confirmation($artifact))
            ->assertSee('Existe');
    }

    public function test_preview_shows_missing_file_warning(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        unlink($this->path($artifact));

        $this->get(route('backup-artifacts.show', $artifact))->assertOk()
            ->assertSee('Ausente')
            ->assertSee('já não existe no storage');
    }

    // DELETE

    public function test_execution_and_device_are_preserved_after_delete(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        $executionId = $artifact->backup_execution_id;
        $deviceId = $artifact->device_id;

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)]);

        $this->assertDatabaseHas('backup_executions', ['id' => $executionId]);
        $this->assertDatabaseHas('devices', ['id' => $deviceId]);
        $this->assertSame('succeeded', BackupExecution::find($executionId)->status);
    }

    public function test_wrong_confirmation_is_rejected_and_file_survives(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => 'EXCLUIR arquivo-errado.rsc'])
            ->assertSessionHasErrors('confirmation');

        $this->assertFileExists($this->path($artifact));
        $this->assertSame('available', $artifact->fresh()->status);
    }

    public function test_missing_file_is_deleted_idempotently(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        unlink($this->path($artifact));

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
            ->assertRedirect(route('backup-artifacts.show', $artifact));

        $this->assertSame('deleted', $artifact->fresh()->status);
        $event = DB::table('audit_events')->where('action', 'backup_artifact.delete')->first();
        $metadata = json_decode($event->metadata, true);
        $this->assertFalse($metadata['file_existed']);
    }

    public function test_already_deleted_artifact_cannot_be_deleted_again(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)]);

        $response = $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)]);

        $response->assertSessionHasErrors('confirmation');
    }

    public function test_running_execution_blocks_deletion(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source(), 'running');

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
            ->assertSessionHasErrors('confirmation');

        $this->assertFileExists($this->path($artifact));
        $this->assertSame('available', $artifact->fresh()->status);
    }

    public function test_csrf_is_enforced_outside_testing_environment(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());

        app()->detectEnvironment(fn () => 'production');
        try {
            $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
                ->assertStatus(419);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->assertFileExists($this->path($artifact));
    }

    // AUDITORIA

    public function test_delete_emits_audit_event_with_expected_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)]);

        $event = DB::table('audit_events')->where('action', 'backup_artifact.delete')->first();
        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame('backup_artifact', $event->resource_type);
        $this->assertSame((string) $artifact->id, $event->resource_id);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame($artifact->backup_execution_id, $metadata['execution_id']);
        $this->assertTrue($metadata['preserved_execution']);
        $this->assertTrue($metadata['file_existed']);
        $this->assertSame($artifact->size_bytes, $metadata['bytes_removed']);
    }

    public function test_audit_metadata_never_contains_secrets(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());

        $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)]);

        $event = DB::table('audit_events')->where('action', 'backup_artifact.delete')->first();
        $this->assertStringNotContainsString('secret-never-display', $event->metadata);
    }

    // SAFETY (path-safety itself is regression-tested against ArtifactStorage in
    // BackupRetentionTest; here we confirm the controller surfaces a blocked
    // preview/delete instead of a raw exception when the artifact is unsafe).

    public function test_tampered_path_blocks_preview_and_delete(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        $artifact->update(['relative_path' => '../escape.rsc']);

        $this->get(route('backup-artifacts.show', $artifact))->assertOk()
            ->assertSee('não passou na validação de segurança');

        $response = $this->delete(route('backup-artifacts.destroy', $artifact), [
            'confirmation' => DestructiveMode::confirmationPhrase(DestructiveMode::DELETE, $artifact->original_filename),
        ]);
        $response->assertSessionHasErrors('confirmation');
        $this->assertSame('available', $artifact->fresh()->status);
    }

    public function test_symlinked_file_blocks_delete(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $artifact = $this->artifact($this->source());
        $outside = sys_get_temp_dir().'/outside-artifact-'.bin2hex(random_bytes(8));
        file_put_contents($outside, 'sentinel');
        unlink($this->path($artifact));
        symlink($outside, $this->path($artifact));

        try {
            $this->delete(route('backup-artifacts.destroy', $artifact), ['confirmation' => $this->confirmation($artifact)])
                ->assertSessionHasErrors('confirmation');
            $this->assertSame('sentinel', file_get_contents($outside));
            $this->assertSame('available', $artifact->fresh()->status);
        } finally {
            unlink($outside);
        }
    }

    // RETENTION — same primitive

    public function test_manual_deletion_and_retention_share_the_same_storage_primitive(): void
    {
        $reflection = new \ReflectionClass(ArtifactDeletionService::class);
        $property = $reflection->getConstructor()->getParameters()[0];
        $this->assertSame(ArtifactStorage::class, $property->getType()->getName());

        $retentionReflection = new \ReflectionClass(BackupRetention::class);
        $retentionProperty = $retentionReflection->getConstructor()->getParameters()[0];
        $this->assertSame(ArtifactStorage::class, $retentionProperty->getType()->getName());
    }

    public function test_artifact_list_filters_by_status_and_device_search(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $available = $this->artifact($this->source());
        $missing = $this->artifact($this->source());
        $available->device->update(['name' => 'ROTEADOR-ALFA']);
        $missing->device->update(['name' => 'ROTEADOR-BETA']);
        $missing->update(['status' => 'missing']);

        $this->get(route('backup-artifacts.index', ['period' => 'all', 'search' => 'ROTEADOR-ALFA']))
            ->assertOk()->assertSee('ROTEADOR-ALFA')->assertDontSee('ROTEADOR-BETA');

        $this->get(route('backup-artifacts.index', ['period' => 'all', 'status' => 'missing']))
            ->assertOk()->assertSee('ROTEADOR-BETA')->assertDontSee('ROTEADOR-ALFA');
    }
}
