<?php

namespace App\Support;

/**
 * Single vocabulary for "how is this thing doing right now" across every
 * health check in the app (engine, worker, scheduler, storage, FTP, devices,
 * ...). Never mix in ad hoc strings like 'ok'/'good'/'error' — always go
 * through this enum so a health page can render one consistent badge set.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    /** Higher = worse. Used by worst()/CheckList aggregation. */
    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Unknown => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    /** The overall status of a group of checks is the single worst one. */
    public static function worst(array $statuses): self
    {
        $result = self::Healthy;
        foreach ($statuses as $status) {
            if ($status->severity() > $result->severity()) {
                $result = $status;
            }
        }

        return $result;
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
            self::Unknown => 'neutral',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Saudável',
            self::Warning => 'Atenção',
            self::Critical => 'Crítico',
            self::Unknown => 'Desconhecido',
        };
    }
}
