<?php

namespace App\Services;

use App\Models\BackupArtifact;
use App\Support\DestructiveActionPreview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual (admin-triggered) deletion of a single BackupArtifact's physical
 * file. Path safety and idempotency go through ArtifactStorage — the exact
 * same primitive App\Services\BackupRetention uses — so manual delete and
 * automatic retention can never diverge on what is safe to remove.
 */
class ArtifactDeletionService
{
    private const IN_PROGRESS_STATUSES = ['pending', 'queued', 'running'];

    public function __construct(private ArtifactStorage $storage, private AuditEvents $audit)
    {
    }

    public function preview(BackupArtifact $artifact): DestructiveActionPreview
    {
        $execution = $artifact->backupExecution;
        $blockers = [];
        $warnings = [];

        if ($artifact->status === 'deleted') {
            $blockers[] = 'Este artefato já foi excluído.';
        }
        if ($execution && in_array($execution->status, self::IN_PROGRESS_STATUSES, true)) {
            $blockers[] = 'A execução de backup associada ainda está em andamento ('.$execution->status.').';
        }

        $check = $this->storage->verify($artifact);
        $fileExists = $check['result'] !== 'missing';
        if ($check['result'] === 'missing') {
            $warnings[] = 'Arquivo físico já não existe no storage — a exclusão apenas atualizará o registro.';
        } elseif ($check['result'] !== 'valid') {
            $blockers[] = 'O caminho do arquivo não passou na validação de segurança.';
        }

        return new DestructiveActionPreview(
            resourceLabel: $artifact->original_filename ?: ('artifact-'.$artifact->id),
            dependencies: [
                ['label' => 'Execução de backup associada', 'count' => 1],
            ],
            filesCount: $fileExists ? 1 : 0,
            filesBytes: $artifact->size_bytes,
            preserved: [
                'Execução de backup #'.$artifact->backup_execution_id.' (histórico)',
                $artifact->device?->name ? 'Equipamento '.$artifact->device->name : 'Equipamento',
                $artifact->backupPolicy?->name ? 'Política '.$artifact->backupPolicy->name : 'Política de backup',
            ],
            removed: ['Arquivo físico do storage', 'Disponibilidade do artefato para download'],
            blockers: $blockers,
            warnings: $warnings,
        );
    }

    /**
     * @throws ValidationException when blocked (friendly message, no stack trace to the user).
     */
    public function delete(BackupArtifact $artifact, int $actorId, ?string $ip): void
    {
        DB::transaction(function () use ($artifact, $actorId, $ip) {
            $locked = BackupArtifact::query()->lockForUpdate()->with('backupExecution.device')->findOrFail($artifact->id);

            if ($locked->status === 'deleted') {
                throw ValidationException::withMessages(['confirmation' => 'Este artefato já foi excluído.']);
            }
            if ($locked->backupExecution && in_array($locked->backupExecution->status, self::IN_PROGRESS_STATUSES, true)) {
                throw ValidationException::withMessages(['confirmation' => 'O artifact está associado a uma execução em andamento.']);
            }

            $removal = $this->storage->remove($locked);
            $fileExisted = $removal['result'] !== 'missing';

            if (! in_array($removal['result'], ['deleted', 'missing'], true)) {
                $this->audit->record('backup_artifact.delete_failed', 'backup_artifact', (string) $locked->id,
                    $locked->original_filename, 'failed', $this->metadata($locked, false, $removal['result']),
                    $actorId, $ip);
                throw ValidationException::withMessages([
                    'confirmation' => 'Arquivo físico não pôde ser removido. O caminho do arquivo não passou na validação de segurança.',
                ]);
            }

            $locked->status = 'deleted';
            $locked->deleted_at = now();
            $locked->deletion_reason = 'manual';
            $locked->save();

            $this->audit->record('backup_artifact.delete', 'backup_artifact', (string) $locked->id,
                $locked->original_filename, 'success', $this->metadata($locked, $fileExisted, 'success'),
                $actorId, $ip);
        });
    }

    private function metadata(BackupArtifact $artifact, bool $fileExisted, string $result): array
    {
        return [
            'execution_id' => $artifact->backup_execution_id,
            'device_id' => $artifact->device_id,
            'relative_path' => $artifact->relative_path,
            'size_bytes' => $artifact->size_bytes,
            'file_existed' => $fileExisted,
            'bytes_removed' => $fileExisted ? $artifact->size_bytes : 0,
            'preserved_execution' => true,
            'result' => $result,
        ];
    }
}
