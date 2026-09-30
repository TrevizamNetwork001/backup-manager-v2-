@extends('layouts.app')
@section('title', 'Artefato #'.$backupArtifact->id.' — Backup Manager')
@section('page-title', 'Artefato #'.$backupArtifact->id)
@section('page-description', 'Metadados do export validado.')
@section('page-header')
<header class="page-header artifact-detail-page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Detalhes do artefato</h1>
        <p class="page-header__description">Arquivo e integridade do backup #{{ $backupArtifact->id }}.</p>
    </div>
    <nav class="artifact-breadcrumb" aria-label="Caminho"><a href="{{ route('backup-artifacts.index') }}">Artefatos</a><x-icon name="chevron-right" size="sm" /><span>#{{ $backupArtifact->id }}</span></nav>
    <div class="page-header__actions">
        <a href="{{ route('backup-artifacts.index') }}" class="btn btn--secondary"><x-icon name="arrow-right" size="sm" /> Voltar aos artefatos</a>
        @can('backup_artifacts.download')
            @if($backupArtifact->status === 'available')<a href="{{ route('backup-artifacts.download', $backupArtifact) }}" class="btn btn--primary">Baixar arquivo</a>@endif
        @endcan
    </div>
</header>
@endsection
@section('content')
<div class="backup-artifacts-page artifact-show-page stack">
@if(session('success'))
    <div class="alert alert--success" role="status">{{ session('success') }}</div>
@endif
<section class="artifact-show-hero">
    <span class="artifact-show-hero__icon"><x-icon name="file" size="lg" /></span>
    <div class="artifact-show-hero__main">
        <p>Artefato #{{ $backupArtifact->id }}</p>
        <h2>{{ $backupArtifact->original_filename ?: 'Arquivo de backup' }}</h2>
        <span>{{ $backupArtifact->device->name }} @if($backupArtifact->device->site)· {{ $backupArtifact->device->site->name }}@endif</span>
    </div>
    <span class="badge badge--{{ $backupArtifact->status === 'available' ? 'success' : ($backupArtifact->status === 'deleted' ? 'neutral' : 'warning') }}">{{ $backupArtifact->statusLabel() }}</span>
</section>
<article class="card artifact-show-card">
    <div class="card__header"><div><h2 class="card__title">Informações do artefato</h2><p class="card__description">Metadados, origem e verificação de integridade.</p></div></div>
    <div class="card__body">
        <dl class="artifact-show-details">
            <div><dt>Arquivo</dt><dd>{{ $backupArtifact->original_filename ?: 'Artefato #'.$backupArtifact->id }}</dd></div>
            <div><dt>Equipamento</dt><dd>{{ $backupArtifact->device->name }}</dd></div>
            <div><dt>Fabricante / modelo</dt><dd><span class="vendor-cell"><span>{{ trim(($backupArtifact->device->vendor ?? '').' '.($backupArtifact->device->model ?? '')) ?: '—' }}</span></span></dd></div>
            <div><dt>Site / POP</dt><dd>{{ $backupArtifact->device->site?->name ?? '—' }}</dd></div>
            <div><dt>Política</dt><dd>{{ $backupArtifact->backupPolicy->name }}</dd></div>
            <div><dt>Execução</dt><dd><a class="link" href="{{ route('backup-executions.show', $backupArtifact->backup_execution_id) }}">Execução #{{ $backupArtifact->backup_execution_id }}</a></dd></div>
            <div><dt>Tipo / armazenamento</dt><dd>{{ $backupArtifact->type }} · {{ $backupArtifact->storage }}</dd></div>
            <div><dt>Tamanho</dt><dd>{{ number_format($backupArtifact->size_bytes, 0, ',', '.') }} bytes ({{ number_format($backupArtifact->size_bytes / 1024, 1, ',', '.') }} KB)</dd></div>
            <div><dt>SHA256</dt><dd class="text-technical">{{ $backupArtifact->sha256 }}</dd></div>
            <div><dt>Validado em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupArtifact->validated_at) }}</dd></div>
            <div class="artifact-show-details__wide"><dt>Path relativo</dt><dd class="text-technical">{{ $backupArtifact->relative_path }}</dd></div>
            @if($backupArtifact->deleted_at)<div><dt>Removido em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupArtifact->deleted_at) }} · {{ $backupArtifact->deletionReasonLabel() }}</dd></div>@endif
            @if($backupArtifact->missing_at)<div><dt>Ausência detectada em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupArtifact->missing_at) }}</dd></div>@endif
        </dl>
    </div>

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
                        <div><dt>Execução</dt> <dd>#{{ $backupArtifact->backup_execution_id }} ({{ \App\Support\OperationalLabels::EXECUTION_STATUSES[$backupArtifact->backupExecution?->status ?? ''] ?? 'Desconhecido' }})</dd></div>
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
