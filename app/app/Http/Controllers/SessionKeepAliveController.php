<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Touched by the inactivity-warning modal's "Continuar Conectado" button
 * (resources/views/partials/session-timeout-modal.blade.php). No explicit
 * session write is needed: any authenticated request already refreshes the
 * session's last-activity timestamp via the framework's StartSession
 * middleware, which is exactly the behavior we want to reuse here.
 */
class SessionKeepAliveController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response()->noContent();
    }
}
