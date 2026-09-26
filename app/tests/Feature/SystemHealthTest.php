<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('system-health.index'))->assertRedirect('/login');
    }

    public function test_every_role_can_view_the_health_page(): void
    {
        foreach (['admin', 'operator', 'viewer', 'auditor'] as $role) {
            $user = User::factory()->{$role}()->create();
            $this->actingAs($user)->get(route('system-health.index'))
                ->assertOk()
                ->assertSee('Saúde do sistema')
                ->assertSee('Status geral')
                ->assertSee('CPU do host')
                ->assertSee('Memória do host')
                ->assertSee('Saúde dos serviços')
                ->assertSee('Armazenamento')
                ->assertSee('Detalhes das verificações');
        }
    }

    public function test_page_renders_without_mutating_anything_and_is_not_audited(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertDatabaseCount('audit_events', 0);
        $this->get(route('system-health.index'))->assertOk();
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('backup_executions', 0);
    }

    public function test_page_never_leaks_credentials_or_stack_traces(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $response = $this->get(route('system-health.index'))->assertOk();
        $response->assertDontSee('APP_KEY')->assertDontSee('password', false)
            ->assertDontSee('secret', false)->assertDontSee('Stack trace', false)
            ->assertDontSee('#0 /', false);
    }

    public function test_partial_check_failure_still_renders_the_page(): void
    {
        config()->set('backup.storage_root', '/nonexistent/path/for/sure');
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('system-health.index'))
            ->assertOk()
            ->assertSee('badge--danger', false)
            ->assertSee('Crítico')
            ->assertSee('Os dados de capacidade não estão disponíveis nesta verificação.');
    }
}
