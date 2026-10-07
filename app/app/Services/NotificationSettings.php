<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class NotificationSettings
{
    public function get(): object
    {
        return DB::table('notification_settings')->where('id', 1)->first();
    }

    public function token(): ?string
    {
        $encrypted = $this->get()->bot_token;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    /** An empty $token keeps the stored secret. */
    public function save(array $values, ?string $token): void
    {
        if ($token !== null && $token !== '') {
            $values['bot_token'] = Crypt::encryptString($token);
        }
        DB::table('notification_settings')->where('id', 1)->update($values + ['updated_at' => now()]);
    }

    public function ready(): bool
    {
        $settings = $this->get();

        return (bool) $settings->enabled && $settings->bot_token && $settings->chat_id;
    }

    public function inMaintenance(\DateTimeInterface $localNow): bool
    {
        $settings = $this->get();
        if (! $settings->maintenance_enabled) {
            return false;
        }
        $time = $localNow->format('H:i');
        [$start, $end] = [$settings->maintenance_start, $settings->maintenance_end];

        return $start <= $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
    }
}
