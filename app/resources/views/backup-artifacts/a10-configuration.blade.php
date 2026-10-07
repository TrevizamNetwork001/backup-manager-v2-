@extends('layouts.app')
@section('title', 'Configuração A10 — Backup Manager')
@section('page-title', 'Configuração A10')
@section('page-description', 'Arquivo de configuração salva dentro do backup do sistema.')

@section('content')
<div class="backup-artifacts-page stack">
    <header class="page-header">
        <div class="page-header__content">
            <h1 class="page-header__title">Configuração salva de {{ $backupArtifact->device?->name }}</h1>
            <p class="page-header__description">Artifact #{{ $backupArtifact->id }} · backup_system.tar · startup-config.{{ $slot }}</p>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--secondary" href="{{ route('backup-artifacts.show', $backupArtifact) }}">Voltar ao artefato</a>
            <a class="btn btn--secondary" href="{{ route('backup-artifacts.a10-configuration', [$backupArtifact, 'slot' => $slot === 'pri' ? 'sec' : 'pri']) }}">Ver {{ $slot === 'pri' ? 'sec' : 'pri' }}</a>
        </div>
    </header>
    <section class="card">
        <div class="card__header"><div><h2 class="card__title">startup-config.{{ $slot }}</h2><p class="card__description">Configuração salva no pacote exportado pelo A10. Mudanças não salvas no equipamento podem não constar aqui.</p></div></div>
        <div class="card__body"><pre class="text-technical" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:70vh;overflow:auto">{{ $content }}</pre></div>
    </section>
</div>
@endsection
