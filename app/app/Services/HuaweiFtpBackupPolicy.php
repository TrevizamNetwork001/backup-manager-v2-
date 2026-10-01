<?php

namespace App\Services;

use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HuaweiFtpBackupPolicy
{
    public function compatible(DeviceBackupPolicy $association): bool
    {
        $policy = $association->backupPolicy;

        return $policy?->is_active && $policy->method === 'ftp_push' &&
            $policy->artifact_mode === 'config' && $policy->schedule_type === 'manual' &&
            $association->credential_id === null;
    }

    public function active(Device $device): ?DeviceBackupPolicy
    {
        return $device->deviceBackupPolicies()->with('backupPolicy')->where('is_active', true)->whereNull('archived_at')
            ->orderBy('id')->get()->first(fn ($association) => $this->compatible($association));
    }

    public function ensure(Device $device): DeviceBackupPolicy
    {
        return DB::transaction(function () use ($device) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if (! $locked->is_active || ! $locked->isHuaweiFtpEligible()) {
                throw new \InvalidArgumentException('Equipamento incompatível com backup Huawei FTP.');
            }
            if ($active = $this->active($locked)) {
                return $active;
            }
            if (DeviceBackupPolicy::hasActiveMethod($locked->id, 'ftp_push')) {
                throw ValidationException::withMessages([
                    'device_id' => 'Este equipamento já possui uma política FTP ativa incompatível. Desative o vínculo antigo antes de preparar outra política FTP.',
                ]);
            }

            // A device lock serializes account creation and explicit preparation.
            $inactive = $locked->deviceBackupPolicies()->with('backupPolicy')->where('is_active', false)->whereNull('archived_at')
                ->orderBy('id')->get()->first(fn ($association) => $this->compatible($association));
            if ($inactive) {
                $inactive->is_active = true;
                $inactive->save();

                return $inactive;
            }
            $policy = BackupPolicy::query()->where('is_active', true)->whereNull('archived_at')->where('method', 'ftp_push')
                ->where('artifact_mode', 'config')->where('schedule_type', 'manual')
                ->whereDoesntHave('deviceBackupPolicies', fn ($query) => $query->where('device_id', $locked->id))
                ->orderBy('id')->first();
            if (! $policy) {
                $policy = BackupPolicy::create(['name' => 'FTP Push Manual', 'method' => 'ftp_push',
                    'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
            }

            return DeviceBackupPolicy::create(['device_id' => $locked->id, 'backup_policy_id' => $policy->id,
                'credential_id' => null, 'is_active' => true]);
        });
    }
}
