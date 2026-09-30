<?php

namespace App\Http\Controllers;

use App\Models\BackupPolicy;
use App\Models\DeviceBackupPolicy;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

class DeviceBackupPolicyController extends Controller
{
    public function store(Request $request, BackupPolicy $backupPolicy): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        if ($backupPolicy->method === 'ftp_push' && $backupPolicy->schedule_type !== 'manual') {
            throw ValidationException::withMessages(['schedule_type' => 'Nesta fase, Huawei OLT via FTP Push suporta somente execução manual.']);
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
        $backupPolicy->deviceBackupPolicies()->create($validated);

        return redirect()->route('backup-policies.edit', $backupPolicy)
            ->with('success', 'Equipamento associado à política.');
    }

    public function update(Request $request, BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        if ($backupPolicy->method === 'ftp_push' && $backupPolicy->schedule_type !== 'manual' && $validated['is_active']) {
            throw ValidationException::withMessages(['schedule_type' => 'Nesta fase, Huawei OLT via FTP Push suporta somente execução manual.']);
        }
        $association->update($validated);

        return redirect()->route('backup-policies.edit', $backupPolicy)
            ->with('success', 'Associação atualizada.');
    }

    public function destroy(BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);
        if ($association->backupExecutions()->exists()) {
            return redirect()->route('backup-policies.edit', $backupPolicy)
                ->with('warning', 'Esta associação possui execuções históricas e não pode ser removida.');
        }
        $association->delete();

        return redirect()->route('backup-policies.edit', $backupPolicy)
            ->with('success', 'Associação removida.');
    }
}
