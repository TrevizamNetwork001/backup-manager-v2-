<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Reports\DeviceReportQuery;
use App\Services\DeviceBackupHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceReportTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name, string $ip, Site $site, string $vendor = 'MikroTik'): Device
    {
        return Device::create(['site_id' => $site->id, 'name' => $name,
            'management_ip' => $ip, 'vendor' => $vendor, 'is_active' => true]);
    }

    private function policyFor(Device $device, string $scheduleType): DeviceBackupPolicy
    {
        $policy = BackupPolicy::create(['name' => 'Política '.$device->name, 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => $scheduleType, 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH',
            'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'segredo-nunca-exportado';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
    }

    public function test_filters_use_the_same_health_rows_as_the_dashboard(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $otherSite = Site::create(['name' => 'Outro', 'is_active' => true]);
        $healthy = $this->device('A saudável', '192.0.2.11', $site);
        $association = $this->policyFor($healthy, 'manual');
        BackupExecution::create(['device_backup_policy_id' => $association->id,
            'backup_policy_id' => $association->backup_policy_id, 'device_id' => $healthy->id,
            'credential_id' => $association->credential_id, 'origin' => 'manual',
            'status' => 'succeeded', 'attempt' => 1]);
        $unknown = $this->device('B sem política', '192.0.2.12', $site, 'Huawei');
        $critical = $this->device('C agendado', '192.0.2.13', $otherSite);
        $this->policyFor($critical, 'daily');

        $report = app(DeviceReportQuery::class);
        $healthRows = collect(app(DeviceBackupHealth::class)->rows())->keyBy('device_id');
        $this->assertSame($healthRows->pluck('status', 'device_id')->all(),
            $report->filtered([])->keyBy('device_id')->pluck('status', 'device_id')->all());
        $this->assertSame([$healthy->id], $report->filtered(['status' => 'healthy'])->pluck('device_id')->all());
        $this->assertSame([$unknown->id], $report->filtered(['policy' => 'without'])->pluck('device_id')->all());
        $this->assertSame([$unknown->id, $critical->id],
            $report->filtered(['freshness' => 'never'])->pluck('device_id')->all());
        $this->assertSame([$critical->id],
            $report->filtered(['site_id' => $otherSite->id, 'vendor' => 'MikroTik'])->pluck('device_id')->all());
    }

    public function test_device_export_audits_the_completed_row_count_without_secrets(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = $this->device('Router', '192.0.2.21', $site);
        $this->policyFor($device, 'manual');
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('reports.devices.export'))->assertOk();
        $this->assertSame(0, AuditEvent::query()->where('action', 'report.exported')->count());
        ob_start();
        try {
            $response->sendContent();
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $event = AuditEvent::query()->where('action', 'report.exported')->sole();
        $metadata = is_string($event->metadata) ? json_decode($event->metadata, true) : $event->metadata;
        $this->assertSame(1, $metadata['row_count']);
        $this->assertSame('devices', $metadata['report_type']);
        $this->assertStringContainsString('Router', $content);
        $this->assertStringNotContainsString('segredo-nunca-exportado', $content);
        $this->assertStringNotContainsString('segredo-nunca-exportado', json_encode($metadata));
    }

    public function test_device_report_paginates_without_showing_all_rows_on_one_page(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        for ($i = 1; $i <= 30; $i++) {
            $this->device(sprintf('Router %02d', $i), '192.0.2.'.($i + 30), $site);
        }
        $this->actingAs(User::factory()->admin()->create());

        $first = $this->get(route('reports.devices'))->assertOk()->viewData('devices');
        $second = $this->get(route('reports.devices', ['page' => 2]))->assertOk()->viewData('devices');

        $this->assertSame(30, $first->total());
        $this->assertCount(25, $first->items());
        $this->assertCount(5, $second->items());
    }
}
