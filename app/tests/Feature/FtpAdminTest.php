<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\FtpAccountManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class FtpAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function device(string $name = 'OLT'): Device
    {
        $site = Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        return Device::create(['site_id' => $site->id, 'name' => $name, 'management_ip' => '192.0.2.'.($name === 'OLT' ? '10' : '11'), 'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);
    }

    public function test_create_once_provisioning_and_isolated_chroot(): void
    {
        $this->admin();
        $device = $this->device();
        $response = $this->post(route('ftp.store'), ['device_id' => $device->id, 'username' => 'oltbackup',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $account = $device->ftpAccount()->firstOrFail();
        $this->assertSame('ValidPassword123!', $account->secret);
        $this->assertLessThanOrEqual(40, strlen($account->secret));
        $response->assertSee($account->secret);
        $this->assertNotSame($account->secret, DB::table('ftp_accounts')->where('id', $account->id)->value('secret'));
        $this->get(route('ftp.show', $account))->assertOk()->assertSee($account->homePath())->assertSee('Path remoto')->assertDontSee($account->secret);
        $this->get(route('ftp.index'))->assertOk()->assertDontSee($account->secret);
        Artisan::call('ftp:accounts');
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($device->id, $rows[0]['device_id']);
        $this->assertArrayNotHasKey('secret', $rows[0]);
        $second = $this->device('OLT 2');
        $this->assertSame('/data/ftp/accounts/'.$account->account_uuid.'/incoming', $account->homePath());
        $this->assertDatabaseHas('ftp_account_audits', ['ftp_account_id' => $account->id, 'action' => 'create']);
        $this->assertStringNotContainsString($account->secret, json_encode(DB::table('ftp_account_audits')->get()));
    }

    public function test_manual_username_and_password_are_saved(): void
    {
        $this->admin();
        $device = $this->device();
        $this->post(route('ftp.store'), ['device_id' => $device->id, 'username' => 'customolt',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertOk();
        $this->assertSame('customolt', $device->ftpAccount->username);
        $this->assertSame('ValidPassword123!', $device->ftpAccount->secret);
    }

    public function test_new_accounts_reject_automatic_username_and_missing_username(): void
    {
        $this->admin();
        $device = $this->device();
        $password = 'ValidPassword123!';
        $this->post(route('ftp.store'), ['device_id' => $device->id, 'mode' => 'automatic',
            'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('mode');
        $this->post(route('ftp.store'), ['device_id' => $device->id,
            'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('username');
        $this->assertDatabaseCount('ftp_accounts', 0);
        $generated = app(FtpAccountManager::class)->generatedPassword();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $generated);
    }

    public function test_duplicate_username_and_device_are_rejected(): void
    {
        $this->admin();
        $first = $this->device();
        $second = $this->device('OLT 2');
        $data = ['username' => 'shareduser', 'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'];
        $this->post(route('ftp.store'), ['device_id' => $first->id] + $data)->assertOk();
        $this->post(route('ftp.store'), ['device_id' => $second->id] + $data)->assertSessionHasErrors('username');
        $this->post(route('ftp.store'), ['device_id' => $first->id, 'username' => 'otheruser',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertSessionHasErrors('device_id');
        $this->assertDatabaseCount('ftp_accounts', 1);
    }

    public function test_manual_password_validation_rotation_status_and_audit(): void
    {
        $this->admin();
        $device = $this->device();
        $manual = ['device_id' => $device->id, 'username' => 'oltbackup', 'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!'];
        $this->post(route('ftp.store'), array_replace($manual, ['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post(route('ftp.store'), array_replace($manual, ['password' => str_repeat('a', 41), 'password_confirmation' => str_repeat('a', 41)]))->assertSessionHasErrors('password');
        $this->post(route('ftp.store'), $manual)->assertOk();
        $account = $device->ftpAccount()->firstOrFail();
        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now(), 'sync_error' => 'Falha de sincronização']);
        $this->get(route('ftp.show', $account))->assertSee('Falha de sincronização');
        $this->post(route('ftp.rotate', $account), ['password' => 'RotatedPass123!', 'password_confirmation' => 'RotatedPass123!'])->assertOk()->assertSee('RotatedPass123!')->assertDontSee('SecurePass123!');
        $this->assertSame('RotatedPass123!', $account->fresh()->secret);
        $this->assertNull($account->fresh()->provisioned_at);
        $this->get(route('ftp.show', $account))->assertDontSee('RotatedPass123!')->assertSee('aguardando novo recebimento');
        $this->patch(route('ftp.status', $account), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($account->fresh()->is_active);
        $this->patch(route('ftp.status', $account), ['is_active' => 1])->assertRedirect();
        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame(['create', 'rotate', 'disable', 'enable'], DB::table('ftp_account_audits')->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('RotatedPass123!', json_encode(DB::table('ftp_account_audits')->get()));
    }

    public function test_viewer_can_see_ftp_but_cannot_manage_accounts(): void
    {
        // Under RBAC (ADMIN-2), ftp.view (read-only) is broader than the old
        // is_admin-only gate; only ftp.manage/ftp.delete remain restricted.
        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('ftp.index'))->assertOk();
        $this->post(route('ftp.store'), ['device_id' => $this->device()->id, 'mode' => 'automatic'])->assertForbidden();
    }

    public function test_file_server_is_standalone_and_backup_requires_device(): void
    {
        $this->admin();
        $password = 'ValidPassword123!';
        $data = ['purpose' => 'file_server', 'username' => 'standalone', 'password' => $password, 'password_confirmation' => $password];
        $this->post(route('ftp.store'), $data)->assertOk()->assertSee($password);
        $account = FtpAccount::where('username', 'standalone')->firstOrFail();
        $this->assertNull($account->device_id);
        $this->assertSame('file_server', $account->purpose);
        $this->assertSame('/data/ftp/accounts/'.$account->account_uuid.'/incoming', $account->homePath());
        $this->get(route('ftp.show', $account))->assertDontSee($password);
        $this->post(route('ftp.store'), ['purpose' => 'backup', 'username' => 'orphan', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('device_id');
        $this->post(route('ftp.store'), $data)->assertSessionHasErrors('username');
        $this->assertDatabaseMissing('backup_executions', ['device_id' => 0]);
    }

    public function test_standalone_receipt_history_has_no_backup_execution(): void
    {
        $this->admin();
        $password = 'ValidPassword123!';
        $this->post(route('ftp.store'), ['purpose' => 'file_server', 'username' => 'receivefiles',
            'password' => $password, 'password_confirmation' => $password])->assertOk();
        $account = FtpAccount::where('username', 'receivefiles')->firstOrFail();
        Artisan::call('ftp:receipt', ['account' => $account->id, 'token' => str_repeat('a', 32),
            'filename' => 'n'.rtrim(strtr(base64_encode('sample.bin'), '+/', '-_'), '='),
            'received' => now()->timestamp, 'status' => 'stored', 'size' => 4,
            'hash' => hash('sha256', 'data'), 'path' => 'ftp-files/'.$account->account_uuid.'/'.str_repeat('a', 32),
            'error' => '-']);
        Artisan::call('ftp:receipt', ['account' => $account->id, 'token' => str_repeat('a', 32),
            'filename' => 'n'.rtrim(strtr(base64_encode('sample.bin'), '+/', '-_'), '='),
            'received' => now()->timestamp, 'status' => 'processing', 'size' => 4,
            'hash' => '-', 'path' => '-', 'error' => 'FTP_PROCESSING_RETRY']);
        $this->assertDatabaseHas('ftp_received_files', ['claim_token' => str_repeat('a', 32), 'status' => 'stored']);
        $this->assertDatabaseCount('ftp_received_files', 1);
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('sample.bin')
            ->assertSee('4 bytes')->assertDontSee($password);
        $this->get(route('ftp.index'))->assertOk()->assertSee('receivefiles');
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_ftp_pages_and_account_export_work_before_core_migration(): void
    {
        $this->admin();
        $device = $this->device();
        $account = new FtpAccount(['device_id' => $device->id, 'account_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'home_layout' => 'legacy', 'purpose' => 'backup', 'username' => 'legacyolt', 'is_active' => true]);
        $account->secret = 'SyntheticPass123!';
        $account->save();
        Schema::drop('ftp_received_files');
        Schema::table('ftp_accounts', fn (Blueprint $table) => $table->dropUnique(['account_uuid']));
        Schema::table('ftp_accounts', fn (Blueprint $table) => $table->dropColumn(['account_uuid', 'purpose', 'home_layout']));
        $this->get(route('ftp.index'))->assertOk()->assertSee('legacyolt')
            ->assertSee('A criação de novas contas aguarda a migration FTP-CORE-1.');
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('/data/ftp/'.$device->id.'/incoming');
        $this->post(route('ftp.store'), ['purpose' => 'backup', 'device_id' => $device->id,
            'username' => 'newolt', 'password' => 'SyntheticPass123!',
            'password_confirmation' => 'SyntheticPass123!'])->assertStatus(503);
        Artisan::call('ftp:accounts');
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('legacy', $rows[0]['home_layout']);
        $this->assertSame('/data/ftp/'.$device->id.'/incoming', $rows[0]['home']);
    }
}
