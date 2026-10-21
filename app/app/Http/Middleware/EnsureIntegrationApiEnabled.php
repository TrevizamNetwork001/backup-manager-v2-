<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntegrationApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('integration_api.enabled') === true, 404);

        return $next($request);
    }
}
