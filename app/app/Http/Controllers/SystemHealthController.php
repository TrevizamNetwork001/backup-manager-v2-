<?php

namespace App\Http\Controllers;

use App\Services\EngineHealth;
use App\Services\HostResources;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function index(EngineHealth $health, HostResources $hostResources): View
    {
        $this->authorize('system_health.view');

        // Read-only diagnostic view — deliberately not audited (see
        // docs/ENGINE_HEALTH.md, "Auditoria": opening a health page every
        // few seconds must not flood audit_events).
        return view('system-health.index', [
            'report' => $health->report(),
            'hostResources' => $hostResources->snapshot(),
        ]);
    }
}
