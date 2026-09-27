<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_report_page_renders_for_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['reports.index', 'reports.executions', 'reports.devices', 'reports.artifacts', 'reports.failures', 'reports.ftp'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_every_export_streams_for_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['reports.executions.export', 'reports.devices.export', 'reports.artifacts.export', 'reports.failures.export', 'reports.ftp.export'] as $route) {
            $this->get(route($route))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        }
    }
}
