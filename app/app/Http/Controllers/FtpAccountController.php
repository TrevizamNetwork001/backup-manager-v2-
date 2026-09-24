<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\FtpAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class FtpAccountController extends Controller
{
    public function store(Request $request, Device $device): Response
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        abort_unless(mb_strtolower(trim($device->vendor)) === 'huawei' && $device->platform === 'olt', 422);
        abort_if($device->ftpAccount()->exists(), 409);
        $validated = $this->credentials($request, $device);
        $secret = $validated['mode'] === 'manual' ? $validated['password'] : bin2hex(random_bytes(16));
        $account = new FtpAccount(['device_id' => $device->id, 'username' => $validated['username'], 'is_active' => true]);
        $account->secret = $secret;
        $account->save();
        return $this->oneTime($device, $secret);
    }

    public function update(Request $request, Device $device): \Illuminate\Http\RedirectResponse
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        $account = $device->ftpAccount()->firstOrFail();
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $account->fill($validated);
        $account->provisioned_at = null;
        $account->save();
        return redirect()->route('devices.edit', $device);
    }

    public function rotate(Device $device): Response
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        $account = $device->ftpAccount()->firstOrFail();
        $secret = bin2hex(random_bytes(16));
        $account->secret = $secret;
        $account->provisioned_at = null;
        $account->save();
        $this->resetWizard($device);
        return $this->oneTime($device, $secret);
    }

    public function replace(Request $request, Device $device): Response
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        abort_unless(mb_strtolower(trim($device->vendor)) === 'huawei' && $device->platform === 'olt', 422);
        $account = $device->ftpAccount()->firstOrFail();
        $validated = $this->credentials($request, $device, $account);
        $secret = $validated['mode'] === 'manual' ? $validated['password'] : bin2hex(random_bytes(16));
        $credentialsChanged = $account->username !== $validated['username'] || $account->secret !== $secret;
        $account->username = $validated['username'];
        if ($credentialsChanged) {
            $account->secret = $secret;
        }
        $account->is_active = true;
        $account->provisioned_at = null;
        $account->sync_error = null;
        $account->save();
        if ($credentialsChanged) {
            $this->resetWizard($device);
        }
        return $this->oneTime($device, $secret);
    }

    public function retry(Device $device): \Illuminate\Http\RedirectResponse
    {
        $account = $device->ftpAccount()->firstOrFail();
        DB::table('ftp_accounts')->where('id', $account->id)
            ->update(['provisioned_at' => null, 'sync_error' => null]);
        return redirect()->route('devices.edit', [$device, 'olt_wizard' => 1]);
    }

    private function credentials(Request $request, Device $device, ?FtpAccount $account = null): array
    {
        $request->merge(['username' => $request->input('mode') === 'automatic'
            ? 'bmdev'.$device->id : $request->input('username')]);
        return $request->validate([
            'mode' => ['required', Rule::in(['automatic', 'manual'])],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/\A[a-z][a-z0-9_-]*\z/D', Rule::unique('ftp_accounts', 'username')->ignore($account?->id)],
            'password' => ['required_if:mode,manual', 'nullable', 'string', 'min:12', 'max:40', 'regex:/\A[\x21-\x7e]+\z/D', 'confirmed'],
        ]);
    }

    private function resetWizard(Device $device): void
    {
        if (Schema::hasTable('olt_ftp_integrations')) {
            $device->oltFtpIntegration()->update([
                'olt_confirmed_at' => null,
                'test_execution_id' => null,
                'account_updated_at' => now(),
            ]);
        }
    }

    private function oneTime(Device $device, string $ftpSecret): Response
    {
        $device->refresh();
        $sites = \App\Models\Site::query()->where(fn ($query) => $query
            ->where('is_active', true)->orWhere('id', $device->site_id))->orderBy('name')->get();
        return response()->view('devices.edit', compact('device', 'sites', 'ftpSecret'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
