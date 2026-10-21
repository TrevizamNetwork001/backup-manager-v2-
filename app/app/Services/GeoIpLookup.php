<?php

namespace App\Services;

use MaxMind\Db\Reader;
use Throwable;

class GeoIpLookup
{
    private array $results = [];

    /** @return array{country_code: string, country_name: string, asn: ?int, organization: string} */
    public function lookup(?string $address): array
    {
        $address = trim((string) $address);
        if (isset($this->results[$address])) {
            return $this->results[$address];
        }

        $empty = ['country_code' => '', 'country_name' => 'Não identificado', 'asn' => null, 'organization' => ''];
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return $this->results[$address] = $empty;
        }
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $this->results[$address] = [
                'country_code' => '', 'country_name' => 'Rede local', 'asn' => null, 'organization' => '',
            ];
        }

        $country = $this->record((string) config('geoip.country_database'), $address);
        $network = $this->record((string) config('geoip.asn_database'), $address);
        $countryData = $country['country'] ?? $country['registered_country'] ?? [];
        $code = strtoupper((string) ($countryData['iso_code'] ?? ''));
        $names = $countryData['names'] ?? [];

        return $this->results[$address] = [
            'country_code' => preg_match('/\A[A-Z]{2}\z/D', $code) ? strtolower($code) : '',
            'country_name' => (string) ($names['pt-BR'] ?? $names['pt'] ?? $names['en'] ?? 'Não identificado'),
            'asn' => isset($network['autonomous_system_number']) ? (int) $network['autonomous_system_number'] : null,
            'organization' => (string) ($network['autonomous_system_organization'] ?? ''),
        ];
    }

    public function available(): bool
    {
        return $this->readable((string) config('geoip.country_database'))
            && $this->readable((string) config('geoip.asn_database'));
    }

    private function readable(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path) && ! is_link($path)
            && filesize($path) <= 512 * 1024 * 1024;
    }

    private function record(string $path, string $address): array
    {
        if (! $this->readable($path)) {
            return [];
        }

        try {
            $reader = new Reader($path);
            try {
                return $reader->get($address) ?: [];
            } finally {
                $reader->close();
            }
        } catch (Throwable) {
            return [];
        }
    }
}
