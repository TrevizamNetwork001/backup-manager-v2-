<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\BackupScheduler;
use App\Services\EngineJobService;
use App\Services\InstanceTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BackupSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function association(string $type = 'daily', ?int $weekday = null, string $suffix = '1'): DeviceBackupPolicy
    {
        $site = Site::firstOrCreate(['name' => 'POP Teste'], ['is_active' => true]);
        $device = Device::create([
            'site_id' => $site->id, 'name' => 'Router '.$suffix,
            'management_ip' => '192.0.2.'.$suffix, 'vendor' => 'MikroTik', 'is_active' => true,
        ]);
        $policy = BackupPolicy::create([
            'name' => 'Política '.$suffix, 'method' => 'ssh_pull', 'artifact_mode' => 'config',
            'schedule_type' => $type, 'schedule_time' => $type === 'manual' ? null : '03:00',
            'schedule_weekday' => $weekday, 'retention_days' => 30, 'is_active' => true,
        ]);
        $credential = new Credential([
            'device_id' => $device->id, 'name' => 'SSH '.$suffix,
            'type' => 'ssh', 'username' => 'operator', 'is_active' => true,
        ]);
        $credential->secret = 'secret-never-display';
        $credential->save();

        return DeviceBackupPolicy::create([
            'device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true,
        ]);
    }

    private function runAt(string $utc): int
    {
        return app(BackupScheduler::class)->run(CarbonImmutable::parse($utc, 'UTC'));
    }

    public function test_default_timezone_and_admin_can_save_valid_iana_timezone(): void
    {
        $timezone = app(InstanceTimezone::class);
        $this->assertSame('America/Sao_Paulo', $timezone->get());
        $this->get(route('settings.edit'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(route('settings.edit'))->assertForbidden();
        $this->put(route('settings.update'), ['timezone' => 'UTC'])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('value="America/Sao_Paulo"', false)
            ->assertSee('Brasília, Goiás, Sudeste e Sul')
            ->assertSee('Hora atual');
        $this->put(route('settings.update'), ['timezone' => 'America/Manaus'])->assertRedirect(route('settings.edit'));
        $this->assertSame('America/Manaus', $timezone->get());
        $this->get(route('settings.edit'))->assertOk()->assertSee('Amazonas (AM) — Manaus');
        $this->put(route('settings.update'), ['timezone' => 'UTC'])->assertRedirect(route('settings.edit'));
        $this->get(route('settings.edit'))->assertOk()->assertSee('Fuso atual — UTC');
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->put(route('settings.update'), ['timezone' => 'Invalid/Zone'])->assertSessionHasErrors('timezone');
        $this->assertSame(InstanceTimezone::DEFAULT, app(InstanceTimezone::class)->get());
    }

    public function test_timezone_changes_only_presentation_and_not_stored_timestamps(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $association = $this->association();
        $this->assertSame(1, $this->runAt('2026-09-23 06:02:00'));
        $job = BackupExecution::firstOrFail();
        $stored = DB::table('backup_executions')->where('id', $job->id)->value('scheduled_for');
        $this->get(route('backup-executions.show', $job))->assertSee('23/09/2026 03:00:00');
        $this->put(route('settings.update'), ['timezone' => 'UTC'])->assertRedirect();
        $this->get(route('backup-executions.show', $job))->assertSee('23/09/2026 06:00:00');
        $this->assertSame($stored, DB::table('backup_executions')->where('id', $job->id)->value('scheduled_for'));
        $this->assertSame($association->id, $job->device_backup_policy_id);
    }

    public function test_daily_creates_queued_job_once_inside_grace_with_utc_occurrence(): void
    {
        $association = $this->association();
        $this->assertSame(0, $this->runAt('2026-09-23 05:59:59'));
        $this->assertSame(1, $this->runAt('2026-09-23 06:02:00'));
        $this->assertSame(0, $this->runAt('2026-09-23 06:03:00'));
        $job = BackupExecution::firstOrFail();
        $this->assertSame([$association->id, 'scheduler', 'queued', 1],
            [$job->device_backup_policy_id, $job->origin, $job->status, $job->attempt]);
        $this->assertSame('2026-09-23 06:00:00', $job->scheduled_for->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('backup_executions', 1);
    }

    public function test_a10_schedule_runs_only_when_enabled_and_binary_device_matches(): void
    {
        config()->set('backup.a10_enabled', false);
        $association = $this->association();
        $association->device->update(['vendor' => 'A10 Networks', 'platform' => 'network', 'a10_transfer_interface' => 'management']);
        $association->backupPolicy->update(['method' => 'a10_system', 'artifact_mode' => 'binary']);
        $this->assertSame(0, $this->runAt('2026-09-23 06:02:00'));
        config()->set('backup.a10_enabled', true);
        $this->assertSame(1, $this->runAt('2026-09-23 06:02:00'));
        $this->assertSame('a10_system', BackupExecution::firstOrFail()->backupPolicy->method);
    }

    public function test_daily_outside_grace_does_not_catch_up(): void
    {
        $this->association();
        $this->assertSame(0, $this->runAt('2026-09-23 06:05:00'));
        $this->assertSame(0, $this->runAt('2026-09-24 06:06:00'));
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_weekly_uses_existing_iso_weekday_convention(): void
    {
        $this->association('weekly', 3);
        $this->assertSame(0, $this->runAt('2026-09-22 06:02:00'));
        $this->assertSame(1, $this->runAt('2026-09-23 06:02:00'));
        $this->assertDatabaseCount('backup_executions', 1);
    }

    public function test_grace_window_crosses_local_midnight_for_daily_and_weekly(): void
    {
        $daily = $this->association();
        $weekly = $this->association('weekly', 3, '2');
        $daily->backupPolicy->update(['schedule_time' => '23:59']);
        $weekly->backupPolicy->update(['schedule_time' => '23:59']);
        // Thursday 00:02 local time, three minutes after Wednesday's occurrence.
        $this->assertSame(2, $this->runAt('2026-09-24 03:02:00'));
        $this->assertSame(0, $this->runAt('2026-09-24 03:04:00'));
        $this->assertDatabaseCount('backup_executions', 2);
    }

    public function test_manual_and_each_inactive_component_are_skipped(): void
    {
        $this->association('manual');
        foreach (['backupPolicy', 'device', 'credential', 'association'] as $part) {
            $association = $this->association('daily', null, (string) (DeviceBackupPolicy::count() + 1));
            ($part === 'association' ? $association : $association->$part)->update(['is_active' => false]);
        }
        $this->assertSame(0, $this->runAt('2026-09-23 06:02:00'));
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_same_policy_can_schedule_two_associations(): void
    {
        $first = $this->association();
        $second = $this->association('daily', null, '2');
        $second->update(['backup_policy_id' => $first->backup_policy_id]);
        $this->assertSame(2, $this->runAt('2026-09-23 06:02:00'));
        $this->assertDatabaseCount('backup_executions', 2);
    }

    public function test_database_unique_constraint_rejects_concurrent_duplicate_occurrence(): void
    {
        $this->association();
        $this->runAt('2026-09-23 06:02:00');
        $row = DB::table('backup_executions')->first();
        $duplicate = (array) $row;
        unset($duplicate['id']);
        $this->expectException(QueryException::class);
        DB::table('backup_executions')->insert($duplicate);
    }

    public function test_scheduler_skips_device_with_a_live_manual_execution(): void
    {
        // Lesson from V1 (backup_manager/jobs.py queue_run): reject a duplicate
        // at creation time rather than only relying on claim()'s per-device guard.
        $association = $this->association();
        BackupExecution::createManual($association);
        $this->assertSame(0, $this->runAt('2026-09-23 06:02:00'));
        $this->assertDatabaseCount('backup_executions', 1);
    }

    public function test_manual_creation_rejects_second_live_execution_for_same_device(): void
    {
        $association = $this->association();
        BackupExecution::createManual($association);
        $this->expectException(ValidationException::class);
        BackupExecution::createManual($association);
    }

    public function test_scheduled_job_uses_engine_and_keeps_host_trust_and_stale_recovery(): void
    {
        $this->actingAs(User::factory()->create());
        $this->association();
        $this->runAt('2026-09-23 06:02:00');
        $job = BackupExecution::firstOrFail();
        $this->get(route('backup-executions.index', ['origin' => 'scheduler']))
            ->assertOk()->assertSee(route('backup-executions.show', $job))->assertDontSee('secret-never-display');
        $this->get(route('backup-executions.index', ['origin' => 'manual']))
            ->assertOk()->assertDontSee(route('backup-executions.show', $job));
        $this->get(route('backup-executions.show', $job))
            ->assertOk()->assertSee('Agendado para:')->assertDontSee('secret-never-display');
        $engine = app(EngineJobService::class);
        $claimed = $engine->claim(str_repeat('a', 32));
        $this->assertSame($job->id, $claimed->id);
        $this->assertNull($engine->job($job->id)['ssh_host_key_fingerprint']);
        DB::table('backup_executions')->where('id', $job->id)->update(['heartbeat_at' => now()->subSeconds(301)]);
        $this->assertSame(1, $engine->recoverStale());
        $this->assertSame('ENGINE_STALE', $job->fresh()->error_code);
    }
}
