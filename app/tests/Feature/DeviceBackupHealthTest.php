<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
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

    public function test_manual_only_device_with_history_is_healthy_regardless_of_age(): void
    {
        $device = $this->deviceWithPolicy('1', 'manual');
        $this->succeed($device, now()->subYear());
        $summary = app(DeviceBackupHealth::class)->summary();
        $this->assertSame(1, $summary['counts']['healthy']);
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
}
