<?php

namespace App\Http\Controllers;

use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\FtpAccount;
use App\Services\AuditEvents;
use App\Services\FtpAccountDeletionService;
use App\Services\FtpAccountManager;
use App\Services\FtpServerSettings;
use App\Services\HuaweiFtpBackupPolicy;
use App\Services\MikrotikFtpIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FtpAdminController extends Controller
{
    public function index(): Response
    {
        $this->authorize('ftp.view');
        $accounts = FtpAccount::query()->with('device:id,name,management_ip')
            ->orderByDesc('id')->get();
        $ftpCoreReady = Schema::hasTable('ftp_received_files') && Schema::hasColumn('ftp_accounts', 'account_uuid');
        $receipts = $ftpCoreReady
            ? DB::table('ftp_received_files')->select('ftp_account_id')
                ->selectRaw('MAX(received_at) AS last_received_at')
                ->groupBy('ftp_account_id')->pluck('last_received_at', 'ftp_account_id')
            : DB::table('backup_executions')->select('ftp_account_id')
                ->selectRaw('MAX(received_at) AS last_received_at')
                ->whereNotNull('ftp_account_id')->whereNotNull('received_at')
                ->groupBy('ftp_account_id')->pluck('last_received_at', 'ftp_account_id');
        $devices = Device::query()->whereDoesntHave('ftpAccount')->orderBy('name')->get(['id', 'name', 'management_ip']);
        $server = app(FtpServerSettings::class)->get();

        return response()->view('ftp.index', compact('accounts', 'devices', 'receipts', 'server', 'ftpCoreReady'));
    }

    public function show(Request $request, FtpAccount $ftpAccount, FtpAccountDeletionService $deletion,
        MikrotikFtpIntegration $mikrotik): Response
    {
        $this->authorize('ftp.view');
        $ftpAccount->load('device');
        $huaweiPolicy = app(HuaweiFtpBackupPolicy::class);
        $isFtpBackup = ($ftpAccount->purpose ?? 'backup') === 'backup' && (bool) $ftpAccount->device?->isBackupFtpEligible();
        $policyReady = $isFtpBackup && $huaweiPolicy->active($ftpAccount->device) !== null;
        $accountReady = $isFtpBackup && $ftpAccount->is_active && ! $ftpAccount->deletion_mode && $ftpAccount->device->is_active;
        $pureDbReady = $accountReady && $ftpAccount->provisioned_at !== null && $ftpAccount->sync_error === null;
        $history = Schema::hasTable('ftp_received_files')
            ? DB::table('ftp_received_files')->where('ftp_account_id', $ftpAccount->id)
                ->orderByDesc('received_at')->limit(10)->get()
            : DB::table('backup_executions')->where('ftp_account_id', $ftpAccount->id)
                ->whereNotNull('received_at')->orderByDesc('received_at')->limit(10)
                ->select(['received_at', 'received_filename as original_filename'])
                ->selectRaw("'stored' AS status, 0 AS size_bytes, NULL AS sha256, NULL AS relative_path, NULL AS error_code")
                ->get();
        $lastReceipt = $history->first();
        $deletionPreview = $request->boolean('deletion_preview') || (bool) $ftpAccount->deletion_mode ||
            session('errors')?->has('mode') || session('errors')?->has('confirmation');
        if ($deletionPreview && $request->user()->can('ftp.delete')) {
            $deletion->requestInspection($ftpAccount);
        }
        $impact = $deletion->preview($ftpAccount);
        $confirmationPhrases = $deletion->confirmationPhrases($ftpAccount);
        $isMikrotik = $mikrotik->isMikrotik($ftpAccount);
        $mikrotikState = $isMikrotik ? $mikrotik->summary($ftpAccount) : null;

        return response()->view('ftp.show', compact('ftpAccount', 'lastReceipt', 'history', 'impact', 'confirmationPhrases',
            'isFtpBackup', 'policyReady', 'accountReady', 'pureDbReady', 'deletionPreview', 'isMikrotik', 'mikrotikState'));
    }

    public function integrateMikrotik(Request $request, FtpAccount $ftpAccount, MikrotikFtpIntegration $integration): JsonResponse
    {
        $this->authorize('ftp.manage');
        abort_unless($integration->isMikrotik($ftpAccount->load('device')), 404);
        $validated = $request->validate([
            'schedule_time' => ['required', 'date_format:H:i'],
        ]);

        return response()->json($integration->start($ftpAccount, $validated['schedule_time'], $request->user()->id, $request->ip()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function mikrotikStatus(Request $request, FtpAccount $ftpAccount, MikrotikFtpIntegration $integration): JsonResponse
    {
        $this->authorize('ftp.manage');
        abort_unless($integration->isMikrotik($ftpAccount->load('device')), 404);

        return response()->json($integration->status($ftpAccount, $request->user()->id, $request->ip()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $request, FtpAccount $ftpAccount, HuaweiFtpBackupPolicy $policy, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('ftp.manage');
        DB::transaction(function () use ($request, $ftpAccount, $policy, $audit) {
            $account = FtpAccount::query()->lockForUpdate()->findOrFail($ftpAccount->id);
            if ($account->purpose !== 'backup' || ! $account->device_id || $account->deletion_mode) {
                throw ValidationException::withMessages(['account' => 'A conta deve ser de backup, ter equipamento e não estar em exclusão.']);
            }
            $device = Device::query()->lockForUpdate()->findOrFail($account->device_id);
            if (! $device->is_active || ! $device->isHuaweiFtpEligible()) {
                throw ValidationException::withMessages(['account' => 'O equipamento deve ser compatível com FTP push (Huawei OLT/rede ou VSOL OLT) e ativo.']);
            }

            $existingAssociationIds = $device->deviceBackupPolicies()->pluck('id')->all();
            $existingPolicyIds = BackupPolicy::query()->pluck('id')->all();
            $association = $policy->ensure($device);
            if (Schema::hasTable('audit_events')) {
                $audit->record('ftp.backup_policy.prepared', 'ftp_account', (string) $account->id,
                    $account->username, 'success', [
                        'account_id' => $account->id,
                        'device_id' => $device->id,
                        'policy_id' => $association->backup_policy_id,
                        'association_id' => $association->id,
                        'reused_policy' => in_array($association->backup_policy_id, $existingPolicyIds, true),
                        'reused_association' => in_array($association->id, $existingAssociationIds, true),
                        'result' => 'success',
                    ], $request->user()->id, $request->ip());
            }
        });

        return redirect()->route('ftp.show', $ftpAccount)->with('status', 'Backup FTP preparado com sucesso.');
    }

    public function delete(Request $request, FtpAccount $ftpAccount, FtpAccountDeletionService $deletion): RedirectResponse
    {
        $this->authorize('ftp.delete');
        abort_unless(Schema::hasTable('audit_events'), 503, 'A exclusão aguarda a migration FTP-CORE-2.');
        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(FtpAccountDeletionService::CONFIRMATION_PREFIXES))],
            'confirmation' => ['required', 'string'],
        ], [
            'mode.required' => 'Selecione um modo de exclusão.',
            'mode.in' => 'Selecione um modo de exclusão válido.',
            'confirmation.required' => 'Informe a frase de confirmação.',
        ]);
        $deletion->request($ftpAccount, $data['mode'], $data['confirmation'], $request->user()->id, $request->ip());

        return redirect()->route('ftp.index')
            ->with('status', 'Exclusão solicitada. A verificação e a revogação da conta FTP continuarão em segundo plano.')
            ->with('ftp_deletion_requested', true);
    }

    public function store(Request $request, FtpAccountManager $manager): Response
    {
        $this->authorize('ftp.manage');
        abort_unless(Schema::hasTable('ftp_received_files') && Schema::hasColumn('ftp_accounts', 'account_uuid'), 503,
            'A criação de contas FTP aguarda a migration FTP-CORE-1.');
        $request->merge(['purpose' => $request->input('purpose', 'backup')]);
        $data = $request->validate([
            'purpose' => ['required', 'in:backup,file_server'],
            'device_id' => ['required_if:purpose,backup', 'nullable', 'integer', 'exists:devices,id'],
        ]);
        $purpose = $data['purpose'] ?? 'backup';
        if ($purpose === 'file_server' && ! empty($data['device_id'])) {
            throw ValidationException::withMessages(['device_id' => 'Servidor de arquivos não usa equipamento nesta fase.']);
        }
        $device = $purpose === 'backup' ? Device::findOrFail($data['device_id']) : null;
        [$account, $secret] = $manager->create($device, $request->all(), $request->user()->id);

        return $this->once($account, $secret, 'Conta criada. A sincronização com o PureDB ocorrerá em seguida.', false);
    }

    public function rotate(Request $request, FtpAccount $ftpAccount, FtpAccountManager $manager): Response
    {
        $this->authorize('ftp.manage');
        $secret = $manager->rotate($ftpAccount, $request->all(), $request->user()->id);

        return $this->once($ftpAccount, $secret, 'Credencial alterada. Aguarde a sincronização com o PureDB.', true);
    }

    public function revealSecret(Request $request, FtpAccount $ftpAccount, AuditEvents $audit): JsonResponse
    {
        $this->authorize('ftp.manage');
        abort_if($ftpAccount->deletion_mode || ! $ftpAccount->secret, 404);

        $audit->record('ftp.account.secret_revealed', 'ftp_account', (string) $ftpAccount->id,
            $ftpAccount->username, 'success', [], $request->user()->id, $request->ip());

        return response()->json(['username' => $ftpAccount->username, 'secret' => $ftpAccount->secret])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function status(Request $request, FtpAccount $ftpAccount, FtpAccountManager $manager): RedirectResponse
    {
        $this->authorize('ftp.manage');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $manager->setActive($ftpAccount, (bool) $data['is_active'], $request->user()->id);

        return redirect()->route('ftp.show', $ftpAccount);
    }

    private function once(FtpAccount $account, string $secret, string $message, bool $isRotation): Response
    {
        $device = $account->device;
        $policyMissing = $account->purpose === 'backup' && $device?->isBackupFtpEligible() &&
            app(HuaweiFtpBackupPolicy::class)->active($device) === null;

        return response()->view('ftp.once', compact('account', 'secret', 'message', 'isRotation', 'policyMissing'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
