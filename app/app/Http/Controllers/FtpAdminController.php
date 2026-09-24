<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\FtpAccount;
use App\Services\FtpAccountManager;
use App\Services\FtpAccountDeletionService;
use App\Services\FtpServerSettings;
use App\Services\HuaweiFtpBackupPolicy;
use App\Services\AuditEvents;
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

    public function show(Request $request, FtpAccount $ftpAccount, FtpAccountDeletionService $deletion): Response
    {
        $this->authorize('ftp.view');
        $ftpAccount->load('device');
        $huaweiPolicy = app(\App\Services\HuaweiFtpBackupPolicy::class);
        $isHuaweiBackup = ($ftpAccount->purpose ?? 'backup') === 'backup' && $ftpAccount->device?->platform === 'olt' &&
            mb_strtolower(trim($ftpAccount->device->vendor)) === 'huawei';
        $policyReady = $isHuaweiBackup && $huaweiPolicy->active($ftpAccount->device) !== null;
        $accountReady = $isHuaweiBackup && $ftpAccount->is_active && ! $ftpAccount->deletion_mode && $ftpAccount->device->is_active;
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
        if ($deletionPreview) $deletion->requestInspection($ftpAccount);
        $impact = $deletion->preview($ftpAccount);
        $confirmationPhrases = $deletion->confirmationPhrases($ftpAccount);
        return response()->view('ftp.show', compact('ftpAccount', 'lastReceipt', 'history', 'impact', 'confirmationPhrases',
            'isHuaweiBackup', 'policyReady', 'accountReady', 'pureDbReady', 'deletionPreview'));
    }

    public function prepare(Request $request, FtpAccount $ftpAccount, HuaweiFtpBackupPolicy $policy, AuditEvents $audit): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('ftp.manage');
        DB::transaction(function () use ($request, $ftpAccount, $policy, $audit) {
            $account = FtpAccount::query()->lockForUpdate()->findOrFail($ftpAccount->id);
            if ($account->purpose !== 'backup' || ! $account->device_id || $account->deletion_mode) {
                throw ValidationException::withMessages(['account' => 'A conta deve ser de backup, ter equipamento e não estar em exclusão.']);
            }
            $device = Device::query()->lockForUpdate()->findOrFail($account->device_id);
            if (! $device->is_active || $device->platform !== 'olt' || mb_strtolower(trim($device->vendor)) !== 'huawei') {
                throw ValidationException::withMessages(['account' => 'O equipamento deve ser uma OLT Huawei ativa.']);
            }

            $existingAssociationIds = $device->deviceBackupPolicies()->pluck('id')->all();
            $existingPolicyIds = \App\Models\BackupPolicy::query()->pluck('id')->all();
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

    public function delete(Request $request, FtpAccount $ftpAccount, FtpAccountDeletionService $deletion): \Illuminate\Http\RedirectResponse
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
        return redirect()->route('ftp.show', $ftpAccount)->with('status', 'Exclusão solicitada. Aguardando confirmação de revogação pelo PureDB.');
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
            throw \Illuminate\Validation\ValidationException::withMessages(['device_id' => 'Servidor de arquivos não usa equipamento nesta fase.']);
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

    public function status(Request $request, FtpAccount $ftpAccount, FtpAccountManager $manager): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('ftp.manage');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $manager->setActive($ftpAccount, (bool) $data['is_active'], $request->user()->id);
        return redirect()->route('ftp.show', $ftpAccount);
    }

    private function once(FtpAccount $account, string $secret, string $message, bool $isRotation): Response
    {
        return response()->view('ftp.once', compact('account', 'secret', 'message', 'isRotation'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

}
