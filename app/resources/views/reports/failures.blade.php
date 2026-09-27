@extends('layouts.app')

@section('title', 'Relatório de falhas — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatório de falhas</h1>
        <p class="page-header__description"><a href="{{ route('reports.index') }}">Relatórios</a></p>
    </div>
    @can('reports.export')
        <a class="btn btn--primary" href="{{ route('reports.failures.export', $filters) }}">Exportar CSV</a>
    @endcan
</header>
@endsection

@section('content')
<div class="stack">
    <section class="card">
        <div class="card__body">
            <form method="GET" action="{{ route('reports.failures') }}" class="grid grid--4">
                <div class="form-field">
                    <label class="form-label" for="period">Período</label>
                    <select class="form-control" id="period" name="period">
                        <option value="">Todos</option>
                        @foreach(\App\Support\ReportPeriod::OPTIONS as $option)
                            <option value="{{ $option }}" @selected(($filters['period'] ?? '') === $option)>{{ \App\Support\ReportPeriod::label($option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field" style="align-self: end;">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                </div>
            </form>
        </div>
    </section>

    <div class="grid grid--2">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Top erros</h2></div>
            <div class="table-shell" role="region" aria-label="Erros por código" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Código</th><th>Ocorrências</th><th>Retryable</th><th>Última ocorrência</th></tr></thead>
                    <tbody>
                        @forelse($byErrorCode as $row)
                            <tr>
                                <td><code>{{ $row['error_code'] }}</code></td>
                                <td>{{ $row['total'] }}</td>
                                <td><span class="badge badge--{{ $row['retryable'] ? 'info' : 'neutral' }}">{{ $row['retryable'] ? 'Sim' : 'Não' }}</span></td>
                                <td>{{ app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row['last_seen_at'])) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty-table">Nenhuma falha na janela selecionada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Equipamentos mais afetados</h2></div>
            <div class="table-shell" role="region" aria-label="Equipamentos mais afetados" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Equipamento</th><th>Vendor</th><th>Falhas</th><th>Última ocorrência</th></tr></thead>
                    <tbody>
                        @forelse($byDevice as $row)
                            <tr>
                                <td>{{ $row->name }}</td>
                                <td>{{ $row->vendor }}</td>
                                <td>{{ $row->total }}</td>
                                <td>{{ app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row->last_seen_at)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty-table">Nenhuma falha na janela selecionada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
@endsection
