<?php

namespace App\Http\Controllers;

use App\Services\DeviceBackupHealth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;

class DeviceBackupHealthController extends Controller
{
    public function index(DeviceBackupHealth $health): View
    {
        $this->authorize('dashboard.view');

        $summary = $health->summary(null);
        $page = Paginator::resolveCurrentPage();
        $perPage = 20;
        $devices = new LengthAwarePaginator(
            array_slice($summary['problem_devices'], ($page - 1) * $perPage, $perPage),
            $summary['problem_devices_total'],
            $perPage,
            $page,
            ['path' => route('backup-health.index')],
        );

        return view('backup-health.index', compact('summary', 'devices'));
    }
}
