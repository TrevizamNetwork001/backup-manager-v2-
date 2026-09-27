<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Models\FtpAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FtpAccountDeletionService
{
    public const CONFIRMATION_PREFIXES = [
        'account' => 'EXCLUIR ',
        'ftp_data' => 'EXCLUIR DADOS ',
        'all' => 'APAGAR TUDO ',
    ];

    public function expectedConfirmation(FtpAccount $account, string $mode): string
    {
        if (! array_key_exists($mode, self::CONFIRMATION_PREFIXES)) {
            throw ValidationException::withMessages(['mode' => 'Selecione um modo de exclusão válido.']);
        }

        return self::CONFIRMATION_PREFIXES[$mode].$account->username;
    }

    public function confirmationPhrases(FtpAccount $account): array
    {
        $phrases = [];
        foreach (array_keys(self::CONFIRMATION_PREFIXES) as $mode) {
            $phrases[$mode] = $this->expectedConfirmation($account, $mode);
        }

        return $phrases;
    }

    public function preview(FtpAccount $account): array
    {
        $receipts = Schema::hasTable('ftp_received_files')
            ? DB::table('ftp_received_files')->where('ftp_account_id', $account->id)->get() : collect();
        $executions = DB::table('backup_executions')->where('ftp_account_id', $account->id)->get();
        $artifacts = BackupArtifact::query()->whereIn('backup_execution_id', $executions->pluck('id'))->get();
        $unknown = $account->device_id ? DB::table('backup_executions')->where('device_id', $account->device_id)
            ->where('origin', 'ftp_received')->whereNull('ftp_account_id')->count() : 0;
        $stored = $receipts->where('status', 'stored')->filter(fn ($r) => $account->purpose === 'file_server');
        $unattributable = 0;
        foreach ($artifacts as $artifact) {
            $check = app(ArtifactStorage::class)->verify($artifact);
            if ($check['result'] !== 'valid' && ! ($check['result'] === 'missing' && $artifact->status !== 'available')) $unattributable++;
        }
        $home = $account->homePath();
        $physical = $this->physicalSnapshot($account);
        $safetyError = null;
        $unattributedStored = 0;
        $incoming = $physical['incoming']['files'] ?? null;
        $processing = $physical['processing']['files'] ?? null;
        $quarantine = $physical['quarantine']['files'] ?? null;
        if ($account->purpose === 'file_server') {
            foreach ($stored as $receipt) {
                if (! preg_match('/\A[a-f0-9]{32}\z/D', $receipt->claim_token) ||
                    $receipt->relative_path !== 'ftp-files/'.$account->account_uuid.'/'.$receipt->claim_token) {
                    $safetyError = 'Há arquivos file_server sem vínculo seguro.';
                }
            }
            try {
                $directory = rtrim(config('backup.storage_root'), '/').'/ftp-files/'.$account->account_uuid;
                $known = $stored->pluck('relative_path')->filter()->map(fn ($path) => rtrim(config('backup.storage_root'), '/').'/'.$path)->all();
                $unattributedStored = count(array_diff($this->directoryFiles($directory), $known));
                if ($unattributedStored) $safetyError = 'Há arquivos file_server sem vínculo seguro.';
            } catch (RuntimeException $error) {
                $safetyError = $error->getMessage();
            }
        }
        $blocker = null;
        if ($physical === null) {
            $blocker = 'Estado físico FTP indisponível; exclusão bloqueada até nova verificação.';
        } elseif (($physical['status'] ?? null) !== 'ok' || ! ($physical['safe'] ?? false)) {
            $blocker = $this->physicalMessage($physical['blockers'][0] ?? 'ftp_admin_unavailable');
        } elseif ($receipts->where('status', 'processing')->count() ||
            $executions->whereIn('status', ['pending', 'queued', 'running'])->count()) {
            $blocker = 'Há processamento ou claim ativo. Tente novamente depois.';
        }
        return [
            'account' => $account->username, 'purpose' => $account->purpose, 'device' => $account->device?->name,
            'home_layout' => $account->home_layout, 'chroot' => $home,
            'receipts' => $receipts->count(), 'incoming' => $incoming,
            'processing' => $processing, 'quarantine' => $quarantine, 'physical' => $physical,
            'executions' => $executions->count(), 'artifacts' => $artifacts->count(),
            'artifact_bytes' => $artifacts->sum('size_bytes'), 'stored_files' => $stored->count(),
            'stored_bytes' => $stored->sum('size_bytes'),
            'unattributed_stored_files' => $unattributedStored,
            'stored_paths' => $stored->pluck('relative_path')->filter()->values()->all(),
            'unattributed_executions' => $unknown,
            'unattributable_artifacts' => $unattributable,
            'puredb' => $account->sync_error ? 'Erro: '.$account->sync_error : ($account->provisioned_at ? 'Sincronizado' : 'Pendente'),
            'device_released' => $account->purpose === 'backup',
            'deletion_mode' => $account->deletion_mode ?? null, 'deletion_error' => $account->deletion_error ?? null,
            'safety_error' => $safetyError,
            'blocker' => $blocker,
        ];
    }

    public function request(FtpAccount $account, string $mode, string $phrase, int $actor, ?string $ip): void
    {
        DB::transaction(function () use ($account, $mode, $phrase, $actor, $ip) {
            $locked = FtpAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->deletion_mode) throw ValidationException::withMessages(['mode' => 'A conta mudou de estado. Revise o impacto antes de continuar.']);
            if (! hash_equals($this->expectedConfirmation($locked, $mode), $phrase)) {
                throw ValidationException::withMessages(['confirmation' => 'A frase de confirmação não corresponde ao modo selecionado.']);
            }
            if (! is_dir(config('backup.storage_root'))) {
                throw ValidationException::withMessages(['mode' => 'Storage de backup indisponível.']);
            }
            $preview = $this->preview($locked);
            if ($preview['safety_error']) throw ValidationException::withMessages(['mode' => $preview['safety_error']]);
            if ($preview['blocker']) throw ValidationException::withMessages(['mode' => $preview['blocker']]);
            if ($mode === 'all' && $preview['unattributable_artifacts']) {
                throw ValidationException::withMessages(['mode' => 'Artifacts não atribuíveis com segurança.']);
            }
            $this->assertIdle($locked);
            $locked->is_active = false;
            $locked->provisioned_at = null;
            $locked->deletion_mode = $mode;
            $locked->deletion_error = null;
            $locked->deletion_actor_id = $actor;
            $locked->deletion_ip = $ip;
            $locked->deletion_requested_at = now();
            $locked->save();
            $this->event($locked, 'pending', ['mode' => $mode, 'physical' => $preview['physical'], 'preserved' => $mode === 'account' ? 'all_data' : 'processed_backups']);
        });
    }

    // Called only by ftp-admin after its atomic PureDB replacement succeeded.
    public function finalize(FtpAccount $account, string $payload): void
    {
        if (! $account->deletion_mode) return;
        $physical = $this->decodePayload($payload);
        if (($physical['status'] ?? null) !== 'ok' || ! isset($physical['files_removed'], $physical['bytes_removed'])) {
            throw new RuntimeException('Resultado físico FTP inválido.');
        }
        $moved = [];
        $deleted = [];
        $bytes = 0;
        try {
            DB::transaction(function () use ($account, $physical, &$moved, &$deleted, &$bytes) {
                $locked = FtpAccount::query()->lockForUpdate()->findOrFail($account->id);
                if (! $locked->deletion_mode || $locked->is_active) throw new RuntimeException('Estado de exclusão inválido.');
                $this->assertIdle($locked);
                $mode = $locked->deletion_mode;
                $paths = $this->targets($locked, $mode);
                foreach ($paths as $path) {
                    $stat = $this->checkedFile($path);
                    if ($stat === null) continue;
                    $root = $this->rootFor($path);
                    $trash = $root.'/.ftp-delete-'.$locked->account_uuid;
                    if (is_link($trash)) throw new RuntimeException('Lixeira FTP inválida.');
                    if (! is_dir($trash) && ! mkdir($trash, 0700)) throw new RuntimeException('Lixeira FTP indisponível.');
                    $destination = $trash.'/'.bin2hex(random_bytes(16));
                    if (! rename($path, $destination)) throw new RuntimeException('Falha ao isolar arquivo.');
                    $moved[] = [$path, $destination];
                    $deleted[] = $path;
                    $bytes += $stat['size'];
                }
                if ($mode !== 'account') DB::table('ftp_received_files')->where('ftp_account_id', $locked->id)->delete();
                $executionIds = DB::table('backup_executions')->where('ftp_account_id', $locked->id)->pluck('id');
                $artifactCount = 0;
                if ($mode === 'all') {
                    $artifactCount = DB::table('backup_artifacts')->whereIn('backup_execution_id', $executionIds)->count();
                    DB::table('backup_artifacts')->whereIn('backup_execution_id', $executionIds)->delete();
                    DB::table('backup_executions')->whereIn('id', $executionIds)->delete();
                } else {
                    DB::table('backup_executions')->where('ftp_account_id', $locked->id)->update(['ftp_account_id' => null]);
                }
                if ($locked->device_id && \Illuminate\Support\Facades\Schema::hasTable('olt_ftp_integrations')) {
                    DB::table('olt_ftp_integrations')->where('device_id', $locked->device_id)->update([
                        'olt_confirmed_at' => null, 'test_execution_id' => null,
                        'account_updated_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->event($locked, 'success', [
                    'mode' => $mode, 'ftp_files_removed' => $physical['files_removed'], 'ftp_bytes_removed' => $physical['bytes_removed'],
                    'files_removed' => count($deleted) + $physical['files_removed'], 'bytes_removed' => $bytes + $physical['bytes_removed'],
                    'paths_removed' => $deleted, 'executions_removed' => $mode === 'all' ? $executionIds->count() : 0,
                    'artifacts_removed' => $artifactCount,
                    'preserved' => $mode === 'account' ? 'receipts,ftp_files,backups' : ($mode === 'all' ? 'unattributed_backups,device,site,policies,credentials' : 'processed_backups'),
                ]);
                $locked->delete();
            });
        } catch (\Throwable $error) {
            $rollback = true;
            foreach (array_reverse($moved) as [$source, $staged]) {
                if (! @rename($staged, $source)) $rollback = false;
            }
            $fresh = FtpAccount::find($account->id);
            if ($fresh) {
                $fresh->deletion_error = 'Exclusão não concluída: '.substr($error->getMessage(), 0, 180);
                $fresh->save();
                $this->event($fresh, 'failed', ['mode' => $fresh->deletion_mode, 'error' => $error->getMessage(), 'rollback' => $rollback]);
            }
            throw $error;
        }
        foreach ($moved as [, $staged]) {
            if (! @unlink($staged)) {
                Log::error('ftp_delete_trash_cleanup_failed', ['path' => $staged]);
                $this->event($account, 'partial', ['mode' => $account->deletion_mode,
                    'error' => 'Falha ao limpar arquivo isolado após commit.', 'paths_physical_pending' => [$staged], 'rollback' => false]);
            }
            $trash = dirname($staged);
            if (is_dir($trash) && ! is_link($trash) && count(scandir($trash)) === 2) @rmdir($trash);
        }

    }

    private function targets(FtpAccount $account, string $mode): array
    {
        if ($mode === 'account') return [];
        $paths = [];
        $receipts = DB::table('ftp_received_files')->where('ftp_account_id', $account->id)->lockForUpdate()->get();
        if ($account->purpose === 'file_server') {
            $storedPaths = [];
            foreach ($receipts->where('status', 'stored') as $receipt) {
                $expected = 'ftp-files/'.$account->account_uuid.'/'.$receipt->claim_token;
                if ($receipt->relative_path !== $expected || ! preg_match('/\A[a-f0-9]{32}\z/D', $receipt->claim_token)) {
                    throw new RuntimeException('Arquivo file_server não atribuível com segurança.');
                }
                $physical = rtrim(config('backup.storage_root'), '/').'/'.$expected;
                $stat = $this->checkedFile($physical);
                if ($stat && ($stat['size'] !== (int) $receipt->size_bytes ||
                    ! hash_equals(strtolower((string) $receipt->sha256), hash_file('sha256', $physical)))) {
                    throw new RuntimeException('Arquivo file_server alterado após recebimento.');
                }
                $storedPaths[] = $physical;
            }
            $directory = rtrim(config('backup.storage_root'), '/').'/ftp-files/'.$account->account_uuid;
            $actual = $this->directoryFiles($directory);
            if (array_diff($actual, $storedPaths)) throw new RuntimeException('Arquivos file_server não atribuíveis com segurança.');
            array_push($paths, ...$storedPaths);
        }
        if ($mode === 'all') {
            $jobs = DB::table('backup_executions')->where('ftp_account_id', $account->id)->lockForUpdate()->get();
            foreach (BackupArtifact::query()->whereIn('backup_execution_id', $jobs->pluck('id'))->with('backupExecution')->lockForUpdate()->get() as $artifact) {
                $check = app(ArtifactStorage::class)->verify($artifact);
                if ($check['result'] === 'missing' && $artifact->status !== 'available') continue;
                if ($check['result'] !== 'valid') throw new RuntimeException('Artifact não atribuível ou inválido: '.$artifact->id.' ('.$check['result'].').');
                $paths[] = $check['path'];
            }
        }
        return array_values(array_unique($paths));
    }

    private function assertIdle(FtpAccount $account): void
    {
        if (DB::table('ftp_received_files')->where('ftp_account_id', $account->id)->where('status', 'processing')->exists() ||
            DB::table('backup_executions')->where('ftp_account_id', $account->id)->whereIn('status', ['pending', 'queued', 'running', 'retry_wait'])->exists()) {
            throw ValidationException::withMessages(['mode' => 'Há processamento ou claim ativo. Tente novamente após a conclusão.']);
        }
    }

    private function rootFor(string $path): string
    {
        foreach ([rtrim(config('backup.storage_root'), '/')] as $root) {
            if ($root === '/' || is_link($root) || ! is_dir($root)) continue;
            $real = realpath($root);
            if ($real === $root && str_starts_with($path, $root.'/')) return $root;
        }
        throw new RuntimeException('Path fora da raiz autorizada.');
    }

    private function checkedFile(string $path): ?array
    {
        $root = $this->rootFor($path);
        $relative = substr($path, strlen($root) + 1);
        if ($relative === '' || str_contains($relative, '//')) throw new RuntimeException('Path inválido.');
        $current = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, "\0")) throw new RuntimeException('Path traversal.');
            $current .= '/'.$part;
            if (is_link($current)) throw new RuntimeException('Symlink bloqueado.');
        }
        if (! file_exists($path)) return null;
        $stat = lstat($path);
        if (! $stat || ! is_file($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new RuntimeException('Arquivo inesperado.');
        }
        return $stat;
    }

    private function directoryFiles(string $directory): array
    {
        if (! is_dir(config('backup.storage_root'))) return [];
        $root = $this->rootFor($directory);
        $current = $root;
        foreach (explode('/', substr($directory, strlen($root) + 1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('Path inválido.');
            $current .= '/'.$part;
            if (is_link($current)) throw new RuntimeException('Symlink bloqueado.');
        }
        if (! is_dir($directory)) return [];
        $paths = [];
        foreach (new \FilesystemIterator($directory) as $item) {
            $path = $item->getPathname();
            $this->checkedFile($path);
            $paths[] = $path;
        }
        return $paths;
    }

    public function requestInspection(FtpAccount $account): void
    {
        if (! Schema::hasTable('audit_events') || $this->physicalSnapshot($account) !== null) return;
        $latest = DB::table('audit_events')->where('action', 'ftp.physical.request')
            ->where('resource_id', (string) $account->id)->orderByDesc('id')->first();
        if ($latest && \Carbon\Carbon::parse($latest->created_at)->gt(now()->subSeconds(20))) return;
        app(AuditEvents::class)->record('ftp.physical.request', 'ftp_account', (string) $account->id,
            $account->username, 'pending', ['version' => $account->getRawOriginal('updated_at')]);
    }

    public function physicalReport(int $id, string $version, string $payload): void
    {
        $account = FtpAccount::findOrFail($id);
        if ($account->getRawOriginal('updated_at') !== $version) return;
        $report = $this->decodePayload($payload);
        if (! in_array($report['status'] ?? null, ['ok', 'error'], true)) throw new RuntimeException('Relatório FTP inválido.');
        app(AuditEvents::class)->record('ftp.physical.inspect', 'ftp_account', (string) $id,
            $account->username, $report['status'], ['version' => $version, 'report' => $report]);
    }

    public function puredbRevoked(int $id): void
    {
        $account = FtpAccount::findOrFail($id);
        if ($account->deletion_mode && ! $account->is_active) {
            $this->event($account, 'puredb_revoked', ['mode' => $account->deletion_mode]);
        }
    }

    public function physicalFailed(int $id, string $code): void
    {
        $account = FtpAccount::findOrFail($id);
        if (! $account->deletion_mode || ! preg_match('/\A[a-z_]{3,40}\z/D', $code)) return;
        $account->deletion_error = $this->physicalMessage($code);
        $account->save();
        $this->event($account, 'failed', ['mode' => $account->deletion_mode, 'error_code' => $code, 'retry' => true]);
    }

    private function physicalSnapshot(FtpAccount $account): ?array
    {
        if (! Schema::hasTable('audit_events')) return null;
        $event = DB::table('audit_events')->where('action', 'ftp.physical.inspect')
            ->where('resource_type', 'ftp_account')->where('resource_id', (string) $account->id)
            ->orderByDesc('id')->first();
        if (! $event || \Carbon\Carbon::parse($event->created_at)->lt(now()->subSeconds(45))) return null;
        $metadata = json_decode($event->metadata, true);
        if (($metadata['version'] ?? null) !== $account->getRawOriginal('updated_at')) return null;
        return $metadata['report'] ?? null;
    }

    private function decodePayload(string $payload): array
    {
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) > 8192) throw new RuntimeException('Relatório FTP inválido.');
        $value = json_decode($decoded, true);
        if (! is_array($value)) throw new RuntimeException('Relatório FTP inválido.');
        return $value;
    }

    private function physicalMessage(string $code): string
    {
        return match ($code) {
            'active_claim' => 'Há processamento ou claim ativo. Tente novamente depois.',
            'recent_upload' => 'Há upload recente em incoming. Aguarde 60 segundos sem alterações.',
            'symlink_detected', 'unsafe_path' => 'Path FTP inseguro; exclusão bloqueada.',
            'filesystem_permission_denied' => 'Permissão insuficiente no ftp-admin para verificar ou limpar os dados FTP.',
            'puredb_revoke_failed' => 'Não foi possível confirmar a revogação no PureDB.',
            'filesystem_cleanup_failed' => 'Falha na limpeza FTP; a conta permanece revogada e a operação será tentada novamente.',
            default => 'Estado físico FTP indisponível; exclusão bloqueada até nova verificação.',
        };
    }

    private function event(FtpAccount $account, string $result, array $details): void
    {
        $action = match ($account->deletion_mode) {
            'account' => 'ftp.account.delete', 'ftp_data' => 'ftp.account.delete_with_data', default => 'ftp.account.delete_all',
        };
        app(AuditEvents::class)->record($action, 'ftp_account', (string) $account->id, $account->username, $result,
            ['account_id' => $account->id, 'username' => $account->username, 'purpose' => $account->purpose,
                'device_id' => $account->device_id, 'device_name' => $account->device?->name,
                'account_uuid' => $account->account_uuid, 'home_layout' => $account->home_layout,
                'chroot' => $account->homePath(), 'result' => $result] + $details, $account->deletion_actor_id, $account->deletion_ip);
    }
}
