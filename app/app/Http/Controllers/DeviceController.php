<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        $devices = Device::query()
            ->with('site')
            ->orderBy('name')
            ->paginate(20);

        return view('devices.index', compact('devices'));
    }

    public function create(): View
    {
        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('devices.create', compact('sites'));
    }

    public function store(Request $request): RedirectResponse
    {
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
            'vendor' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::in(['network', 'olt'])],
            'model' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! Schema::hasColumn('devices', 'platform')) {
            if ($validated['platform'] !== 'network') throw ValidationException::withMessages(['platform' => 'Atualização do banco pendente.']);
            unset($validated['platform']);
        }
        Device::create($validated);

        return redirect()
            ->route('devices.index')
            ->with('success', 'Equipamento cadastrado com sucesso.');
    }

    public function edit(Device $device): View
    {
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
            'vendor' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::in(['network', 'olt'])],
            'model' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! Schema::hasColumn('devices', 'platform')) {
            if ($validated['platform'] !== 'network') throw ValidationException::withMessages(['platform' => 'Atualização do banco pendente.']);
            unset($validated['platform']);
        }
        DB::transaction(function () use ($device, $validated) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
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
        return redirect()->route('devices.edit', $device)->with('success', 'Chave SSH confiada.');
    }

    public function destroy(Device $device): RedirectResponse
    {
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
            'hostname' => trim((string) $request->input('hostname')) ?: null,
            'management_ip' => trim((string) $request->input('management_ip')),
            'vendor' => trim((string) $request->input('vendor')),
            'platform' => $request->input('platform', 'network'),
            'model' => trim((string) $request->input('model')) ?: null,
            'os_version' => trim((string) $request->input('os_version')) ?: null,
            'notes' => trim((string) $request->input('notes')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);
    }
}
