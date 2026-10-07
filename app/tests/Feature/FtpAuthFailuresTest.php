<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Services\FtpAuthFailures;
use App\Services\NotificationManager;
use App\Services\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FtpAuthFailuresTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ftp-auth-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['backup.ftp_log_dir' => $this->dir, 'backup.ftp_auth_threshold' => 3, 'backup.ftp_auth_window_minutes' => 30]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function events(array $rows, string $file = 'auth-events.log'): void
    {
        file_put_contents("{$this->dir}/{$file}", implode('', array_map(
            fn ($r) => implode("\t", $r)."\n", $rows)), FILE_APPEND);
    }

    private function account(string $username, string $deviceName): FtpAccount
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => $deviceName,
            'management_ip' => '192.0.2.'.random_int(2, 250), 'vendor' => 'Huawei', 'platform' => 'network', 'is_active' => true]);
        $account = new FtpAccount(['device_id' => $device->id, 'account_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'purpose' => 'backup', 'home_layout' => 'uuid', 'username' => $username, 'is_active' => true]);
        $account->secret = 'x';
        $account->save();

        return $account;
    }

    public function test_repeated_refusals_of_a_registered_account_are_active(): void
    {
        $this->account('bng-ne8000', 'BNG-NE8000');
        $now = time();
        $this->events([[$now - 600, 'F', 'bng-ne8000', '10.0.0.5'], [$now - 400, 'F', 'bng-ne8000', '10.0.0.5'],
            [$now - 60, 'F', 'bng-ne8000', '10.0.0.5']]);

        $active = app(FtpAuthFailures::class)->active();
        $this->assertSame(3, $active['bng-ne8000']['count']);
        $this->assertSame('BNG-NE8000', $active['bng-ne8000']['label']);
        $this->assertSame('10.0.0.5', $active['bng-ne8000']['last_ip']);
    }

    public function test_below_threshold_old_events_and_unknown_accounts_are_ignored(): void
    {
        $this->account('bng-ne8000', 'BNG-NE8000');
        $now = time();
        $this->events([[$now - 60, 'F', 'bng-ne8000', '10.0.0.5'], [$now - 50, 'F', 'bng-ne8000', '10.0.0.5']]);
        $this->assertSame([], app(FtpAuthFailures::class)->active(), 'only 2 refusals');

        $this->events([[$now - 7200, 'F', 'bng-ne8000', '1'], [$now - 7100, 'F', 'bng-ne8000', '1'], [$now - 7000, 'F', 'bng-ne8000', '1']]);
        $this->assertSame([], app(FtpAuthFailures::class)->active(), 'outside the window');

        $this->events([[$now - 30, 'F', 'ghost', '9.9.9.9'], [$now - 20, 'F', 'ghost', '9.9.9.9'], [$now - 10, 'F', 'ghost', '9.9.9.9']]);
        $this->assertSame([], app(FtpAuthFailures::class)->active(), 'user is not a registered account');
    }

    public function test_a_successful_login_clears_earlier_refusals_and_malformed_lines_are_skipped(): void
    {
        $this->account('bng-ne8000', 'BNG-NE8000');
        $now = time();
        file_put_contents("{$this->dir}/auth-events.log", "garbage\n\t\t\t\n12\tF\n");
        $this->events([[$now - 300, 'F', 'bng-ne8000', '1'], [$now - 200, 'F', 'bng-ne8000', '1'],
            [$now - 100, 'F', 'bng-ne8000', '1'], [$now - 50, 'S', 'bng-ne8000', '1']]);
        $this->assertSame([], app(FtpAuthFailures::class)->active());

        $this->events([[$now - 40, 'F', 'bng-ne8000', '1'], [$now - 30, 'F', 'bng-ne8000', '1'], [$now - 20, 'F', 'bng-ne8000', '1']]);
        $this->assertArrayHasKey('bng-ne8000', app(FtpAuthFailures::class)->active());
    }

    public function test_rotated_file_is_read_and_missing_directory_is_unreadable(): void
    {
        $this->account('bng-ne8000', 'BNG-NE8000');
        $now = time();
        $this->events([[$now - 300, 'F', 'bng-ne8000', '1'], [$now - 250, 'F', 'bng-ne8000', '1']], 'auth-events.log.1');
        $this->events([[$now - 100, 'F', 'bng-ne8000', '1']]);
        $this->assertSame(3, app(FtpAuthFailures::class)->active()['bng-ne8000']['count']);

        config(['backup.ftp_log_dir' => $this->dir.'-missing']);
        $this->assertNull(app(FtpAuthFailures::class)->active());
    }

    public function test_telegram_alert_normalization_and_no_false_normalization_when_unreadable(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        app(NotificationSettings::class)->save(['enabled' => true, 'chat_id' => '-100123'], '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        $this->account('bng-ne8000', 'BNG-NE8000');
        $now = time();
        $this->events([[$now - 300, 'F', 'bng-ne8000', '10.0.0.5'], [$now - 200, 'F', 'bng-ne8000', '10.0.0.5'],
            [$now - 100, 'F', 'bng-ne8000', '10.0.0.5']]);

        $manager = \Mockery::mock(NotificationManager::class, [
            app(NotificationSettings::class), app(\App\Services\EngineHealth::class), app(\App\Services\DeviceBackupHealth::class),
            app(\App\Services\InstanceTimezone::class), app(\App\Services\NotificationSummary::class), app(FtpAuthFailures::class),
            app(\App\Services\FtpAlertSources::class),
        ])->makePartial();
        $manager->shouldAllowMockingProtectedMethods();
        // Only the FTP condition is under test: the health-derived ones are not the point here.
        $reflection = new \ReflectionMethod(NotificationManager::class, 'ftpAuthConditions');

        $this->assertArrayHasKey('ftp-auth:bng-ne8000', $reflection->invoke($manager));
        $detail = $reflection->invoke($manager)['ftp-auth:bng-ne8000']['detail'];
        $this->assertStringContainsString('3 recusas', $detail);
        $this->assertStringContainsString('10.0.0.5', $detail);

        DB::table('notification_states')->insert(['condition_key' => 'ftp-auth:bng-ne8000', 'active' => true,
            'reason' => 'ftp_auth_refused', 'label' => 'Login FTP recusado: BNG-NE8000', 'created_at' => now(), 'updated_at' => now()]);
        config(['backup.ftp_log_dir' => $this->dir.'-missing']);
        $carried = $reflection->invoke($manager);
        $this->assertArrayHasKey('ftp-auth:bng-ne8000', $carried, 'unreadable log keeps the active condition');
        $this->assertSame('Login FTP recusado: BNG-NE8000', $carried['ftp-auth:bng-ne8000']['label']);
    }
}
