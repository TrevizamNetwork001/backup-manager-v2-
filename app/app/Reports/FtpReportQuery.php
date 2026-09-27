<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account FTP receipt summary. "Stuck processing" reuses the exact
 * threshold EngineHealth already uses (health.ftp_processing_stale_minutes)
 * — V1 never had this metric at all (see docs/REPORTS.md), so there was no
 * existing definition to preserve; this is new, but consistent with the one
 * already shipped in ENGINE-3 rather than a second, different threshold.
 */
class FtpReportQuery
{
    public function accounts(string $purpose): array
    {
        if (! Schema::hasTable('ftp_received_files')) {
            return [];
        }

        $accounts = DB::table('ftp_accounts')
            ->leftJoin('devices', 'devices.id', '=', 'ftp_accounts.device_id')
            ->where('ftp_accounts.purpose', $purpose)
            ->select('ftp_accounts.id', 'ftp_accounts.username', 'ftp_accounts.home_layout',
                'ftp_accounts.is_active', 'devices.name as device_name')
            ->orderBy('ftp_accounts.id')->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $accountIds = $accounts->pluck('id')->all();
        $byStatus = DB::table('ftp_received_files')->whereIn('ftp_account_id', $accountIds)
            ->select('ftp_account_id', 'status', DB::raw('count(*) as total'), DB::raw('max(received_at) as last_at'))
            ->groupBy('ftp_account_id', 'status')->get()->groupBy('ftp_account_id');

        $staleCutoff = now()->subMinutes((int) config('health.ftp_processing_stale_minutes'));
        $stuck = DB::table('ftp_received_files')->whereIn('ftp_account_id', $accountIds)
            ->where('status', 'processing')->where('updated_at', '<', $staleCutoff)
            ->select('ftp_account_id', DB::raw('count(*) as total'))
            ->groupBy('ftp_account_id')->pluck('total', 'ftp_account_id');

        return $accounts->map(function ($account) use ($byStatus, $stuck) {
            $statuses = $byStatus->get($account->id, collect())->keyBy('status');

            return (array) $account + [
                'stored_count' => (int) ($statuses['stored']->total ?? 0),
                'quarantined_count' => (int) ($statuses['quarantined']->total ?? 0),
                'last_received_at' => $statuses->max(fn ($s) => $s->last_at),
                'stuck_count' => (int) ($stuck[$account->id] ?? 0),
            ];
        })->all();
    }
}
