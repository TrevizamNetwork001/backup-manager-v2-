<?php

namespace App\Http\Controllers;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BackupPolicyController extends Controller
{
    public function index(): View
    {
        $this->authorize('backup_policies.view');
        $policies = BackupPolicy::query()
            ->whereNull('archived_at')
            ->withCount(['deviceBackupPolicies' => fn ($query) => $query->whereNull('archived_at')])
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
        abort_if($backupPolicy->archived_at !== null, 404);
        $backupPolicy->load(['deviceBackupPolicies' => fn ($query) => $query->whereNull('archived_at')
            ->with(['device:id,name,is_active,platform', 'credential:id,name,type,username,is_active'])
            ->withCount('backupExecutions')
            ->orderBy('id')]);

        return view('backup-policies.edit', compact('backupPolicy'));
    }

    public function update(Request $request, BackupPolicy $backupPolicy): RedirectResponse
    {
        $this->authorize('backup_policies.manage');
        abort_if($backupPolicy->archived_at !== null, 404);
        $validated = $this->validated($request);
        $newType = in_array($validated['method'], ['ssh_pull', 'a10_system'], true) ? 'ssh' : 'none';

        if ($validated['method'] !== 'a10_system' && $backupPolicy->method === 'a10_system' &&
            BackupArtifact::query()->where('backup_policy_id', $backupPolicy->id)->where('type', 'binary')->exists()) {
            throw ValidationException::withMessages(['method' => 'Esta política possui backups binários A10 no histórico e não pode mudar de método.']);
        }
        if ($validated['method'] !== 'a10_system' && $backupPolicy->method === 'a10_system' &&
            $backupPolicy->deviceBackupPolicies()->exists()) {
            throw ValidationException::withMessages(['method' => 'Remova os vínculos A10 antes de alterar o método da política.']);
        }

        if ($validated['method'] !== $backupPolicy->method && $backupPolicy->deviceBackupPolicies()
            ->where(function ($query) use ($newType) {
                if ($newType === 'none') {
                    $query->whereNotNull('credential_id');
                } else {
                    $query->whereNull('credential_id')->orWhereHas('credential', fn ($q) => $q->where('type', '!=', 'ssh'));
                }
            })
            ->exists()) {
            throw ValidationException::withMessages([
                'method' => 'O método não pode ser alterado nesta política porque execuções anteriores preservam essa configuração. Crie a associação FTP pelo assistente do equipamento e desative a associação SSH antiga.',
            ]);
        }
        if ($validated['method'] !== $backupPolicy->method && $validated['method'] === 'ftp_push' && $backupPolicy->deviceBackupPolicies()->exists()) {
            $incompatible = $backupPolicy->deviceBackupPolicies()->whereHas('device', fn ($query) => $query
                ->whereNotIn('platform', ['olt', 'network'])->orWhereRaw('LOWER(TRIM(vendor)) != ?', ['huawei']))->exists();
            if ($incompatible) {
                throw ValidationException::withMessages(['method' => 'FTP Push requer um equipamento compatível (Huawei OLT/rede ou VSOL OLT).']);
            }
        }
        if ($validated['method'] === 'a10_system' && $backupPolicy->deviceBackupPolicies()->whereHas('device', fn ($query) => $query
            ->where('platform', '!=', 'network')->orWhereRaw('LOWER(TRIM(vendor)) != ?', ['a10 networks']))->exists()) {
            throw ValidationException::withMessages(['method' => 'Backup A10 requer somente equipamentos A10 de rede.']);
        }

        DB::transaction(function () use ($backupPolicy, $validated): void {
            $associations = $backupPolicy->deviceBackupPolicies()->where('is_active', true)->get(['id', 'device_id']);
            Device::query()->whereIn('id', $associations->pluck('device_id'))->orderBy('id')->lockForUpdate()->get(['id']);
            if ($validated['is_active'] && ($validated['method'] !== $backupPolicy->method || ! $backupPolicy->is_active)) {
                foreach ($associations as $association) {
                    if (DeviceBackupPolicy::hasActiveMethod($association->device_id, $validated['method'], $association->id)) {
                        throw ValidationException::withMessages([
                            'method' => 'A alteração criaria duas políticas ativas do mesmo método em um equipamento. Desative o vínculo conflitante primeiro.',
                        ]);
                    }
                }
            }
            $backupPolicy->update($validated);
        });

        $page = $request->integer('return_page');

        return redirect()->route('backup-policies.index', $page > 1 ? ['page' => $page] : [])
            ->with('success', 'Política atualizada com sucesso.');
    }

    public function destroy(BackupPolicy $backupPolicy): RedirectResponse
    {
        $this->authorize('backup_policies.delete');
        abort_if($backupPolicy->archived_at !== null, 404);
        $result = DB::transaction(function () use ($backupPolicy): string {
            $policy = BackupPolicy::query()->lockForUpdate()->findOrFail($backupPolicy->id);
            $links = $policy->deviceBackupPolicies()->whereNull('archived_at');
            if ((clone $links)->where('is_active', true)->exists()) {
                return 'active';
            }
            if ((clone $links)->whereHas('backupExecutions', fn ($query) => $query->whereIn('status', BackupExecution::LIVE_STATUSES))->exists()) {
                return 'busy';
            }
            if ($policy->deviceBackupPolicies()->exists()) {
                $policy->deviceBackupPolicies()->whereNull('archived_at')
                    ->update(['is_active' => false, 'archived_at' => now()]);
                $policy->update(['is_active' => false, 'archived_at' => now()]);

                return 'archived';
            }
            $policy->delete();

            return 'deleted';
        });
        if ($result === 'active') {
            return redirect()->route('backup-policies.index')
                ->with('warning', 'Desative os vínculos ativos desta política antes de removê-la.');
        }
        if ($result === 'busy') {
            return redirect()->route('backup-policies.index')
                ->with('warning', 'Aguarde as execuções em andamento terminarem antes de remover esta política.');
        }

        return redirect()->route('backup-policies.index')
            ->with('success', $result === 'archived' ? 'Política removida do catálogo; histórico preservado.' : 'Política removida com sucesso.');
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
            throw ValidationException::withMessages(['schedule_type' => 'Nesta fase, FTP Push não usa o agendador do Backup Manager; configure o intervalo no equipamento.']);
        }
        if ($validated['method'] === 'a10_system' && ((! config('backup.a10_enabled') && $validated['is_active']) || $validated['artifact_mode'] !== 'binary')) {
            throw ValidationException::withMessages(['method' => 'Backup A10 requer recepção habilitada e artefato binário.']);
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
