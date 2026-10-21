<?php

namespace App\Services;

use App\Models\IntegrationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IntegrationApiSettings
{
    public function available(): bool
    {
        return Schema::hasColumn('application_settings', 'integration_api_enabled')
            && Schema::hasTable('integration_tokens');
    }

    /** @return array{enabled: bool, allowed_ips: list<string>, active_tokens: int} */
    public function get(): array
    {
        if (! $this->available()) {
            return ['enabled' => false, 'allowed_ips' => [], 'active_tokens' => 0];
        }

        $row = DB::table('application_settings')->where('id', 1)
            ->first(['integration_api_enabled', 'integration_api_allowed_ips']);

        return [
            'enabled' => (bool) ($row->integration_api_enabled ?? false),
            'allowed_ips' => json_decode($row->integration_api_allowed_ips ?? '[]', true) ?: [],
            'active_tokens' => IntegrationToken::query()->where('scope', IntegrationToken::SCOPE_READ)
                ->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
        ];
    }

    public function enabled(): bool
    {
        $settings = $this->get();

        return $settings['enabled'] && $settings['allowed_ips'] !== [];
    }

    /** @return list<string> */
    public function normalizeIps(string $input): array
    {
        $lines = preg_split('/[\s,;]+/', trim($input));
        $ips = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$address, $prefix] = array_pad(explode('/', $line, 2), 2, '32');
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
                ! ctype_digit($prefix) || (int) $prefix < 8 || (int) $prefix > 32) {
                throw ValidationException::withMessages([
                    'integration_api_allowed_ips' => 'Informe somente IPv4 ou blocos IPv4 CIDR válidos, um por linha.',
                ]);
            }
            $bits = (int) $prefix;
            $mask = $bits === 32 ? 0xFFFFFFFF : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            $network = long2ip(ip2long($address) & $mask).'/'.$bits;
            $ips[$network] = $network;
        }
        if (count($ips) > 32) {
            throw ValidationException::withMessages([
                'integration_api_allowed_ips' => 'Informe no máximo 32 IPs ou redes.',
            ]);
        }

        return array_values($ips);
    }

    /** @param list<string> $allowedIps */
    public function save(bool $enabled, array $allowedIps): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Atualização do banco pendente para configurar a API.');
        }

        DB::table('application_settings')->where('id', 1)->update([
            'integration_api_enabled' => $enabled,
            'integration_api_allowed_ips' => json_encode($allowedIps, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
}
