<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EngineHealth;
use App\Services\HostResources;
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

    public function test_unknown_status_is_not_presented_as_a_healthy_system(): void
    {
        $report = app(EngineHealth::class)->report();
        $report['overall_status'] = 'unknown';
        // Um item realmente desconhecido (aqui a retenção). O processador de tarefas ocioso é tratado
        // à parte: é normal e não rebaixa o estado geral (ver test_idle_worker_alone_...).
        foreach ($report['checks'] as &$check) {
            $check['status'] = $check['check'] === 'retention' ? 'unknown' : 'healthy';
        }
        unset($check);
        $this->mock(EngineHealth::class)->shouldReceive('report')->once()->andReturn($report);
        $this->mock(HostResources::class)->shouldReceive('snapshot')->once()->andReturn([]);

        $this->actingAs(User::factory()->admin()->create())->get(route('system-health.index'))
            ->assertOk()->assertSee('health-state--unknown', false)
            ->assertSee('Alguns componentes estão sem dados recentes de verificação.')
            ->assertDontSee('Todos os componentes estão operando normalmente.')
            ->assertSee('data-health-target="health-check-retention"', false)
            ->assertSee('Capacidade indisponível');
    }

    public function test_history_panel_separates_never_completed_backups_from_other_device_problems(): void
    {
        $report = app(EngineHealth::class)->report();
        foreach ($report['checks'] as &$check) {
            if ($check['check'] === 'devices') {
                $check['metadata']['problem_devices'] = [
                    ['device_id' => 1, 'name' => 'Manual sem backup', 'status' => 'unknown', 'reason' => 'manual_never_backed_up'],
                    ['device_id' => 2, 'name' => 'Agendado sem sucesso', 'status' => 'critical', 'reason' => 'scheduled_never_succeeded'],
                    ['device_id' => 3, 'name' => 'Falhas repetidas', 'status' => 'critical', 'reason' => 'consecutive_failures'],
                ];
            }
        }
        unset($check);
        $this->mock(EngineHealth::class)->shouldReceive('report')->once()->andReturn($report);
        $this->mock(HostResources::class)->shouldReceive('snapshot')->once()->andReturn([
            'cpu_count' => 4, 'load_1m' => 1.57, 'memory_used_bytes' => 25, 'memory_total_bytes' => 100,
        ]);
        $this->mock(\App\Services\CpuUsage::class)->shouldReceive('percent')->once()->andReturn(37);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('system-health.index'))->assertOk();
        $html = $response->getContent();
        $panel = substr($html, strpos($html, '<section class="health-panel health-no-history"'));
        $panel = substr($panel, 0, strpos($panel, '</section>'));
        $this->assertStringContainsString('Manual sem backup', $panel);
        $this->assertStringContainsString('Agendado sem sucesso', $panel);
        $this->assertStringNotContainsString('Falhas repetidas', $panel);
        $response->assertSee('aria-label="Uso da CPU" aria-valuenow="37"', false)
            ->assertSee('aria-label="Uso da memória" aria-valuenow="25"', false)
            ->assertSee('Falhas repetidas');
    }

    public function test_idle_worker_is_shown_as_idle_not_as_unknown(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('system-health.index'))->assertOk();
        $response->assertSee('Ocioso')
            ->assertSee('Nenhum backup em andamento. Este item só é avaliado enquanto há uma execução rodando')
            ->assertDontSee('Nenhuma execução em andamento no momento.');
    }

    public function test_idle_worker_alone_does_not_make_the_overall_status_unknown(): void
    {
        $report = app(EngineHealth::class)->report();
        foreach ($report['checks'] as &$check) {
            $idle = $check['check'] === 'worker';
            $check['status'] = $idle ? 'unknown' : 'healthy';
            $check['code'] = $idle ? 'idle' : 'ok';
            $check['message'] = $idle ? 'Nenhuma execução em andamento no momento.' : 'ok';
        }
        unset($check);
        $report['overall_status'] = 'unknown';
        $report['alerts'] = [];
        $this->mock(EngineHealth::class)->shouldReceive('report')->once()->andReturn($report);

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('system-health.index'))
            ->assertOk()->getContent();
        $overview = substr($html, strpos($html, 'aria-label="Status geral"'));
        $overview = substr($overview, 0, strpos($overview, '</section>'));
        $this->assertStringContainsString('Saudável', $overview);
        $this->assertStringNotContainsString('Desconhecido', $overview);
        $this->assertStringContainsString('0 desconhecidas', $html);
        $this->assertStringContainsString('Ocioso', $html);
    }
}
