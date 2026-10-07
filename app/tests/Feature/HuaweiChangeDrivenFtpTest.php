<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\DeviceBackupHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Huawei VRP routers/switches only push on config change: silence is not a failure. */
class HuaweiChangeDrivenFtpTest extends TestCase
{
    use RefreshDatabase;

    private function ftpDevice(string $vendor, string $platform, ?int $expectedHours, int $lastFileHoursAgo): Device
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => "{$vendor}-{$platform}",
            'management_ip' => '192.0.2.'.random_int(2, 250), 'vendor' => $vendor, 'platform' => $platform,
            'expected_ftp_interval_hours' => $expectedHours, 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'FTP '.$device->name, 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $association = DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'is_active' => true]);
        $execution = BackupExecution::create(['device_backup_policy_id' => $association->id,
            'backup_policy_id' => $policy->id, 'device_id' => $device->id,
            'origin' => 'ftp_received', 'status' => 'succeeded', 'attempt' => 1]);
        DB::table('backup_executions')->where('id', $execution->id)
            ->update(['created_at' => now()->subHours($lastFileHoursAgo)]);

        return $device;
    }

    private function row(Device $device): array
    {
        return collect(app(DeviceBackupHealth::class)->rows())->firstWhere('device_id', $device->id);
    }

    public function test_huawei_network_silence_is_not_an_alert_even_with_a_legacy_interval(): void
    {
        $device = $this->ftpDevice('Huawei', 'network', 24, 500);
        $this->assertNotContains($this->row($device)['status'], ['warning', 'critical']);
        $this->assertStringNotContainsString('ftp_', $this->row($device)['reason']);
    }

    public function test_huawei_olt_with_an_interval_still_alerts_when_it_stops_sending(): void
    {
        $device = $this->ftpDevice('Huawei', 'olt', 24, 500);
        $row = $this->row($device);
        $this->assertSame('critical', $row['status']);
        $this->assertSame('ftp_backup_stale', $row['reason']);
    }

    public function test_other_vendors_keep_the_interval_check(): void
    {
        $device = $this->ftpDevice('VSOL', 'olt', 24, 500);
        $this->assertSame('ftp_backup_stale', $this->row($device)['reason']);
    }

    public function test_saving_a_huawei_network_device_clears_the_expected_interval(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $payload = ['site_id' => $site->id, 'name' => 'SW-X', 'management_ip' => '192.0.2.77', 'vendor' => 'Huawei',
            'platform' => 'network', 'expected_ftp_interval_hours' => 24, 'is_active' => 1];
        $this->post(route('devices.store'), $payload)->assertRedirect();
        $this->assertNull(Device::where('name', 'SW-X')->value('expected_ftp_interval_hours'));

        $olt = array_merge($payload, ['name' => 'OLT-X', 'management_ip' => '192.0.2.78', 'platform' => 'olt']);
        $this->post(route('devices.store'), $olt)->assertRedirect();
        $this->assertSame(24, Device::where('name', 'OLT-X')->value('expected_ftp_interval_hours'));
    }

    public function test_helper_distinguishes_network_from_olt(): void
    {
        $this->assertTrue(Device::pushesOnlyOnConfigChange('Huawei', 'network'));
        $this->assertTrue(Device::pushesOnlyOnConfigChange(' huawei ', null));
        $this->assertFalse(Device::pushesOnlyOnConfigChange('Huawei', 'olt'));
        $this->assertFalse(Device::pushesOnlyOnConfigChange('MikroTik', 'network'));
    }
}
