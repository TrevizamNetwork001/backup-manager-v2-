<?php

namespace Tests\Unit;

use App\Services\EngineJobService;
use App\Support\ErrorCodes;
use PHPUnit\Framework\TestCase;

class ErrorCodesTest extends TestCase
{
    public function test_known_local_unknown_and_empty_codes(): void
    {
        $this->assertSame('Autenticação SSH falhou.', ErrorCodes::message('SSH_AUTH_FAILED'));
        $this->assertSame('Execução excedeu o tempo máximo permitido.', ErrorCodes::message('ENGINE_TIMEOUT'));
        $this->assertSame('Falha no processamento.', ErrorCodes::message('ALGO_NOVO'));
        $this->assertSame('Falha sem código registrado.', ErrorCodes::message(null));
        $this->assertSame('Falha sem código registrado.', ErrorCodes::message(''));
    }

    public function test_every_engine_message_is_in_portuguese_not_just_the_code(): void
    {
        foreach (EngineJobService::ERROR_MESSAGES as $code => $message) {
            $this->assertNotSame($code, $message);
            $this->assertMatchesRegularExpression('/\S+\s+\S+/', $message, "{$code} precisa de uma frase");
        }
    }
}
