<?php

namespace App\Http\Controllers;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceBackupPolicyController extends Controller
{
    public function store(Request $request, BackupPolicy $backupPolicy): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        abort_if($backupPolicy->archived_at !== null, 404);
        if ($backupPolicy->method === 'ftp_push' && $backupPolicy->schedule_type !== 'manual') {
            throw ValidationException::withMessages(['schedule_type' => 'Nesta fase, FTP Push não usa o agendador do Backup Manager; configure o intervalo no equipamento.']);
        }
        $validated = $request->validate([
            'device_id' => ['required', Rule::exists('devices', 'id')->where('is_active', true), Rule::unique('device_backup_policies')->where('backup_policy_id', $backupPolicy->id)],
            'credential_id' => [$backupPolicy->method === 'ftp_push' ? 'nullable' : 'required', Rule::exists('credentials', 'id')->where(function ($query) use ($request, $backupPolicy) {
                $query->where('device_id', $request->input('device_id'))
                    ->where('type', $backupPolicy->credentialType())
                    ->where('is_active', true);
            })],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($backupPolicy->method === 'ftp_push' && ! Schema::hasTable('ftp_accounts')) {
            throw ValidationException::withMessages(['device_id' => 'Atualização do banco pendente.']);
        }
        $device = ($backupPolicy->method === 'ftp_push' ? Device::with('ftpAccount') : Device::query())->findOrFail($validated['device_id']);
        if ($backupPolicy->method === 'ftp_push' &&
            (! $device->isHuaweiFtpEligible() ||
             ! $device->ftpAccount?->is_active || $backupPolicy->artifact_mode !== 'config')) {
            throw ValidationException::withMessages(['device_id' => 'FTP Push requer um equipamento compatível e conta FTP ativa.']);
        }
        if ($backupPolicy->method === 'a10_system' && (! config('backup.a10_enabled') ||
            $backupPolicy->artifact_mode !== 'binary' || $device->platform !== 'network' ||
            ! in_array($device->a10_transfer_interface, ['management', 'data'], true) ||
            mb_strtolower(trim($device->vendor)) !== 'a10 networks')) {
            throw ValidationException::withMessages(['device_id' => 'Backup A10 requer equipamento A10 de rede, recepção habilitada e política binária.']);
        }
        // VSOL OLT is the one exception: unlike Huawei OLT (never SSH-managed),
        // this vendor's OLT genuinely accepts an SSH-pulled `show
        // running-config`, so it's allowed either method — see
        // Device::isHuaweiFtpEligible() / docs/CORE_STATUS.md.
        if ($backupPolicy->method === 'ssh_pull' && $device->platform === 'olt'
            && mb_strtolower(trim($device->vendor)) !== 'vsol') {
            throw ValidationException::withMessages(['device_id' => 'OLT requer política FTP Push.']);
        }

        if ($backupPolicy->method === 'ftp_push' && ! empty($validated['credential_id'])) {
            throw ValidationException::withMessages(['credential_id' => 'FTP Push não usa credencial SSH.']);
        }
        $validated['credential_id'] = $backupPolicy->method === 'ftp_push' ? null : $validated['credential_id'];
        DB::transaction(function () use ($backupPolicy, $validated): void {
            Device::query()->lockForUpdate()->findOrFail($validated['device_id']);
            $this->assertNoActiveMethodConflict($validated['device_id'], $backupPolicy, (bool) $validated['is_active']);
            $backupPolicy->deviceBackupPolicies()->create($validated);
        });

        return $this->returnAfterChange($request, $backupPolicy, $device->id)
            ->with('success', "Política {$backupPolicy->name} associada ao equipamento {$device->name}.");
    }

    public function update(Request $request, BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        abort_if($backupPolicy->archived_at !== null, 404);
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);
        abort_if($association->archived_at !== null, 404);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        if ($backupPolicy->method === 'ftp_push' && $backupPolicy->schedule_type !== 'manual' && $validated['is_active']) {
            throw ValidationException::withMessages(['schedule_type' => 'Nesta fase, FTP Push não usa o agendador do Backup Manager; configure o intervalo no equipamento.']);
        }
        if ($backupPolicy->method === 'a10_system' && $validated['is_active'] && ! config('backup.a10_enabled')) {
            throw ValidationException::withMessages(['is_active' => 'Recepção do backup A10 não está habilitada.']);
        }
        DB::transaction(function () use ($association, $backupPolicy, $validated): void {
            Device::query()->lockForUpdate()->findOrFail($association->device_id);
            $this->assertNoActiveMethodConflict($association->device_id, $backupPolicy, (bool) $validated['is_active'], $association->id);
            $association->update($validated);
        });

        return $this->returnAfterChange($request, $backupPolicy, $association->device_id, true)
            ->with('success', "Política {$backupPolicy->name} ".($association->is_active ? 'ativada' : 'desativada').' para o equipamento.');
    }

    public function destroy(Request $request, BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);
        abort_if($association->archived_at !== null, 404);
        $result = DB::transaction(function () use ($association): string {
            Device::query()->lockForUpdate()->findOrFail($association->device_id);
            $locked = DeviceBackupPolicy::query()->lockForUpdate()->findOrFail($association->id);
            if ($locked->backupExecutions()->whereIn('status', BackupExecution::LIVE_STATUSES)->exists()) {
                return 'busy';
            }
            if ($locked->backupExecutions()->exists()) {
                $locked->update(['is_active' => false, 'archived_at' => now()]);

                return 'archived';
            }
            $locked->delete();

            return 'deleted';
        });
        if ($result === 'busy') {
            return $this->returnAfterChange($request, $backupPolicy, $association->device_id, true)
                ->with('warning', 'Aguarde a execução em andamento terminar antes de remover esta associação.');
        }

        return $this->returnAfterChange($request, $backupPolicy, $association->device_id, true)
            ->with('success', "Política {$backupPolicy->name} removida do equipamento".($result === 'archived' ? '; histórico preservado.' : '.'));
    }

    private function assertNoActiveMethodConflict(int $deviceId, BackupPolicy $policy, bool $activating, ?int $exceptAssociationId = null): void
    {
        if ($activating && $policy->is_active && DeviceBackupPolicy::hasActiveMethod($deviceId, $policy->method, $exceptAssociationId)) {
            throw ValidationException::withMessages([
                'is_active' => 'Este equipamento já possui uma política ativa para '.match ($policy->method) {
                    'ssh_pull' => 'SSH', 'a10_system' => 'A10 completo', default => 'FTP',
                }.'. Desative o vínculo atual antes de ativar outra política do mesmo método.',
            ]);
        }
    }

    private function returnAfterChange(Request $request, BackupPolicy $backupPolicy, int $deviceId, bool $reopenModal = false): RedirectResponse
    {
        if ($request->input('return_to') === 'devices') {
            $page = $request->integer('page');
            $url = route('devices.index', $page > 1 ? ['page' => $page] : []);

            $redirect = redirect()->to($url.($reopenModal ? '#device-policy-'.$deviceId : ''));

            return $reopenModal ? $redirect->with('policy_device_id', $deviceId) : $redirect;
        }

        return redirect()->route('backup-policies.edit', $backupPolicy);
    }
}
