<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\EngineJobService;
use App\Services\BackupScheduler;
use App\Services\BackupRetention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HuaweiOltFtpTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $account = true): array
    {
        $site = Site::create(['name' => 'Lab', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'OLT', 'management_ip' => '192.0.2.10', 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'OLT FTP', 'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'retention_count' => 2, 'is_active' => true]);
        if ($account) {
            $ftp = new FtpAccount(['device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
            $ftp->secret = 'synthetic-only-secret';
            $ftp->save();
            DB::table('ftp_accounts')->where('id', $ftp->id)->update(['provisioned_at' => now()->addSecond()]);
        }
        return [$device, $policy];
    }

    public function test_account_is_encrypted_hidden_and_only_shown_once(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->create())->post(route('devices.ftp-account.store', $device))
            ->assertOk()->assertSee('Senha FTP gerada')->assertHeader('Cache-Control', 'no-store, private');
        $account = $device->ftpAccount()->firstOrFail();
        $raw = DB::table('ftp_accounts')->where('id', $account->id)->value('secret');
        $this->assertNotSame($account->secret, $raw);
        $this->assertArrayNotHasKey('secret', $account->toArray());
        $this->artisan('ftp:provisioned', ['id' => $account->id, 'version' => $account->updated_at->toISOString()])->assertExitCode(0);
        $this->assertNotNull($account->fresh()->provisioned_at);
        $this->get(route('devices.edit', $device))->assertOk()->assertDontSee($account->secret)->assertDontSee($raw);
        $this->artisan('ftp:accounts')->doesntExpectOutputToContain($account->secret)->assertExitCode(0);
    }

    public function test_olt_ftp_instructions_follow_account_and_server_readiness(): void
    {
        [$device] = $this->fixture(false);
        $this->actingAs(User::factory()->create());
        config()->set('backup.ftp_host', '');

        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('O que fazer agora')
            ->assertSee('Não configure a OLT ainda.')
            ->assertSee('Crie a conta FTP deste equipamento')
            ->assertSee('O endereço do servidor FTP desta instalação ainda não foi configurado.')
            ->assertSee('Próximo passo no servidor:')
            ->assertSeeInOrder(['Sincronizar a conta no PureDB.', 'Configurar o endereço do servidor FTP.'])
            ->assertDontSee('SSH Host Key')
            ->assertDontSee('Configure BACKUP_FTP_HOST');

        $account = new FtpAccount(['device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $account->secret = 'synthetic-only-secret';
        $account->save();
        config()->set('backup.ftp_host', '192.0.2.20');
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Não configure a OLT ainda.')
            ->assertSee('Sincronizar a conta no PureDB.')
            ->assertDontSee('<strong>Servidor:</strong> 192.0.2.20', false);

        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now()->addSecond()]);
        config()->set('backup.ftp_host', '');
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Não configure a OLT ainda.')
            ->assertSee('O endereço do servidor FTP desta instalação ainda não foi configurado.')
            ->assertSee('Configurar o endereço do servidor FTP.')
            ->assertDontSee('<strong>Usuário:</strong> bmdev'.$device->id, false);

        config()->set('backup.ftp_host', '192.0.2.20');
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Pronto para configurar a OLT.')
            ->assertSee('<strong>Servidor:</strong> 192.0.2.20', false)
            ->assertSee('<strong>Porta:</strong> 21', false)
            ->assertSee('<strong>Usuário:</strong> bmdev'.$device->id, false)
            ->assertSee('Diretório remoto:')
            ->assertSee('Configuração inicial da OLT — feita uma única vez')
            ->assertSee('Execução de backup — feita a cada backup')
            ->assertSee('A V2 não executa')
            ->assertDontSee('SSH Host Key')
            ->assertDontSee('Não configure a OLT ainda.')
            ->assertDontSee('synthetic-only-secret');

        $account->update(['is_active' => false]);
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('Não configure a OLT ainda.')
            ->assertSee('Ative a conta FTP deste equipamento.');

        $device->update(['platform' => 'network']);
        $this->get(route('devices.edit', $device))->assertOk()
            ->assertSee('SSH Host Key')
            ->assertDontSee('O que fazer agora');
    }

    public function test_ftp_requires_olt_account_and_no_ssh_credential(): void
    {
        [$device, $policy] = $this->fixture(false);
        $this->actingAs(User::factory()->create())->post(route('backup-policies.associations.store', $policy), [
            'device_id' => $device->id, 'is_active' => 1,
        ])->assertSessionHasErrors('device_id');
        $ftp = new FtpAccount(['device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $ftp->secret = 'synthetic-only-secret';
        $ftp->save();
        $this->post(route('backup-policies.associations.store', $policy), [
            'device_id' => $device->id, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNull(DeviceBackupPolicy::firstOrFail()->credential_id);
        $job = $this->runningJob($policy, $device);
        $device->update(['platform' => 'network']);
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

    public function test_validated_ftp_file_is_the_only_path_to_success(): void
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
            file_put_contents($path, 'invalid');
            try { $engine->complete($job->id, $relative); $this->fail('Invalid artifact accepted'); }
            catch (ValidationException) { $this->assertSame('running', $job->fresh()->status); }
            $valid = "#\nsysname OLT-Lab\n#\ninterface gpon 0/1\n description lab\n#\n";
            file_put_contents($path, $valid);
            $artifact = $engine->complete($job->id, $relative);
            $this->assertSame(hash('sha256', $valid), $artifact->sha256);
            $this->assertSame('succeeded', $job->fresh()->status);
            $this->assertSame('available', $artifact->fresh()->status);
            $this->assertDatabaseCount('backup_artifacts', 1);
            $this->assertSame(1, app(BackupRetention::class)->run(false)['scanned']);
            try { $engine->complete($job->id, $relative); $this->fail('Duplicate artifact accepted'); }
            catch (ValidationException) { $this->assertDatabaseCount('backup_artifacts', 1); }
        } finally {
            unlink($path);
            $dir = dirname($path);
            while ($dir !== $root) { rmdir($dir); $dir = dirname($dir); }
            rmdir($root);
        }
    }

    public function test_ftp_policy_accepts_manual_and_rejects_daily_and_weekly(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = ['name' => 'OLT manual', 'method' => 'ftp_push', 'artifact_mode' => 'config',
            'schedule_type' => 'manual', 'retention_count' => 2, 'is_active' => 1];
        $this->get(route('backup-policies.create'))->assertOk()
            ->assertSee('Nesta fase, Huawei OLT via FTP Push suporta somente execução manual.');
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
        try { BackupExecution::createManual($association); $this->fail('Legacy FTP policy started'); }
        catch (ValidationException) { $this->assertDatabaseCount('backup_executions', 0); }

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
}
