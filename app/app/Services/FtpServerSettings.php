<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FtpServerSettings
{
    public function get(): array
    {
        $saved = DB::table('application_settings')->where('id', 1)->first();

        return [
            'host' => trim((string) (($saved->ftp_host ?? null) ?: config('backup.ftp_host'))),
            'passive_address' => trim((string) config('backup.ftp_passive_address')),
            'port' => 21,
        ];
    }

    public function set(string $host, ?string $passiveAddress, int $port): void
    {
        DB::table('application_settings')->where('id', 1)->update([
            'ftp_host' => trim($host),
            'ftp_passive_address' => $passiveAddress === null ? null : trim($passiveAddress),
            'ftp_port' => $port,
            'updated_at' => now(),
        ]);
    }

    public static function validAddress(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP)) return true;
        if (preg_match('/\A[0-9.]+\z/D', $value)) return false;
        if (strlen($value) > 253 || $value === '' || ! preg_match('/\A(?=.{1,253}\z)[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?)*\z/iD', $value)) return false;

        foreach (explode('.', $value) as $label) {
            if (strlen($label) > 63) return false;
        }

        return true;
    }
}
