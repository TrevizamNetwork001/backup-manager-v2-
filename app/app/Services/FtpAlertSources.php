<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Condições de FTP que o V1 avisava e o V2 só mostrava em contagens:
 * arquivo recebido e rejeitado (quarentena) e servidor FTP fora do ar.
 */
class FtpAlertSources
{
    /**
     * Contas ativas com arquivos rejeitados na janela e nenhum arquivo aceito depois do último rejeitado
     * (um envio bom limpa o aviso).
     *
     * @return array<string, array{count:int,error_code:?string,label:string}> usuário => detalhes
     */
    public function rejected(): array
    {
        $since = now()->subHours((int) config('backup.ftp_rejected_window_hours'));
        $rows = DB::table('ftp_received_files as f')
            ->join('ftp_accounts as a', 'a.id', '=', 'f.ftp_account_id')
            ->leftJoin('devices as d', 'd.id', '=', 'a.device_id')
            ->where('f.status', 'quarantined')->where('f.received_at', '>=', $since)->where('a.is_active', true)
            ->orderBy('f.received_at')
            ->get(['a.id as account_id', 'a.username', 'd.name as device_name', 'f.error_code', 'f.received_at']);

        $active = [];
        foreach ($rows->groupBy('username') as $username => $files) {
            $last = $files->last();
            $storedAfter = DB::table('ftp_received_files')->where('ftp_account_id', $last->account_id)
                ->where('status', 'stored')->where('received_at', '>', $last->received_at)->exists();
            if ($storedAfter) {
                continue;
            }
            $active[$username] = [
                'count' => $files->count(), 'error_code' => $last->error_code,
                'label' => $last->device_name ?: $username,
            ];
        }

        return $active;
    }

    /**
     * Servidor FTP sem responder na porta configurada, confirmado por duas tentativas seguidas (evita avisar
     * num reinício de poucos segundos). Desligado quando BACKUP_FTP_PROBE_HOST está vazio.
     */
    public function serverDown(int $retryDelayMicroseconds = 2000000): bool
    {
        $host = (string) config('backup.ftp_probe_host');
        if ($host === '') {
            return false;
        }
        $port = (int) config('backup.ftp_probe_port');

        if ($this->reachable($host, $port)) {
            return false;
        }
        usleep(max(0, $retryDelayMicroseconds));

        return ! $this->reachable($host, $port);
    }

    private function reachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
