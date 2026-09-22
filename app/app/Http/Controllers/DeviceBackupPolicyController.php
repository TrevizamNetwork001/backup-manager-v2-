<?php

namespace App\Http\Controllers;

use App\Models\BackupPolicy;
use App\Models\DeviceBackupPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceBackupPolicyController extends Controller
{
    public function store(Request $request, BackupPolicy $backupPolicy): RedirectResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', Rule::exists('devices', 'id')->where('is_active', true), Rule::unique('device_backup_policies')->where('backup_policy_id', $backupPolicy->id)],
            'credential_id' => ['required', Rule::exists('credentials', 'id')->where(function ($query) use ($request, $backupPolicy) {
                $query->where('device_id', $request->input('device_id'))
                    ->where('type', $backupPolicy->credentialType())
                    ->where('is_active', true);
            })],
            'is_active' => ['required', 'boolean'],
        ]);

        $backupPolicy->deviceBackupPolicies()->create($validated);

        return redirect()->route('backup-policies.edit', $backupPolicy)
            ->with('success', 'Equipamento associado à política.');
    }

    public function update(Request $request, BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $association->update($validated);

        return redirect()->route('backup-policies.edit', $backupPolicy)
            ->with('success', 'Associação atualizada.');
    }

    public function destroy(BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);
        $association->delete();

        return redirect()->route('backup-policies.edit', $backupPolicy)
            ->with('success', 'Associação removida.');
    }
}
