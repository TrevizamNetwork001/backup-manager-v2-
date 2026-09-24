<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
    }

    private function device(): Device
    {
        return Device::create(['site_id' => $this->site()->id, 'name' => 'Router RBAC',
            'management_ip' => '192.0.2.200', 'vendor' => 'MikroTik', 'platform' => 'network', 'is_active' => true]);
    }

    private function ftpAccount(Device $device): FtpAccount
    {
        $account = new FtpAccount(['device_id' => $device->id,
            'account_uuid' => (string) Str::uuid(), 'purpose' => 'backup',
            'home_layout' => 'account', 'username' => 'rbac_test', 'is_active' => true]);
        $account->secret = 'ValidPassword123!';
        $account->save();

        return $account;
    }

    // MATRIZ — cada papel só acessa o que docs/RBAC.md descreve.

    public function test_admin_has_full_access(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('sites.index'))->assertOk();
        $this->get(route('sites.create'))->assertOk();
        $this->get(route('devices.index'))->assertOk();
        $this->get(route('credentials.index'))->assertOk();
        $this->get(route('ftp.index'))->assertOk();
        $this->get(route('backup-policies.index'))->assertOk();
        $this->get(route('backup-executions.index'))->assertOk();
        $this->get(route('backup-artifacts.index'))->assertOk();
        $this->get(route('audit.index'))->assertOk();
        $this->get(route('settings.edit'))->assertOk();
        $this->get(route('users.index'))->assertOk();
        $this->get(route('users.create'))->assertOk();
    }

    public function test_operator_matrix(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $device = $this->device();

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('sites.index'))->assertOk();
        $this->get(route('sites.create'))->assertOk();
        $this->get(route('devices.index'))->assertOk();
        $this->get(route('devices.edit', $device))->assertOk();
        $this->get(route('credentials.index'))->assertOk();
        $this->get(route('ftp.index'))->assertOk();
        $this->get(route('backup-policies.index'))->assertOk();
        $this->get(route('backup-executions.index'))->assertOk();
        $this->get(route('backup-artifacts.index'))->assertOk();

        // Operator has ftp.manage: authorization passes (validation may still
        // reject the payload, but it must not be a 403).
        $mutation = $this->post(route('ftp.store'), ['device_id' => $device->id, 'mode' => 'automatic']);
        $this->assertNotSame(403, $mutation->getStatusCode());

        // Operator lacks the destructive/administrative permissions.
        $this->delete(route('ftp.delete', $this->ftpAccount($device)), [])->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
        $this->get(route('settings.edit'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_viewer_matrix(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $device = $this->device();

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('sites.index'))->assertOk();
        $this->get(route('devices.index'))->assertOk();
        $this->get(route('credentials.index'))->assertOk();
        $this->get(route('ftp.index'))->assertOk();
        $this->get(route('backup-policies.index'))->assertOk();
        $this->get(route('backup-executions.index'))->assertOk();
        $this->get(route('backup-artifacts.index'))->assertOk();

        // No mutation permission anywhere.
        $this->get(route('sites.create'))->assertForbidden();
        $this->post(route('sites.store'), [])->assertForbidden();
        $this->get(route('devices.edit', $device))->assertForbidden();
        $this->post(route('ftp.store'), [])->assertForbidden();
        $this->get(route('backup-policies.create'))->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
        $this->get(route('settings.edit'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_auditor_matrix(): void
    {
        $this->actingAs(User::factory()->auditor()->create());
        $device = $this->device();

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('audit.index'))->assertOk();
        $this->get(route('sites.index'))->assertOk();
        $this->get(route('devices.index'))->assertOk();
        $this->get(route('credentials.index'))->assertOk();
        $this->get(route('ftp.index'))->assertOk();
        $this->get(route('backup-policies.index'))->assertOk();
        $this->get(route('backup-executions.index'))->assertOk();
        $this->get(route('backup-artifacts.index'))->assertOk();

        // No mutations, no user/settings management.
        $this->get(route('sites.create'))->assertForbidden();
        $this->get(route('devices.edit', $device))->assertForbidden();
        $this->post(route('ftp.store'), [])->assertForbidden();
        $this->get(route('settings.edit'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
    }

    // AUTH

    public function test_disabled_user_cannot_login(): void
    {
        $user = User::factory()->operator()->inactive()->create(['password' => 'ValidPassword123!']);

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'ValidPassword123!']);

        $response->assertSessionHasErrors('email');
        $this->assertSame('Credenciais inválidas ou acesso indisponível.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_disabled_and_wrong_password_share_the_same_generic_message(): void
    {
        // The message must not leak whether the account exists but is disabled.
        $disabled = User::factory()->operator()->inactive()->create(['password' => 'ValidPassword123!']);

        $genericMessage = 'Credenciais inválidas ou acesso indisponível.';

        $this->post(route('login.store'), ['email' => $disabled->email, 'password' => 'WrongPassword123!'])
            ->assertSessionHasErrors(['email' => $genericMessage]);

        $this->post(route('login.store'), ['email' => $disabled->email, 'password' => 'ValidPassword123!'])
            ->assertSessionHasErrors(['email' => $genericMessage]);
    }

    public function test_active_admin_still_logs_in_normally(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'ValidPassword123!']);

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'ValidPassword123!'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_existing_session_is_dropped_once_user_is_disabled(): void
    {
        $user = User::factory()->operator()->create();
        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk();

        $user->is_active = false;
        $user->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // MIGRATION — up/down/backfill (item 21)

    public function test_migration_backfills_existing_admin_and_preserves_access(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1]);

        $legacyAdminId = DB::table('users')->insertGetId([
            'name' => 'Legacy Admin', 'email' => 'legacy-admin@example.com',
            'password' => bcrypt('ValidPassword123!'), 'is_admin' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacyUserId = DB::table('users')->insertGetId([
            'name' => 'Legacy User', 'email' => 'legacy-user@example.com',
            'password' => bcrypt('ValidPassword123!'), 'is_admin' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('migrate');

        $this->assertSame(Rbac::ROLE_ADMIN, DB::table('users')->where('id', $legacyAdminId)->value('role'));
        $this->assertSame(Rbac::ROLE_VIEWER, DB::table('users')->where('id', $legacyUserId)->value('role'));
        $this->assertEquals(1, DB::table('users')->where('id', $legacyAdminId)->value('is_active'));

        $admin = User::find($legacyAdminId);
        $this->actingAs($admin);
        $this->get(route('settings.edit'))->assertOk();
        $this->get(route('users.index'))->assertOk();
    }
}
