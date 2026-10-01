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
use App\Services\BackupRetention;
use App\Services\EngineJobService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private CarbonImmutable $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/retention-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config()->set('backup.storage_root', $this->root);
        $this->clock = CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC');
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

    private function source(?int $days = null, ?int $count = null, ?BackupPolicy $policy = null, ?Device $device = null): DeviceBackupPolicy
    {
        $site = Site::firstOrCreate(['name' => 'Teste'], ['is_active' => true]);
        $device ??= Device::create(['site_id' => $site->id, 'name' => 'Router '.uniqid(), 'management_ip' => '192.0.2.'.(Device::count() + 1), 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy ??= BackupPolicy::create(['name' => 'Policy '.uniqid(), 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_days' => $days, 'retention_count' => $count, 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'secret-never-display';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
    }

    private function artifact(DeviceBackupPolicy $source, int $ageDays, string $status = 'succeeded', bool $validated = true): BackupArtifact
    {
        $date = $this->clock->subDays($ageDays);
        $job = BackupExecution::create(['device_backup_policy_id' => $source->id, 'backup_policy_id' => $source->backup_policy_id,
            'device_id' => $source->device_id, 'credential_id' => $source->credential_id, 'origin' => 'manual',
            'status' => $status, 'attempt' => 1]);
        DB::table('backup_executions')->where('id', $job->id)->update(['created_at' => $date]);
        $job = $job->fresh();
        $relative = app(EngineJobService::class)->relativePath($job);
        if (file_exists($this->root.'/'.$relative)) {
            $relative = substr($relative, 0, strrpos($relative, '.')).'-exec-'.$job->id.'.'.pathinfo($relative, PATHINFO_EXTENSION);
        }
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
            'validated_at' => $validated ? $date : null]);
        DB::table('backup_artifacts')->where('id', $artifact->id)->update(['created_at' => $date]);

        return $artifact->fresh();
    }

    private function retention(bool $apply = false): array
    {
        return app(BackupRetention::class)->run($apply, $this->clock);
    }

    private function path(BackupArtifact $artifact): string
    {
        return $this->root.'/'.$artifact->relative_path;
    }

    public function test_days_keep_in_window_and_protect_last_valid_backup(): void
    {
        $source = $this->source(30);
        $old = $this->artifact($source, 40);
        $summary = $this->retention(true);
        $this->assertSame(1, $summary['protected_latest']);
        $this->assertFileExists($this->path($old));
        $this->assertSame('available', $old->fresh()->status);
        $new = $this->artifact($source, 2);
        $summary = $this->retention(true);
        $this->assertSame(1, $summary['deleted']);
        $this->assertFileDoesNotExist($this->path($old));
        $this->assertFileExists($this->path($new));
        $this->assertSame('retention_days', $old->fresh()->deletion_reason);
        $this->assertSame('succeeded', $old->backupExecution->fresh()->status);
    }

    public function test_existing_numeric_path_remains_eligible_for_retention(): void
    {
        $source = $this->source(30);
        $artifact = $this->artifact($source, 40);
        $job = $artifact->backupExecution;
        $legacy = $job->device_id.'/'.$job->created_at->format('Y/m/d').'/execution-'.$job->id.'-config.rsc';
        $oldPath = $this->path($artifact);
        $legacyPath = $this->root.'/'.$legacy;
        mkdir(dirname($legacyPath), 0700, true);
        rename($oldPath, $legacyPath);
        $artifact->update(['relative_path' => $legacy, 'original_filename' => basename($legacy)]);
        $this->assertSame(1, $this->retention(false)['protected_latest']);
    }

    public function test_count_keeps_newest_versions_and_apply_is_idempotent(): void
    {
        $source = $this->source(count: 2);
        $old = $this->artifact($source, 3);
        $middle = $this->artifact($source, 2);
        $new = $this->artifact($source, 1);
        $this->assertSame(1, $this->retention(true)['deleted']);
        $this->assertDatabaseHas('backup_artifacts', ['id' => $old->id, 'status' => 'deleted', 'deletion_reason' => 'retention_count']);
        $this->assertNotNull($old->fresh()->deleted_at);
        $this->assertFileExists($this->path($middle));
        $this->assertFileExists($this->path($new));
        $this->assertSame(0, $this->retention(true)['deleted']);
    }

    public function test_pages_preserve_valid_rank_with_tied_dates_and_group_updates(): void
    {
        $source = $this->source(count: 3);
        $artifacts = [];
        for ($index = 0; $index < 505; $index++) {
            $artifacts[] = $this->artifact($source, 40);
        }
        unlink($this->path($artifacts[504]));
        file_put_contents($this->path($artifacts[503]), str_repeat('X', $artifacts[503]->size_bytes));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $preview = $this->retention();
        $this->assertSame(500, $preview['candidates']);
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains(strtolower($sql), 'for update')));
        $queries = [];
        $result = $this->retention(true);
        $this->assertSame(505, $result['scanned']);
        $this->assertSame(500, $result['deleted']);
        $this->assertSame(1, $result['missing']);
        $this->assertSame(1, $result['anomalies']);
        $this->assertLessThan(30, count($queries));
        foreach ([500, 501, 502] as $index) {
            $this->assertSame('available', $artifacts[$index]->fresh()->status);
            $this->assertFileExists($this->path($artifacts[$index]));
        }
        $this->assertSame('missing', $artifacts[504]->fresh()->status);
        $this->assertSame('available', $artifacts[503]->fresh()->status);
        $this->assertSame(0, $this->retention(true)['deleted']);
    }

    public function test_huawei_cfg_participates_in_retention(): void
    {
        $source = $this->source(count: 1);
        $source->device->update(['vendor' => 'Huawei']);
        $old = $this->artifact($source, 3);
        $new = $this->artifact($source, 1);
        $this->assertStringEndsWith('.cfg', $old->relative_path);
        $this->assertSame(1, $this->retention(true)['deleted']);
        $this->assertSame('deleted', $old->fresh()->status);
        $this->assertSame('available', $new->fresh()->status);
        $this->assertFileExists($this->path($new));
    }

    public function test_policy_change_between_pages_stops_further_deletion(): void
    {
        $source = $this->source(count: 1);
        $oldest = $this->artifact($source, 40);
        for ($index = 0; $index < 500; $index++) {
            $this->artifact($source, 40);
        }
        $changed = false;
        app('events')->listen(TransactionCommitted::class,
            function () use ($source, &$changed): void {
                if (! $changed) {
                    $changed = true;
                    $source->backupPolicy->update(['retention_count' => 1000]);
                }
            });
        $result = $this->retention(true);
        $this->assertTrue($changed);
        $this->assertSame(499, $result['deleted']);
        $this->assertFileExists($this->path($oldest));
        $this->assertSame('available', $oldest->fresh()->status);
        $this->assertSame(0, $this->retention(true)['deleted']);
    }

    public function test_losing_protected_backup_between_pages_stops_further_deletion(): void
    {
        $source = $this->source(count: 1);
        $oldest = $this->artifact($source, 40);
        for ($index = 0; $index < 500; $index++) {
            $latest = $this->artifact($source, 40);
        }
        $changed = false;
        app('events')->listen(TransactionCommitted::class,
            function () use ($latest, &$changed): void {
                if (! $changed) {
                    $changed = true;
                    unlink($this->path($latest));
                }
            });
        $this->assertSame(499, $this->retention(true)['deleted']);
        $this->assertFileExists($this->path($oldest));
        $this->assertSame('available', $oldest->fresh()->status);
        $this->assertSame(0, $this->retention(true)['deleted']);
    }

    public function test_existing_rsc_remains_retirable_after_vendor_correction(): void
    {
        $source = $this->source(count: 1);
        $old = $this->artifact($source, 3);
        $this->assertStringEndsWith('.rsc', $old->relative_path);
        $source->device->update(['vendor' => 'Huawei']);
        $new = $this->artifact($source, 1);
        $this->assertStringEndsWith('.cfg', $new->relative_path);
        $this->assertSame(1, $this->retention(true)['deleted']);
        $this->assertSame('deleted', $old->fresh()->status);
        $this->assertSame('available', $new->fresh()->status);
    }

    public function test_both_rules_are_union_and_reason_is_exact(): void
    {
        $source = $this->source(30, 2);
        $both = $this->artifact($source, 50);
        $days = $this->artifact($source, 40);
        $count = $this->artifact($source, 4);
        $keep = $this->artifact($source, 1);
        $this->assertSame(2, $this->retention(true)['deleted']);
        $this->assertSame('retention_days_and_count', $both->fresh()->deletion_reason);
        $this->assertSame('retention_days_and_count', $days->fresh()->deletion_reason);
        $this->assertSame('available', $count->fresh()->status);
        $this->assertSame('available', $keep->fresh()->status);
    }

    public function test_devices_policies_and_associations_are_isolated(): void
    {
        $first = $this->source(30);
        $second = $this->source(30, policy: $first->backupPolicy);
        $third = $this->source(30, device: $first->device);
        $a = $this->artifact($first, 40);
        $b = $this->artifact($second, 40);
        $c = $this->artifact($third, 40);
        $this->assertSame(3, $this->retention(true)['protected_latest']);
        foreach ([$a, $b, $c] as $artifact) {
            $this->assertFileExists($this->path($artifact));
        }
    }

    public function test_dry_run_and_default_command_never_change_files_or_lifecycle(): void
    {
        $source = $this->source(30);
        $old = $this->artifact($source, 40);
        $this->artifact($source, 1);
        $this->artisan('backups:retention')->expectsOutputToContain('candidates=1')->assertExitCode(0);
        $this->artisan('backups:retention', ['--dry-run' => true])->assertExitCode(0);
        $this->assertFileExists($this->path($old));
        $this->assertSame('available', $old->fresh()->status);
        $this->assertNull($old->fresh()->deleted_at);
        $this->artisan('backups:retention', ['--dry-run' => true, '--apply' => true])->assertExitCode(1);
    }

    public function test_missing_is_reported_and_preserved_as_history(): void
    {
        $source = $this->source(30);
        $missing = $this->artifact($source, 40);
        unlink($this->path($missing));
        $this->assertSame(1, $this->retention()['missing']);
        $this->assertSame('available', $missing->fresh()->status);
        $this->assertSame(1, $this->retention(true)['missing']);
        $this->assertDatabaseHas('backup_artifacts', ['id' => $missing->id, 'status' => 'missing']);
        $this->assertNotNull($missing->fresh()->missing_at);
    }

    public function test_hash_size_traversal_and_outside_root_block_deletion(): void
    {
        $source = $this->source(30);
        $hash = $this->artifact($source, 50);
        $size = $this->artifact($source, 45);
        $traversal = $this->artifact($source, 40);
        $outside = $this->artifact($source, 35);
        $this->artifact($source, 1);
        file_put_contents($this->path($hash), str_repeat('X', $hash->size_bytes));
        file_put_contents($this->path($size), 'X');
        $traversal->update(['relative_path' => '../escape.rsc']);
        $outsidePath = sys_get_temp_dir().'/outside-retention-'.bin2hex(random_bytes(8));
        file_put_contents($outsidePath, 'sentinel');
        unlink($this->path($outside));
        symlink($outsidePath, $this->path($outside));
        try {
            $result = $this->retention(true);
            $this->assertSame(0, $result['deleted']);
            $this->assertGreaterThanOrEqual(4, $result['anomalies']);
            $this->assertSame('sentinel', file_get_contents($outsidePath));
            $this->assertSame('available', $hash->fresh()->status);
            $this->assertSame('available', $traversal->fresh()->status);
        } finally {
            unlink($outsidePath);
        }
    }

    public function test_non_succeeded_artifacts_are_ignored(): void
    {
        $source = $this->source(30);
        $failed = $this->artifact($source, 40, 'failed');
        $this->artifact($source, 1);
        $this->assertSame(1, $this->retention(true)['scanned']);
        $this->assertFileExists($this->path($failed));
        $this->assertSame('available', $failed->fresh()->status);
    }

    public function test_schema_rejects_an_artifact_without_validation(): void
    {
        $source = $this->source(30);
        $this->expectException(QueryException::class);
        $this->artifact($source, 40, validated: false);
    }

    public function test_logs_and_views_are_sanitized(): void
    {
        Log::spy();
        $source = $this->source(30);
        $old = $this->artifact($source, 40);
        $this->artifact($source, 1);
        $this->retention(true);
        Log::shouldHaveReceived('info')->with('backup_retention', \Mockery::on(function ($context) use ($old) {
            return $context['artifact_id'] === $old->id && ! str_contains(json_encode($context), 'secret-never-display') && ! str_contains(json_encode($context), '/interface');
        }));
        $this->actingAs(User::factory()->create());
        $this->get(route('backup-artifacts.show', $old))->assertOk()->assertSee('Removido')->assertSee('Retenção em dias')->assertDontSee('secret-never-display');
        $this->get(route('backup-executions.show', $old->backupExecution))->assertOk()->assertSee('A execução permanece concluída com sucesso.')->assertDontSee('secret-never-display');
    }

    public function test_renaming_device_or_site_after_the_fact_does_not_break_retention(): void
    {
        // STABILIZATION-1 regression: ArtifactStorage::verify() used to
        // recompute the expected path from the device/site's *current* name —
        // renaming either made every historical artifact of that device
        // "invalid_path" forever, so retention could never delete them again.
        $source = $this->source(30);
        $old = $this->artifact($source, 40);
        $old->backupExecution->device->update(['name' => 'Novo Nome do Equipamento']);
        $old->backupExecution->device->site->update(['name' => 'Novo Nome do Site']);
        $new = $this->artifact($source, 2);
        $summary = $this->retention(true);
        $this->assertSame(0, $summary['anomalies']);
        $this->assertSame(1, $summary['deleted']);
        $this->assertFileDoesNotExist($this->path($old));
        $this->assertFileExists($this->path($new));
    }

    public function test_scheduler_registration_uses_configured_time_and_instance_timezone(): void
    {
        config()->set('backup.retention_time', '05:15');
        DB::table('application_settings')->where('id', 1)->update(['timezone' => 'America/Manaus']);
        $before = collect(Schedule::events())->filter(fn ($event) => str_contains($event->command, 'backups:retention --scheduled'))->count();
        require base_path('routes/console.php');
        $events = collect(Schedule::events())->filter(fn ($event) => str_contains($event->command, 'backups:retention --scheduled'));
        $this->assertCount($before + 1, $events);
        $event = $events->last();
        $this->assertSame('15 5 * * *', $event->expression);
        $this->assertSame('America/Manaus', $event->timezone);
    }

    public function test_retention_requires_preview_and_confirmation_before_enabling(): void
    {
        $this->travelTo($this->clock);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $source = $this->source(30);
        $old = $this->artifact($source, 40);
        $this->artifact($source, 1);

        $this->get(route('settings.edit'))->assertOk()->assertSee('Retenção de backups')->assertSee('Desligada');
        $this->patch(route('settings.retention.update'), ['enabled' => '1', 'confirmed' => '1'])
            ->assertSessionHasErrors('enabled');
        $this->assertDatabaseHas('application_settings', ['id' => 1, 'retention_enabled' => false]);

        $this->post(route('settings.retention.preview'))->assertRedirect(route('settings.edit').'#retention');
        $this->get(route('settings.edit'))->assertOk()->assertSee('Backups que seriam removidos')->assertSee('Conferi a prévia');
        $this->assertFileExists($this->path($old));

        $this->patch(route('settings.retention.update'), ['enabled' => '1'])
            ->assertSessionHasErrors('enabled');
        $this->patch(route('settings.retention.update'), ['enabled' => '1', 'confirmed' => '1'])
            ->assertRedirect(route('settings.edit').'#retention');
        $this->assertDatabaseHas('application_settings', ['id' => 1, 'retention_enabled' => true]);
        $this->assertDatabaseHas('audit_events', ['action' => 'backup_retention.setting_changed']);
        $this->assertFileExists($this->path($old));

        $this->patch(route('settings.retention.update'), ['enabled' => '0'])
            ->assertRedirect(route('settings.edit').'#retention');
        $this->assertDatabaseHas('application_settings', ['id' => 1, 'retention_enabled' => false]);
    }

    public function test_scheduled_cleanup_obeys_setting_and_preserves_latest_backup(): void
    {
        $this->travelTo($this->clock);
        $source = $this->source(30);
        $old = $this->artifact($source, 40);
        $latest = $this->artifact($source, 1);

        $this->artisan('backups:retention', ['--scheduled' => true])->expectsOutput('disabled')->assertExitCode(0);
        $this->assertFileExists($this->path($old));

        DB::table('application_settings')->where('id', 1)->update(['retention_enabled' => true]);
        $this->artisan('backups:retention', ['--scheduled' => true])->expectsOutputToContain('deleted=1')->assertExitCode(0);
        $this->assertFileDoesNotExist($this->path($old));
        $this->assertFileExists($this->path($latest));
    }

    public function test_non_admin_cannot_preview_or_change_retention(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post(route('settings.retention.preview'))->assertForbidden();
        $this->patch(route('settings.retention.update'), ['enabled' => '1', 'confirmed' => '1'])->assertForbidden();
        $this->assertDatabaseHas('application_settings', ['id' => 1, 'retention_enabled' => false]);
    }
}
