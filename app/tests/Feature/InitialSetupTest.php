<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_account_only_exists_on_empty_installation(): void
    {
        $this->artisan('app:create-admin', ['--bootstrap' => true])->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('backup_manager', $user->name);
        $this->assertTrue($user->must_complete_setup);
        $this->assertTrue(Hash::check('backup_manager', $user->password));
        $this->assertTrue($user->is_admin);

        $this->artisan('app:create-admin', ['--bootstrap' => true])->assertFailed();
        $this->assertSame(1, User::query()->count());
    }

    public function test_bootstrap_login_is_limited_to_initial_setup(): void
    {
        $this->artisan('app:create-admin', ['--bootstrap' => true])->assertSuccessful();

        $this->post(route('login.store'), ['email' => 'backup_manager', 'password' => 'backup_manager'])
            ->assertRedirect(route('initial-setup.edit'));
        $this->get(route('initial-setup.edit'))->assertOk()->assertSee('Configure seu acesso');
        $this->get(route('dashboard'))->assertRedirect(route('initial-setup.edit'));
        $this->get(route('users.index'))->assertRedirect(route('initial-setup.edit'));
        $this->post(route('session.keep-alive'))->assertRedirect(route('initial-setup.edit'));
    }

    public function test_initial_setup_requires_valid_new_credentials_and_logs_out(): void
    {
        $this->artisan('app:create-admin', ['--bootstrap' => true])->assertSuccessful();
        $user = User::query()->sole();
        $this->actingAs($user);

        $this->post(route('initial-setup.update'), [
            'email' => 'admin@example.com', 'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_complete_setup);

        $this->post(route('initial-setup.update'), [
            'email' => 'admin@example.com', 'password' => 'NovaSenhaSegura123!',
            'password_confirmation' => 'NovaSenhaSegura123!',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertFalse($user->must_complete_setup);
        $this->assertSame('admin@example.com', $user->name);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue(Hash::check('NovaSenhaSegura123!', $user->password));
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => 'backup_manager', 'password' => 'backup_manager'])
            ->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'NovaSenhaSegura123!'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_existing_users_do_not_enter_initial_setup(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->get(route('initial-setup.edit'))->assertRedirect(route('dashboard'));
        $this->artisan('app:create-admin', ['--bootstrap' => true])->assertFailed();
    }
}
