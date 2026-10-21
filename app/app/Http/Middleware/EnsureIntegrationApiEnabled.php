<?php

namespace App\Http\Middleware;

use App\Services\IntegrationApiSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntegrationApiEnabled
{
    public function __construct(private readonly IntegrationApiSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->enabled(), 404);

        return $next($request);
    }
}
