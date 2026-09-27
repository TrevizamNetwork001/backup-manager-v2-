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
use App\Reports\FailureReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FailureReportTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name, string $ip, Site $site, string $vendor): DeviceBackupPolicy
    {
        $device = Device::create(['site_id' => $site->id, 'name' => $name,
            'management_ip' => $ip, 'vendor' => $vendor, 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Política '.$name, 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH',
            'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'senha-super-secreta';
        $credential->save();

        return DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
    }

    private function execution(DeviceBackupPolicy $association, string $status, ?string $code,
        string $at): BackupExecution
    {
        $execution = BackupExecution::create(['device_backup_policy_id' => $association->id,
            'backup_policy_id' => $association->backup_policy_id, 'device_id' => $association->device_id,
            'credential_id' => $association->credential_id, 'origin' => 'manual',
            'status' => $status, 'attempt' => 1]);
        DB::table('backup_executions')->where('id', $execution->id)->update([
            'error_code' => $code, 'error_message' => 'senha-super-secreta',
            'created_at' => CarbonImmutable::parse($at, 'UTC'),
        ]);

        return $execution->fresh();
    }

    public function test_groups_terminal_failures_by_code_and_affected_device(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $other = Site::create(['name' => 'Outro', 'is_active' => true]);
        $router = $this->device('Router', '192.0.2.41', $site, 'MikroTik');
        $olt = $this->device('OLT', '192.0.2.42', $other, 'Huawei');
        $this->execution($router, 'failed', 'SSH_TIMEOUT', '2026-09-26 12:00:00');
        $this->execution($router, 'timed_out', 'SSH_TIMEOUT', '2026-09-26 13:00:00');
        $this->execution($olt, 'failed', 'SSH_AUTH_FAILED', '2026-09-26 14:00:00');
        $this->execution($olt, 'succeeded', 'SSH_TIMEOUT', '2026-09-26 15:00:00');
        $this->execution($olt, 'retry_wait', 'SSH_TIMEOUT', '2026-09-26 16:00:00');
        $this->execution($olt, 'failed', null, '2026-09-26 17:00:00');

        $query = app(FailureReportQuery::class);
        $byCode = collect($query->byErrorCode([]))->keyBy('error_code');
        $this->assertSame(2, (int) $byCode['SSH_TIMEOUT']['total']);
        $this->assertTrue($byCode['SSH_TIMEOUT']['retryable']);
        $this->assertSame(1, (int) $byCode['SSH_AUTH_FAILED']['total']);
        $this->assertFalse($byCode['SSH_AUTH_FAILED']['retryable']);
        $this->assertCount(2, $byCode);
        $this->assertSame(2, (int) $query->byDevice([])[0]->total);
        $this->assertSame('Router', $query->byDevice([])[0]->name);
        $this->assertSame(['SSH_TIMEOUT'], array_column($query->byErrorCode(['site_id' => $site->id]), 'error_code'));
        $this->assertSame(['SSH_AUTH_FAILED'], array_column($query->byErrorCode(['vendor' => 'Huawei']), 'error_code'));
    }

    public function test_custom_period_uses_instance_timezone_at_local_midnight(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $router = $this->device('Router', '192.0.2.43', $site, 'MikroTik');
        $this->execution($router, 'failed', 'SSH_TIMEOUT', '2026-09-27 01:30:00'); // 26/09 22:30 in São Paulo.
        $this->execution($router, 'failed', 'SSH_AUTH_FAILED', '2026-09-27 04:00:00'); // 27/09 01:00.

        $filters = [
            'period' => 'custom', 'date_from' => '2026-09-26', 'date_to' => '2026-09-26',
        ];
        $rows = app(FailureReportQuery::class)->byErrorCode($filters);

        $this->assertSame(['SSH_TIMEOUT'], array_column($rows, 'error_code'));
        $this->assertSame(1, (int) app(FailureReportQuery::class)->byDevice($filters)[0]->total);
    }

    public function test_failure_export_audits_only_after_stream_without_secret_content_or_metadata(): void
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $router = $this->device('Router', '192.0.2.44', $site, 'MikroTik');
        $this->execution($router, 'failed', 'SSH_TIMEOUT', '2026-09-26 12:00:00');
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('reports.failures.export'))->assertOk();
        $this->assertSame(0, AuditEvent::query()->where('action', 'report.exported')->count());
        ob_start();
        try {
            $response->sendContent();
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $event = AuditEvent::query()->where('action', 'report.exported')->sole();
        $metadata = $event->metadata;
        $this->assertSame(1, $metadata['row_count']);
        $this->assertSame('failures', $metadata['report_type']);
        $this->assertStringContainsString('SSH_TIMEOUT', $content);
        $this->assertStringNotContainsString('senha-super-secreta', $content);
        $this->assertStringNotContainsString('senha-super-secreta', json_encode($metadata));
    }
}
