<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\FtpAccount;
use App\Services\FtpAccountManager;
use App\Services\FtpServerSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class FtpAdminController extends Controller
{
    public function index(): Response
    {
        $this->admin();
        $accounts = FtpAccount::query()->with('device:id,name,management_ip')
            ->orderByDesc('id')->get();
        $receipts = DB::table('backup_executions')->select('ftp_account_id')
            ->selectRaw('MAX(received_at) AS last_received_at')
            ->whereNotNull('ftp_account_id')->whereNotNull('received_at')
            ->groupBy('ftp_account_id')->pluck('last_received_at', 'ftp_account_id');
        $devices = Device::query()->whereDoesntHave('ftpAccount')->orderBy('name')->get(['id', 'name', 'management_ip']);
        $server = app(FtpServerSettings::class)->get();
        return response()->view('ftp.index', compact('accounts', 'devices', 'receipts', 'server'));
    }

    public function show(FtpAccount $ftpAccount): Response
    {
        $this->admin();
        $ftpAccount->load('device');
        $lastReceipt = DB::table('backup_executions')->where('ftp_account_id', $ftpAccount->id)
            ->whereNotNull('received_at')->orderByDesc('received_at')->first(['id', 'received_at', 'received_filename']);
        return response()->view('ftp.show', compact('ftpAccount', 'lastReceipt'));
    }

    public function store(Request $request, FtpAccountManager $manager): Response
    {
        $this->admin();
        $data = $request->validate(['device_id' => ['required', 'integer', 'exists:devices,id']]);
        [$account, $secret] = $manager->create(Device::findOrFail($data['device_id']), $request->all(), $request->user()->id);
        return $this->once($account, $secret, 'Conta criada. A sincronização com o PureDB ocorrerá em seguida.', false);
    }

    public function rotate(Request $request, FtpAccount $ftpAccount, FtpAccountManager $manager): Response
    {
        $this->admin();
        $secret = $manager->rotate($ftpAccount, $request->all(), $request->user()->id);
        return $this->once($ftpAccount, $secret, 'Credencial alterada. Atualize a senha no equipamento e aguarde um novo recebimento.', true);
    }

    public function status(Request $request, FtpAccount $ftpAccount, FtpAccountManager $manager): \Illuminate\Http\RedirectResponse
    {
        $this->admin();
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

    private function admin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }
}
