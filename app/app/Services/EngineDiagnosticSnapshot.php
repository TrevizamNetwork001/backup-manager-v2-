<?php

namespace App\Services;

/**
 * Read-only support-bundle-style snapshot (ENGINE-3, item 23). Wraps
 * EngineHealth::report() rather than duplicating its checks — this only adds
 * the few extra identification fields a support ticket needs (app commit,
 * PHP/Laravel version) that don't belong in a per-check health result.
 *
 * NEVER include: APP_KEY, passwords, tokens, private keys, decrypted
 * credentials, cookies, Authorization headers, full device configs. Nothing
 * in EngineHealth's checks carries any of those either (see
 * docs/ENGINE_HEALTH.md, "Segurança/sanitização").
 */
class EngineDiagnosticSnapshot
{
    public function __construct(private readonly EngineHealth $health)
    {
    }

    public function build(): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'app_commit' => $this->gitCommit(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'health' => $this->health->report(),
        ];
    }

    private function gitCommit(): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }
        $output = @shell_exec('git rev-parse --short HEAD 2>/dev/null');
        $sha = is_string($output) ? trim($output) : '';

        return preg_match('/\A[0-9a-f]{4,40}\z/', $sha) === 1 ? $sha : null;
    }
}
