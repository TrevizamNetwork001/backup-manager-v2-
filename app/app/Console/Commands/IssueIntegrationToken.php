<?php

namespace App\Console\Commands;

use App\Models\IntegrationToken;
use App\Services\AuditEvents;
use Illuminate\Console\Command;

class IssueIntegrationToken extends Command
{
    protected $signature = 'integration:token-issue {name : Nome da integração} {--days=90 : Validade em dias (1 a 365)}';

    protected $description = 'Emite um token de integração somente leitura e o mostra uma única vez';

    public function handle(AuditEvents $audit): int
    {
        $name = trim((string) $this->argument('name'));
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 100 || $days === false || $days < 1 || $days > 365) {
            $this->error('Informe nome com até 100 caracteres e validade entre 1 e 365 dias.');

            return self::FAILURE;
        }

        [$plain, $token] = IntegrationToken::issue($name, $days);
        $audit->record('api.integration.token.issued', 'integration_token', (string) $token->id,
            $token->name, 'success', ['scope' => $token->scope, 'expires_at' => $token->expires_at->toIso8601String()]);
        $this->info('Token criado. Copie agora; o valor não poderá ser consultado novamente.');
        $this->line($plain);

        return self::SUCCESS;
    }
}
