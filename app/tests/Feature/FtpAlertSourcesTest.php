<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Services\AuditEvents;
use App\Services\FtpAlertSources;
use App\Services\NotificationManager;
use App\Services\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FtpAlertSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $username, string $deviceName, bool $active = true): FtpAccount
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => $deviceName,
            'management_ip' => '192.0.2.'.random_int(2, 250), 'vendor' => 'Huawei', 'platform' => 'network', 'is_active' => true]);
        $account = new FtpAccount(['device_id' => $device->id, 'account_uuid' => (string) Str::uuid(),
            'purpose' => 'backup', 'home_layout' => 'uuid', 'username' => $username, 'is_active' => $active]);
        $account->secret = 'x';
        $account->save();

        return $account;
    }

    private function receipt(FtpAccount $account, string $status, CarbonImmutable $at, ?string $error = null): void
    {
        DB::table('ftp_received_files')->insert(['ftp_account_id' => $account->id, 'claim_token' => bin2hex(random_bytes(16)),
            'original_filename' => 'a.cfg', 'status' => $status, 'error_code' => $error, 'received_at' => $at,
            'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_rejected_files_in_the_window_are_active_and_a_later_good_file_clears_them(): void
    {
        $account = $this->account('sw-a', 'Switch A');
        $now = CarbonImmutable::now('UTC');
        $this->receipt($account, 'quarantined', $now->subHours(3), 'FTP_FILE_INVALID');
        $this->receipt($account, 'quarantined', $now->subHour(), 'FTP_QUARANTINED');

        $rejected = app(FtpAlertSources::class)->rejected();
        $this->assertSame(2, $rejected['sw-a']['count']);
        $this->assertSame('FTP_QUARANTINED', $rejected['sw-a']['error_code']);
        $this->assertSame('Switch A', $rejected['sw-a']['label']);

        $this->receipt($account, 'stored', $now->subMinutes(10));
        $this->assertSame([], app(FtpAlertSources::class)->rejected(), 'um arquivo aceito depois limpa o aviso');
    }

    public function test_old_rejections_and_inactive_accounts_are_ignored(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->receipt($this->account('sw-b', 'Switch B'), 'quarantined', $now->subHours(30));
        $this->receipt($this->account('sw-c', 'Switch C', false), 'quarantined', $now->subHour());

        $this->assertSame([], app(FtpAlertSources::class)->rejected());
    }

    public function test_server_probe_is_off_without_a_host_detects_open_and_closed_ports(): void
    {
        config(['backup.ftp_probe_host' => '']);
        $this->assertFalse(app(FtpAlertSources::class)->serverDown(0), 'desligada sem host');

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config(['backup.ftp_probe_host' => '127.0.0.1', 'backup.ftp_probe_port' => $port]);
        $this->assertFalse(app(FtpAlertSources::class)->serverDown(0), 'porta aberta = no ar');
        fclose($server);

        $this->assertTrue(app(FtpAlertSources::class)->serverDown(0), 'porta fechada duas vezes = fora do ar');
    }

    public function test_retention_notice_is_sent_once_for_real_deletions_only(): void
    {
        app(NotificationSettings::class)->save(['enabled' => true, 'chat_id' => '-100123'], '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        $audit = app(AuditEvents::class);
        $audit->record('backup_retention.completed', 'system', null, null, 'success', ['deleted' => 5, 'errors' => 0, 'mode' => 'apply']);
        $audit->record('backup_retention.completed', 'system', null, null, 'success', ['deleted' => 0, 'errors' => 0, 'mode' => 'apply']);
        $audit->record('backup_retention.completed', 'system', null, null, 'success', ['deleted' => 9, 'errors' => 0, 'mode' => 'dry_run']);
        DB::table('audit_events')->insert(['action' => 'backup_retention.completed', 'resource_type' => 'system', 'result' => 'success',
            'metadata' => json_encode(['deleted' => 7, 'errors' => 0, 'mode' => 'apply']), 'created_at' => now()->subDays(3)]);

        $manager = app(NotificationManager::class);
        $this->assertSame(1, $manager->retentionNotices());
        $this->assertSame(0, $manager->retentionNotices(), 'a mesma execução não avisa duas vezes');

        $notice = DB::table('notification_queue')->where('kind', 'notice')->sole();
        $this->assertSame('Limpeza por retenção concluída', $notice->title);
        $this->assertStringContainsString('5 backup(s)', $notice->body);
        $this->assertSame('info', $notice->severity);
    }

    public function test_retention_notice_waits_for_the_maintenance_window_to_end(): void
    {
        app(NotificationSettings::class)->save(['enabled' => true, 'chat_id' => '-100123',
            'maintenance_enabled' => true, 'maintenance_start' => '00:00', 'maintenance_end' => '23:59'], '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        app(AuditEvents::class)->record('backup_retention.completed', 'system', null, null, 'success', ['deleted' => 2, 'errors' => 0, 'mode' => 'apply']);

        $this->assertSame(0, app(NotificationManager::class)->retentionNotices());
    }
}
