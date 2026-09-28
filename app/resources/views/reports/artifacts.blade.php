@extends('layouts.app')

@section('title', 'Relatório de artefatos — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatório de artefatos</h1>
        <p class="page-header__description"><a href="{{ route('reports.index') }}">Relatórios</a></p>
    </div>
    @can('reports.export')
        <a class="btn btn--primary" href="{{ route('reports.artifacts.export', $filters) }}">Exportar CSV</a>
    @endcan
</header>
@endsection

@section('content')
<div class="stack report-detail-page">
    <section class="card">
        <div class="card__body">
            <form method="GET" action="{{ route('reports.artifacts') }}" class="grid grid--4">
                <div class="form-field">
                    <label class="form-label" for="period">Período</label>
                    <select class="form-control" id="period" name="period">
                        <option value="">Todos</option>
                        @foreach(\App\Support\ReportPeriod::OPTIONS as $option)
                            <option value="{{ $option }}" @selected(($filters['period'] ?? '') === $option)>{{ \App\Support\ReportPeriod::label($option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">Todos</option>
                        @foreach(\App\Models\BackupArtifact::STATUSES as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Support\OperationalLabels::ARTIFACT_STATUSES[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field" style="align-self: end;">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="table-shell" role="region" aria-label="Artefatos" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Site</th><th>Equipamento</th><th>Arquivo</th><th>Tamanho</th><th>SHA256</th><th>Criado em</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($artifacts as $artifact)
                        <tr>
                            <td data-label="Site">{{ $artifact->device?->site?->name }}</td>
                            <td data-label="Equipamento">{{ $artifact->device?->name }}</td>
                            <td data-label="Arquivo">{{ $artifact->original_filename }}</td>
                            <td data-label="Tamanho">{{ number_format($artifact->size_bytes / 1024, 1) }} KB</td>
                            <td data-label="SHA256"><code>{{ substr($artifact->sha256, 0, 12) }}…</code></td>
                            <td data-label="Criado em">{{ app(\App\Services\InstanceTimezone::class)->format($artifact->created_at) }}</td>
                            <td data-label="Status"><span class="badge badge--{{ $artifact->status === 'available' ? 'success' : 'neutral' }}">{{ $artifact->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-table">Nenhum artefato encontrado para os filtros selecionados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $artifacts->links() }}
    </section>
</div>
@endsection
