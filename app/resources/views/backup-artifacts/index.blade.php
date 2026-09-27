@extends('layouts.app')

@section('title', 'Artefatos — Backup Manager')
@section('page-title', 'Artefatos')
@section('page-description', 'Exports de configuração validados e armazenados localmente.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Artefatos de backup</h1>
        <p class="page-header__description">Exports de configuração validados e armazenados localmente.</p>
    </div>
</header>
@endsection

@section('content')
<div class="backup-artifacts-page stack">
    <section class="card" aria-labelledby="artifact-list-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="artifact-list-title">Artefatos</h2>
                <p class="card__description">{{ $artifacts->total() }} {{ $artifacts->total() === 1 ? 'arquivo armazenado' : 'arquivos armazenados' }}</p>
            </div>
        </div>
        @if($artifacts->isEmpty())
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true"><x-icon name="file" /></div>
                <h3 class="empty-state__title">Nenhum artefato encontrado</h3>
                <p class="empty-state__description">Os arquivos de backup validados aparecerão aqui.</p>
            </div>
        @else
            <div class="table-shell" role="region" aria-label="Lista de artefatos de backup" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Arquivo</th><th>Equipamento</th><th>Política</th><th>Execução</th><th>Estado</th><th>Tipo</th><th>Tamanho</th><th>SHA256</th><th>Criado em</th></tr></thead>
                    <tbody>
                        @foreach($artifacts as $artifact)
                            <tr>
                                <td data-label="Arquivo"><a class="table-action" href="{{ route('backup-artifacts.show', $artifact) }}">{{ $artifact->original_filename ?: 'Artefato #'.$artifact->id }}</a><small class="entity-cell__meta">#{{ $artifact->id }}</small></td>
                                <td data-label="Equipamento"><span class="entity-cell__title">{{ $artifact->device->name }}</span></td>
                                <td data-label="Política">{{ $artifact->backupPolicy->name }}</td>
                                <td data-label="Execução"><a class="table-action" href="{{ route('backup-executions.show', $artifact->backup_execution_id) }}">#{{ $artifact->backup_execution_id }}</a></td>
                                <td data-label="Estado"><span class="badge badge--{{ $artifact->status === 'available' ? 'success' : ($artifact->status === 'deleted' ? 'neutral' : 'warning') }}">{{ $artifact->statusLabel() }}</span>@if($artifact->deleted_at)<small class="entity-cell__meta">{{ app(\App\Services\InstanceTimezone::class)->format($artifact->deleted_at, 'd/m/Y H:i') }} · {{ $artifact->deletionReasonLabel() }}</small>@endif</td>
                                <td data-label="Tipo">{{ $artifact->type }}</td>
                                <td data-label="Tamanho"><span class="tech-value">{{ number_format($artifact->size_bytes / 1024, 1, ',', '.') }} KB</span></td>
                                <td data-label="SHA256"><code>{{ substr($artifact->sha256, 0, 12) }}…</code></td>
                                <td data-label="Criado em"><span class="tech-value">{{ app(\App\Services\InstanceTimezone::class)->format($artifact->created_at, 'd/m/Y H:i') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($artifacts->hasPages())
                <div class="pagination-container">{{ $artifacts->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
