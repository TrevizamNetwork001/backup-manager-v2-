<?php

namespace App\Http\Middleware;

use App\Models\IntegrationToken;
use App\Services\AuditEvents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditIntegrationApi
{
    public function __construct(private readonly AuditEvents $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $token = $request->attributes->get('integration_token');
        $this->audit->record('api.integration.read', 'integration_token',
            $token instanceof IntegrationToken ? (string) $token->id : null,
            $token instanceof IntegrationToken ? $token->name : null,
            $response->getStatusCode() < 400 ? 'success' : 'denied',
            ['route' => $request->route()?->getName(), 'status' => $response->getStatusCode()],
            null, $request->ip());
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
