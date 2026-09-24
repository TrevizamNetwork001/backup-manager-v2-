@extends('layouts.app')

@php
    $presenter = app(\App\Services\AuditPresenter::class);
    $badge = $presenter->resultBadge($auditEvent->result);
    $sanitizedMetadata = $presenter->sanitizeMetadata($auditEvent->metadata ?? []);
    $metadataRows = $presenter->metadataRows($sanitizedMetadata);
@endphp

@section('title', 'Evento de auditoria #'.$auditEvent->id.' — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Evento de auditoria #{{ $auditEvent->id }}</h1>
        <p class="page-header__description">{{ $presenter->actionLabel($auditEvent->action) }}</p>
    </div>
    <div class="page-header__actions">
        <a href="{{ route('audit.index') }}" class="btn btn--ghost">← Voltar</a>
    </div>
</header>
@endsection

@section('content')
<div class="audit-detail stack">

    <div class="table-shell" role="region" aria-label="Dados do evento" tabindex="0">
        <table class="data-table">
            <tbody>
                <tr><th>ID</th><td>{{ $auditEvent->id }}</td></tr>
                <tr><th>Data/hora</th><td>{{ app(\App\Services\InstanceTimezone::class)->format($auditEvent->created_at) }}</td></tr>
                <tr><th>Usuário</th><td>{{ $presenter->actorLabel($auditEvent->actor) }}</td></tr>
                <tr><th>Ação</th><td>{{ $presenter->actionLabel($auditEvent->action) }} <code class="tech-value">{{ $auditEvent->action }}</code></td></tr>
                <tr><th>Recurso</th><td>{{ $presenter->resourceTypeLabel($auditEvent->resource_type) }} <code class="tech-value">{{ $auditEvent->resource_type }}</code></td></tr>
                <tr><th>ID do recurso</th><td>{{ $auditEvent->resource_id ?? '—' }}</td></tr>
                <tr><th>Rótulo do recurso</th><td>{{ $auditEvent->resource_label ?? '—' }}</td></tr>
                <tr><th>Resultado</th><td><span class="badge badge--{{ $badge['variant'] }}">{{ $badge['label'] }}</span> <code class="tech-value">{{ $auditEvent->result }}</code></td></tr>
                <tr><th>IP</th><td>{{ $auditEvent->ip_address ?? '—' }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="table-shell" role="region" aria-label="Metadados do evento" tabindex="0">
        <table class="data-table">
            <thead><tr><th colspan="2">Detalhes</th></tr></thead>
            <tbody>
                @forelse($metadataRows as $row)
                    <tr><th>{{ $row['label'] }}</th><td>@if(str_contains($row['value'], "\n"))<pre class="audit-metadata-block">{{ $row['value'] }}</pre>@else{{ $row['value'] }}@endif</td></tr>
                @empty
                    <tr><td colspan="2">Nenhum detalhe adicional.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
