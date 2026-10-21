<?php

namespace App\Services;

use App\Models\BackupExecution;
use App\Models\Credential;
use App\Models\DeviceBackupPolicy;
use App\Models\FtpAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MikrotikFtpIntegration
{
    public function __construct(
        private readonly MikrotikFtpBridge $bridge,
        private readonly FtpServerSettings $settings,
        private readonly HuaweiFtpBackupPolicy $policies,
        private readonly AuditEvents $audit,
    ) {}

    public function isMikrotik(FtpAccount $account): bool
    {
        $device = $account->device;

        return $account->purpose === 'backup' && $device !== null
            && mb_strtolower(trim($device->vendor)) === 'mikrotik' && $device->platform === 'network';
    }

    /** @return array{status: string, message: string} */
    public function summary(FtpAccount $account): array
    {
        return $this->reply($account->mikrotik_setup_status ?? 'ready', $account->mikrotik_setup_error);
    }

    /** @return array{status: string, message: string} */
    public function start(FtpAccount $account, string $scheduleTime, ?int $actorId, ?string $ip): array
    {
        $state = DB::transaction(function () use ($account, $scheduleTime): string {
            $locked = FtpAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->mikrotik_setup_status === 'completed') {
                return 'completed';
            }
            if (in_array($locked->mikrotik_setup_status, ['running', 'testing', 'enabling'], true)
                && $locked->mikrotik_setup_started_at?->gt(now()->subMinutes(5))) {
                return 'in_progress';
            }
            $this->validateAccount($locked);
            $locked->mikrotik_setup_status = 'running';
            $locked->mikrotik_setup_started_at = now();
            $locked->mikrotik_setup_completed_at = null;
            $locked->mikrotik_test_filename = null;
            $locked->mikrotik_setup_error = null;
            $locked->mikrotik_schedule_time = $scheduleTime;
            $locked->save();

            return 'started';
        });
        if ($state !== 'started') {
            return $this->reply($state);
        }

        try {
            $result = $this->bridge->run($this->payload($account->fresh(), 'install_test'));
            if (! ($result['success'] ?? false) || ! preg_match('/\A[a-zA-Z0-9._-]{1,100}\.rsc\z/D', $result['filename'] ?? '')) {
                return $this->fail($account, (string) ($result['code'] ?? 'INTEGRATION_FAILED'), $actorId, $ip);
            }
            FtpAccount::query()->whereKey($account->id)->where('mikrotik_setup_status', 'running')->update([
                'mikrotik_setup_status' => 'testing', 'mikrotik_test_filename' => $result['filename'], 'updated_at' => now(),
            ]);
            $this->audit->record('mikrotik.ftp.install_test_sent', 'ftp_account', (string) $account->id,
                $account->username, 'success', ['device_id' => $account->device_id, 'filename' => $result['filename']],
                $actorId, $ip);

            return $this->status($account, $actorId, $ip);
        } catch (\Throwable) {
            return $this->fail($account, 'INTEGRATION_FAILED', $actorId, $ip);
        }
    }

    /** @return array{status: string, message: string} */
    public function status(FtpAccount $account, ?int $actorId, ?string $ip): array
    {
        $account = $account->fresh();
        if (in_array($account->mikrotik_setup_status, ['running', 'enabling'], true)
            && $account->mikrotik_setup_started_at?->lt(now()->subMinutes(5))) {
            return $this->fail($account, 'INTEGRATION_TIMEOUT', $actorId, $ip);
        }
        if ($account->mikrotik_setup_status !== 'testing') {
            return $this->reply($account->mikrotik_setup_status ?? 'ready', $account->mikrotik_setup_error);
        }
        $execution = BackupExecution::query()->where('ftp_account_id', $account->id)
            ->where('received_filename', $account->mikrotik_test_filename)
            ->where('received_at', '>=', $account->mikrotik_setup_started_at)
            ->orderByDesc('id')->first();
        if ($execution?->status === 'succeeded' && $execution->artifact()->exists()) {
            $claimed = FtpAccount::query()->whereKey($account->id)->where('mikrotik_setup_status', 'testing')
                ->update(['mikrotik_setup_status' => 'enabling', 'updated_at' => now()]);
            if (! $claimed) {
                return $this->reply('in_progress');
            }
            try {
                $result = $this->bridge->run($this->payload($account->fresh(), 'enable'));
                if (! ($result['success'] ?? false)) {
                    return $this->fail($account, (string) ($result['code'] ?? 'SCHEDULER_ENABLE_FAILED'), $actorId, $ip);
                }
                FtpAccount::query()->whereKey($account->id)->where('mikrotik_setup_status', 'enabling')->update([
                    'mikrotik_setup_status' => 'completed', 'mikrotik_setup_completed_at' => now(),
                    'mikrotik_setup_error' => null, 'updated_at' => now(),
                ]);
                $this->audit->record('mikrotik.ftp.integration_completed', 'ftp_account', (string) $account->id,
                    $account->username, 'success', ['device_id' => $account->device_id, 'execution_id' => $execution->id,
                        'schedule_time' => $account->mikrotik_schedule_time], $actorId, $ip);

                return $this->reply('completed');
            } catch (\Throwable) {
                return $this->fail($account, 'SCHEDULER_ENABLE_FAILED', $actorId, $ip);
            }
        }
        if (in_array($execution?->status, ['failed', 'timed_out', 'cancelled'], true)) {
            return $this->fail($account, 'FTP_BACKUP_FAILED', $actorId, $ip);
        }
        $receipt = DB::table('ftp_received_files')->where('ftp_account_id', $account->id)
            ->where('original_filename', $account->mikrotik_test_filename)
            ->where('received_at', '>=', $account->mikrotik_setup_started_at)
            ->orderByDesc('id')->first(['status', 'error_code']);
        if ($receipt?->status === 'quarantined') {
            return $this->fail($account, 'FTP_FILE_REJECTED', $actorId, $ip);
        }
        if ($account->mikrotik_setup_started_at?->lt(now()->subMinutes(3))) {
            return $this->fail($account, 'FTP_RECEIPT_TIMEOUT', $actorId, $ip);
        }

        return $this->reply('testing');
    }

    private function validateAccount(FtpAccount $account): void
    {
        $account->load('device');
        $device = $account->device;
        if (! $this->isMikrotik($account) || ! $device->is_active || ! $account->is_active ||
            $account->deletion_mode || ! $account->provisioned_at || $account->sync_error || ! $account->secret ||
            ! $this->policies->active($device)) {
            throw ValidationException::withMessages(['integration' => 'Conta, equipamento e política FTP precisam estar ativos e prontos.']);
        }
        if (! $device->ssh_host_key_algorithm || ! $device->ssh_host_key_fingerprint || ! $this->sshCredential($account)) {
            throw ValidationException::withMessages(['integration' => 'Credencial SSH ativa e chave confiada são necessárias.']);
        }
        $host = $this->settings->get()['host'];
        if (! FtpServerSettings::validAddress($host)) {
            throw ValidationException::withMessages(['integration' => 'Configure um endereço válido para o servidor FTP.']);
        }
    }

    private function sshCredential(FtpAccount $account): ?Credential
    {
        $association = DeviceBackupPolicy::query()->where('device_id', $account->device_id)
            ->where('is_active', true)->whereNull('archived_at')->whereNotNull('credential_id')
            ->whereHas('backupPolicy', fn ($query) => $query->where('method', 'ssh_pull')->where('is_active', true)->whereNull('archived_at'))
            ->with('credential')->orderBy('id')->first();
        $credential = $association?->credential;

        return $credential?->type === 'ssh' && $credential->is_active && $credential->secret ? $credential : null;
    }

    /** @return array<string, mixed> */
    private function payload(FtpAccount $account, string $action): array
    {
        $account->load('device');
        $credential = $this->sshCredential($account);
        if (! $credential) {
            throw new \RuntimeException('SSH_CREDENTIAL_MISSING');
        }

        return [
            'action' => $action,
            'device_id' => $account->device_id,
            'device_name' => $account->device->name,
            'host' => $account->device->management_ip,
            'port' => $credential->port ?: 22,
            'ssh_username' => $credential->username,
            'ssh_secret' => $credential->secret,
            'ssh_host_key_algorithm' => $account->device->ssh_host_key_algorithm,
            'ssh_host_key_fingerprint' => $account->device->ssh_host_key_fingerprint,
            'ftp_host' => $this->settings->get()['host'],
            'ftp_username' => $account->username,
            'ftp_secret' => $account->secret,
            'schedule_time' => $account->mikrotik_schedule_time ?: '23:00',
        ];
    }

    /** @return array{status: string, message: string} */
    private function fail(FtpAccount $account, string $code, ?int $actorId, ?string $ip): array
    {
        $safeCode = preg_match('/\A[A-Z_]{3,60}\z/D', $code) ? $code : 'INTEGRATION_FAILED';
        FtpAccount::query()->whereKey($account->id)->whereIn('mikrotik_setup_status', ['running', 'testing', 'enabling'])
            ->update(['mikrotik_setup_status' => 'failed', 'mikrotik_setup_error' => $safeCode, 'updated_at' => now()]);
        $this->audit->record('mikrotik.ftp.integration_failed', 'ftp_account', (string) $account->id,
            $account->username, 'failure', ['device_id' => $account->device_id, 'code' => $safeCode], $actorId, $ip);

        return $this->reply('failed', $safeCode);
    }

    /** @return array{status: string, message: string} */
    private function reply(string $status, ?string $code = null): array
    {
        $messages = [
            'ready' => 'Pronto para integrar o MikroTik por FTP.',
            'started' => 'Instalação iniciada.',
            'in_progress' => 'Integração em andamento.',
            'running' => 'Instalando script por SSH.',
            'testing' => 'Script instalado. Aguardando o backup de teste no servidor.',
            'enabling' => 'Backup recebido. Ativando o agendamento diário.',
            'completed' => 'Backup recebido e validado. Agendamento diário ativado no MikroTik.',
            'failed' => match ($code) {
                'LEGACY_INTEGRATION_PRESENT' => 'Já existe uma rotina antiga no MikroTik. Verifique-a antes de integrar pela V2.',
                'SSH_AUTH_FAILED' => 'A autenticação SSH falhou.',
                'SSH_CONNECT_FAILED', 'SSH_TIMEOUT' => 'Não foi possível conectar ao MikroTik por SSH.',
                'FTP_RECEIPT_TIMEOUT' => 'O envio terminou, mas o backup não chegou ao servidor no prazo.',
                'FTP_FILE_REJECTED', 'FTP_BACKUP_FAILED' => 'O servidor recebeu o arquivo, mas não validou o backup.',
                'CREDENTIAL_ROTATED' => 'A senha FTP mudou. Refaça a integração para atualizar o script no MikroTik.',
                'FTP_ACCOUNT_DISABLED' => 'A conta FTP foi desativada. Reative e refaça a integração.',
                default => 'A integração não foi concluída. Confira o equipamento e tente novamente.',
            },
        ];

        return ['status' => $status, 'message' => $messages[$status] ?? $messages['ready']];
    }
}
