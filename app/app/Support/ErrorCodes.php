<?php

namespace App\Support;

use App\Services\EngineJobService;

/** Texto em português para os códigos de erro mostrados em telas e relatórios. */
class ErrorCodes
{
    /** Códigos que só o painel gera (recuperação de execuções), fora da lista do motor. */
    private const LOCAL = [
        'ENGINE_STALE' => 'Execução interrompida: sinal de atividade expirado.',
        'ENGINE_TIMEOUT' => 'Execução excedeu o tempo máximo permitido.',
    ];

    public static function message(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Falha sem código registrado.';
        }

        return EngineJobService::ERROR_MESSAGES[$code] ?? self::LOCAL[$code] ?? 'Falha no processamento.';
    }
}
