<?php

namespace App\Http\Controllers;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Site;
use App\Services\DeviceBackupHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(DeviceBackupHealth $backupHealth): View
    {
        $this->authorize('devices.view');
        $devices = Device::query()
            ->with(['site', 'ftpAccount', 'deviceBackupPolicies' => fn ($query) => $query
                ->with(['backupPolicy:id,name,method,is_active', 'credential:id,name,type,is_active'])
                ->withCount('backupExecutions')])
            ->orderBy('name')
            ->paginate(20);
        $healthByDevice = collect($backupHealth->rows())
            ->whereIn('device_id', $devices->pluck('id')->all())
            ->keyBy('device_id');
        $sites = Site::query()->where('is_active', true)->orderBy('name')->get();

        $policies = BackupPolicy::query()->where('is_active', true)->whereNull('archived_at')->orderBy('name')->get();
        $sshCredentialsByDevice = Credential::query()
            ->whereIn('device_id', $devices->pluck('id'))
            ->where('type', 'ssh')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'device_id', 'name', 'username'])
            ->groupBy('device_id');

        return view('devices.index', compact('devices', 'healthByDevice', 'sites', 'policies', 'sshCredentialsByDevice'));
    }

    public function create(): View
    {
        $this->authorize('devices.manage');
        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('devices.create', compact('sites'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('devices.manage');
        $this->normalize($request);

        $validated = $request->validate([
            'site_id' => [
                'required',
                Rule::exists('sites', 'id')->where(
                    fn ($query) => $query->where('is_active', true)
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'management_ip' => ['required', 'ip', 'max:45', 'unique:devices,management_ip'],
            'vendor' => ['required', 'string', 'max:100', Rule::in(Device::VENDORS)],
            'platform' => ['required', Rule::in(['network', 'olt'])],
            'device_kind' => ['nullable', Rule::in(['router', 'switch', 'firewall', 'olt', 'network'])],
            'device_function' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! Schema::hasColumn('devices', 'platform')) {
            if ($validated['platform'] !== 'network') {
                throw ValidationException::withMessages(['platform' => 'Atualização do banco pendente.']);
            }
            unset($validated['platform']);
        }
        Device::create($validated);

        return redirect()
            ->route('devices.index')
            ->with('success', 'Equipamento cadastrado com sucesso.');
    }

    public function edit(Device $device): View
    {
        $this->authorize('devices.manage');
        $sites = Site::query()
            ->where(function ($query) use ($device) {
                $query
                    ->where('is_active', true)
                    ->orWhere('id', $device->site_id);
            })
            ->orderBy('name')
            ->get();

        return view('devices.edit', compact('device', 'sites'));
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->authorize('devices.manage');
        $this->normalize($request);

        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'management_ip' => [
                'required',
                'ip',
                'max:45',
                Rule::unique('devices', 'management_ip')->ignore($device->id),
            ],
            'vendor' => ['required', 'string', 'max:100', Rule::in(Device::vendorOptions($device->vendor))],
            'platform' => ['required', Rule::in(['network', 'olt'])],
            'device_kind' => ['nullable', Rule::in(['router', 'switch', 'firewall', 'olt', 'network'])],
            'device_function' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! Schema::hasColumn('devices', 'platform')) {
            if ($validated['platform'] !== 'network') {
                throw ValidationException::withMessages(['platform' => 'Atualização do banco pendente.']);
            }
            unset($validated['platform']);
        }
        DB::transaction(function () use ($device, $validated) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $classificationChanged = $locked->vendor !== $validated['vendor'] ||
                ($locked->platform ?? 'network') !== ($validated['platform'] ?? 'network');
            if ($classificationChanged && (
                $locked->deviceBackupPolicies()->exists() ||
                BackupExecution::query()->where('device_id', $locked->id)->exists() ||
                (Schema::hasTable('ftp_accounts') && $locked->ftpAccount()->exists()) ||
                $locked->oltFtpIntegration()->exists()
            )) {
                throw ValidationException::withMessages([
                    'vendor' => 'Fabricante e tipo não podem mudar enquanto houver políticas, execuções ou integração FTP vinculadas. Cadastre o equipamento como um novo registro para preservar o histórico.',
                    'platform' => 'Fabricante e tipo não podem mudar enquanto houver políticas, execuções ou integração FTP vinculadas. Cadastre o equipamento como um novo registro para preservar o histórico.',
                ]);
            }
            if ($locked->management_ip !== $validated['management_ip']) {
                $locked->ssh_observed_algorithm = null;
                $locked->ssh_observed_fingerprint = null;
                $locked->ssh_observed_at = null;
            }
            $locked->fill($validated)->save();
        });

        return redirect()
            ->route('devices.index')
            ->with('success', 'Equipamento atualizado com sucesso.');
    }

    public function trustHostKey(Request $request, Device $device): RedirectResponse
    {
        $this->authorize('devices.manage');
        $returnCredential = null;
        if ($request->input('return_to') === 'credential_test') {
            $validated = $request->validate(['credential_id' => ['required', 'integer', 'exists:credentials,id']]);
            $returnCredential = Credential::query()->where('device_id', $device->id)
                ->where('type', 'ssh')->findOrFail($validated['credential_id']);
        }
        DB::transaction(function () use ($request, $device) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if (! $locked->ssh_observed_algorithm || ! $locked->ssh_observed_fingerprint) {
                throw ValidationException::withMessages(['ssh_host_key' => 'Nenhuma chave SSH observada para confiar.']);
            }
            $locked->ssh_host_key_algorithm = $locked->ssh_observed_algorithm;
            $locked->ssh_host_key_fingerprint = $locked->ssh_observed_fingerprint;
            $locked->ssh_host_key_trusted_at = now();
            $locked->ssh_host_key_trusted_by = $request->user()->id;
            $locked->save();
        });

        if ($request->input('return_to') === 'devices') {
            return redirect()->route('devices.index')->with('success', 'Chave SSH confiada.');
        }
        if ($request->input('return_to') === 'credentials') {
            return redirect()->route('credentials.index')->with('success', 'Chave SSH confiada.');
        }
        if ($returnCredential) {
            return redirect()->route('credentials.edit', ['credential' => $returnCredential, 'ssh_test' => 1])
                ->with('success', 'Chave SSH confiada. Faça agora o teste da conexão SSH.');
        }

        return redirect()->route('devices.edit', $device)->with('success', 'Chave SSH confiada.');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->authorize('devices.delete');
        if ($device->deviceBackupPolicies()->exists()) {
            return redirect()->route('devices.index')
                ->with('warning', 'Remova as políticas associadas antes de remover este equipamento.');
        }

        if ($device->credentials()->exists() || (Schema::hasTable('ftp_accounts') && $device->ftpAccount()->exists())) {
            return redirect()->route('devices.index')
                ->with('warning', 'Remova as credenciais antes de remover este equipamento.');
        }

        $device->delete();

        return redirect()
            ->route('devices.index')
            ->with('success', 'Equipamento removido com sucesso.');
    }

    private function normalize(Request $request): void
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'management_ip' => trim((string) $request->input('management_ip')),
            'vendor' => is_string($request->input('vendor'))
                ? Device::normalizeVendor($request->input('vendor'))
                : $request->input('vendor'),
            'platform' => in_array($request->input('device_kind'), ['router', 'switch', 'firewall', 'olt'], true)
                ? ($request->input('device_kind') === 'olt' ? 'olt' : 'network')
                : $request->input('platform', 'network'),
            'device_function' => is_string($request->input('device_function'))
                ? (trim($request->input('device_function')) ?: null)
                : $request->input('device_function'),
            'model' => trim((string) $request->input('model')) ?: null,
            'os_version' => trim((string) $request->input('os_version')) ?: null,
            'notes' => trim((string) $request->input('notes')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);
        if ($request->exists('hostname')) {
            $request->merge(['hostname' => trim((string) $request->input('hostname')) ?: null]);
        }
    }
}
