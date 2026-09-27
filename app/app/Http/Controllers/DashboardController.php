<?php

namespace App\Http\Controllers;

use App\Models\BackupExecution;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Services\InstanceTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InstanceTimezone $timezone): View
    {
        $this->authorize('dashboard.view');

        $localNow = $timezone->localNow();
        $instanceTimezone = $timezone->get();
        $since = $localNow->subDay()->utc();
        $chartFilters = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '14d', '30d', 'custom'])],
            'start_date' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $period = $chartFilters['period'] ?? '7d';
        $chartEnd = $period === 'custom'
            ? CarbonImmutable::parse($chartFilters['end_date'], $instanceTimezone)->startOfDay()
            : $localNow->startOfDay();
        $chartStart = $period === 'custom'
            ? CarbonImmutable::parse($chartFilters['start_date'], $instanceTimezone)->startOfDay()
            : $chartEnd->subDays((int) $period - 1);
        $chartDayCount = (int) $chartStart->diffInDays($chartEnd) + 1;
        if ($chartDayCount > 31) {
            throw ValidationException::withMessages(['end_date' => 'Selecione um período de até 31 dias.']);
        }
        $chartLabel = $period === 'custom'
            ? $chartStart->format('d/m/Y').' a '.$chartEnd->format('d/m/Y')
            : 'Últimos '.(int) $period.' dias';
        $days = collect(range(0, $chartDayCount - 1))->map(function ($offset) use ($chartStart) {
            $date = $chartStart->addDays($offset);
            $executions = BackupExecution::query()
                ->whereBetween('created_at', [$date->startOfDay()->utc(), $date->endOfDay()->utc()])
                ->get(['status']);
            return [
                'label' => $date->format('d/m'),
                'success' => $executions->where('status', 'succeeded')->count(),
                'failure' => $executions->whereIn('status', ['failed', 'timed_out'])->count(),
                'other' => $executions->whereNotIn('status', ['succeeded', 'failed', 'timed_out'])->count(),
            ];
        });
        $activeDevices = Device::query()->where('is_active', true)->count();
        $inactiveDevices = Device::query()->where('is_active', false)->count();
        $ftpAccounts = Schema::hasTable('ftp_accounts') ? FtpAccount::query() : null;
        $recentReceipts = Schema::hasTable('ftp_received_files')
            ? DB::table('ftp_received_files')->join('ftp_accounts', 'ftp_accounts.id', '=', 'ftp_received_files.ftp_account_id')
                ->orderByDesc('ftp_received_files.received_at')->limit(5)
                ->get(['ftp_received_files.received_at', 'ftp_received_files.original_filename', 'ftp_received_files.size_bytes', 'ftp_received_files.status', 'ftp_accounts.username', 'ftp_accounts.id as account_id'])
            : collect();

        return view('dashboard', [
            'localNow' => $localNow,
            'instanceTimezone' => $instanceTimezone,
            'siteCount' => Site::count(),
            'deviceCount' => Device::count(),
            'policyCount' => BackupPolicy::count(),
            'executionCount' => BackupExecution::count(),
            'successCount' => BackupExecution::query()->where('status', 'succeeded')->where('created_at', '>=', $since)->count(),
            'failureCount' => BackupExecution::query()->whereIn('status', ['failed', 'timed_out'])->where('created_at', '>=', $since)->count(),
            'artifactCount' => BackupArtifact::count(),
            'activeDevices' => $activeDevices,
            'inactiveDevices' => $inactiveDevices,
            'activeDevicePercent' => $activeDevices + $inactiveDevices > 0
                ? (int) round($activeDevices / ($activeDevices + $inactiveDevices) * 100)
                : 0,
            'inactiveDevicePercent' => $activeDevices + $inactiveDevices > 0
                ? 100 - (int) round($activeDevices / ($activeDevices + $inactiveDevices) * 100)
                : 0,
            'days' => $days,
            'period' => $period,
            'chartStart' => $chartStart,
            'chartEnd' => $chartEnd,
            'chartLabel' => $chartLabel,
            'chartMax' => max(1, $days->max(fn ($day) => array_sum(array_slice($day, 1))) ?? 1),
            'recentExecutions' => BackupExecution::query()->with('device:id,name')->latest()->limit(5)->get(),
            'ftpCount' => $ftpAccounts ? (clone $ftpAccounts)->count() : 0,
            'activeFtpCount' => $ftpAccounts ? (clone $ftpAccounts)->where('is_active', true)->count() : 0,
            'inactiveFtpCount' => $ftpAccounts ? (clone $ftpAccounts)->where('is_active', false)->count() : 0,
            'fileServerCount' => $ftpAccounts ? (clone $ftpAccounts)->where('purpose', 'file_server')->count() : 0,
            'backupFtpCount' => $ftpAccounts ? (clone $ftpAccounts)->where('purpose', 'backup')->count() : 0,
            'recentReceipts' => $recentReceipts,
        ]);
    }
}
