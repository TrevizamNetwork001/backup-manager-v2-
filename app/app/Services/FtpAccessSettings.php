<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FtpAccessSettings
{
    public function available(): bool
    {
        return Schema::hasColumn('application_settings', 'ftp_allowed_cidrs');
    }

    /** @return array{cidrs: list<string>, revision: int, applied_revision: int} */
    public function get(): array
    {
        if (! $this->available()) {
            return ['cidrs' => [], 'revision' => 0, 'applied_revision' => 0];
        }
        $row = DB::table('application_settings')->where('id', 1)->first([
            'ftp_allowed_cidrs', 'ftp_acl_revision', 'ftp_acl_applied_revision',
        ]);

        return [
            'cidrs' => json_decode($row->ftp_allowed_cidrs ?? '[]', true) ?: [],
            'revision' => (int) ($row->ftp_acl_revision ?? 0),
            'applied_revision' => (int) ($row->ftp_acl_applied_revision ?? 0),
        ];
    }

    /** @return list<string> */
    public function normalize(string $input): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $input);
        $cidrs = [];
        foreach ($lines as $line) {
            $cidr = trim($line);
            if ($cidr === '') {
                continue;
            }
            if (! preg_match('~\A([0-9.]+)/([0-9]{1,2})\z~D', $cidr, $matches) ||
                ! filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
                (int) $matches[2] < 8 || (int) $matches[2] > 32) {
                throw ValidationException::withMessages(['ftp_allowed_cidrs' => 'Informe somente blocos IPv4 CIDR válidos, um por linha.']);
            }
            $prefix = (int) $matches[2];
            $address = ip2long($matches[1]);
            $mask = $prefix === 32 ? 0xFFFFFFFF : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            $network = long2ip($address & $mask).'/'.$prefix;
            $cidrs[$network] = $network;
        }
        if ($cidrs === [] || count($cidrs) > 32) {
            throw ValidationException::withMessages(['ftp_allowed_cidrs' => 'Informe de 1 a 32 blocos IPv4.']);
        }

        return array_values($cidrs);
    }

    /** @param list<string> $cidrs */
    public function set(array $cidrs): int
    {
        return DB::transaction(function () use ($cidrs): int {
            $row = DB::table('application_settings')->where('id', 1)->lockForUpdate()->first();
            $revision = (int) $row->ftp_acl_revision + 1;
            DB::table('application_settings')->where('id', 1)->update([
                'ftp_allowed_cidrs' => json_encode($cidrs, JSON_THROW_ON_ERROR),
                'ftp_acl_revision' => $revision,
                'updated_at' => now(),
            ]);

            return $revision;
        });
    }

    public function markApplied(int $revision): void
    {
        DB::table('application_settings')->where('id', 1)
            ->where('ftp_acl_revision', $revision)
            ->update(['ftp_acl_applied_revision' => $revision]);
    }
}
