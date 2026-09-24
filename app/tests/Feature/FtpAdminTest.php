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
        $response = $this->post(route('ftp.store'), ['device_id' => $device->id, 'mode' => 'automatic'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $account = $device->ftpAccount()->firstOrFail();
        $this->assertSame(32, strlen($account->secret));
        $this->assertLessThanOrEqual(40, strlen($account->secret));
        $response->assertSee($account->secret);
        $this->assertNotSame($account->secret, DB::table('ftp_accounts')->where('id', $account->id)->value('secret'));
        $this->get(route('ftp.show', $account))->assertOk()->assertSee('/data/ftp/'.$device->id.'/incoming')->assertSee('Path visível ao equipamento')->assertDontSee($account->secret);
        $this->get(route('ftp.index'))->assertOk()->assertDontSee($account->secret);
        Artisan::call('ftp:accounts');
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($device->id, $rows[0]['device_id']);
        $this->assertArrayNotHasKey('secret', $rows[0]);
        $second = $this->device('OLT 2');
        $this->assertNotSame('/data/ftp/'.$device->id.'/incoming', '/data/ftp/'.$second->id.'/incoming');
        $this->assertDatabaseHas('ftp_account_audits', ['ftp_account_id' => $account->id, 'action' => 'create']);
        $this->assertStringNotContainsString($account->secret, json_encode(DB::table('ftp_account_audits')->get()));
    }

    public function test_manual_username_can_use_generated_password(): void
    {
        $this->admin();
        $device = $this->device();
        $this->post(route('ftp.store'), ['device_id' => $device->id, 'mode' => 'manual',
            'username' => 'customolt', 'password_mode' => 'automatic'])->assertOk();
        $this->assertSame('customolt', $device->ftpAccount->username);
        $this->assertSame(32, strlen($device->ftpAccount->secret));
    }

    public function test_duplicate_username_and_device_are_rejected(): void
    {
        $this->admin();
        $first = $this->device();
        $second = $this->device('OLT 2');
        $data = ['mode' => 'manual', 'username' => 'shareduser', 'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'];
        $this->post(route('ftp.store'), ['device_id' => $first->id] + $data)->assertOk();
        $this->post(route('ftp.store'), ['device_id' => $second->id] + $data)->assertSessionHasErrors('username');
        $this->post(route('ftp.store'), ['device_id' => $first->id, 'mode' => 'automatic'])->assertSessionHasErrors('device_id');
        $this->assertDatabaseCount('ftp_accounts', 1);
    }

    public function test_manual_password_validation_rotation_status_and_audit(): void
    {
        $this->admin();
        $device = $this->device();
        $manual = ['device_id' => $device->id, 'mode' => 'manual', 'username' => 'oltbackup', 'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!'];
        $this->post(route('ftp.store'), array_replace($manual, ['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post(route('ftp.store'), array_replace($manual, ['password' => str_repeat('a', 41), 'password_confirmation' => str_repeat('a', 41)]))->assertSessionHasErrors('password');
        $this->post(route('ftp.store'), $manual)->assertOk();
        $account = $device->ftpAccount()->firstOrFail();
        DB::table('ftp_accounts')->where('id', $account->id)->update(['provisioned_at' => now(), 'sync_error' => 'Falha de sincronização']);
        $this->get(route('ftp.show', $account))->assertSee('Falha de sincronização');
        $this->post(route('ftp.rotate', $account), ['mode' => 'manual', 'password' => 'RotatedPass123!', 'password_confirmation' => 'RotatedPass123!'])->assertOk()->assertSee('RotatedPass123!')->assertDontSee('SecurePass123!');
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

    public function test_non_admin_cannot_access_ftp_administration(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(route('ftp.index'))->assertForbidden();
        $this->post(route('ftp.store'), ['device_id' => $this->device()->id, 'mode' => 'automatic'])->assertForbidden();
    }
}
