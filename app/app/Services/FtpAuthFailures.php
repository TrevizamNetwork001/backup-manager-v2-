<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reads the login events the FTP container writes (`epoch<TAB>F|S<TAB>user<TAB>ip`,
 * only for provisioned accounts) and reports accounts whose logins keep being
 * refused. A device whose stored FTP password drifted from the panel's keeps
 * trying and failing without ever producing a file — invisible until now.
 *
 * Stateless: the rolling window is the state. A successful login ("S") clears
 * the failures before it, so a fixed device normalizes immediately.
 */
class FtpAuthFailures
{
    private const FILES = ['auth-events.log.1', 'auth-events.log'];

    /**
     * @return array<string, array{count:int,last_ip:string,label:string}>|null
     *                                                                           username => details; null when the log cannot be read (caller keeps the previous state)
     */
    public function active(): ?array
    {
        $dir = rtrim((string) config('backup.ftp_log_dir'), '/');
        if (! is_dir($dir)) {
            return null;
        }
        $since = time() - (int) config('backup.ftp_auth_window_minutes') * 60;

        $events = [];
        foreach (self::FILES as $name) {
            $path = "{$dir}/{$name}";
            if (! is_file($path)) {
                continue;
            }
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                return null;
            }
            while (($line = fgets($handle)) !== false) {
                $parts = explode("\t", rtrim($line, "\r\n"));
                if (count($parts) !== 4 || ! ctype_digit($parts[0]) || (int) $parts[0] < $since
                    || ! in_array($parts[1], ['F', 'S'], true)) {
                    continue;
                }
                $events[] = [(int) $parts[0], $parts[1], $parts[2], $parts[3]];
            }
            fclose($handle);
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);

        $failures = [];
        foreach ($events as [, $kind, $user, $ip]) {
            if ($kind === 'S') {
                unset($failures[$user]);

                continue;
            }
            $failures[$user] = ['count' => ($failures[$user]['count'] ?? 0) + 1, 'last_ip' => $ip];
        }
        $threshold = (int) config('backup.ftp_auth_threshold');
        $failures = array_filter($failures, fn ($f) => $f['count'] >= $threshold);
        if ($failures === []) {
            return [];
        }

        $accounts = DB::table('ftp_accounts as a')->leftJoin('devices as d', 'd.id', '=', 'a.device_id')
            ->whereIn('a.username', array_keys($failures))->where('a.is_active', true)
            ->get(['a.username', 'd.name as device_name'])->keyBy('username');

        $active = [];
        foreach ($failures as $user => $failure) {
            if (isset($accounts[$user])) {
                $active[$user] = $failure + ['label' => $accounts[$user]->device_name ?: $user];
            }
        }

        return $active;
    }
}
