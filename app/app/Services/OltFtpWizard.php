<?php

namespace App\Services;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\OltFtpIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OltFtpWizard
{
    public function snapshot(Device $device): array
    {
        $account = Schema::hasTable('ftp_accounts') ? $device->ftpAccount : null;
        $integration = Schema::hasTable('olt_ftp_integrations') && $account
            ? OltFtpIntegration::query()->where('device_id', $device->id)->first() : null;
        $ftpServer = app(FtpServerSettings::class)->get();
        $host = $ftpServer['host'];
        $created = (bool) ($account?->is_active);
        $synced = $created && $account->provisioned_at !== null && $account->sync_error === null;
        $server = FtpServerSettings::validAddress($host) &&
            $ftpServer['port'] >= 1 && $ftpServer['port'] <= 65535 &&
            ($ftpServer['passive_address'] === '' || FtpServerSettings::validAddress($ftpServer['passive_address']));
        $confirmed = $synced && $server && $integration?->olt_confirmed_at !== null &&
            $integration->ftp_host === $host &&
            $integration->management_ip === $device->management_ip;
        $execution = $confirmed ? $integration->testExecution : null;
        $operational = $execution?->status === 'succeeded' &&
            $execution->device_id === $device->id &&
            $execution->device_backup_policy_id === $integration->test_association_id &&
            $execution->artifact?->validated_at !== null;
        $state = ! $created ? 'account' : (! $synced ? ($account->sync_error ? 'sync_error' : 'sync') : (! $server ? 'server' :
            (! $confirmed ? 'olt' : (! $execution ? 'test' : ($operational ? 'operational' :
                (in_array($execution->status, ['failed', 'cancelled'], true) ? 'failed' : 'waiting'))))));

        $currentStep = match ($state) {
            'account' => 1,
            'sync', 'sync_error' => 2,
            'server' => 3,
            'olt' => 4,
            'test', 'waiting', 'failed' => 5,
            'operational' => 6,
        };

        return compact('account', 'integration', 'host', 'created', 'synced', 'server', 'confirmed', 'execution', 'operational', 'state') + [
            'current_step' => $currentStep,
            'passive_address' => $ftpServer['passive_address'],
            'port' => $ftpServer['port'],
        ];
    }

    public function confirm(Device $device): void
    {
        DB::transaction(function () use ($device) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertOlt($locked);
            $ready = $this->snapshot($locked);
            if (! $ready['synced'] || ! $ready['server']) {
                throw ValidationException::withMessages(['wizard' => 'A conta precisa estar sincronizada e o servidor FTP configurado.']);
            }
            if ($ready['integration'] === null) {
                DB::table('olt_ftp_integrations')->insertOrIgnore([
                    'device_id' => $locked->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $integration = OltFtpIntegration::query()->where('device_id', $locked->id)
                ->lockForUpdate()->firstOrFail();
            $integration->olt_confirmed_at = now();
            $integration->ftp_host = $ready['host'];
            $integration->management_ip = $locked->management_ip;
            $integration->test_execution_id = null;
            $integration->save();
        });
    }

    public function startTest(Device $device): BackupExecution
    {
        return DB::transaction(function () use ($device) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertOlt($locked);
            $ready = $this->snapshot($locked);
            if (! $ready['confirmed']) {
                throw ValidationException::withMessages(['wizard' => 'Confirme a configuração manual da OLT após preparar o FTP.']);
            }
            $integration = OltFtpIntegration::query()->lockForUpdate()->where('device_id', $device->id)->firstOrFail();
            $previous = $integration->testExecution;
            if ($previous && (in_array($previous->status, ['pending', 'queued', 'running'], true) || $ready['operational'])) {
                return $previous;
            }

            $association = $integration->test_association_id
                ? DeviceBackupPolicy::findOrFail($integration->test_association_id)
                : null;
            if (! $association) {
                $policy = BackupPolicy::create([
                    'name' => 'Teste de integração OLT FTP #'.$locked->id,
                    'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual',
                    'retention_count' => 1, 'is_active' => true,
                ]);
                $association = DeviceBackupPolicy::create([
                    'device_id' => $locked->id, 'backup_policy_id' => $policy->id,
                    'credential_id' => null, 'is_active' => true,
                ]);
                $integration->test_association_id = $association->id;
            }
            $execution = BackupExecution::createManual($association);
            $execution->transitionTo('queued');
            $integration->test_execution_id = $execution->id;
            $integration->save();
            return $execution;
        });
    }

    public function assertOlt(Device $device): void
    {
        abort_unless(mb_strtolower(trim($device->vendor)) === 'huawei' && $device->platform === 'olt', 404);
        abort_unless(Schema::hasTable('olt_ftp_integrations'), 503);
    }
}
