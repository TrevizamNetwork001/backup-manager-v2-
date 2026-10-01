<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\BackupRetention;
use App\Services\BackupScheduler;
use App\Services\EngineJobService;
use App\Services\FtpServerSettings;
use App\Services\HuaweiFtpBackupPolicy;
use App\Services\OltFtpWizard;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HuaweiOltFtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_r19_and_unknown_content_are_stored_with_analysis(): void
    {
        [$device, $policy] = $this->fixture();
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true]);
        $engine = app(EngineJobService::class);
        $root = sys_get_temp_dir().'/olt-content-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        $known = file_get_contents($this->ma5800FixturePath());
        $r19 = str_replace([' sysname OLT-LAB', 'MA5800V100R021C10B066'],
            [' no-sysname OLT-LAB', 'MA5800V100R019C11B072'], $known);
        try {
            foreach (['r19.cfg' => [$r19, 'warning'], 'unknown.cfg' => ['opaque text backup', 'unknown']] as $name => [$data, $status]) {
                $worker = bin2hex(random_bytes(16));
                $token = bin2hex(random_bytes(16));
                $receivedAt = time();
                $received = $engine->receiveFtp($device->id, $token, $name, $receivedAt, $worker);
                $relative = $name === 'unknown.cfg'
                    ? substr($received['relative_path'], 0, -4).'-exec-'.$received['id'].'.cfg'
                    : $received['relative_path'];
                $path = $root.'/'.$relative;
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                file_put_contents($path, $data);
                $artifact = $engine->complete($received['id'], $relative, $worker);
                $this->assertSame('succeeded', BackupExecution::findOrFail($received['id'])->status);
                $this->assertSame($name, $artifact->original_filename);
                $this->assertSame(hash('sha256', $data), $artifact->sha256);
                $this->assertSame(strlen($data), $artifact->size_bytes);
                $this->assertNull(BackupExecution::findOrFail($received['id'])->error_code);
                Artisan::call('ftp:receipt', [
                    'account' => $device->ftpAccount->id, 'token' => $token,
                    'filename' => 'n'.strtr(base64_encode($name), '+/', '-_'), 'received' => $receivedAt,
                    'status' => 'stored', 'size' => strlen($data), 'hash' => hash('sha256', $data),
                    'path' => $relative, 'error' => '-',
                ]);
                $this->assertDatabaseHas('ftp_received_files', ['claim_token' => $token, 'status' => 'stored',
                    'size_bytes' => strlen($data), 'sha256' => hash('sha256', $data),
                    'relative_path' => $relative, 'error_code' => null]);
                $this->assertSame($status, json_decode(DB::table('audit_events')->where('action', 'backup.content_analyzed')
                    ->where('resource_id', (string) $received['id'])->value('metadata'), true)['status']);
                $this->actingAs(User::factory()->create())->get(route('backup-executions.show', $received['id']))
                    ->assertOk()->assertSeeInOrder([
                        'Armazenamento', 'OK', 'Integridade', 'OK',
                        'Análise de conteúdo', $status === 'warning' ? 'Aviso' : 'Não reconhecido',
                    ]);
            }
            $this->assertDatabaseCount('backup_artifacts', 2);
        } finally {
            foreach (glob($root.'/Backup Manager/*/*/*/*.cfg') as $path) {
                unlink($path);
            }
            foreach (glob($root.'/Backup Manager/*/*/*', GLOB_ONLYDIR) as $dir) {
                rmdir($dir);
            }
            foreach (glob($root.'/Backup Manager/*/*', GLOB_ONLYDIR) as $dir) {
                rmdir($dir);
            }
            foreach (glob($root.'/Backup Manager/*', GLOB_ONLYDIR) as $dir) {
                rmdir($dir);
            }
            rmdir($root.'/Backup Manager');
            rmdir($root);
        }
    }

    public function test_spontaneous_receipt_creates_execution_after_claim_and_preserves_remote_name(): void
    {
        [$device, $policy] = $this->fixture();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true]);
        $engine = app(EngineJobService::class);
        $this->assertDatabaseCount('backup_executions', 0);
        $token = bin2hex(random_bytes(16));
        $worker = bin2hex(random_bytes(16));
        $receipt = $engine->receiveFtp($device->id, $token, 'OLT-auto.cfg', time(), $worker);
        $this->assertNotNull($receipt);
        $job = BackupExecution::findOrFail($receipt['id']);
        $this->assertSame($engine->relativePath($job), $receipt['relative_path']);
        $this->assertSame('ftp_received', $job->origin);
        $this->assertSame($association->id, $job->device_backup_policy_id);
        $this->assertSame($device->ftpAccount->id, $job->ftp_account_id);
        $this->assertNotNull($job->received_at);
        $this->assertNotNull($job->processing_at);
        $this->actingAs(User::factory()->create())->get(route('backup-executions.show', $job))
            ->assertOk()->assertSee('FTP recebido')->assertSee('OLT-auto.cfg');
        $newWorker = bin2hex(random_bytes(16));
        $this->assertSame($receipt['id'], $engine->receiveFtp($device->id, $token, 'OLT-auto.cfg', time(), $newWorker)['id']);
        $this->assertSame($newWorker, $job->fresh()->worker_id);
        $root = sys_get_temp_dir().'/olt-receipt-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        $path = $root.'/'.$receipt['relative_path'];
        mkdir(dirname($path), 0700, true);
        try {
            file_put_contents($path, file_get_contents($this->ma5800FixturePath()));
            $artifact = $engine->complete($job->id, $receipt['relative_path'], $newWorker);
            $this->assertSame('OLT-auto.cfg', $artifact->original_filename);
            $this->assertSame(hash_file('sha256', $path), $artifact->sha256);
            $this->assertSame('succeeded', $job->fresh()->status);
        } finally {
            unlink($path);
            $dir = dirname($path);
            while ($dir !== $root) {
                rmdir($dir);
                $dir = dirname($dir);
            }
            rmdir($root);
        }
    }

    public function test_spontaneous_receipt_requires_valid_account_and_device(): void
    {
        [$device, $policy] = $this->fixture(false);
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true]);
        $engine = app(EngineJobService::class);
        $this->assertSame('invalid_account', $engine->receiveFtp($device->id, bin2hex(random_bytes(16)), 'auto.cfg', time(), bin2hex(random_bytes(16)))['error_code']);
        $this->assertDatabaseCount('backup_executions', 0);
        $account = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $account->secret = 'synthetic-only-secret';
        $account->save();
        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now()]);
        $device->update(['is_active' => false]);
        $this->assertSame('unsupported_device', $engine->receiveFtp($device->id, bin2hex(random_bytes(16)), 'auto.cfg', time(), bin2hex(random_bytes(16)))['error_code']);
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_new_huawei_account_prepares_policy_without_wizard_and_reuses_it(): void
    {
        [$device, $policy] = $this->fixture(false);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post(route('ftp.store'), ['device_id' => $device->id, 'username' => 'pop_ipe',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertOk();
        $account = $device->ftpAccount()->firstOrFail();
        $this->assertSame('account', $account->home_layout);
        $this->assertDatabaseHas('device_backup_policies', ['device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'is_active' => true]);
        $this->assertDatabaseMissing('olt_ftp_integrations', ['device_id' => $device->id]);
        $this->post(route('ftp.prepare', $account))->assertRedirect();
        $this->assertDatabaseCount('device_backup_policies', 1);
        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now(), 'sync_error' => null]);
        $result = app(EngineJobService::class)->receiveFtp($device->id, bin2hex(random_bytes(16)),
            'dados6.zip', time(), bin2hex(random_bytes(16)));
        $this->assertSame('running', $result['status']);
        $this->assertSame($account->id, $result['ftp_account_id']);
    }

    public function test_missing_and_inactive_policy_have_distinct_errors_and_account_id(): void
    {
        [$device, $policy] = $this->fixture();
        $engine = app(EngineJobService::class);
        $call = fn () => $engine->receiveFtp($device->id, bin2hex(random_bytes(16)),
            'dados6.zip', time(), bin2hex(random_bytes(16)));
        $missing = $call();
        $this->assertSame('missing_backup_policy', $missing['error_code']);
        $this->assertSame($device->ftpAccount->id, $missing['ftp_account_id']);
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => false]);
        $this->assertSame('invalid_backup_policy', $call()['error_code']);
    }

    public function test_backup_account_on_mikrotik_is_not_processed_as_huawei(): void
    {
        [$device, $policy] = $this->fixture();
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true]);
        $device->update(['vendor' => 'MikroTik', 'platform' => 'network']);
        $result = app(EngineJobService::class)->receiveFtp($device->id, bin2hex(random_bytes(16)),
            'backup.rsc', time(), bin2hex(random_bytes(16)));
        $this->assertSame('unsupported_device', $result['error_code']);
        $this->assertSame($device->ftpAccount->id, $result['ftp_account_id']);
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_preparation_reactivates_oldest_compatible_link_without_duplicates(): void
    {
        [$device, $policy] = $this->fixture(false);
        $other = BackupPolicy::create(['name' => 'Second FTP', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $old = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => false]);
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $other->id,
            'credential_id' => null, 'is_active' => false]);
        $service = app(HuaweiFtpBackupPolicy::class);
        $this->assertSame($old->id, $service->ensure($device)->id);
        $this->assertSame($old->id, $service->ensure($device)->id);
        $this->assertDatabaseCount('device_backup_policies', 2);
        $this->assertDatabaseHas('device_backup_policies', ['id' => $old->id, 'is_active' => true]);
    }

    public function test_prepare_button_posts_and_repairs_missing_policy_for_device_six(): void
    {
        $this->resetHistoricalFixtureSequences();
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        for ($id = 1; $id <= 5; $id++) {
            Device::create(['site_id' => $site->id, 'name' => 'Other '.$id,
                'management_ip' => '192.0.2.'.$id, 'vendor' => 'MikroTik', 'platform' => 'network', 'is_active' => true]);
        }
        $device = Device::create(['site_id' => $site->id, 'name' => 'OLT-huawei-IPE',
            'management_ip' => '192.0.2.6', 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        $this->assertSame(6, $device->id);
        $account = $this->prepareAccount($device, 'pop_ipe');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = route('ftp.prepare', $account);
        $this->assertSame('/ftp/accounts/'.$account->id.'/prepare', parse_url($url, PHP_URL_PATH));
        $this->assertDatabaseCount('device_backup_policies', 0);
        $this->get(route('ftp.show', $account))->assertOk()
            ->assertSee('Política ftp_push</dt><dd>Ausente', false)
            ->assertSee('Pronto para receber backup</dt><dd>NÃO', false)
            ->assertSee('<form method="POST" action="'.$url.'" id="ftp-prepare-form">', false)
            ->assertSee('name="_token"', false)
            ->assertSee('<button type="submit" class="btn btn--secondary">Preparar backup FTP</button>', false);
        $this->get($url)->assertStatus(405);
        $this->post($url)->assertRedirect(route('ftp.show', $account))
            ->assertSessionHas('status', 'Backup FTP preparado com sucesso.');
        $association = DeviceBackupPolicy::firstOrFail();
        $this->assertTrue($association->is_active);
        $this->assertSame($device->id, $association->device_id);
        $this->assertTrue(app(HuaweiFtpBackupPolicy::class)->compatible($association->load('backupPolicy')));
        $this->get(route('ftp.show', $account))->assertOk()
            ->assertSee('Política ftp_push</dt><dd>OK', false)
            ->assertSee('Pronto para receber backup</dt><dd>SIM', false)
            ->assertDontSee('Preparar backup FTP');
        $this->post($url)->assertRedirect(route('ftp.show', $account));
        $this->assertDatabaseCount('device_backup_policies', 1);
        $this->assertDatabaseCount('backup_policies', 1);
        $events = DB::table('audit_events')->where('action', 'ftp.backup_policy.prepared')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $first = json_decode($events[0]->metadata, true);
        $second = json_decode($events[1]->metadata, true);
        $this->assertSame(['account_id', 'device_id', 'policy_id', 'association_id', 'reused_policy', 'reused_association', 'result'], array_keys($first));
        $this->assertFalse($first['reused_policy']);
        $this->assertFalse($first['reused_association']);
        $this->assertTrue($second['reused_policy']);
        $this->assertTrue($second['reused_association']);
    }

    public function test_prepare_reuses_existing_policy_and_reactivates_association(): void
    {
        [$device, $existingPolicy] = $this->fixture(false);
        $account = $this->prepareAccount($device);
        $association = DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $existingPolicy->id, 'credential_id' => null, 'is_active' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('ftp.prepare', $account))->assertRedirect();
        $this->assertTrue($association->fresh()->is_active);
        $this->assertDatabaseCount('device_backup_policies', 1);
        $this->assertDatabaseCount('backup_policies', 1);
        $event = json_decode(DB::table('audit_events')->where('action', 'ftp.backup_policy.prepared')->value('metadata'), true);
        $this->assertTrue($event['reused_policy']);
        $this->assertTrue($event['reused_association']);
    }

    public function test_prepare_reuses_existing_policy_without_association(): void
    {
        [$device, $existingPolicy] = $this->fixture(false);
        $account = $this->prepareAccount($device);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('ftp.prepare', $account))->assertRedirect();
        $this->assertDatabaseHas('device_backup_policies', ['device_id' => $device->id,
            'backup_policy_id' => $existingPolicy->id, 'is_active' => true]);
        $this->assertDatabaseCount('backup_policies', 1);
        $event = json_decode(DB::table('audit_events')->where('action', 'ftp.backup_policy.prepared')->value('metadata'), true);
        $this->assertTrue($event['reused_policy']);
        $this->assertFalse($event['reused_association']);
    }

    public function test_prepare_requires_csrf_admin_and_eligible_account(): void
    {
        [$device] = $this->fixture(false);
        $account = $this->prepareAccount($device);
        $url = route('ftp.prepare', $account);
        // A viewer has ftp.view but not ftp.manage — preparing a backup policy is a mutation.
        $this->actingAs(User::factory()->viewer()->create())->post($url)->assertForbidden();
        $this->assertDatabaseCount('device_backup_policies', 0);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->post($url)->assertStatus(419);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->assertDatabaseCount('device_backup_policies', 0);
        $device->update(['vendor' => 'MikroTik']);
        $this->post($url)->assertSessionHasErrors('account');
        $device->update(['vendor' => 'Huawei', 'is_active' => false]);
        $this->post($url)->assertSessionHasErrors('account');
        $device->update(['is_active' => true]);
        $account->update(['purpose' => 'file_server', 'device_id' => null]);
        $this->post($url)->assertSessionHasErrors('account');
        $this->assertDatabaseCount('device_backup_policies', 0);
        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL rejects this legacy invalid row before the application can read it.
            $this->expectException(QueryException::class);
            DB::transaction(fn () => $account->update(['purpose' => 'backup', 'device_id' => null]));

            return;
        }
        $account->update(['purpose' => 'backup', 'device_id' => null]);
        $this->post($url)->assertSessionHasErrors('account');
        $this->assertDatabaseCount('device_backup_policies', 0);
    }

    private function prepareAccount(Device $device, string $username = 'olt_prepare'): FtpAccount
    {
        $account = new FtpAccount(['account_uuid' => (string) Str::uuid(),
            'home_layout' => 'account', 'purpose' => 'backup', 'device_id' => $device->id,
            'username' => $username, 'is_active' => true]);
        $account->secret = 'synthetic-only-secret';
        $account->save();
        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now(), 'sync_error' => null]);

        return $account->fresh();
    }

    private function fixture(bool $account = true): array
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'OLT', 'management_ip' => '192.0.2.10', 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'OLT FTP', 'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_count' => 2, 'is_active' => true]);
        if ($account) {
            $ftp = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
            $ftp->secret = 'synthetic-only-secret';
            $ftp->save();
            DB::table('ftp_accounts')->where('id', $ftp->id)->update(['provisioned_at' => now()->addSecond()]);
        }

        return [$device, $policy];
    }

    public function test_account_is_encrypted_hidden_and_only_shown_once(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->create())->post(route('devices.ftp-account.store', $device), ['username' => 'bmdev'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345'])
            ->assertOk()->assertSee('Senha FTP.')->assertHeader('Cache-Control', 'no-store, private');
        $account = $device->ftpAccount()->firstOrFail();
        $raw = DB::table('ftp_accounts')->where('id', $account->id)->value('secret');
        $this->assertNotSame($account->secret, $raw);
        $this->assertArrayNotHasKey('secret', $account->toArray());
        Artisan::call('ftp:accounts');
        $version = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0]['updated_at'];
        $this->artisan('ftp:provisioned', ['id' => $account->id, 'version' => $version])->assertExitCode(0);
        $this->assertNotNull($account->fresh()->provisioned_at);
        $this->get(route('devices.edit', $device))->assertOk()->assertDontSee($account->secret)->assertDontSee($raw);
        $this->artisan('ftp:accounts')->doesntExpectOutputToContain($account->secret)->assertExitCode(0);
    }

    public function test_provisioning_version_survives_sync_error_and_retry_then_rotates(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->create())->post(route('devices.ftp-account.store', $device), ['username' => 'bmdev'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345'])->assertOk();
        $account = $device->ftpAccount()->firstOrFail();
        Artisan::call('ftp:accounts');
        $createdVersion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0]['updated_at'];
        $this->assertSame($account->getRawOriginal('updated_at'), $createdVersion);

        $this->artisan('ftp:sync-failed')->assertExitCode(0);
        $this->artisan('ftp:sync-failed')->assertExitCode(0);
        $this->assertNotNull($account->fresh()->sync_error);
        $this->assertSame($createdVersion, $account->fresh()->getRawOriginal('updated_at'));
        $this->post(route('devices.ftp-account.retry', $device))->assertRedirect();
        $this->assertSame($createdVersion, $account->fresh()->getRawOriginal('updated_at'));
        $this->artisan('ftp:provisioned', ['id' => $account->id, 'version' => $createdVersion])->assertExitCode(0);
        $this->assertNotNull($account->fresh()->provisioned_at);
        $this->assertNull($account->fresh()->sync_error);

        $this->travel(2)->seconds();
        $this->post(route('devices.ftp-account.rotate', $device))->assertOk();
        $this->assertNull($account->fresh()->provisioned_at);
        Artisan::call('ftp:accounts');
        $rotatedVersion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0]['updated_at'];
        $this->assertNotSame($createdVersion, $rotatedVersion);
        $this->artisan('ftp:provisioned', ['id' => $account->id, 'version' => $rotatedVersion])->assertExitCode(0);
        $this->assertNotNull($account->fresh()->provisioned_at);
        $this->assertNull($account->fresh()->sync_error);
    }

    public function test_provisioning_rejects_version_from_before_password_rotation(): void
    {
        [$device] = $this->fixture(false);
        $account = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $account->secret = 'synthetic-only-secret';
        $account->save();
        Artisan::call('ftp:accounts');
        $oldVersion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0]['updated_at'];

        $this->travel(2)->seconds();
        $account->secret = 'rotated-synthetic-secret';
        $account->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conta alterada durante provisionamento.');
        Artisan::call('ftp:provisioned', ['id' => $account->id, 'version' => $oldVersion]);
    }

    public function test_manual_account_validates_username_and_password_and_sync_can_retry(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->create());
        $payload = ['username' => 'olt_lab', 'password' => 'Strong!Pass12345',
            'password_confirmation' => 'Strong!Pass12345'];
        $this->post(route('devices.ftp-account.store', $device), array_replace($payload, ['username' => '../bad']))
            ->assertSessionHasErrors('username');
        $this->post(route('devices.ftp-account.store', $device), array_replace($payload, ['password_confirmation' => 'mismatch']))
            ->assertSessionHasErrors('password');
        $this->post(route('devices.ftp-account.store', $device), $payload)
            ->assertOk()->assertSee('Strong!Pass12345')->assertHeader('Cache-Control', 'no-store, private');
        $account = $device->ftpAccount()->firstOrFail();
        $this->assertSame('olt_lab', $account->username);
        $this->assertNotSame($payload['password'], DB::table('ftp_accounts')->where('id', $account->id)->value('secret'));
        $this->get(route('devices.edit', $device))->assertDontSee($payload['password']);

        $other = Device::create(['site_id' => $device->site_id, 'name' => 'OLT 2',
            'management_ip' => '192.0.2.11', 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        $this->post(route('devices.ftp-account.store', $other), $payload)->assertSessionHasErrors('username');
        $this->artisan('ftp:sync-failed')->assertExitCode(0);
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'sync_error');
        $this->get(route('devices.edit', $device))->assertSee('Não foi possível sincronizar a conta no PureDB.')
            ->assertSee('Tentar novamente');
        $this->post(route('devices.ftp-account.retry', $device))->assertRedirect();
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'sync');
    }

    public function test_existing_account_can_be_kept_or_replaced_manually(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        $account = $device->ftpAccount()->firstOrFail();
        $oldSecret = $account->secret;
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Usuário atual:')->assertSee($account->username)
            ->assertSee('Manter conta atual')->assertSee('Substituir conta')
            ->assertSee('Usuário FTP')->assertSee('Confirmar senha')->assertDontSee('Gerar automaticamente')
            ->assertSee('Trocar usuário ou senha depois de configurar a OLT exigirá atualizar os dados na OLT.')
            ->assertDontSee($oldSecret);

        $this->post(route('devices.ftp-account.replace', $device), [
            'username' => 'olt_manual', 'password' => 'Strong!Pass12345',
            'password_confirmation' => 'wrong',
        ])->assertSessionHasErrors('password');
        $this->assertSame($oldSecret, $account->fresh()->secret);

        $this->post(route('devices.ftp-account.replace', $device), [
            'username' => 'olt_manual', 'password' => 'Strong!Pass12345',
            'password_confirmation' => 'Strong!Pass12345',
        ])->assertOk()->assertSee('Strong!Pass12345')->assertHeader('Cache-Control', 'no-store, private');
        $account->refresh();
        $this->assertSame('olt_manual', $account->username);
        $this->assertSame('Strong!Pass12345', $account->secret);
        $this->assertNull($account->provisioned_at);
        $this->assertNotSame($account->secret, DB::table('ftp_accounts')->where('id', $account->id)->value('secret'));
        $this->get(route('devices.edit', $device))->assertDontSee($oldSecret)->assertDontSee($account->secret);
    }

    public function test_existing_account_can_be_replaced_with_explicit_username(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        $account = $device->ftpAccount()->firstOrFail();
        $oldSecret = $account->secret;

        $this->post(route('devices.ftp-account.replace', $device), ['username' => 'bmdev'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345'])
            ->assertOk()->assertSee('Senha FTP.');
        $account->refresh();
        $this->assertSame('bmdev'.$device->id, $account->username);
        $this->assertNotSame($oldSecret, $account->secret);
        $this->assertNull($account->provisioned_at);
        $this->assertSame('sync', app(OltFtpWizard::class)->snapshot($device)['state']);
    }

    public function test_status_polling_is_read_only_and_confirmation_creates_one_integration(): void
    {
        $this->resetHistoricalFixtureSequences();
        $site = Site::create(['name' => 'Other', 'is_active' => true]);
        for ($number = 1; $number <= 3; $number++) {
            Device::create(['site_id' => $site->id, 'name' => 'Other '.$number,
                'management_ip' => '192.0.2.'.$number, 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        }
        [$device] = $this->fixture(false);
        $this->assertSame(4, $device->id);
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '');

        $account = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev4', 'is_active' => true]);
        $account->secret = 'synthetic-only-secret';
        $account->save();
        DB::table('ftp_accounts')->where('id', $account->id)->update([
            'provisioned_at' => now()->subMinute(), 'sync_error' => null,
        ]);
        $this->assertDatabaseMissing('olt_ftp_integrations', ['device_id' => 4]);

        $this->get(route('devices.olt-ftp.status', $device))->assertOk()->assertJsonPath('state', 'server');
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('data-state="server"', false)
            ->assertSee('Etapa 3 de 6')
            ->assertSee('showStep(currentStep); dialog.showModal(); poll();', false);
        for ($poll = 0; $poll < 3; $poll++) {
            $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'server');
        }
        $this->assertDatabaseMissing('olt_ftp_integrations', ['device_id' => 4]);
        $this->post(route('devices.ftp-account.replace', $device), ['username' => 'bmdev'.$device->id, 'password' => 'Strong!Pass12345', 'password_confirmation' => 'Strong!Pass12345'])->assertOk();
        $this->assertDatabaseMissing('olt_ftp_integrations', ['device_id' => 4]);
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'sync');
        DB::table('ftp_accounts')->where('id', $account->id)->update([
            'provisioned_at' => now()->subMinute(), 'sync_error' => null,
        ]);
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'server');
        $this->assertDatabaseMissing('olt_ftp_integrations', ['device_id' => 4]);
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $integrationId = DB::table('olt_ftp_integrations')->where('device_id', 4)->value('id');
        $this->assertNotNull($integrationId);
        DB::enableQueryLog();
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->assertSame($integrationId, DB::table('olt_ftp_integrations')->where('device_id', 4)->value('id'));
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'test');
        $repeatedInserts = array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'insert into "olt_ftp_integrations"'));
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], $repeatedInserts);
        $this->assertSame(1, DB::table('olt_ftp_integrations')->where('device_id', 4)->count());
    }

    public function test_olt_ftp_instructions_follow_account_and_server_readiness(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'account');

        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Usuário FTP')->assertSee('Confirmar senha')->assertDontSee('Gerar automaticamente')
            ->assertDontSee('O que fazer agora')->assertDontSee('Segurança SSH');

        $account = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $account->secret = 'synthetic-only-secret';
        $account->save();
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'sync');
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Sincronizando conta no PureDB')
            ->assertDontSee('<strong>Servidor:</strong> 192.0.2.20', false);

        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now()->addSecond()]);
        config()->set('backup.ftp_host', '');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'server');
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('IP ou hostname do servidor FTP que receberá os backups da OLT.')
            ->assertSee('Host do servidor FTP')
            ->assertSee('Endereço passivo do FTP')
            ->assertSee('Salvar e validar')
            ->assertSee('data-wizard-panel="3" aria-label="Servidor FTP"', false)
            ->assertSee('data-wizard-step="3"', false)
            ->assertSee('id="back-olt-wizard"', false)
            ->assertSee('id="next-olt-wizard"', false)
            ->assertDontSee('<strong>Usuário:</strong> bmdev'.$device->id, false);

        config()->set('backup.ftp_host', '192.0.2.20');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'olt');
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('<strong>Servidor:</strong> 192.0.2.20', false)
            ->assertSee('<strong>Porta:</strong> 21', false)
            ->assertSee('<strong>Usuário:</strong> bmdev'.$device->id, false)
            ->assertSee('Já configurei estes dados na OLT')
            ->assertSee('data-wizard-panel="4" aria-label="Configuração da OLT"', false)
            ->assertSee('Diretório remoto:')
            ->assertSee('Avançar para teste')
            ->assertDontSee('Segurança SSH')
            ->assertDontSee('O que fazer agora')
            ->assertDontSee('synthetic-only-secret');

        $account->update(['is_active' => false]);
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Manter conta atual');

        $device->update(['platform' => 'network']);
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertDontSee('Segurança SSH')
            ->assertDontSee('Configuração Huawei OLT / FTP');
    }

    public function test_server_settings_are_persisted_and_advance_wizard(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        config()->set('backup.ftp_passive_address', '192.0.2.30');

        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('value="192.0.2.20"', false)
            ->assertSee('value="192.0.2.30"', false)
            ->assertSee('value="21"', false);

        config()->set('backup.ftp_host', '');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'server');
        $this->post(route('devices.olt-ftp.server', $device), [
            'ftp_host' => 'olt.example.net', 'ftp_passive_address' => '192.0.2.30', 'ftp_port' => 21,
        ])->assertRedirect(route('devices.edit', [$device, 'olt_wizard' => 1]));
        $this->assertDatabaseHas('application_settings', [
            'id' => 1, 'ftp_host' => 'olt.example.net', 'ftp_passive_address' => '192.0.2.30', 'ftp_port' => 21,
        ]);
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'olt');
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('value="olt.example.net"', false)
            ->assertSee('value="192.0.2.30"', false)
            ->assertSee('value="21"', false)
            ->assertSee('data-wizard-step="4"', false)
            ->assertDontSee('synthetic-only-secret');
    }

    public function test_server_settings_reject_invalid_addresses_and_ports(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        foreach (['bad host', 'https://ftp.example.net', '-bad.example.net', '2001:db8::zz', '999.999.999.999'] as $address) {
            $this->post(route('devices.olt-ftp.server', $device), [
                'ftp_host' => $address, 'ftp_passive_address' => '', 'ftp_port' => 21,
            ])->assertSessionHasErrors('ftp_host');
        }
        foreach ([0, 65536, 'abc'] as $port) {
            $this->post(route('devices.olt-ftp.server', $device), [
                'ftp_host' => '192.0.2.20', 'ftp_passive_address' => '', 'ftp_port' => $port,
            ])->assertSessionHasErrors('ftp_port');
        }
        $this->post(route('devices.olt-ftp.server', $device), [
            'ftp_host' => '192.0.2.20', 'ftp_passive_address' => 'invalid/address', 'ftp_port' => 21,
        ])->assertSessionHasErrors('ftp_passive_address');
        $this->assertDatabaseHas('application_settings', ['id' => 1, 'ftp_host' => null]);
    }

    public function test_server_settings_reject_passive_address_different_from_compose(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_passive_address', '192.0.2.30');

        $this->post(route('devices.olt-ftp.server', $device), [
            'ftp_host' => '192.0.2.20', 'ftp_passive_address' => '192.0.2.31', 'ftp_port' => 21,
        ])->assertSessionHasErrors('ftp_passive_address');
        $this->assertDatabaseHas('application_settings', ['id' => 1, 'ftp_host' => null]);
    }

    public function test_server_settings_keep_passive_fallback_and_default_port(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '');
        config()->set('backup.ftp_passive_address', 'passive.example.net');

        $this->post(route('devices.olt-ftp.server', $device), ['ftp_host' => '2001:db8::20', 'ftp_passive_address' => 'passive.example.net'])
            ->assertRedirect();
        $this->assertDatabaseHas('application_settings', [
            'id' => 1, 'ftp_host' => '2001:db8::20', 'ftp_passive_address' => 'passive.example.net', 'ftp_port' => 21,
        ]);
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('value="2001:db8::20"', false)
            ->assertSee('value="passive.example.net"', false)
            ->assertSee('value="21"', false);
    }

    public function test_server_settings_use_fallbacks_when_fields_are_missing_or_null(): void
    {
        config()->set('backup.ftp_host', '192.0.2.20');
        config()->set('backup.ftp_passive_address', '192.0.2.30');
        $expected = ['host' => '192.0.2.20', 'passive_address' => '192.0.2.30', 'port' => 21];

        $this->assertSame($expected, app(FtpServerSettings::class)->get());

        $query = \Mockery::mock();
        $query->shouldReceive('where')->once()->with('id', 1)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['id' => 1]);
        DB::shouldReceive('table')->once()->with('application_settings')->andReturn($query);
        $this->assertSame($expected, app(FtpServerSettings::class)->get());
    }

    public function test_execution_22_accepts_provisioned_device_4_account_and_saved_ftp_host(): void
    {
        $this->resetHistoricalFixtureSequences();
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        for ($id = 1; $id <= 3; $id++) {
            Device::create(['site_id' => $site->id, 'name' => 'Other '.$id,
                'management_ip' => '192.0.2.'.$id, 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        }
        [$device, $policy] = $this->fixture(false);
        $this->assertSame(4, $device->id);

        $account = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev4', 'is_active' => true]);
        $account->secret = 'Manual!Password123';
        $account->save();
        $this->assertSame(1, $account->id);
        DB::table('ftp_accounts')->where('id', $account->id)->update([
            'provisioned_at' => now()->subMinute(), 'sync_error' => null,
        ]);
        config()->set('backup.ftp_host', '');
        DB::table('application_settings')->where('id', 1)->update(['ftp_host' => '192.0.2.20']);

        $association = DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'credential_id' => null, 'is_active' => true]);
        for ($id = 1; $id <= 21; $id++) {
            // Cancel immediately: only bumping the execution id sequence up to
            // 22 here, not exercising concurrency — ENGINE-2's per-device busy
            // guard (BackupExecution::LIVE_STATUSES) would otherwise reject the
            // next iteration since a 'pending' execution is still live.
            BackupExecution::createManual($association)->transitionTo('cancelled');
        }
        $this->actingAs(User::factory()->create());
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $execution = $device->fresh()->oltFtpIntegration->testExecution;
        $this->assertSame(22, $execution->id);
        $this->assertSame('queued', $execution->status);

        $engine = app(EngineJobService::class);
        $this->assertSame($execution->id, $engine->claim()->id);
        $payload = $engine->job($execution->id);
        $this->assertTrue($payload['ftp_account_available']);
        $this->assertTrue($payload['eligible']);
        $this->assertSame('192.0.2.20', $payload['ftp_host']);
        $this->assertSame('bmdev4', $payload['ftp_username']);
        $this->assertSame('bm-exec-22.cfg', $payload['ftp_filename']);
        $this->assertStringNotContainsString('Manual!Password123', json_encode($payload));

        DB::table('ftp_accounts')->where('id', $account->id)->update(['sync_error' => 'Falha de sincronização']);
        $this->assertFalse($engine->job($execution->id)['ftp_account_available']);
        DB::table('ftp_accounts')->where('id', $account->id)->update(['sync_error' => null, 'provisioned_at' => null]);
        $this->assertFalse($engine->job($execution->id)['ftp_account_available']);
        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now(), 'is_active' => false]);
        $this->assertFalse($engine->job($execution->id)['ftp_account_available']);
    }

    private function resetHistoricalFixtureSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // RefreshDatabase rolls back rows, but PostgreSQL sequence increments survive rollback.
        foreach (['devices', 'ftp_accounts', 'backup_executions'] as $table) {
            $this->assertDatabaseCount($table, 0);
            DB::statement('ALTER SEQUENCE '.$table.'_id_seq RESTART WITH 1');
        }
    }

    public function test_ftp_requires_olt_account_and_no_ssh_credential(): void
    {
        [$device, $policy] = $this->fixture(false);
        $this->actingAs(User::factory()->create())->post(route('backup-policies.associations.store', $policy), [
            'device_id' => $device->id, 'is_active' => 1,
        ])->assertSessionHasErrors('device_id');
        $ftp = new FtpAccount(['account_uuid' => (string) Str::uuid(), 'home_layout' => 'legacy', 'purpose' => 'backup', 'device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $ftp->secret = 'synthetic-only-secret';
        $ftp->save();
        $this->post(route('backup-policies.associations.store', $policy), [
            'device_id' => $device->id, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNull(DeviceBackupPolicy::firstOrFail()->credential_id);
        $job = $this->runningJob($policy, $device);
        // A manual OLT FTP job cannot become a manual network FTP job:
        // routers and switches send their files spontaneously.
        $device->update(['platform' => 'network']);
        $this->assertFalse(app(EngineJobService::class)->job($job->id)['eligible']);
        $device->update(['vendor' => 'ZTE']);
        $this->assertFalse(app(EngineJobService::class)->job($job->id)['eligible']);
    }

    private function runningJob(BackupPolicy $policy, Device $device): BackupExecution
    {
        $association = DeviceBackupPolicy::firstOrCreate(['device_id' => $device->id, 'backup_policy_id' => $policy->id], ['credential_id' => null, 'is_active' => true]);
        $job = BackupExecution::createManual($association);
        $job->transitionTo('queued');
        app(EngineJobService::class)->claim();

        return $job;
    }

    public function test_integrity_is_required_but_content_recognition_is_optional(): void
    {
        [$device, $policy] = $this->fixture();
        $job = $this->runningJob($policy, $device);
        $engine = app(EngineJobService::class);
        $root = sys_get_temp_dir().'/olt-ftp-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        $relative = $engine->relativePath($job);
        $this->assertMatchesRegularExpression('~\ABackup Manager/LAB/OLT/[0-9]{2}-[0-9]{2}-[0-9]{4}/OLT_[0-9]{14}\.cfg\z~', $relative);
        $path = $root.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        try {
            file_put_contents($path, '');
            try {
                $engine->complete($job->id, $relative);
                $this->fail('Invalid artifact accepted');
            } catch (ValidationException) {
                $this->assertSame('running', $job->fresh()->status);
            }
            $valid = file_get_contents($this->ma5800FixturePath());
            $ftpMaxBytes = config('backup.ftp_max_bytes');
            config()->set('backup.ftp_max_bytes', strlen($valid) - 1);
            file_put_contents($path, $valid);
            try {
                $engine->complete($job->id, $relative);
                $this->fail('Oversized artifact accepted');
            } catch (ValidationException) {
                $this->assertSame('running', $job->fresh()->status);
            }
            config()->set('backup.ftp_max_bytes', $ftpMaxBytes);
            $valid = str_replace('[global-config]', str_repeat(" board add 0/2 H901X\n", 300).'[global-config]', $valid);
            file_put_contents($path, $valid);
            $artifact = $engine->complete($job->id, $relative);
            $this->assertSame(hash('sha256', $valid), $artifact->sha256);
            $this->assertSame('succeeded', $job->fresh()->status);
            $this->assertSame('available', $artifact->fresh()->status);
            $this->assertDatabaseCount('backup_artifacts', 1);
            $analysis = json_decode(DB::table('audit_events')->where('action', 'backup.content_analyzed')->value('metadata'), true);
            $this->assertSame('recognized', $analysis['status']);
            $this->assertSame(1, app(BackupRetention::class)->run(false)['scanned']);
            try {
                $engine->complete($job->id, $relative);
                $this->fail('Duplicate artifact accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('backup_artifacts', 1);
            }
        } finally {
            unlink($path);
            $dir = dirname($path);
            while ($dir !== $root) {
                rmdir($dir);
                $dir = dirname($dir);
            }
            rmdir($root);
        }
    }

    public function test_ftp_policy_accepts_manual_and_rejects_daily_and_weekly(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = ['name' => 'OLT manual', 'method' => 'ftp_push', 'artifact_mode' => 'config',
            'schedule_type' => 'manual', 'retention_count' => 2, 'is_active' => 1];
        $this->get(route('backup-policies.create'))->assertOk()
            ->assertSee('Modelo pré-definido')
            ->assertSee('Configuração via FTP · envio pelo equipamento · 90 dias')
            ->assertSee('Para receber backup FTP todos os dias, configure o envio automático em cada equipamento Huawei. O Backup Manager recebe os arquivos; esta política não agenda o envio. O teste da OLT continua manual.');
        $this->post(route('backup-policies.store'), $payload)->assertSessionHasNoErrors();
        $policy = BackupPolicy::firstOrFail();
        $this->assertSame('manual', $policy->schedule_type);
        foreach (['daily', 'weekly'] as $type) {
            $automatic = array_replace($payload, ['schedule_type' => $type, 'schedule_time' => '07:00',
                'schedule_weekday' => $type === 'weekly' ? 2 : null]);
            $this->post(route('backup-policies.store'), $automatic)->assertSessionHasErrors('schedule_type');
            $this->put(route('backup-policies.update', $policy), $automatic)->assertSessionHasErrors('schedule_type');
        }
        $this->assertDatabaseCount('backup_policies', 1);
        $this->assertSame('manual', $policy->fresh()->schedule_type);
    }

    public function test_legacy_automatic_ftp_is_not_associated_manually_started_or_scheduled(): void
    {
        [$device, $policy] = $this->fixture();
        $this->actingAs(User::factory()->create());
        $policy->update(['schedule_type' => 'daily', 'schedule_time' => '07:00']);
        $this->post(route('backup-policies.associations.store', $policy), [
            'device_id' => $device->id, 'is_active' => 1,
        ])->assertSessionHasErrors('schedule_type');
        $association = DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'credential_id' => null, 'is_active' => true]);
        $this->patch(route('backup-policies.associations.update', [$policy, $association]),
            ['is_active' => 1])->assertSessionHasErrors('schedule_type');
        try {
            BackupExecution::createManual($association);
            $this->fail('Legacy FTP policy started');
        } catch (ValidationException) {
            $this->assertDatabaseCount('backup_executions', 0);
        }

        $weekly = BackupPolicy::create(['name' => 'OLT weekly legacy', 'method' => 'ftp_push',
            'artifact_mode' => 'config', 'schedule_type' => 'weekly', 'schedule_time' => '07:00',
            'schedule_weekday' => 2, 'retention_count' => 2, 'is_active' => true]);
        DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $weekly->id,
            'credential_id' => null, 'is_active' => true]);
        $this->assertSame(0, app(BackupScheduler::class)->run(CarbonImmutable::parse('2026-09-22 10:00:15 UTC')));
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_manual_execution_shows_expected_filename_without_ftp_secret(): void
    {
        [$device, $policy] = $this->fixture();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'credential_id' => null, 'is_active' => true]);
        $execution = BackupExecution::createManual($association);
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->actingAs(User::factory()->create())
            ->get(route('backup-executions.show', $execution))->assertOk()
            ->assertSee('bm-exec-'.$execution->id.'.cfg')
            ->assertSee('backup configuration ftp 192.0.2.20 bm-exec-'.$execution->id.'.cfg')
            ->assertDontSee('synthetic-only-secret');
    }

    public function test_wizard_tracks_real_readiness_confirmation_and_validated_test(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');

        $this->get(route('devices.olt-ftp.status', $device))->assertOk()->assertJsonPath('state', 'olt');
        $this->post(route('devices.olt-ftp.confirm', $device))->assertSessionHasErrors('olt_configured');
        $this->post(route('devices.olt-ftp.test', $device))->assertSessionHasErrors('wizard');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'test');
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('Iniciar teste de integração')
            ->assertSee('data-wizard-panel="5" aria-label="Teste de integração"', false);
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $execution = $device->fresh()->oltFtpIntegration->testExecution;
        $this->assertSame('queued', $execution->status);
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('backup configuration ftp 192.0.2.20 bm-exec-'.$execution->id.'.cfg')
            ->assertSee('Esta tela será atualizada automaticamente quando o arquivo for recebido e validado.')
            ->assertDontSee('synthetic-only-secret');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'waiting');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('current_step', 5);

        app(EngineJobService::class)->claim();
        $root = sys_get_temp_dir().'/olt-wizard-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config()->set('backup.storage_root', $root);
        $relative = app(EngineJobService::class)->relativePath($execution);
        $path = $root.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        try {
            file_put_contents($path, file_get_contents($this->ma5800FixturePath()));
            app(EngineJobService::class)->complete($execution->id, $relative);
            $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'operational');
            $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('current_step', 6);
            $this->get(route('devices.edit', $device))->assertSee('Integração operacional.')
                ->assertSee('id="finish-olt-wizard"', false)
                ->assertSee('Concluir');
        } finally {
            unlink($path);
            $dir = dirname($path);
            while ($dir !== $root) {
                rmdir($dir);
                $dir = dirname($dir);
            }
            rmdir($root);
        }

        config()->set('backup.ftp_host', '192.0.2.21');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'olt');
    }

    public function test_wizard_failure_allows_retry_with_new_filename(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1']);
        $this->post(route('devices.olt-ftp.test', $device));
        $first = $device->fresh()->oltFtpIntegration->testExecution;
        $this->post(route('devices.olt-ftp.test', $device));
        $this->assertSame($first->id, $device->fresh()->oltFtpIntegration->test_execution_id);
        app(EngineJobService::class)->claim();
        app(EngineJobService::class)->fail($first->id, 'FTP_FILE_INVALID');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'failed');
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('current_step', 5);
        $this->get(route('devices.edit', $device))->assertSee('Arquivo FTP inválido.')->assertSee('Tentar novamente');
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $second = $device->fresh()->oltFtpIntegration->testExecution;
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('queued', $second->status);
        $this->assertSame('failed', $first->fresh()->status);
        $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('Execução de teste #'.$second->id)
            ->assertSee('bm-exec-'.$second->id.'.cfg')
            ->assertSee('backup configuration ftp 192.0.2.20 bm-exec-'.$second->id.'.cfg')
            ->assertSee('Copiar comando')
            ->assertSee('Cada nova tentativa gera um novo número de execução e um novo nome de arquivo. Use sempre o comando exibido nesta tentativa.')
            ->assertDontSee('backup configuration ftp 192.0.2.20 bm-exec-'.$first->id.'.cfg');
        $this->assertSame($first->backup_policy_id, $second->backup_policy_id);
        $this->post(route('devices.ftp-account.rotate', $device))->assertOk();
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'sync');
    }

    public function test_failed_execution_keeps_only_first_four_steps_complete_and_reopens_at_five(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $execution = $device->fresh()->oltFtpIntegration->testExecution;
        app(EngineJobService::class)->claim();
        app(EngineJobService::class)->fail($execution->id, 'FTP_ACCOUNT_UNAVAILABLE');

        $snapshot = app(OltFtpWizard::class)->snapshot($device);
        $this->assertSame(5, $snapshot['current_step']);
        $this->assertFalse($snapshot['operational']);
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('state', 'failed')
            ->assertJsonPath('current_step', 5);

        $html = $this->get(route('devices.edit', [$device, 'olt_wizard' => 1]))->assertOk()
            ->assertSee('data-current-step="5"', false)
            ->assertSee('Etapa 5 de 6')
            ->assertSee('Tentar novamente')
            ->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        foreach ([1, 2, 3, 4] as $step) {
            $item = $xpath->query('//li[@data-wizard-step-item="'.$step.'"]')->item(0);
            $this->assertStringContainsString('is-complete', $item->getAttribute('class'));
            $this->assertSame('Concluído', trim($xpath->query('.//small', $item)->item(0)->textContent));
        }
        $current = $xpath->query('//li[@data-wizard-step-item="5"]')->item(0);
        $this->assertStringContainsString('is-current', $current->getAttribute('class'));
        $this->assertSame('Etapa atual', trim($xpath->query('.//small', $current)->item(0)->textContent));
        $future = $xpath->query('//li[@data-wizard-step-item="6"]')->item(0);
        $this->assertStringContainsString('is-pending', $future->getAttribute('class'));
        $this->assertSame('Pendente', trim($xpath->query('.//small', $future)->item(0)->textContent));
        $this->assertStringContainsString('const active = number === currentStep;', $html);
        $this->assertStringContainsString('showStep(currentStep); dialog.showModal(); poll();', $html);
        $this->assertSame(5, app(OltFtpWizard::class)->snapshot($device)['current_step']);
    }

    public function test_account_operational_update_does_not_rewind_confirmed_wizard(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $account = $device->ftpAccount()->firstOrFail();
        DB::table('ftp_accounts')->where('id', $account->id)->update(['updated_at' => now()->addMinute()]);
        $snapshot = app(OltFtpWizard::class)->snapshot($device->fresh());
        $this->assertTrue($snapshot['synced']);
        $this->assertTrue((bool) $snapshot['confirmed']);
        $this->assertSame(5, $snapshot['current_step']);
    }

    public function test_provisioning_sync_error_retry_and_polling_preserve_confirmation_and_test(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $integration = $device->fresh()->oltFtpIntegration;
        $confirmedAt = $integration->getRawOriginal('olt_confirmed_at');
        $executionId = $integration->test_execution_id;
        $updatedAt = $integration->getRawOriginal('updated_at');
        $account = $device->ftpAccount()->firstOrFail();

        $this->patch(route('devices.ftp-account.update', $device), ['is_active' => true])->assertRedirect();
        $this->assertDatabaseHas('olt_ftp_integrations', [
            'device_id' => $device->id, 'olt_confirmed_at' => $confirmedAt,
            'test_execution_id' => $executionId, 'updated_at' => $updatedAt,
        ]);
        $this->assertSame(2, app(OltFtpWizard::class)->snapshot($device->fresh())['current_step']);

        $this->artisan('ftp:sync-failed')->assertExitCode(0);
        $this->assertSame('sync_error', app(OltFtpWizard::class)->snapshot($device->fresh())['state']);
        $this->post(route('devices.ftp-account.retry', $device))->assertRedirect();
        Artisan::call('ftp:accounts');
        $version = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0]['updated_at'];
        $this->artisan('ftp:provisioned', ['id' => $account->id, 'version' => $version])->assertExitCode(0);
        DB::table('ftp_accounts')->where('id', $account->id)->update(['updated_at' => now()->addMinute()]);

        for ($poll = 0; $poll < 3; $poll++) {
            $this->get(route('devices.olt-ftp.status', $device))->assertOk()
                ->assertJsonPath('current_step', 5)->assertJsonPath('execution_id', $executionId);
        }
        $this->assertDatabaseHas('olt_ftp_integrations', [
            'device_id' => $device->id, 'olt_confirmed_at' => $confirmedAt,
            'test_execution_id' => $executionId, 'updated_at' => $updatedAt,
            'ftp_host' => '192.0.2.20',
        ]);
    }

    public function test_replacing_with_identical_manual_credentials_preserves_confirmation_and_test(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $before = $device->fresh()->oltFtpIntegration;

        $this->post(route('devices.ftp-account.replace', $device), [
            'username' => $device->ftpAccount->username,
            'password' => 'synthetic-only-secret', 'password_confirmation' => 'synthetic-only-secret',
        ])->assertOk();
        $after = $device->fresh()->oltFtpIntegration;
        $this->assertSame($before->getRawOriginal('olt_confirmed_at'), $after->getRawOriginal('olt_confirmed_at'));
        $this->assertSame($before->test_execution_id, $after->test_execution_id);
        $this->assertSame($before->getRawOriginal('updated_at'), $after->getRawOriginal('updated_at'));
    }

    public function test_real_password_and_username_changes_invalidate_confirmation_and_test(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        foreach (['password', 'username'] as $changedField) {
            $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
            $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
            $account = $device->ftpAccount()->firstOrFail();
            $oldSecret = $account->secret;
            $oldUsername = $account->username;
            $username = $changedField === 'username' ? 'new_olt_user'.$device->id : $oldUsername;
            $password = $changedField === 'password' ? 'NewSynthetic!Pass123' : $oldSecret;

            $this->post(route('devices.ftp-account.replace', $device), [
                'username' => $username,
                'password' => $password, 'password_confirmation' => $password,
            ])->assertOk();
            $integration = $device->fresh()->oltFtpIntegration;
            $this->assertNull($integration->olt_confirmed_at, $changedField);
            $this->assertNull($integration->test_execution_id, $changedField);
            $this->assertNotNull($integration->account_updated_at, $changedField);
            $this->assertSame('192.0.2.20', $integration->ftp_host);
            $this->assertSame(2, app(OltFtpWizard::class)->snapshot($device->fresh())['current_step']);
            DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now()]);
            $this->assertSame(4, app(OltFtpWizard::class)->snapshot($device->fresh())['current_step']);
        }
    }

    public function test_persisted_ftp_settings_must_be_valid_before_olt_step(): void
    {
        [$device] = $this->fixture();
        config()->set('backup.ftp_host', '');
        DB::table('application_settings')->where('id', 1)->update(['ftp_host' => '192.0.2.20', 'ftp_port' => 0]);
        $this->assertSame(4, app(OltFtpWizard::class)->snapshot($device)['current_step']);
        DB::table('application_settings')->where('id', 1)->update(['ftp_port' => 21, 'ftp_passive_address' => 'bad/address']);
        $this->assertSame(4, app(OltFtpWizard::class)->snapshot($device)['current_step']);
        DB::table('application_settings')->where('id', 1)->update(['ftp_passive_address' => null]);
        $this->assertSame(4, app(OltFtpWizard::class)->snapshot($device)['current_step']);
    }

    public function test_wizard_and_engine_agree_on_sync_error_contract(): void
    {
        [$device, $policy] = $this->fixture();
        config()->set('backup.ftp_host', '192.0.2.20');
        $association = DeviceBackupPolicy::create([
            'device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => null, 'is_active' => true,
        ]);
        $execution = BackupExecution::createManual($association);
        $execution->transitionTo('queued');
        app(EngineJobService::class)->claim();

        foreach ([null, '', 'erro qualquer'] as $error) {
            DB::table('ftp_accounts')->where('device_id', $device->id)->update(['sync_error' => $error]);
            $snapshot = app(OltFtpWizard::class)->snapshot($device->fresh());
            $available = app(EngineJobService::class)->job($execution->id)['ftp_account_available'];
            $this->assertSame($error === null, $snapshot['synced']);
            $this->assertSame($snapshot['synced'], $available);
            $this->assertSame($error === null ? 4 : 2, $snapshot['current_step']);
        }
    }

    public function test_all_nonvalidated_execution_statuses_keep_test_current(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $execution = $device->fresh()->oltFtpIntegration->testExecution;
        foreach (['pending', 'queued', 'running', 'failed', 'cancelled', 'succeeded'] as $status) {
            DB::table('backup_executions')->where('id', $execution->id)->update(['status' => $status]);
            $snapshot = app(OltFtpWizard::class)->snapshot($device);
            $this->assertTrue($snapshot['synced']);
            $this->assertSame(5, $snapshot['current_step'], $status);
            $this->assertFalse($snapshot['operational'], $status);
        }
    }

    public function test_succeeded_without_validated_artifact_stays_at_test_and_can_retry(): void
    {
        [$device] = $this->fixture();
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->post(route('devices.olt-ftp.confirm', $device), ['olt_configured' => '1'])->assertRedirect();
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $first = $device->fresh()->oltFtpIntegration->testExecution;
        DB::table('backup_executions')->where('id', $first->id)->update(['status' => 'succeeded']);
        $this->get(route('devices.olt-ftp.status', $device))->assertJsonPath('current_step', 5);
        $this->get(route('devices.edit', $device))->assertSee('Tentar novamente');
        $this->post(route('devices.olt-ftp.test', $device))->assertRedirect();
        $second = $device->fresh()->oltFtpIntegration->testExecution;
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('succeeded', $first->fresh()->status);
    }

    private function ma5800FixturePath(): string
    {
        $containerPath = '/engine/tests/fixtures/ma5800_ftp.cfg';

        return is_file($containerPath) ? $containerPath : dirname(__DIR__, 3).'/engine/tests/fixtures/ma5800_ftp.cfg';
    }
}
