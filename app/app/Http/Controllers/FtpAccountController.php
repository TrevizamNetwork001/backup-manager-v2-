<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\FtpAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class FtpAccountController extends Controller
{
    public function store(Device $device): Response
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        abort_unless(mb_strtolower(trim($device->vendor)) === 'huawei' && $device->platform === 'olt', 422);
        abort_if($device->ftpAccount()->exists(), 409);
        $secret = bin2hex(random_bytes(24));
        $account = new FtpAccount(['device_id' => $device->id, 'username' => 'bmdev'.$device->id, 'is_active' => true]);
        $account->secret = $secret;
        $account->save();
        return $this->oneTime($device, $secret);
    }

    public function update(Request $request, Device $device): \Illuminate\Http\RedirectResponse
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        $account = $device->ftpAccount()->firstOrFail();
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $account->update($validated);
        return redirect()->route('devices.edit', $device);
    }

    public function rotate(Device $device): Response
    {
        abort_unless(Schema::hasTable('ftp_accounts'), 503);
        $account = $device->ftpAccount()->firstOrFail();
        $secret = bin2hex(random_bytes(24));
        $account->secret = $secret;
        $account->save();
        return $this->oneTime($device, $secret);
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
