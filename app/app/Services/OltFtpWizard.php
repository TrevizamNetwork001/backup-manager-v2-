<?php

namespace App\Services;

use App\Models\BackupExecution;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\OltFtpIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Historically named/tabled for OLT (table `olt_ftp_integrations`), this
 * wizard now also covers Huawei network devices (router/switch) — VRP
 * firmware can push its own saved-configuration to an FTP server the same
 * way an OLT does. See Device::isHuaweiFtpEligible().
 */
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
        // OLT: the operator triggers one specific execution on demand (exact
        // expected filename). Network devices (router/switch) push
        // automatically on their own schedule with their own filename — VRP's
        // `save-configuration backup-to-server` has no "send now with this
        // name" equivalent — so instead of waiting on one pre-created
        // execution, we watch for whichever spontaneous ftp_received
        // execution shows up first after confirmation.
        $execution = ! $confirmed ? null : ($device->platform === 'olt'
            ? $integration->testExecution
            : BackupExecution::query()->with('artifact')
                ->where('device_id', $device->id)
                ->where('device_backup_policy_id', $integration->test_association_id)
                ->where('origin', 'ftp_received')
                ->where('created_at', '>=', $integration->olt_confirmed_at)
                ->orderByDesc('id')->first());
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
            $this->assertEligible($locked);
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
            if ($locked->platform !== 'olt') {
                // Network devices have no on-demand "startTest" step (see
                // snapshot()) — the association to watch is provisioned here.
                $integration->test_association_id = app(HuaweiFtpBackupPolicy::class)->ensure($locked)->id;
            }
            $integration->save();
        });
    }

    public function startTest(Device $device): BackupExecution
    {
        return DB::transaction(function () use ($device) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertEligible($locked);
            abort_unless($locked->platform === 'olt', 404, 'Equipamentos de rede aguardam o próximo envio automático; não há teste sob demanda.');
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
            if (! $association || ! $association->is_active ||
                ! app(HuaweiFtpBackupPolicy::class)->compatible($association->load('backupPolicy'))) {
                $association = app(HuaweiFtpBackupPolicy::class)->ensure($locked);
                $integration->test_association_id = $association->id;
            }
            $execution = BackupExecution::createManual($association);
            $execution->transitionTo('queued');
            $integration->test_execution_id = $execution->id;
            $integration->save();
            return $execution;
        });
    }

    public function assertEligible(Device $device): void
    {
        abort_unless($device->isHuaweiFtpEligible(), 404);
        abort_unless(Schema::hasTable('olt_ftp_integrations'), 503);
    }
}
