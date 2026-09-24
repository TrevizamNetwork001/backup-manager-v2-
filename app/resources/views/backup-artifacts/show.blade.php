@extends('layouts.app')
@section('title', 'Artefato #'.$backupArtifact->id.' — Backup Manager')
@section('page-title', 'Artefato #'.$backupArtifact->id)
@section('page-description', 'Metadados do export validado.')
@section('content')
<div class="page-width stack">
@if(session('success'))
    <div class="alert alert--success" role="status">{{ session('success') }}</div>
@endif
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Metadados</h2></div></div>
    <p><strong>Equipamento:</strong> {{ $backupArtifact->device->name }}</p>
    <p><strong>Política:</strong> {{ $backupArtifact->backupPolicy->name }}</p>
    <p><strong>Execução:</strong> <a href="{{ route('backup-executions.show', $backupArtifact->backup_execution_id) }}">#{{ $backupArtifact->backup_execution_id }}</a></p>
    <p><strong>Estado:</strong> {{ $backupArtifact->statusLabel() }}</p>
    @if($backupArtifact->deleted_at)<p><strong>Removido em:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupArtifact->deleted_at) }} · <strong>Motivo:</strong> {{ $backupArtifact->deletionReasonLabel() }}</p>@endif
    @if($backupArtifact->missing_at)<p><strong>Ausência detectada em:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupArtifact->missing_at) }}</p>@endif
    <p><strong>Tipo:</strong> {{ $backupArtifact->type }} · <strong>Storage:</strong> {{ $backupArtifact->storage }}</p>
    <p><strong>Tamanho:</strong> {{ number_format($backupArtifact->size_bytes) }} bytes</p>
    <p><strong>SHA256:</strong> <code>{{ $backupArtifact->sha256 }}</code></p>
    <p><strong>Path relativo:</strong> <code>{{ $backupArtifact->relative_path }}</code></p>
    <p><strong>Validado em:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupArtifact->validated_at) }}</p>

    @can('backup_artifacts.delete')
    <x-risk-zone description="Essa ação remove o arquivo físico do storage. A execução de backup permanecerá no histórico.">
        <div>
            <strong>Excluir artefato</strong>
            <p class="muted-text">{{ $backupArtifact->status === 'deleted' ? 'Este artefato já foi excluído.' : 'Requer confirmação com o nome do arquivo.' }}</p>
        </div>
        <button type="button" class="btn btn--secondary" id="artifact-delete-open" @disabled($preview->hasBlockers())>Excluir artefato</button>
    </x-risk-zone>

    <dialog class="modal" id="artifact-delete-dialog" aria-labelledby="artifact-delete-title">
        <div class="modal__surface">
            <div class="modal__header">
                <h2 class="modal__title" id="artifact-delete-title">Excluir artefato #{{ $backupArtifact->id }}</h2>
                <button type="button" class="modal__close" data-close-artifact-delete aria-label="Fechar"><x-icon name="close" size="sm" /></button>
            </div>
            <form method="POST" action="{{ route('backup-artifacts.destroy', $backupArtifact) }}">
                @csrf
                @method('DELETE')
                <div class="modal__body ftp-modal-body">
                    @if($errors->has('confirmation'))
                        <div class="alert alert--warning" role="alert">{{ $errors->first('confirmation') }}</div>
                    @endif

                    <dl class="risk-zone-preview">
                        <div><dt>Arquivo</dt> <dd>{{ $preview->resourceLabel }}</dd></div>
                        <div><dt>Equipamento</dt> <dd>{{ $backupArtifact->device->name }}</dd></div>
                        <div><dt>Execução</dt> <dd>#{{ $backupArtifact->backup_execution_id }} ({{ $backupArtifact->backupExecution->status ?? 'desconhecido' }})</dd></div>
                        <div><dt>Tamanho</dt> <dd>{{ number_format($preview->filesBytes ?? 0) }} bytes</dd></div>
                        <div><dt>Arquivo físico</dt> <dd>{{ $preview->filesCount > 0 ? 'Existe' : 'Ausente' }}</dd></div>
                        @foreach($preview->preserved as $item)
                            <div><dt>Preservado</dt> <dd>{{ $item }}</dd></div>
                        @endforeach
                        @foreach($preview->warnings as $warning)
                            <div><dt>Aviso</dt> <dd class="warning">{{ $warning }}</dd></div>
                        @endforeach
                        @foreach($preview->blockers as $blocker)
                            <div><dt>Bloqueio</dt> <dd class="blocker">{{ $blocker }}</dd></div>
                        @endforeach
                    </dl>

                    <div class="form-field">
                        <label class="form-label" for="artifact-delete-confirmation">
                            Frase de confirmação: <strong id="artifact-delete-phrase">{{ $confirmationPhrase }}</strong>
                        </label>
                        <input class="form-control" id="artifact-delete-confirmation" name="confirmation" autocomplete="off" required
                            @if($errors->has('confirmation')) aria-invalid="true" @endif>
                    </div>
                </div>
                <div class="modal__footer ftp-modal-footer">
                    <button type="button" class="btn btn--ghost" data-close-artifact-delete>Cancelar</button>
                    <button type="submit" class="btn btn--danger" @disabled($preview->hasBlockers())>Excluir definitivamente</button>
                </div>
            </form>
        </div>
    </dialog>
    <script>
    (() => {
        const dialog = document.getElementById('artifact-delete-dialog');
        document.getElementById('artifact-delete-open')?.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-close-artifact-delete]').forEach(btn => btn.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        @if ($errors->has('confirmation')) dialog.showModal(); @endif
    })();
    </script>
    @endcan
</article>
</div>
@endsection
