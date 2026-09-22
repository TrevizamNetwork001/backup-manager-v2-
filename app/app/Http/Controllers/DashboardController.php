<?php

namespace App\Http\Controllers;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'deviceCount' => Device::count(),
            'policyCount' => BackupPolicy::count(),
            'executionCount' => BackupExecution::count(),
        ]);
    }
}
