<?php

namespace App\Services;

class HostResources
{
    public function snapshot(): array
    {
        $cpuInfo = @file_get_contents('/proc/cpuinfo') ?: '';
        $cpuCount = preg_match_all('/^processor\s*:/m', $cpuInfo) ?: null;
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $memoryInfo = @file_get_contents('/proc/meminfo') ?: '';
        $memoryTotal = $this->memoryBytes($memoryInfo, 'MemTotal');
        $memoryAvailable = $this->memoryBytes($memoryInfo, 'MemAvailable');

        return [
            'cpu_count' => $cpuCount,
            'load_1m' => is_array($load) ? $load[0] : null,
            'memory_total_bytes' => $memoryTotal,
            'memory_used_bytes' => $memoryTotal !== null && $memoryAvailable !== null
                ? max(0, $memoryTotal - $memoryAvailable)
                : null,
        ];
    }

    private function memoryBytes(string $contents, string $key): ?int
    {
        if (! preg_match('/^'.preg_quote($key, '/').':\s*(\d+)\s+kB$/m', $contents, $matches)) {
            return null;
        }

        return (int) $matches[1] * 1024;
    }
}
