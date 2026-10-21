<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationToken extends Model
{
    public const SCOPE_READ = 'read';

    protected $fillable = ['name', 'token_hash', 'scope', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return array{0: string, 1: self} */
    public static function issue(string $name, int $days = 90): array
    {
        $plain = 'bmiv1_'.bin2hex(random_bytes(32));
        $record = self::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'scope' => self::SCOPE_READ,
            'expires_at' => now()->addDays($days),
        ]);

        return [$plain, $record];
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
