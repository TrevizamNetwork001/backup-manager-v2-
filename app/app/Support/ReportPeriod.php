<?php

namespace App\Support;

use App\Services\InstanceTimezone;
use Carbon\CarbonImmutable;

/**
 * Single period-filter resolver shared by every report and by AuditController
 * (FEATURES-FINAL-1, Part S/J — avoid the divergent period logic V1 had
 * scattered per report). Always resolves in the INSTANCE's configured
 * timezone, then converts to UTC for querying — V1's reports compared date
 * filters directly against UTC-stored columns with no timezone conversion,
 * silently misattributing rows near local midnight (see docs/REPORTS.md,
 * "Comparação com V1").
 */
class ReportPeriod
{
    public const OPTIONS = ['today', '7d', '30d', '90d', 'custom'];

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} [fromUtc, toUtc]
     */
    public static function resolve(?string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $timezone = app(InstanceTimezone::class)->get();
        $now = CarbonImmutable::now($timezone);

        [$from, $to] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            '90d' => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'custom' => [
                $dateFrom ? CarbonImmutable::parse($dateFrom, $timezone)->startOfDay() : null,
                $dateTo ? CarbonImmutable::parse($dateTo, $timezone)->endOfDay() : null,
            ],
            default => [null, null],
        };

        return [$from?->utc(), $to?->utc()];
    }

    public static function label(?string $period): string
    {
        return match ($period) {
            'today' => 'Hoje',
            '7d' => 'Últimos 7 dias',
            '30d' => 'Últimos 30 dias',
            '90d' => 'Últimos 90 dias',
            'custom' => 'Personalizado',
            default => 'Todos',
        };
    }
}
