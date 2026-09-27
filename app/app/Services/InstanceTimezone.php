<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InstanceTimezone
{
    public const DEFAULT = 'America/Sao_Paulo';

    // FEATURES-FINAL-1 (P2 from CORE_STATUS): get() ran a fresh query on every
    // call with no memoization — dozens of extra queries per page on any
    // listing that formats a timestamp per row (executions, audit, artifacts,
    // users, and now every report). Bound as a singleton (AppServiceProvider)
    // so this cache is shared for the whole request, not just this instance.
    private ?string $cached = null;

    public function get(): string
    {
        return $this->cached ??= DB::table('application_settings')->where('id', 1)->value('timezone') ?? self::DEFAULT;
    }

    public function set(string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Fuso horário IANA inválido.');
        }

        DB::table('application_settings')->where('id', 1)->update([
            'timezone' => $timezone, 'updated_at' => CarbonImmutable::now('UTC'),
        ]);
        $this->cached = $timezone;
    }

    public function format(?CarbonInterface $timestamp, string $format = 'd/m/Y H:i:s'): ?string
    {
        return $timestamp?->toImmutable()->setTimezone($this->get())->format($format);
    }

    public function localNow(?CarbonInterface $now = null): CarbonImmutable
    {
        return ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now('UTC'))
            ->setTimezone($this->get());
    }
}
