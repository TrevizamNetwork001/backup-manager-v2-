<?php

namespace App\Services;

/**
 * Whole-host CPU busy percentage, measured from /proc/stat over a short window
 * (the load average alone does not say how much of the CPU is in use).
 */
class CpuUsage
{
    public function __construct(private readonly string $statPath = '/proc/stat') {}

    /** @return int|null 0–100, or null when /proc/stat is unavailable or unusable */
    public function percent(int $sampleMicroseconds = 250000): ?int
    {
        $first = $this->read();
        if ($first === null) {
            return null;
        }
        usleep(max(0, $sampleMicroseconds));
        $second = $this->read();
        if ($second === null) {
            return null;
        }

        return self::between($first, $second);
    }

    /**
     * @param  array{idle:int,total:int}  $first
     * @param  array{idle:int,total:int}  $second
     */
    public static function between(array $first, array $second): ?int
    {
        $total = $second['total'] - $first['total'];
        if ($total <= 0) {
            return null;
        }
        $idle = $second['idle'] - $first['idle'];

        return (int) round(min(100, max(0, 100 * (1 - $idle / $total))));
    }

    /** @return array{idle:int,total:int}|null */
    public static function parse(string $line): ?array
    {
        if (! preg_match('/^cpu\s+((?:\d+\s*){4,})/', $line, $matches)) {
            return null;
        }
        $fields = array_map('intval', preg_split('/\s+/', trim($matches[1])));
        // user nice system idle iowait irq softirq steal — guest time is already inside user.
        $counted = array_slice($fields, 0, 8);

        return ['idle' => $fields[3] + ($fields[4] ?? 0), 'total' => array_sum($counted)];
    }

    /** @return array{idle:int,total:int}|null */
    private function read(): ?array
    {
        if (! is_readable($this->statPath)) {
            return null;
        }
        $handle = fopen($this->statPath, 'r');
        if ($handle === false) {
            return null;
        }
        $line = fgets($handle);
        fclose($handle);

        return is_string($line) ? self::parse($line) : null;
    }
}
