<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInitialSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_complete_setup && ! $request->routeIs('initial-setup.*', 'logout')) {
            return redirect()->route('initial-setup.edit');
        }

        return $next($request);
    }
}
