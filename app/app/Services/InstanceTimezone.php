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

    public function get(): string
    {
        return DB::table('application_settings')->where('id', 1)->value('timezone') ?? self::DEFAULT;
    }

    public function set(string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Fuso horário IANA inválido.');
        }

        DB::table('application_settings')->where('id', 1)->update([
            'timezone' => $timezone, 'updated_at' => CarbonImmutable::now('UTC'),
        ]);
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
