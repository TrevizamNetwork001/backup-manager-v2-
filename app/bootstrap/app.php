<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The deletion confirmation is compared byte for byte, including edge whitespace.
        TrimStrings::skipWhen(fn (Request $request) => $request->isMethod('DELETE') && $request->is('ftp/accounts/*'));
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        // The app always sits behind at least one operator-controlled reverse
        // proxy (host nginx terminating TLS, then the container's own nginx) —
        // both are infrastructure we control, never a public/untrusted hop.
        // Without this, url()/asset() and $request->ip() (used by audit
        // logging) read the proxy's own address/scheme instead of the real
        // client's, regardless of X-Forwarded-* headers being sent correctly.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['secret']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
