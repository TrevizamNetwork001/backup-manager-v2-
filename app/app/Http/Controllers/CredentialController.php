<?php

namespace App\Http\Controllers;

use App\Models\Credential;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CredentialController extends Controller
{
    public function index(): View
    {
        $credentials = Credential::query()
            ->select(['id', 'device_id', 'name', 'type', 'username', 'port', 'is_active'])
            ->with('device:id,name')
            ->orderBy('name')
            ->paginate(20);

        return view('credentials.index', compact('credentials'));
    }

    public function create(): View
    {
        $devices = Device::query()->orderBy('name')->get(['id', 'name']);

        return view('credentials.create', compact('devices'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalize($request);

        $validated = $request->validate($this->rules(true));
        $secret = $validated['secret'];
        unset($validated['secret']);

        $credential = new Credential($validated);
        $credential->secret = $secret;
        $credential->save();

        return redirect()->route('credentials.index')
            ->with('success', 'Credencial cadastrada com sucesso.');
    }

    public function edit(Credential $credential): View
    {
        $devices = Device::query()->orderBy('name')->get(['id', 'name']);

        return view('credentials.edit', compact('credential', 'devices'));
    }

    public function update(Request $request, Credential $credential): RedirectResponse
    {
        $this->normalize($request);

        $validated = $request->validate($this->rules(false));

        if ($credential->deviceBackupPolicies()->exists() && (
            (int) $validated['device_id'] !== $credential->device_id ||
            $validated['type'] !== $credential->type
        )) {
            return back()->withErrors([
                'type' => 'Remova as associações de políticas antes de alterar o equipamento ou tipo desta credencial.',
            ])->withInput($request->except('secret'));
        }

        $secret = $validated['secret'] ?? null;
        unset($validated['secret']);

        $credential->fill($validated);

        if ($secret !== null && $secret !== '') {
            $credential->secret = $secret;
        }

        $credential->save();

        return redirect()->route('credentials.index')
            ->with('success', 'Credencial atualizada com sucesso.');
    }

    public function destroy(Credential $credential): RedirectResponse
    {
        if ($credential->deviceBackupPolicies()->exists()) {
            return redirect()->route('credentials.index')
                ->with('warning', 'Remova as associações de políticas antes de remover esta credencial.');
        }

        $credential->delete();

        return redirect()->route('credentials.index')
            ->with('success', 'Credencial removida com sucesso.');
    }

    private function rules(bool $creating): array
    {
        return [
            'device_id' => ['required', 'exists:devices,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Credential::TYPES)],
            'username' => ['required', 'string', 'max:255'],
            'secret' => $creating
                ? ['required', 'string']
                : ['sometimes', 'nullable', 'string'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function normalize(Request $request): void
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'type' => strtolower(trim((string) $request->input('type'))),
            'username' => trim((string) $request->input('username')),
            'port' => $request->input('port') === '' ? null : $request->input('port'),
            'notes' => trim((string) $request->input('notes')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);
    }
}
