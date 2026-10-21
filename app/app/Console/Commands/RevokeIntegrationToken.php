<?php

namespace App\Console\Commands;

use App\Models\IntegrationToken;
use App\Services\AuditEvents;
use Illuminate\Console\Command;

class RevokeIntegrationToken extends Command
{
    protected $signature = 'integration:token-revoke {id : ID do token}';

    protected $description = 'Revoga um token de integração';

    public function handle(AuditEvents $audit): int
    {
        $token = IntegrationToken::query()->find($this->argument('id'));
        if ($token === null) {
            $this->error('Token não encontrado.');

            return self::FAILURE;
        }

        $token->revoke();
        $audit->record('api.integration.token.revoked', 'integration_token', (string) $token->id,
            $token->name, 'success', []);
        $this->info('Token revogado.');

        return self::SUCCESS;
    }
}
