<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditEvents;
use App\Services\InstanceTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_report_page_renders_for_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['reports.index', 'reports.executions', 'reports.devices', 'reports.documentation', 'reports.artifacts', 'reports.failures', 'reports.ftp'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_every_export_streams_for_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['reports.executions.export', 'reports.devices.export', 'reports.documentation.csv', 'reports.artifacts.export', 'reports.failures.export', 'reports.ftp.export'] as $route) {
            $this->get(route($route))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        }
        $this->get(route('reports.documentation.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_landing_page_counts_real_records_and_failed_executions(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'Router', 'management_ip' => '192.0.2.10', 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Manual', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'report-test-secret';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
        foreach (['succeeded', 'failed', 'cancelled'] as $status) {
            BackupExecution::create(['device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id, 'device_id' => $device->id, 'credential_id' => $credential->id, 'origin' => 'manual', 'status' => $status, 'attempt' => 1]);
        }
        BackupArtifact::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'backup_execution_id' => BackupExecution::query()->first()->id, 'type' => 'config', 'storage' => 'local', 'relative_path' => 'lab/router.cfg', 'original_filename' => 'router.cfg', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'validated_at' => now(), 'status' => 'available']);
        $account = new FtpAccount(['device_id' => $device->id, 'account_uuid' => (string) Str::uuid(), 'purpose' => 'backup', 'home_layout' => 'account', 'username' => 'router', 'is_active' => true]);
        $account->secret = 'report-test-secret';
        $account->save();
        app(AuditEvents::class)->record('report.exported', 'report', 'executions', 'executions', 'success', ['filters' => [], 'row_count' => 3], $admin->id);

        $this->actingAs($admin)->get(route('reports.index'))->assertOk()
            ->assertViewHas('counts', ['executions' => 3, 'devices' => 1, 'documentation' => 1, 'failures' => 1, 'health' => 1, 'artifacts' => 1, 'ftp' => 1, 'audit' => 1])
            ->assertDontSee('report-test-secret');
    }

    public function test_recent_exports_are_ordered_limited_and_preserve_original_filters(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Report admin']);
        $audit = app(AuditEvents::class);
        foreach (['executions', 'devices', 'failures', 'artifacts'] as $type) {
            $audit->record('report.exported', 'report', $type, $type, 'success', ['filters' => [], 'row_count' => 1], $admin->id);
        }
        $filters = ['period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-09-10', 'status' => 'failed'];
        $audit->record('report.exported', 'report', 'executions', 'executions', 'success', ['filters' => $filters, 'row_count' => 12], $admin->id);
        $audit->record('report.exported', 'report', 'unknown', 'unknown', 'success', [], $admin->id);
        $audit->record('auth.login', 'user', (string) $admin->id, $admin->name, 'success', [], $admin->id);
        DB::table('application_settings')->where('id', 1)->update(['timezone' => 'America/Manaus']);
        $this->app->forgetInstance(InstanceTimezone::class);
        DB::table('audit_events')->update(['created_at' => '2026-09-27 15:00:00']);
        DB::table('audit_events')->where('action', 'report.exported')->where('resource_id', 'executions')->orderByDesc('id')->limit(1)->update(['created_at' => '2026-09-27 16:00:00']);

        $response = $this->actingAs($admin)->get(route('reports.index'))->assertOk()
            ->assertSee('27/09/2026 12:00')
            ->assertSee(route('reports.executions.export', $filters))
            ->assertSee(route('reports.executions', $filters))
            ->assertSee('Ver todos');
        $recent = $response->viewData('recentReports');
        $this->assertCount(3, $recent);
        $this->assertSame(['executions', 'artifacts', 'failures'], $recent->pluck('resource_id')->all());
    }

    public function test_export_history_respects_audit_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $audit = app(AuditEvents::class);
        $audit->record('report.exported', 'report', 'executions', 'executions', 'success', ['filters' => [], 'row_count' => 1], $admin->id);
        foreach (['operator', 'viewer', 'auditor'] as $role) {
            $user = User::factory()->{$role}()->create();
            $audit->record('report.exported', 'report', 'devices', 'devices', 'success', ['filters' => [], 'row_count' => 1], $user->id);
            $response = $this->actingAs($user)->get(route('reports.index'))->assertOk();
            $recent = $response->viewData('recentReports');
            if ($role === 'auditor') {
                $this->assertCount(3, $recent);
                $response->assertSee('Ver todos');
            } else {
                $this->assertSame([$user->id], $recent->pluck('actor_user_id')->all());
                $response->assertDontSee('Ver todos')->assertDontSee('Ver detalhes');
                $this->assertSame(0, $response->viewData('counts')['audit']);
            }
        }
    }
}
