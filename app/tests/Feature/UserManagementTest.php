<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    // USERS — listar, criar, editar, trocar papel, ativar/desativar, redefinir senha

    public function test_admin_can_list_users(): void
    {
        $this->admin();
        User::factory()->viewer()->create(['name' => 'Fulano']);

        $this->get(route('users.index'))->assertOk()->assertSeeText('Fulano');
    }

    public function test_admin_can_create_user(): void
    {
        $this->admin();

        $response = $this->post(route('users.store'), [
            'name' => 'Novo Operador', 'email' => 'novo.operador@example.com',
            'role' => Rbac::ROLE_OPERATOR, 'password' => 'ValidPassword123!',
            'password_confirmation' => 'ValidPassword123!', 'is_active' => '1',
        ]);

        $response->assertRedirect(route('users.index'));
        $user = User::where('email', 'novo.operador@example.com')->firstOrFail();
        $this->assertSame(Rbac::ROLE_OPERATOR, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('ValidPassword123!', $user->password));
    }

    public function test_admin_can_edit_user_name_and_email(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->put(route('users.update', $user), [
            'name' => 'Nome Atualizado', 'email' => $user->email, 'role' => $user->role,
        ])->assertRedirect(route('users.index'));

        $this->assertSame('Nome Atualizado', $user->fresh()->name);
    }

    public function test_admin_can_change_user_role(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => Rbac::ROLE_OPERATOR,
        ])->assertRedirect(route('users.index'));

        $this->assertSame(Rbac::ROLE_OPERATOR, $user->fresh()->role);
    }

    public function test_admin_can_disable_and_enable_user(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->patch(route('users.status', $user), ['is_active' => '0'])->assertRedirect(route('users.index'));
        $this->assertFalse($user->fresh()->is_active);

        $this->patch(route('users.status', $user), ['is_active' => '1'])->assertRedirect(route('users.index'));
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_can_reset_user_password(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->post(route('users.reset-password', $user), [
            'password' => 'BrandNewPassword123!', 'password_confirmation' => 'BrandNewPassword123!',
        ])->assertRedirect(route('users.edit', $user));

        $this->assertTrue(Hash::check('BrandNewPassword123!', $user->fresh()->password));
    }

    // PROTEÇÕES

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->admin();

        $response = $this->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => Rbac::ROLE_VIEWER,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame(Rbac::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_last_admin_can_be_demoted_when_another_admin_exists(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create();

        $this->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => Rbac::ROLE_VIEWER,
        ])->assertRedirect(route('users.index'));

        $this->assertSame(Rbac::ROLE_VIEWER, $admin->fresh()->role);
    }

    public function test_last_active_admin_model_check(): void
    {
        // With only admin holding users.manage, the "other admin disables the
        // last admin" HTTP path can never be reached (the actor themself would
        // always count as another active admin) — so this invariant is verified
        // directly against the model instead. The reachable, user-facing
        // guarantee is exercised end-to-end by test_self_disable_is_blocked below.
        $solo = User::factory()->admin()->create();
        $this->assertTrue($solo->isLastActiveAdmin());

        $second = User::factory()->admin()->create();
        $this->assertFalse($solo->fresh()->isLastActiveAdmin());
        $this->assertFalse($second->isLastActiveAdmin());

        $second->is_active = false;
        $second->save();
        $this->assertTrue($solo->fresh()->isLastActiveAdmin());
    }

    public function test_self_disable_is_blocked(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create(); // another admin, so it is not a "last admin" edge case

        $response = $this->patch(route('users.status', $admin), ['is_active' => '0']);

        $response->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_operator_cannot_manage_users(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $target = User::factory()->viewer()->create();

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->post(route('users.store'), [])->assertForbidden();
        $this->get(route('users.edit', $target))->assertForbidden();
        $this->put(route('users.update', $target), [])->assertForbidden();
        $this->patch(route('users.status', $target), ['is_active' => '0'])->assertForbidden();
        $this->post(route('users.reset-password', $target), [])->assertForbidden();
    }

    // AUDITORIA

    public function test_user_created_emits_audit_event_without_password(): void
    {
        $this->admin();

        $this->post(route('users.store'), [
            'name' => 'Auditado', 'email' => 'auditado@example.com', 'role' => Rbac::ROLE_VIEWER,
            'password' => 'SuperSecret123!', 'password_confirmation' => 'SuperSecret123!', 'is_active' => '1',
        ]);

        $event = DB::table('audit_events')->where('action', 'user.created')->first();
        $this->assertNotNull($event);
        $this->assertSame('user', $event->resource_type);
        $this->assertStringNotContainsString('SuperSecret123!', $event->metadata);
        $this->assertStringNotContainsString('password', strtolower($event->metadata));
    }

    public function test_role_change_emits_audit_event_with_old_and_new_role(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => Rbac::ROLE_OPERATOR,
        ]);

        $event = DB::table('audit_events')->where('action', 'user.role_changed')->first();
        $this->assertNotNull($event);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame(Rbac::ROLE_VIEWER, $metadata['old_role']);
        $this->assertSame(Rbac::ROLE_OPERATOR, $metadata['new_role']);
    }

    public function test_disable_and_enable_emit_audit_events(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->patch(route('users.status', $user), ['is_active' => '0']);
        $this->patch(route('users.status', $user), ['is_active' => '1']);

        $this->assertDatabaseHas('audit_events', ['action' => 'user.disabled', 'resource_id' => (string) $user->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.enabled', 'resource_id' => (string) $user->id]);
    }

    public function test_password_reset_emits_audit_event_without_password(): void
    {
        $this->admin();
        $user = User::factory()->viewer()->create();

        $this->post(route('users.reset-password', $user), [
            'password' => 'AnotherSecret123!', 'password_confirmation' => 'AnotherSecret123!',
        ]);

        $event = DB::table('audit_events')->where('action', 'user.password_reset')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('AnotherSecret123!', $event->metadata);
    }

    // VIEWS — botões sensíveis escondidos

    public function test_viewer_does_not_see_mutation_buttons(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('ftp.index'))->assertOk()->assertDontSee('Nova conta FTP');
        $this->get(route('sites.index'))->assertOk()->assertDontSee('Novo Site / POP');
    }

    public function test_viewer_does_not_see_administration_nav_section(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('Auditoria')
            ->assertDontSee('Configurações')
            ->assertDontSee(route('users.index'), false);
    }
}
