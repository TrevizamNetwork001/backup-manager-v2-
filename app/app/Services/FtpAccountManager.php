<?php

namespace App\Services;

use App\Models\Device;
use App\Models\FtpAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class FtpAccountManager
{
    public function __construct(private AuditEvents $auditEvents)
    {
    }

    public function create(?Device $device, array $input, int $actorId): array
    {
        $values = $this->credentials($input, $device);
        return DB::transaction(function () use ($device, $values, $actorId) {
            if ($device) Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device && $device->ftpAccount()->exists()) {
                throw ValidationException::withMessages(['device_id' => 'Este equipamento já possui uma conta FTP.']);
            }
            $secret = $values['password'];
            $account = new FtpAccount(['device_id' => $device?->id, 'account_uuid' => (string) Str::uuid(),
                'home_layout' => 'account', 'purpose' => $values['purpose'], 'username' => $values['username'], 'is_active' => true]);
            $account->secret = $secret;
            $account->credential_changed_at = now();
            $account->save();
            if ($device && $values['purpose'] === 'backup' && $device->isHuaweiFtpEligible()) {
                app(HuaweiFtpBackupPolicy::class)->ensure($device);
            }
            $this->audit($account, $actorId, 'create');
            return [$account, $secret];
        });
    }

    public function rotate(FtpAccount $account, array $input, int $actorId): string
    {
        $values = validator($input, [
            'mode' => ['prohibited'],
            'password' => ['required', 'string', 'min:12', 'max:40', 'regex:/\A[\x21-\x7e]+\z/D', 'confirmed'],
        ])->validate();
        $secret = $values['password'];
        DB::transaction(function () use ($account, $secret, $actorId) {
            $locked = FtpAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->deletion_mode) throw ValidationException::withMessages(['account' => 'Conta em exclusão.']);
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
            if ($locked->deletion_mode) throw ValidationException::withMessages(['account' => 'Conta em exclusão.']);
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

    private function credentials(array $input, ?Device $device): array
    {
        $input['purpose'] = $input['purpose'] ?? 'backup';
        $values = validator($input, [
            'purpose' => ['required', Rule::in(['backup', 'file_server'])],
            'mode' => ['prohibited'],
            'password_mode' => ['prohibited'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/\A[a-z][a-z0-9_-]*\z/D', Rule::unique('ftp_accounts', 'username')],
            'password' => ['required', 'string', 'min:12', 'max:40', 'regex:/\A[\x21-\x7e]+\z/D', 'confirmed'],
        ])->validate();
        if ($values['purpose'] === 'backup' && ! $device) {
            throw ValidationException::withMessages(['device_id' => 'Equipamento obrigatório para conta de backup.']);
        }
        if ($values['purpose'] === 'file_server' && $device) {
            throw ValidationException::withMessages(['device_id' => 'Servidor de arquivos não usa equipamento nesta fase.']);
        }
        return $values;
    }

    private function audit(FtpAccount $account, int $actorId, string $action): void
    {
        DB::table('ftp_account_audits')->insert([
            'ftp_account_id' => $account->id, 'device_id' => $account->device_id,
            'user_id' => $actorId, 'action' => $action, 'username' => $account->username,
            'created_at' => now(),
        ]);

        if (! Schema::hasTable('audit_events')) {
            return;
        }
        $globalAction = match ($action) {
            'create' => 'ftp.account.create',
            'rotate' => 'ftp.account.password_rotated',
            'enable' => 'ftp.account.enable',
            'disable' => 'ftp.account.disable',
            default => null,
        };
        if ($globalAction === null) {
            return;
        }
        $this->auditEvents->record($globalAction, 'ftp_account', (string) $account->id, $account->username,
            'success', ['device_id' => $account->device_id, 'purpose' => $account->purpose], $actorId, request()?->ip());
    }
}
