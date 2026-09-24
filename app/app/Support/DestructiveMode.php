<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Shared confirmation-phrase scheme for destructive actions, generalizing the
 * pattern validated by FTP-CORE-2 (FtpAccountDeletionService). The backend
 * always computes the expected phrase and compares it exactly (hash_equals);
 * the frontend only ever displays it — never trust a phrase sent by the client.
 */
class DestructiveMode
{
    public const DEACTIVATE = 'deactivate';
    public const ARCHIVE = 'archive';
    public const DELETE = 'delete';
    public const DELETE_WITH_DATA = 'delete_with_data';
    public const DELETE_PERMANENTLY = 'delete_permanently';

    private const PREFIXES = [
        self::DEACTIVATE => 'DESATIVAR ',
        self::ARCHIVE => 'ARQUIVAR ',
        self::DELETE => 'EXCLUIR ',
        self::DELETE_WITH_DATA => 'EXCLUIR DADOS ',
        self::DELETE_PERMANENTLY => 'APAGAR TUDO ',
    ];

    public static function confirmationPhrase(string $mode, string $identifier): string
    {
        if (! isset(self::PREFIXES[$mode])) {
            throw new InvalidArgumentException("Modo destrutivo desconhecido: {$mode}");
        }

        return self::PREFIXES[$mode].$identifier;
    }

    public static function confirmed(string $mode, string $identifier, ?string $provided): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals(self::confirmationPhrase($mode, $identifier), $provided);
    }
}
