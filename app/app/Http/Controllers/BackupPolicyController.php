<?php

namespace App\Http\Controllers;

use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BackupPolicyController extends Controller
{
    public function index(): View
    {
        $this->authorize('backup_policies.view');
        $policies = BackupPolicy::query()
            ->withCount('deviceBackupPolicies')
            ->orderBy('name')
            ->paginate(20);

        return view('backup-policies.index', compact('policies'));
    }

    public function create(): View
    {
        $this->authorize('backup_policies.manage');

        return view('backup-policies.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        $policy = BackupPolicy::create($this->validated($request));

        return redirect()->route('backup-policies.edit', $policy)
            ->with('success', 'Política cadastrada com sucesso.');
    }

    public function edit(BackupPolicy $backupPolicy): View
    {
        $this->authorize('backup_policies.manage');
        $backupPolicy->load(['deviceBackupPolicies' => fn ($query) => $query
            ->with(['device:id,name,is_active', 'credential:id,name,type,username,is_active'])
            ->orderBy('id')]);

        $devices = Device::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $credentials = Credential::query()
            ->where('type', $backupPolicy->credentialType())
            ->where('is_active', true)
            ->whereHas('device', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'device_id', 'name', 'type', 'username']);

        return view('backup-policies.edit', compact('backupPolicy', 'devices', 'credentials'));
    }

    public function update(Request $request, BackupPolicy $backupPolicy): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        $validated = $this->validated($request);
        $newType = $validated['method'] === 'ssh_pull' ? 'ssh' : 'none';

        if ($backupPolicy->deviceBackupPolicies()
            ->where(function ($query) use ($newType) {
                if ($newType === 'none') $query->whereNotNull('credential_id');
                else $query->whereNull('credential_id')->orWhereHas('credential', fn ($q) => $q->where('type', '!=', 'ssh'));
            })
            ->exists()) {
            throw ValidationException::withMessages([
                'method' => 'Remova as associações com credenciais incompatíveis antes de alterar o método.',
            ]);
        }
        if ($validated['method'] === 'ftp_push' && $backupPolicy->deviceBackupPolicies()->exists()) {
            $incompatible = $backupPolicy->deviceBackupPolicies()->whereHas('device', fn ($query) => $query
                ->whereNotIn('platform', ['olt', 'network'])->orWhereRaw('LOWER(TRIM(vendor)) != ?', ['huawei']))->exists();
            if ($incompatible) throw ValidationException::withMessages(['method' => 'FTP Push requer um equipamento compatível (Huawei OLT/rede ou VSOL OLT).']);
        }

        $backupPolicy->update($validated);

        return redirect()->route('backup-policies.index')
            ->with('success', 'Política atualizada com sucesso.');
    }

    public function destroy(BackupPolicy $backupPolicy): RedirectResponse
    {
        $this->authorize('backup_policies.delete');
        if ($backupPolicy->deviceBackupPolicies()->exists()) {
            return redirect()->route('backup-policies.index')
                ->with('warning', 'Remova as associações antes de remover esta política.');
        }

        $backupPolicy->delete();

        return redirect()->route('backup-policies.index')
            ->with('success', 'Política removida com sucesso.');
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'notes' => trim((string) $request->input('notes')) ?: null,
            'schedule_time' => $request->input('schedule_time') ?: null,
            'schedule_weekday' => $request->input('schedule_weekday') ?: null,
            'retention_days' => $request->input('retention_days') ?: null,
            'retention_count' => $request->input('retention_count') ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'method' => ['required', Rule::in(BackupPolicy::METHODS)],
            'artifact_mode' => ['required', Rule::in(BackupPolicy::ARTIFACT_MODES)],
            'schedule_type' => ['required', Rule::in(BackupPolicy::SCHEDULE_TYPES)],
            'schedule_time' => ['nullable', 'required_if:schedule_type,daily,weekly', 'date_format:H:i'],
            'schedule_weekday' => ['nullable', 'required_if:schedule_type,weekly', 'integer', 'between:1,7'],
            'retention_days' => ['nullable', 'integer', 'min:1'],
            'retention_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validated['retention_days'] === null && $validated['retention_count'] === null) {
            throw ValidationException::withMessages([
                'retention_days' => 'Informe dias ou quantidade para retenção.',
            ]);
        }
        if ($validated['method'] === 'ftp_push' && $validated['artifact_mode'] !== 'config') {
            throw ValidationException::withMessages(['artifact_mode' => 'FTP Push suporta apenas configuração nesta fase.']);
        }
        if ($validated['method'] === 'ftp_push' && $validated['schedule_type'] !== 'manual') {
            throw ValidationException::withMessages(['schedule_type' => 'Nesta fase, Huawei OLT via FTP Push suporta somente execução manual.']);
        }

        if ($validated['schedule_type'] !== 'weekly') {
            $validated['schedule_weekday'] = null;
        }
        if ($validated['schedule_type'] === 'manual') {
            $validated['schedule_time'] = null;
        }

        return $validated;
    }
}
