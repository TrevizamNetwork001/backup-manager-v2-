<?php

namespace App\Http\Controllers;

use App\Models\Credential;
use App\Models\Device;
use App\Services\AuditEvents;
use App\Services\SshCredentialProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CredentialController extends Controller
{
    public function index(): View
    {
        $this->authorize('credentials.view');
        $credentials = Credential::query()
            ->select(['id', 'device_id', 'name', 'type', 'username', 'port', 'is_active'])
            ->with('device:id,name,management_ip,ssh_host_key_algorithm,ssh_host_key_fingerprint,ssh_observed_algorithm,ssh_observed_fingerprint')
            ->orderBy('name')
            ->paginate(20);
        $devices = Device::query()->orderBy('name')->get(['id', 'name']);

        return view('credentials.index', compact('credentials', 'devices'));
    }

    public function create(): View
    {
        $this->authorize('credentials.manage');
        $devices = Device::query()->orderBy('name')->get(['id', 'name']);

        return view('credentials.create', compact('devices'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('credentials.manage');
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
        $this->authorize('credentials.manage');
        $credentials = Credential::query()
            ->select(['id', 'device_id', 'name', 'type', 'username', 'port', 'is_active'])
            ->with('device:id,name,management_ip,ssh_host_key_algorithm,ssh_host_key_fingerprint,ssh_observed_algorithm,ssh_observed_fingerprint')
            ->orderBy('name')
            ->paginate(20);
        $devices = Device::query()->orderBy('name')->get(['id', 'name']);

        return view('credentials.index', ['credentials' => $credentials, 'devices' => $devices, 'editingCredential' => $credential]);
    }

    public function update(Request $request, Credential $credential): RedirectResponse
    {
        $this->authorize('credentials.manage');
        $this->normalize($request);

        $validated = $request->validate($this->rules(false, $credential));

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

        return redirect()->route('credentials.index', ['page' => $request->query('page')])
            ->with('success', 'Credencial atualizada com sucesso.');
    }

    public function testSsh(Request $request, SshCredentialProbe $probe): JsonResponse
    {
        $this->authorize('credentials.manage');
        $validated = $request->validate([
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'type' => ['required', 'in:ssh'],
            'username' => ['required', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:4096'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
        ]);
        $device = Device::findOrFail($validated['device_id']);
        $credential = isset($validated['credential_id']) ? Credential::findOrFail($validated['credential_id']) : null;
        if (! $device->is_active) {
            return response()->json(['success' => false, 'message' => 'Ative o equipamento antes de testar o SSH.']);
        }
        if ($credential && $credential->device_id !== $device->id) {
            return response()->json(['success' => false, 'message' => 'A credencial não pertence ao equipamento selecionado.'], 422);
        }
        $secret = $validated['secret'] ?? '';
        if ($secret === '' && $credential) {
            $secret = $credential->secret;
        }
        if ($secret === '') {
            return response()->json(['success' => false, 'message' => 'Informe a senha SSH para executar o teste.'], 422);
        }

        $result = $probe->test($device, trim($validated['username']), $secret, (int) ($validated['port'] ?? 22));
        $messages = [
            'SSH_AUTH_FAILED' => 'Usuário ou senha SSH incorretos.',
            'SSH_TIMEOUT' => 'O equipamento não respondeu dentro do tempo limite.',
            'SSH_CONNECTION_REFUSED' => 'O equipamento recusou a conexão SSH.',
            'SSH_CONNECT_FAILED' => 'Não foi possível conectar ao equipamento por SSH.',
            'SSH_NEGOTIATION_FAILED' => 'A negociação SSH falhou.',
            'SSH_HOST_KEY_UNKNOWN' => 'Chave SSH observada. Confira o fingerprint antes de autorizar.',
            'SSH_HOST_KEY_MISMATCH' => 'A chave SSH mudou. Confira o novo fingerprint antes de autorizar.',
            'VSOL_PROMPT_FAILED' => 'A OLT VSOL não apresentou o prompt esperado.',
            'UNSUPPORTED_VENDOR' => 'Este fabricante ainda não possui teste SSH disponível.',
            'UNSUPPORTED_POLICY' => 'Este tipo de equipamento não possui teste SSH disponível.',
            'ENGINE_FAILED' => 'O teste SSH está indisponível neste momento.',
        ];

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] ? 'Conexão e autenticação SSH validadas.' : ($messages[$result['code']] ?? 'O teste SSH não foi concluído.'),
            'latency_ms' => $result['latency_ms'],
            'trust_url' => $credential && $request->user()->can('devices.manage') &&
                in_array($result['code'], ['SSH_HOST_KEY_UNKNOWN', 'SSH_HOST_KEY_MISMATCH'], true)
                ? route('credentials.edit', ['credential' => $credential, 'ssh_security' => $credential->id]) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function revealSecret(Request $request, Credential $credential, AuditEvents $auditEvents): JsonResponse
    {
        $this->authorize('credentials.manage');
        $auditEvents->record('credential.secret_revealed', 'credential', (string) $credential->id,
            $credential->name, 'success', [], $request->user()->id, $request->ip());

        return response()->json(['secret' => $credential->secret])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function destroy(Credential $credential): RedirectResponse
    {
        $this->authorize('credentials.disable');
        if ($credential->deviceBackupPolicies()->exists()) {
            return redirect()->route('credentials.index')
                ->with('warning', 'Remova as associações de políticas antes de remover esta credencial.');
        }

        $credential->delete();

        return redirect()->route('credentials.index')
            ->with('success', 'Credencial removida com sucesso.');
    }

    private function rules(bool $creating, ?Credential $credential = null): array
    {
        return [
            'device_id' => ['required', 'exists:devices,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in($creating ? Credential::SELECTABLE_TYPES : array_unique([...Credential::SELECTABLE_TYPES, $credential?->type ?? 'ssh']))],
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
