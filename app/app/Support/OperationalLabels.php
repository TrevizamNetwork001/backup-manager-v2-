<?php

namespace App\Support;

class OperationalLabels
{
    public const EXECUTION_STATUSES = [
        'pending' => 'Pendente', 'queued' => 'Na fila', 'running' => 'Em andamento',
        'succeeded' => 'Concluído', 'failed' => 'Falhou', 'retry_wait' => 'Aguardando nova tentativa',
        'timed_out' => 'Tempo esgotado', 'cancelled' => 'Cancelado',
    ];

    public const EXECUTION_ORIGINS = ['manual' => 'Manual', 'scheduler' => 'Agendamento', 'ftp_received' => 'FTP recebido'];

    public const METHODS = ['ssh_pull' => 'Coleta via SSH', 'ftp_push' => 'Envio via FTP'];

    public const ARTIFACT_STATUSES = ['available' => 'Disponível', 'deleted' => 'Removido', 'missing' => 'Ausente'];

    public const FTP_STATUSES = [
        'received' => 'Recebido', 'pending' => 'Pendente', 'processing' => 'Em processamento',
        'stored' => 'Armazenado', 'quarantined' => 'Em quarentena', 'failed' => 'Falhou',
        'error' => 'Erro', 'deleted' => 'Removido',
    ];

    public const FTP_LAYOUTS = ['legacy' => 'Legado', 'account' => 'Por conta', 'account_uuid' => 'Por conta'];
}
