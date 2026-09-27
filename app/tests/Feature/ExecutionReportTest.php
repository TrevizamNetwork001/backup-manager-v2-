<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Reports\ExecutionReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionReportTest extends TestCase
{
    use RefreshDatabase;

    private function association(string $suffix = '1'): DeviceBackupPolicy
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK '.$suffix,
            'management_ip' => '192.0.2.'.$suffix, 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Policy '.$suffix, 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'segredo-nunca-exportado';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
    }

    private function execution(DeviceBackupPolicy $association, string $status, ?string $errorCode = null): BackupExecution
    {
        $execution = BackupExecution::create(['device_backup_policy_id' => $association->id,
            'backup_policy_id' => $association->backup_policy_id, 'device_id' => $association->device_id,
            'credential_id' => $association->credential_id, 'origin' => 'manual', 'status' => $status, 'attempt' => 1]);
        if ($errorCode) {
            $execution->error_code = $errorCode;
            $execution->save();
        }

        return $execution->fresh();
    }

    public function test_success_rate_only_counts_terminal_pertinent_states(): void
    {
        $a1 = $this->association('1');
        $this->execution($a1, 'succeeded');
        $this->execution($this->association('2'), 'failed', 'SSH_TIMEOUT');
        $this->execution($this->association('3'), 'pending');
        $this->execution($this->association('4'), 'running');
        $this->execution($this->association('5'), 'retry_wait');
        $this->execution($this->association('6'), 'cancelled');

        $summary = app(ExecutionReportQuery::class)->summary([]);
        // Terminal+pertinent = 1 succeeded + 1 failed = 2; rate = 50%, not diluted by the other 4.
        $this->assertSame(50.0, $summary['success_rate_percent']);
        $this->assertSame(3, $summary['pending_or_running']); // pending + running + retry_wait
        $this->assertSame(1, $summary['cancelled']);
    }

    public function test_filters_narrow_the_result_set(): void
    {
        $a1 = $this->association('1');
        $a2 = $this->association('2');
        $this->execution($a1, 'succeeded');
        $this->execution($a2, 'failed', 'SSH_AUTH_FAILED');

        $query = app(ExecutionReportQuery::class);
        $this->assertSame(1, $query->filtered(['device_id' => $a1->device_id])->count());
        $this->assertSame(1, $query->filtered(['status' => 'failed'])->count());
        $this->assertSame(1, $query->filtered(['error_code' => 'SSH_AUTH_FAILED'])->count());
        $this->assertSame(0, $query->filtered(['error_code' => 'NOPE'])->count());
    }

    public function test_view_respects_instance_timezone(): void
    {
        $this->execution($this->association(), 'succeeded');
        \Illuminate\Support\Facades\DB::table('application_settings')->where('id', 1)->update(['timezone' => 'America/Manaus']);
        $this->app->forgetInstance(\App\Services\InstanceTimezone::class);

        $execution = BackupExecution::firstOrFail();
        $localHour = app(\App\Services\InstanceTimezone::class)->format($execution->created_at, 'H:i');
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('reports.executions'))->assertOk()->assertSee($localHour);
    }

    public function test_csv_export_contains_no_secrets(): void
    {
        $this->execution($this->association(), 'succeeded');
        $this->actingAs(User::factory()->admin()->create());
        $response = $this->get(route('reports.executions.export'));
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $this->assertStringNotContainsString('segredo-nunca-exportado', $content);
        $this->assertStringContainsString('Data/Hora', $content);
    }

    public function test_export_is_audited_without_the_row_content(): void
    {
        $this->execution($this->association(), 'succeeded');
        $this->actingAs($user = User::factory()->admin()->create());
        $response = $this->get(route('reports.executions.export'));
        ob_start();
        $response->sendContent();
        ob_end_clean();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'report.exported', 'resource_type' => 'report', 'resource_id' => 'executions',
        ]);
        $event = \App\Models\AuditEvent::where('action', 'report.exported')->first();
        $metadata = is_string($event->metadata) ? json_decode($event->metadata, true) : $event->metadata;
        $this->assertSame('csv', $metadata['format']);
        $this->assertSame(1, $metadata['row_count']);
        $this->assertStringNotContainsString('segredo-nunca-exportado', json_encode($metadata));
    }

    public function test_pagination_does_not_load_everything(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->execution($this->association((string) $i), 'succeeded');
        }
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('reports.executions'))->assertOk()->assertSee('25');
    }
}
