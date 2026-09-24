<?php

namespace App\Services;

use App\Models\Device;
use App\Models\FtpAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FtpAccountManager
{
    public function create(Device $device, array $input, int $actorId): array
    {
        $values = $this->credentials($input, $device);
        return DB::transaction(function () use ($device, $values, $actorId) {
            Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device->ftpAccount()->exists()) {
                throw ValidationException::withMessages(['device_id' => 'Este equipamento já possui uma conta FTP.']);
            }
            $secret = $values['password_mode'] === 'automatic' ? $this->generatedPassword() : $values['password'];
            $account = new FtpAccount(['device_id' => $device->id, 'username' => $values['username'], 'is_active' => true]);
            $account->secret = $secret;
            $account->credential_changed_at = now();
            $account->save();
            $this->audit($account, $actorId, 'create');
            return [$account, $secret];
        });
    }

    public function rotate(FtpAccount $account, array $input, int $actorId): string
    {
        $values = validator($input, [
            'mode' => ['required', Rule::in(['automatic', 'manual'])],
            'password' => ['required_if:mode,manual', 'nullable', 'string', 'min:12', 'max:40', 'regex:/\A[\x21-\x7e]+\z/D', 'confirmed'],
        ])->validate();
        $secret = $values['mode'] === 'automatic' ? $this->generatedPassword() : $values['password'];
        DB::transaction(function () use ($account, $secret, $actorId) {
            $locked = FtpAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked->secret = $secret;
            $locked->provisioned_at = null;
            $locked->sync_error = null;
            $locked->credential_changed_at = now();
            $locked->save();
            $this->audit($locked, $actorId, 'rotate');
        });
        return $secret;
    }

    public function setActive(FtpAccount $account, bool $active, int $actorId): void
    {
        DB::transaction(function () use ($account, $active, $actorId) {
            $locked = FtpAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->is_active === $active) return;
            $locked->is_active = $active;
            $locked->provisioned_at = null;
            $locked->sync_error = null;
            $locked->save();
            $this->audit($locked, $actorId, $active ? 'enable' : 'disable');
        });
    }

    public function generatedPassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function credentials(array $input, Device $device): array
    {
        if (($input['mode'] ?? null) === 'automatic') $input['username'] = 'bmdev'.$device->id;
        $input['password_mode'] = $input['password_mode'] ?? $input['mode'] ?? null;
        return validator($input, [
            'mode' => ['required', Rule::in(['automatic', 'manual'])],
            'password_mode' => ['required', Rule::in(['automatic', 'manual'])],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/\A[a-z][a-z0-9_-]*\z/D', Rule::unique('ftp_accounts', 'username')],
            'password' => ['required_if:password_mode,manual', 'nullable', 'string', 'min:12', 'max:40', 'regex:/\A[\x21-\x7e]+\z/D', 'confirmed'],
        ])->validate();
    }

    private function audit(FtpAccount $account, int $actorId, string $action): void
    {
        DB::table('ftp_account_audits')->insert([
            'ftp_account_id' => $account->id, 'device_id' => $account->device_id,
            'user_id' => $actorId, 'action' => $action, 'username' => $account->username,
            'created_at' => now(),
        ]);
    }
}
