<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('reports.index'))->assertRedirect('/login');
        $this->get(route('reports.executions'))->assertRedirect('/login');
        $this->get(route('reports.executions.export'))->assertRedirect('/login');
    }

    public function test_every_role_can_view_every_report(): void
    {
        foreach (['admin', 'operator', 'viewer', 'auditor'] as $role) {
            $user = User::factory()->{$role}()->create();
            $this->actingAs($user);
            foreach (['reports.index', 'reports.executions', 'reports.devices', 'reports.artifacts', 'reports.failures', 'reports.ftp'] as $route) {
                $this->get(route($route))->assertOk();
            }
        }
    }

    public function test_every_role_can_export_every_report(): void
    {
        foreach (['admin', 'operator', 'viewer', 'auditor'] as $role) {
            $user = User::factory()->{$role}()->create();
            $this->actingAs($user);
            foreach (['reports.executions.export', 'reports.devices.export', 'reports.artifacts.export', 'reports.failures.export', 'reports.ftp.export'] as $route) {
                $this->get(route($route))->assertOk();
            }
        }
    }
}
