<?php

namespace App\Http\Middleware;

use App\Models\IntegrationToken;
use App\Services\IntegrationApiSettings;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationToken
{
    public function __construct(private readonly IntegrationApiSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure()) {
            return $this->reject('https_required', 'HTTPS é obrigatório.', 403);
        }

        $allowedIps = $this->settings->get()['allowed_ips'];
        if ($allowedIps === [] || ! IpUtils::checkIp($request->ip(), $allowedIps)) {
            return $this->reject('ip_not_allowed', 'Origem não autorizada.', 403);
        }

        $plain = $request->bearerToken();
        if (! is_string($plain) || preg_match('/\Abmiv1_[a-f0-9]{64}\z/D', $plain) !== 1) {
            return $this->reject('unauthorized', 'Token de integração inválido.', 401);
        }

        $token = IntegrationToken::query()->where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || $token->revoked_at !== null || $token->expires_at->isPast()) {
            return $this->reject('unauthorized', 'Token de integração inválido.', 401);
        }
        if ($token->scope !== IntegrationToken::SCOPE_READ) {
            return $this->reject('forbidden', 'Escopo insuficiente.', 403);
        }

        $request->attributes->set('integration_token', $token);

        return $next($request);
    }

    private function reject(string $code, string $message, int $status): JsonResponse
    {
        $response = response()->json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->header('Cache-Control', 'private, no-store');

        return $status === 401
            ? $response->header('WWW-Authenticate', 'Bearer realm="backup-manager-integration"')
            : $response;
    }
}
