<?php

namespace App\Services;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Str;

class AuditPresenter
{
    private const SENSITIVE_KEY_NEEDLES = [
        'password', 'passwd', 'pass', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'cookie', 'app_key', 'private_key', 'credential', 'confirmation_hash',
    ];

    private const ACTION_LABELS = [
        'ftp.account.create' => 'Criação de conta FTP',
        'ftp.account.delete' => 'Excluir conta FTP',
        'ftp.account.delete_with_data' => 'Excluir conta FTP + dados',
        'ftp.account.delete_all' => 'Excluir conta FTP + todos os dados',
        'ftp.account.password_rotated' => 'Rotação de senha FTP',
        'ftp.account.enable' => 'Ativação de conta FTP',
        'ftp.account.disable' => 'Desativação de conta FTP',
        'ftp.backup_policy.prepared' => 'Preparação de backup FTP',
        'ftp.physical.inspect' => 'Inspeção física FTP',
        'ftp.physical.request' => 'Solicitação de inspeção FTP',
        'backup.content_analyzed' => 'Análise de conteúdo de backup',
        'user.created' => 'Criação de usuário',
        'user.updated' => 'Atualização de usuário',
        'user.role_changed' => 'Alteração de papel do usuário',
        'user.enabled' => 'Ativação de usuário',
        'user.disabled' => 'Desativação de usuário',
        'user.password_reset' => 'Redefinição de senha do usuário',
        'backup_artifact.delete' => 'Excluir artefato de backup',
        'backup_artifact.delete_failed' => 'Falha ao excluir artefato de backup',
    ];

    private const RESULT_LABELS = [
        'success' => ['Sucesso', 'success'],
        'ok' => ['Sucesso', 'success'],
        'completed' => ['Sucesso', 'success'],
        'recognized' => ['Sucesso', 'success'],
        'pending' => ['Pendente', 'warning'],
        'partial' => ['Parcial', 'warning'],
        'warning' => ['Aviso', 'warning'],
        'failed' => ['Falha', 'danger'],
        'error' => ['Falha', 'danger'],
        'blocked' => ['Bloqueado', 'danger'],
        'puredb_revoked' => ['PureDB revogado', 'info'],
    ];

    private const RESOURCE_TYPE_LABELS = [
        'ftp_account' => 'Conta FTP',
        'device' => 'Equipamento',
        'site' => 'Site/POP',
        'backup_execution' => 'Execução de backup',
        'backup_artifact' => 'Artefato de backup',
        'user' => 'Usuário',
    ];

    private const MODE_LABELS = [
        'account' => 'Somente conta',
        'ftp_data' => 'Conta + dados FTP',
        'all' => 'Conta + todos os dados',
    ];

    private const METADATA_KEY_LABELS = [
        'account_id' => 'ID da conta',
        'username' => 'Conta FTP',
        'device_id' => 'ID do equipamento',
        'device_name' => 'Equipamento',
        'purpose' => 'Finalidade',
        'account_uuid' => 'UUID da conta',
        'home_layout' => 'Layout do diretório',
        'chroot' => 'Chroot',
        'mode' => 'Modo',
        'physical' => 'Inspeção física',
        'preserved' => 'Backups preservados',
        'ftp_files_removed' => 'Arquivos FTP removidos',
        'ftp_bytes_removed' => 'Bytes FTP removidos',
        'files_removed' => 'Arquivos removidos',
        'bytes_removed' => 'Bytes removidos',
        'paths_removed' => 'Caminhos removidos',
        'executions_removed' => 'Execuções removidas',
        'artifacts_removed' => 'Artefatos removidos',
        'error' => 'Erro',
        'rollback' => 'Rollback',
        'error_code' => 'Código de erro',
        'retry' => 'Nova tentativa',
        'paths_physical_pending' => 'Caminhos físicos pendentes',
        'version' => 'Versão',
        'report' => 'Relatório físico',
        'policy_id' => 'ID da política',
        'association_id' => 'ID da associação',
        'reused_policy' => 'Política reaproveitada',
        'reused_association' => 'Associação reaproveitada',
        'result' => 'Resultado',
        'status' => 'Status',
        'is_active' => 'Ativa',
        'target_user_id' => 'ID do usuário',
        'target_user_name' => 'Usuário',
        'target_user_email' => 'E-mail',
        'old_role' => 'Papel anterior',
        'new_role' => 'Novo papel',
        'old_status' => 'Status anterior',
        'new_status' => 'Novo status',
        'execution_id' => 'ID da execução',
        'relative_path' => 'Path relativo',
        'file_existed' => 'Arquivo existia',
        'preserved_execution' => 'Execução preservada',
        'size_bytes' => 'Tamanho',
    ];

    private const BYTE_KEYS = ['bytes_removed', 'ftp_bytes_removed', 'size_bytes'];

    private const STATUS_LABELS = [
        'active' => 'Ativo',
        'disabled' => 'Desativado',
    ];

    private const ROLE_VALUE_KEYS = ['old_role', 'new_role'];
    private const STATUS_VALUE_KEYS = ['old_status', 'new_status'];

    public function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? $action;
    }

    public function resultBadge(string $result): array
    {
        [$label, $variant] = self::RESULT_LABELS[$result] ?? [$result, 'neutral'];

        return ['label' => $label, 'variant' => $variant];
    }

    public function resourceTypeLabel(string $resourceType): string
    {
        return self::RESOURCE_TYPE_LABELS[$resourceType] ?? $resourceType;
    }

    public function actorLabel(?User $actor): string
    {
        return $actor?->name ?? 'Sistema';
    }

    /**
     * Recursively redacts values behind sensitive keys, defensively — even
     * though producers of audit events should never emit secrets in the first place.
     */
    public function sanitizeMetadata(mixed $metadata): mixed
    {
        if (! is_array($metadata)) {
            return $metadata;
        }

        $sanitized = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds a flat, human-readable list of [label, value] rows for the metadata
     * detail view. Input must already be sanitized.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function metadataRows(array $sanitizedMetadata, string $prefix = ''): array
    {
        $rows = [];
        foreach ($sanitizedMetadata as $key => $value) {
            $label = $prefix !== '' ? $prefix.' › '.$this->keyLabel((string) $key) : $this->keyLabel((string) $key);

            if (is_array($value)) {
                if ($value === [] || ! $this->isAssociative($value)) {
                    $rows[] = ['label' => $label, 'value' => $this->formatList($value)];
                } else {
                    $rows = array_merge($rows, $this->metadataRows($value, $label));
                }
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $this->formatScalar((string) $key, $value)];
        }

        return $rows;
    }

    private function keyLabel(string $key): string
    {
        return self::METADATA_KEY_LABELS[$key] ?? Str::of($key)->replace('_', ' ')->ucfirst()->toString();
    }

    private function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function formatList(array $value): string
    {
        if ($value === []) {
            return '—';
        }
        $scalars = array_filter($value, static fn ($item) => ! is_array($item));
        if (count($scalars) === count($value)) {
            return implode(', ', array_map(static fn ($item) => (string) $item, $value));
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function formatScalar(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }
        if ($value === null) {
            return '—';
        }
        if ($key === 'mode' && is_string($value)) {
            return self::MODE_LABELS[$value] ?? $value;
        }
        if (in_array($key, self::BYTE_KEYS, true) && is_numeric($value)) {
            return $this->formatBytes((float) $value);
        }
        if (in_array($key, self::ROLE_VALUE_KEYS, true) && is_string($value)) {
            return Rbac::roleLabel($value);
        }
        if (in_array($key, self::STATUS_VALUE_KEYS, true) && is_string($value)) {
            return self::STATUS_LABELS[$value] ?? $value;
        }

        return (string) $value;
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 2, ',', '.').' '.$units[$i];
    }
}
