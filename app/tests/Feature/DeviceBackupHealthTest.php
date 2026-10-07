<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\DeviceBackupHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceBackupHealthTest extends TestCase
{
    use RefreshDatabase;

    private function deviceWithPolicy(string $suffix, string $scheduleType): Device
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'Device '.$suffix,
            'management_ip' => '192.0.2.'.$suffix, 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Policy '.$suffix, 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => $scheduleType,
            'schedule_time' => $scheduleType !== 'manual' ? '03:00' : null, 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'x';
        $credential->save();
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);

        return $device;
    }

    private function succeed(Device $device, \DateTimeInterface $when): void
    {
        $execution = BackupExecution::create([
            'device_backup_policy_id' => $device->deviceBackupPolicies()->first()->id,
            'backup_policy_id' => $device->deviceBackupPolicies()->first()->backup_policy_id,
            'device_id' => $device->id, 'credential_id' => $device->credentials()->first()->id,
            'origin' => 'manual', 'status' => 'succeeded', 'attempt' => 1, 'finished_at' => $when,
        ]);
        // created_at is not mass-assignable (Eloquent auto-manages timestamps);
        // set the backdated value directly for freshness-window tests.
        DB::table('backup_executions')->where('id', $execution->id)->update(['created_at' => $when]);
    }

    private function failExecution(Device $device): void
    {
        BackupExecution::create([
            'device_backup_policy_id' => $device->deviceBackupPolicies()->first()->id,
            'backup_policy_id' => $device->deviceBackupPolicies()->first()->backup_policy_id,
            'device_id' => $device->id, 'credential_id' => $device->credentials()->first()->id,
            'origin' => 'manual', 'status' => 'failed', 'attempt' => 1,
        ]);
    }

    public function test_recent_scheduled_backup_is_healthy(): void
    {
        $device = $this->deviceWithPolicy('1', 'daily');
        $this->succeed($device, now()->subHours(2));
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['healthy']);
        $this->assertSame([], $summary['problem_devices']);
    }

    public function test_delayed_daily_backup_is_warning(): void
    {
        config()->set('health.device_daily_warning_hours', 30);
        config()->set('health.device_daily_critical_hours', 48);
        $device = $this->deviceWithPolicy('1', 'daily');
        $this->succeed($device, now()->subHours(35));
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['warning']);
        $this->assertSame('backup_delayed', $summary['problem_devices'][0]['reason']);
    }

    public function test_very_stale_daily_backup_is_critical(): void
    {
        config()->set('health.device_daily_critical_hours', 48);
        $device = $this->deviceWithPolicy('1', 'daily');
        $this->succeed($device, now()->subHours(50));
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['critical']);
        $this->assertSame('backup_stale', $summary['problem_devices'][0]['reason']);
    }

    public function test_scheduled_device_that_never_succeeded_is_critical(): void
    {
        $device = $this->deviceWithPolicy('1', 'daily');
        $this->failExecution($device);
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['critical']);
        $this->assertSame('scheduled_never_succeeded', $summary['problem_devices'][0]['reason']);
    }

    public function test_consecutive_failures_are_critical_regardless_of_cadence(): void
    {
        config()->set('health.device_consecutive_failures_critical', 3);
        $device = $this->deviceWithPolicy('1', 'manual');
        $this->succeed($device, now()->subDays(10));
        $this->failExecution($device);
        $this->failExecution($device);
        $this->failExecution($device);
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['critical']);
        $this->assertSame('consecutive_failures', $summary['problem_devices'][0]['reason']);
    }

    public function test_dashboard_graph_counts_each_active_device_with_an_unresolved_failure_once(): void
    {
        $failed = $this->deviceWithPolicy('1', 'daily');
        $healthy = $this->deviceWithPolicy('2', 'daily');
        $inactive = $this->deviceWithPolicy('3', 'daily');
        $inactive->update(['is_active' => false]);
        $this->failExecution($failed);
        $this->failExecution($failed);
        BackupExecution::query()->where('device_id', $failed->id)->latest('id')->firstOrFail()
            ->update(['status' => 'retry_wait']);
        $this->succeed($healthy, now());

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('failedBackupDevices', 1)
            ->assertViewHas('activeWithoutFailure', 1)
            ->assertSee('Falha no backup')
            ->assertDontSee(route('backup-health.index', ['filter' => 'failed']));

        $this->succeed($failed, now());
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('failedBackupDevices', 0)
            ->assertViewHas('activeWithoutFailure', 2);
    }

    public function test_manual_only_device_with_history_is_healthy_regardless_of_age(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $this->succeed($device, now()->subYear());
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['healthy']);
    }

    public function test_expected_ftp_arrival_is_checked_independently_of_recent_ssh_success(): void
    {
        $device = $this->deviceWithPolicy('1', 'daily');
        $device->update(['expected_ftp_interval_hours' => 24]);
        $ftpPolicy = BackupPolicy::create(['name' => 'FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $ftpAssociation = DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $ftpPolicy->id, 'is_active' => true]);

        $ftpExecution = BackupExecution::create(['device_backup_policy_id' => $ftpAssociation->id,
            'backup_policy_id' => $ftpPolicy->id, 'device_id' => $device->id,
            'origin' => 'ftp_received', 'status' => 'succeeded', 'attempt' => 1]);
        DB::table('backup_executions')->where('id', $ftpExecution->id)
            ->update(['created_at' => now()->subHours(32)]);
        $this->succeed($device, now());

        $row = collect(app(DeviceBackupHealth::class)->rows())->firstWhere('device_id', $device->id);
        $this->assertSame('warning', $row['status']);
        $this->assertSame('ftp_backup_delayed', $row['reason']);

        DB::table('backup_executions')->where('id', $ftpExecution->id)
            ->update(['created_at' => now()->subHours(50)]);
        $row = collect(app(DeviceBackupHealth::class)->rows())->firstWhere('device_id', $device->id);
        $this->assertSame('critical', $row['status']);
        $this->assertSame('ftp_backup_stale', $row['reason']);
    }

    public function test_expected_ftp_without_any_receipt_is_critical(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $device->update(['expected_ftp_interval_hours' => 24]);
        $ftpPolicy = BackupPolicy::create(['name' => 'FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $ftpPolicy->id, 'is_active' => true]);

        $row = collect(app(DeviceBackupHealth::class)->rows())->firstWhere('device_id', $device->id);
        $this->assertSame('critical', $row['status']);
        $this->assertSame('ftp_never_received', $row['reason']);
    }

    public function test_manual_device_with_previous_success_and_latest_failure_needs_attention(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $this->succeed($device, now()->subDay());
        $this->failExecution($device);

        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['warning']);
        $this->assertSame('latest_backup_failed', $summary['problem_devices'][0]['reason']);

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('backup-health.index'))->assertOk()->assertSee('Última tentativa falhou');
    }

    public function test_manual_only_device_never_backed_up_is_unknown_not_critical(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['unknown']);
        $this->assertSame('manual_never_backed_up', $summary['problem_devices'][0]['reason']);
    }

    public function test_weekly_cadence_uses_its_own_thresholds(): void
    {
        config()->set('health.device_weekly_warning_hours', 192);
        config()->set('health.device_weekly_critical_hours', 240);
        $device = $this->deviceWithPolicy('1', 'weekly');
        $this->succeed($device, now()->subHours(100));
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['healthy']);
    }

    public function test_no_active_devices_returns_empty_summary(): void
    {
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(['healthy' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0], $summary['counts']);
        $this->assertSame([], $summary['problem_devices']);
    }

    public function test_backup_health_is_available_in_dedicated_page_without_dashboard_panel(): void
    {
        $scheduled = $this->deviceWithPolicy('1', 'daily');
        $manual = $this->deviceWithPolicy('2', 'manual');
        $healthy = $this->deviceWithPolicy('3', 'manual');
        $this->succeed($healthy, now()->subDay());
        $inactive = $this->deviceWithPolicy('4', 'daily');
        $inactive->update(['is_active' => false]);

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Status dos backups')
            ->assertDontSee('Precisam de acompanhamento')
            ->assertViewMissing('backupHealth');

        $this->get(route('backup-health.index'))->assertOk()
            ->assertSee($scheduled->name)
            ->assertSee('Backup agendado nunca concluído')
            ->assertSee($manual->name)
            ->assertSee('Sem histórico')
            ->assertDontSee($healthy->name)
            ->assertDontSee($inactive->name);
    }

    public function test_device_list_uses_existing_health_rows_and_marks_inactive_devices_unassessed(): void
    {
        $active = $this->deviceWithPolicy('1', 'daily');
        $this->succeed($active, now()->subHours(2));
        $inactive = $this->deviceWithPolicy('2', 'daily');
        $inactive->update(['is_active' => false]);

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('devices.index'))->assertOk()
            ->assertDontSee('Policy 1')
            ->assertSee('Coleta via SSH')
            ->assertSee('Saudável')
            ->assertSee('Não avaliado');
    }

    public function test_device_list_method_comes_from_active_policies_instead_of_old_executions(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $this->succeed($device, now()->subDay());
        $oldAssociation = $device->deviceBackupPolicies()->firstOrFail();
        $oldAssociation->update(['is_active' => false, 'archived_at' => now()]);

        $ftpPolicy = BackupPolicy::create(['name' => 'Huawei FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $ftpPolicy->id,
            'credential_id' => null, 'is_active' => true]);

        $row = collect(app(DeviceBackupHealth::class)->rows())->firstWhere('device_id', $device->id);
        $this->assertSame('ftp_push', $row['method']);
        $this->assertSame(['ftp_push'], $row['methods']);
        $this->assertSame('Huawei FTP', $row['policy_name']);
        $this->assertNotNull($row['last_backup_at']);

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('devices.index'))->assertOk()->assertSee('Envio via FTP');
    }

    public function test_device_list_shows_both_active_backup_methods(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $ftpPolicy = BackupPolicy::create(['name' => 'Huawei FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $ftpPolicy->id,
            'credential_id' => null, 'is_active' => true]);

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('devices.index'))->assertOk()
            ->assertSee('Coleta via SSH · Envio via FTP');
    }

    public function test_affected_device_list_includes_devices_beyond_first_page(): void
    {
        foreach (range(1, 21) as $number) {
            $this->deviceWithPolicy((string) $number, 'daily');
        }

        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('backup-health.index'))->assertOk()
            ->assertViewHas('devices', fn ($devices) => $devices->total() === 21 && $devices->count() === 20);
        $this->get(route('backup-health.index', ['page' => 2]))->assertOk()
            ->assertViewHas('devices', fn ($devices) => $devices->total() === 21 && $devices->count() === 1);
    }
}
