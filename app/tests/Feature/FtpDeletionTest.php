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
use App\Services\EngineJobService;
use App\Services\FtpAccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FtpDeletionTest extends TestCase
{
    use RefreshDatabase;

    private string $ftpRoot;
    private string $backupRoot;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/ftp-core2-test-'.bin2hex(random_bytes(8));
        $this->ftpRoot = $base.'/ftp';
        $this->backupRoot = $base.'/backups';
        mkdir($this->ftpRoot, 0700, true);
        mkdir($this->backupRoot, 0700, true);
        config(['backup.ftp_root' => $this->ftpRoot, 'backup.storage_root' => $this->backupRoot]);
        $this->adminUser = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->adminUser);
    }

    protected function tearDown(): void
    {
        $this->erase(dirname($this->ftpRoot));
        parent::tearDown();
    }

    private function erase(string $path): void
    {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (! is_dir($path)) return;
        foreach (new \FilesystemIterator($path) as $item) $this->erase($item->getPathname());
        rmdir($path);
    }

    private function account(string $purpose = 'backup', string $layout = 'account', string $name = 'olt_test'): FtpAccount
    {
        $device = null;
        if ($purpose === 'backup') {
            $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
            $device = Device::create(['site_id' => $site->id, 'name' => $name, 'management_ip' => '192.0.2.'.(10 + Device::count()),
                'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
        }
        $account = new FtpAccount(['device_id' => $device?->id, 'account_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'purpose' => $purpose, 'home_layout' => $layout, 'username' => $name, 'is_active' => true]);
        $account->secret = 'SecurePassword123!';
        $account->save();
        mkdir($account->homePath(), 0700, true);
        $this->physicalReport($account);
        return $account;
    }

    private function physicalReport(FtpAccount $account, array $changes = []): void
    {
        $report = array_replace_recursive([
            'status' => 'ok', 'safe' => true, 'home_exists' => true,
            'incoming' => ['files' => 0, 'bytes' => 0], 'processing' => ['files' => 0, 'bytes' => 0],
            'quarantine' => ['files' => 0, 'bytes' => 0], 'blockers' => [],
        ], $changes);
        app(FtpAccountDeletionService::class)->physicalReport($account->id, $account->getRawOriginal('updated_at'),
            base64_encode(json_encode($report)));
    }

    private function privilegedResult(FtpAccount $account, int $files = 0, int $bytes = 0): string
    {
        return base64_encode(json_encode(['status' => 'ok', 'files_removed' => $files, 'bytes_removed' => $bytes]));
    }

    private function receipt(FtpAccount $account, string $token, string $status, ?string $relative = null, int $size = 4): void
    {
        DB::table('ftp_received_files')->insert(['ftp_account_id' => $account->id, 'claim_token' => $token,
            'original_filename' => 'test.cfg', 'size_bytes' => $size, 'sha256' => hash('sha256', 'data'),
            'status' => $status, 'relative_path' => $relative, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function execution(FtpAccount $account, string $content = 'data'): BackupExecution
    {
        $policy = BackupPolicy::create(['name' => 'FTP '.$account->id, 'method' => 'ftp_push', 'artifact_mode' => 'config',
            'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $account->device_id, 'name' => 'FTP', 'type' => 'ftp', 'username' => $account->username, 'is_active' => true]);
        $credential->secret = 'SecurePassword123!';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $account->device_id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
        $job = BackupExecution::create(['device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id,
            'device_id' => $account->device_id, 'credential_id' => $credential->id, 'ftp_account_id' => $account->id,
            'origin' => 'ftp_received', 'status' => 'succeeded', 'attempt' => 1]);
        $relative = app(EngineJobService::class)->relativePath($job);
        $path = $this->backupRoot.'/'.$relative;
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $content);
        BackupArtifact::create(['backup_execution_id' => $job->id, 'device_id' => $job->device_id,
            'backup_policy_id' => $policy->id, 'type' => 'config', 'storage' => 'local', 'relative_path' => $relative,
            'original_filename' => 'test.cfg', 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'validated_at' => now()]);
        return $job;
    }

    private function requestAndFinalize(FtpAccount $account, string $mode): void
    {
        $phrase = ['account' => 'EXCLUIR ', 'ftp_data' => 'EXCLUIR DADOS ', 'all' => 'APAGAR TUDO '][$mode].$account->username;
        $this->delete(route('ftp.delete', $account), ['mode' => $mode, 'confirmation' => $phrase])->assertRedirect();
        $this->assertFalse($account->fresh()->is_active);
        Artisan::call('ftp:accounts');
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse(collect($rows)->firstWhere('id', $account->id)['is_active']);
        if ($mode !== 'account') {
            foreach (new \FilesystemIterator($account->homePath()) as $item) unlink($item->getPathname());
            foreach (['quarantine', 'processing'] as $area) {
                $directory = $this->ftpRoot.'/'.$area;
                if (is_dir($directory)) foreach (new \FilesystemIterator($directory) as $item) unlink($item->getPathname());
            }
        }
        app(FtpAccountDeletionService::class)->finalize($account->fresh(), $this->privilegedResult($account));
    }

    public function test_preview_and_account_only_preserve_backups_and_release_device(): void
    {
        foreach (['legacy', 'account'] as $layout) {
            $account = $this->account('backup', $layout, 'olt_'.$layout);
            $job = $this->execution($account);
            $this->receipt($account, str_repeat($layout === 'legacy' ? 'a' : 'b', 32), 'stored', $job->artifact->relative_path);
            $preview = app(FtpAccountDeletionService::class)->preview($account);
            $this->assertSame(1, $preview['receipts']);
            $this->assertSame(4, $preview['artifact_bytes']);
            $this->assertTrue($preview['device_released']);
            $this->requestAndFinalize($account, 'account');
            $this->assertDatabaseMissing('ftp_accounts', ['id' => $account->id]);
            $this->assertDatabaseHas('backup_executions', ['id' => $job->id, 'ftp_account_id' => null]);
            $this->assertDatabaseHas('ftp_received_files', ['claim_token' => str_repeat($layout === 'legacy' ? 'a' : 'b', 32), 'ftp_account_id' => null]);
            $this->assertFileExists($this->backupRoot.'/'.$job->artifact->relative_path);
            $this->assertFalse($account->device->ftpAccount()->exists());
            $this->get(route('ftp.index'))->assertOk()->assertSee($account->device->name);
            $this->post(route('ftp.store'), ['purpose' => 'backup', 'device_id' => $account->device_id,
                'username' => 'replacement_'.$layout, 'password' => 'AnotherSecurePassword123!',
                'password_confirmation' => 'AnotherSecurePassword123!'])->assertOk();
            $this->assertSame('replacement_'.$layout, $account->device->fresh()->ftpAccount->username);
        }
    }

    public function test_delete_and_recreate_huawei_account_preserves_operational_policy(): void
    {
        $account = $this->account();
        $policy = app(\App\Services\HuaweiFtpBackupPolicy::class)->ensure($account->device);
        $this->requestAndFinalize($account, 'account');
        $this->assertDatabaseHas('device_backup_policies', ['id' => $policy->id, 'is_active' => true]);
        $this->post(route('ftp.store'), ['device_id' => $account->device_id, 'username' => 'recreated_olt',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertOk();
        $fresh = FtpAccount::where('username', 'recreated_olt')->firstOrFail();
        $this->assertNotSame($account->account_uuid, $fresh->account_uuid);
        $this->assertDatabaseCount('device_backup_policies', 1);
        DB::table('ftp_accounts')->where('id', $fresh->id)->update(['provisioned_at' => now(), 'sync_error' => null]);
        $result = app(EngineJobService::class)->receiveFtp($fresh->device_id, bin2hex(random_bytes(16)),
            'olt.cfg', time(), bin2hex(random_bytes(16)));
        $this->assertSame('running', $result['status']);
        $this->assertSame($fresh->id, $result['ftp_account_id']);
    }

    public function test_ftp_data_removes_transient_files_but_preserves_artifact(): void
    {
        $account = $this->account();
        $job = $this->execution($account);
        file_put_contents($account->homePath().'/upload.cfg', 'incoming');
        touch($account->homePath().'/upload.cfg', time() - 120);
        $token = str_repeat('c', 32);
        $this->receipt($account, $token, 'quarantined');
        mkdir($this->ftpRoot.'/quarantine');
        file_put_contents($this->ftpRoot.'/quarantine/'.$token.'.quarantine', 'bad');
        file_put_contents($this->ftpRoot.'/quarantine/'.$token.'.json', json_encode(['ftp_account_id' => $account->id]));
        $this->physicalReport($account, ['incoming' => ['files' => 1], 'quarantine' => ['files' => 2]]);
        $this->requestAndFinalize($account, 'ftp_data');
        $this->assertFileDoesNotExist($account->homePath().'/upload.cfg');
        $this->assertFileDoesNotExist($this->ftpRoot.'/quarantine/'.$token.'.quarantine');
        $this->assertDatabaseMissing('ftp_received_files', ['claim_token' => $token]);
        $this->assertDatabaseHas('backup_artifacts', ['backup_execution_id' => $job->id]);
    }

    public function test_all_removes_only_linked_artifact_and_execution(): void
    {
        $account = $this->account();
        $other = $this->account('backup', 'account', 'other_olt');
        $job = $this->execution($account, 'first');
        $otherJob = $this->execution($other, 'other');
        $path = $this->backupRoot.'/'.$job->artifact->relative_path;
        $otherPath = $this->backupRoot.'/'.$otherJob->artifact->relative_path;
        $this->requestAndFinalize($account, 'all');
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($otherPath);
        $this->assertDatabaseMissing('backup_executions', ['id' => $job->id]);
        $this->assertDatabaseHas('backup_executions', ['id' => $otherJob->id]);
        $this->assertDatabaseHas('devices', ['id' => $account->device_id]);
    }

    public function test_file_server_modes_and_path_safety(): void
    {
        $account = $this->account('file_server', 'account', 'files_one');
        $token = str_repeat('d', 32);
        $relative = 'ftp-files/'.$account->account_uuid.'/'.$token;
        mkdir(dirname($this->backupRoot.'/'.$relative), 0700, true);
        file_put_contents($this->backupRoot.'/'.$relative, 'data');
        $this->receipt($account, $token, 'stored', $relative);
        $preview = app(FtpAccountDeletionService::class)->preview($account);
        $this->assertSame(1, $preview['stored_files']);
        $this->assertSame(4, $preview['stored_bytes']);
        $this->assertSame(0, $preview['executions']);
        $this->requestAndFinalize($account, 'ftp_data');
        $this->assertFileDoesNotExist($this->backupRoot.'/'.$relative);
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_file_server_account_only_preserves_stored_file_and_history(): void
    {
        $account = $this->account('file_server', 'account', 'files_keep');
        $token = str_repeat('1', 32);
        $relative = 'ftp-files/'.$account->account_uuid.'/'.$token;
        mkdir(dirname($this->backupRoot.'/'.$relative), 0700, true);
        file_put_contents($this->backupRoot.'/'.$relative, 'data');
        $this->receipt($account, $token, 'stored', $relative);
        $this->requestAndFinalize($account, 'account');
        $this->assertFileExists($this->backupRoot.'/'.$relative);
        $this->assertDatabaseHas('ftp_received_files', ['claim_token' => $token, 'ftp_account_id' => null]);
    }

    public function test_invalid_confirmation_active_claim_symlink_and_authorization(): void
    {
        $account = $this->account();
        $this->delete(route('ftp.delete', $account), ['mode' => 'all', 'confirmation' => 'EXCLUIR '.$account->username])
            ->assertSessionHasErrors('confirmation');
        $this->assertTrue($account->fresh()->is_active);
        $token = str_repeat('e', 32);
        $this->receipt($account, $token, 'processing');
        $this->delete(route('ftp.delete', $account), ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS '.$account->username])
            ->assertSessionHasErrors('mode');
        DB::table('ftp_received_files')->where('claim_token', $token)->delete();
        symlink('/etc/hosts', $account->homePath().'/link');
        $this->physicalReport($account, ['status' => 'error', 'safe' => false, 'blockers' => ['symlink_detected']]);
        $this->delete(route('ftp.delete', $account), ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS '.$account->username])
            ->assertSessionHasErrors('mode');
        unlink($account->homePath().'/link');
        $this->physicalReport($account);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->delete(route('ftp.delete', $account), ['mode' => 'account', 'confirmation' => 'EXCLUIR '.$account->username])->assertForbidden();
    }

    public function test_each_mode_accepts_only_its_exact_confirmation(): void
    {
        $prefixes = ['account' => 'EXCLUIR ', 'ftp_data' => 'EXCLUIR DADOS ', 'all' => 'APAGAR TUDO '];
        $number = 0;

        foreach ($prefixes as $mode => $prefix) {
            foreach ($prefixes as $otherMode => $otherPrefix) {
                if ($otherMode === $mode) continue;
                $account = $this->account('backup', 'account', 'olt_contract_'.(++$number));
                $this->delete(route('ftp.delete', $account), ['mode' => $mode, 'confirmation' => $otherPrefix.$account->username])
                    ->assertSessionHasErrors(['confirmation' => 'A frase de confirmação não corresponde ao modo selecionado.']);
                $this->assertTrue($account->fresh()->is_active);
                $this->assertNull($account->fresh()->deletion_mode);
            }

            $account = $this->account('backup', 'account', 'olt_contract_'.(++$number));
            $this->delete(route('ftp.delete', $account), ['mode' => $mode, 'confirmation' => $prefix.$account->username])
                ->assertSessionHasNoErrors();
            $this->assertSame($mode, $account->fresh()->deletion_mode);
        }
    }

    public function test_missing_invalid_and_inexact_inputs_show_validation_errors(): void
    {
        $account = $this->account();
        $url = route('ftp.delete', $account);
        $valid = ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS olt_test'];

        $this->delete($url, ['confirmation' => $valid['confirmation']])
            ->assertSessionHasErrors(['mode' => 'Selecione um modo de exclusão.']);
        $this->delete($url, ['mode' => 'unknown', 'confirmation' => $valid['confirmation']])
            ->assertSessionHasErrors(['mode' => 'Selecione um modo de exclusão válido.']);
        $this->delete($url, ['mode' => 'ftp_data'])
            ->assertSessionHasErrors(['confirmation' => 'Informe a frase de confirmação.']);
        foreach (['excluir DADOS olt_test', 'EXCLUIR DADOS olt_test ', ' EXCLUIR DADOS olt_test'] as $phrase) {
            $this->delete($url, ['mode' => 'ftp_data', 'confirmation' => $phrase])
                ->assertSessionHasErrors('confirmation');
        }
        $this->assertTrue($account->fresh()->is_active);
        $this->assertNull($account->fresh()->deletion_mode);
    }

    public function test_delete_error_reopens_modal_with_selected_mode_and_correct_phrase(): void
    {
        $account = $this->account();
        $show = route('ftp.show', $account);
        $response = $this->followingRedirects()->from($show)->delete(route('ftp.delete', $account), [
            'mode' => 'ftp_data', 'confirmation' => 'EXCLUIR olt_test',
        ]);

        $response->assertOk()
            ->assertSee('A frase de confirmação não corresponde ao modo selecionado.')
            ->assertSee('id="ftp-delete-error"', false)
            ->assertSee('value="ftp_data" checked', false)
            ->assertSee('id="ftp-delete-phrase">EXCLUIR DADOS olt_test', false)
            ->assertSee('const phrases =', false)
            ->assertSee('"ftp_data":"EXCLUIR DADOS olt_test"', false)
            ->assertSee("input[name=\"mode\"]:checked", false)
            ->assertSee('confirmation.value =', false)
            ->assertDontSee('name="confirmation" value="EXCLUIR olt_test"', false);
        $this->assertMatchesRegularExpression('/syncPhrase\(\);\s*dialog\.showModal\(\);\s*\}\)\(\);/', $response->getContent());
    }

    public function test_failure_is_audited_and_retry_can_finish_after_path_is_fixed(): void
    {
        $account = $this->account('file_server', 'account', 'files_retry');
        $token = str_repeat('f', 32);
        $relative = 'ftp-files/'.$account->account_uuid.'/'.$token;
        mkdir(dirname($this->backupRoot.'/'.$relative), 0700, true);
        file_put_contents($this->backupRoot.'/'.$relative, 'data');
        $this->receipt($account, $token, 'stored', $relative);
        $this->delete(route('ftp.delete', $account), ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS files_retry'])->assertRedirect();
        unlink($this->backupRoot.'/'.$relative);
        symlink('/etc/hosts', $this->backupRoot.'/'.$relative);
        try {
            app(FtpAccountDeletionService::class)->finalize($account->fresh(), $this->privilegedResult($account));
            $this->fail('Symlink accepted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Symlink', $error->getMessage());
        }
        $this->assertDatabaseHas('ftp_accounts', ['id' => $account->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_events', ['resource_id' => (string) $account->id, 'result' => 'failed']);
        unlink($this->backupRoot.'/'.$relative);
        file_put_contents($this->backupRoot.'/'.$relative, 'data');
        app(FtpAccountDeletionService::class)->finalize($account->fresh(), $this->privilegedResult($account));
        $events = DB::table('audit_events')->where('resource_id', (string) $account->id)->where('action', 'ftp.account.delete_with_data')->orderBy('id')->get();
        $this->assertSame(['pending', 'failed', 'success'], $events->pluck('result')->all());
        $metadata = json_decode($events->last()->metadata, true);
        $this->assertSame(4, $metadata['bytes_removed']);
        $this->assertSame($this->adminUser->id, $events->last()->actor_user_id);
        $this->assertNotNull($events->last()->ip_address);
        $this->assertStringNotContainsString('SecurePassword123!', json_encode($events));
    }

    public function test_file_server_traversal_and_unattributed_artifact_block_destructive_modes(): void
    {
        $files = $this->account('file_server', 'account', 'files_unsafe');
        $token = str_repeat('2', 32);
        $this->receipt($files, $token, 'stored', '../outside');
        $this->assertSame('Há arquivos file_server sem vínculo seguro.', app(FtpAccountDeletionService::class)->preview($files)['safety_error']);
        $this->delete(route('ftp.delete', $files), ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS files_unsafe'])
            ->assertSessionHasErrors('mode');
        $this->assertTrue($files->fresh()->is_active);

        $backup = $this->account('backup', 'account', 'bad_artifact');
        $job = $this->execution($backup);
        DB::table('backup_artifacts')->where('backup_execution_id', $job->id)->update(['relative_path' => '../outside']);
        $this->assertSame(1, app(FtpAccountDeletionService::class)->preview($backup)['unattributable_artifacts']);
        $this->delete(route('ftp.delete', $backup), ['mode' => 'all', 'confirmation' => 'APAGAR TUDO bad_artifact'])
            ->assertSessionHasErrors('mode');
        $this->assertTrue($backup->fresh()->is_active);
    }

    public function test_delete_requires_csrf_token_outside_test_environment(): void
    {
        $account = $this->account();
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->delete(route('ftp.delete', $account), ['mode' => 'account', 'confirmation' => 'EXCLUIR '.$account->username])
                ->assertStatus(419);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_recent_incoming_upload_blocks_deletion(): void
    {
        $account = $this->account();
        file_put_contents($account->homePath().'/upload.cfg', 'partial');
        $this->physicalReport($account, ['safe' => false, 'incoming' => ['files' => 1], 'blockers' => ['recent_upload']]);
        $this->delete(route('ftp.delete', $account), ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS '.$account->username])
            ->assertSessionHasErrors('mode');
        $this->assertTrue($account->fresh()->is_active);
    }
    public function test_legacy_0700_preview_uses_privileged_report_and_unavailable_blocks(): void
    {
        $account = $this->account('backup', 'legacy', 'olt_teste');
        chmod($account->homePath(), 0700);
        $this->physicalReport($account, ['incoming' => ['files' => 2, 'bytes' => 4096]]);
        $preview = app(FtpAccountDeletionService::class)->preview($account);
        $this->assertSame(2, $preview['incoming']);
        $this->assertNull($preview['blocker']);
        $source = file_get_contents(app_path('Services/FtpAccountDeletionService.php'));
        $this->assertStringNotContainsString('directoryFiles($account->homePath())', $source);
        DB::table('audit_events')->where('action', 'ftp.physical.inspect')->delete();
        $preview = app(FtpAccountDeletionService::class)->preview($account);
        $this->assertSame('Estado físico FTP indisponível; exclusão bloqueada até nova verificação.', $preview['blocker']);
        $this->delete(route('ftp.delete', $account), ['mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS olt_teste'])
            ->assertSessionHasErrors('mode');
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_physical_warning_is_visible_only_for_current_deletion_intent(): void
    {
        $account = $this->account();
        DB::table('audit_events')->where('action', 'ftp.physical.inspect')->delete();
        $warning = 'Estado físico FTP indisponível; exclusão bloqueada até nova verificação.';

        $this->get(route('ftp.show', $account))->assertOk()->assertDontSee($warning);
        $this->assertDatabaseMissing('audit_events', ['action' => 'ftp.physical.request', 'resource_id' => (string) $account->id]);

        $this->get(route('ftp.show', ['ftpAccount' => $account, 'deletion_preview' => 1]))
            ->assertOk()->assertSee($warning);
        $this->assertDatabaseHas('audit_events', ['action' => 'ftp.physical.request', 'resource_id' => (string) $account->id]);

        $account->deletion_mode = 'ftp_data';
        $account->is_active = false;
        $account->save();
        $this->get(route('ftp.show', $account))->assertOk()->assertSee($warning);

        app(FtpAccountDeletionService::class)->physicalFailed($account->id, 'filesystem_permission_denied');
        $this->get(route('ftp.show', $account))->assertOk()
            ->assertSee('Permissão insuficiente no ftp-admin')
            ->assertSee($warning);
    }

    public function test_finalization_requires_privileged_success_result(): void
    {
        $account = $this->account();
        $this->delete(route('ftp.delete', $account), ['mode' => 'account', 'confirmation' => 'EXCLUIR olt_test'])
            ->assertSessionHasNoErrors();
        try {
            app(FtpAccountDeletionService::class)->finalize($account->fresh(), base64_encode(json_encode(['status' => 'error'])));
            $this->fail('Resultado inválido aceito.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Resultado físico FTP inválido.', $error->getMessage());
        }
        $this->assertDatabaseHas('ftp_accounts', ['id' => $account->id, 'device_id' => $account->device_id]);
    }

    public function test_request_path_is_ignored_and_physical_retry_keeps_account_inactive(): void
    {
        $account = $this->account('backup', 'legacy', 'olt_path');
        $this->delete(route('ftp.delete', $account), [
            'mode' => 'ftp_data', 'confirmation' => 'EXCLUIR DADOS olt_path',
            'path' => '/data/ftp', 'root' => '/data/ftp/accounts',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->is_active);
        app(FtpAccountDeletionService::class)->puredbRevoked($account->id);
        app(FtpAccountDeletionService::class)->physicalFailed($account->id, 'filesystem_permission_denied');
        $this->assertDatabaseHas('ftp_accounts', ['id' => $account->id, 'is_active' => false,
            'deletion_mode' => 'ftp_data']);
        $events = DB::table('audit_events')->where('resource_id', (string) $account->id)
            ->where('action', 'ftp.account.delete_with_data')->orderBy('id')->pluck('result')->all();
        $this->assertSame(['pending', 'puredb_revoked', 'failed'], $events);
        $this->assertStringContainsString('Permissão insuficiente', $account->fresh()->deletion_error);
    }

}
