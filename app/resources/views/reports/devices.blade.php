@extends('layouts.app')

@section('title', 'Relatório de equipamentos — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatório de equipamentos</h1>
        <p class="page-header__description"><a href="{{ route('reports.index') }}">Relatórios</a></p>
    </div>
    @can('reports.export')
        <a class="btn btn--primary" href="{{ route('reports.devices.export', $filters) }}">Exportar CSV</a>
    @endcan
</header>
@endsection

@section('content')
<div class="stack report-detail-page">
    <section class="card">
        <div class="card__body">
            <form method="GET" action="{{ route('reports.devices') }}" class="grid grid--4">
                <div class="form-field">
                    <label class="form-label" for="status">Saúde</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">Todas</option>
                        @foreach(['healthy', 'warning', 'critical', 'unknown'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Support\HealthStatus::from($status)->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="policy">Política</label>
                    <select class="form-control" id="policy" name="policy">
                        <option value="">Todos</option>
                        <option value="with" @selected(($filters['policy'] ?? '') === 'with')>Com política</option>
                        <option value="without" @selected(($filters['policy'] ?? '') === 'without')>Sem política</option>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="freshness">Backup</label>
                    <select class="form-control" id="freshness" name="freshness">
                        <option value="">Todos</option>
                        <option value="never" @selected(($filters['freshness'] ?? '') === 'never')>Nunca fez backup</option>
                        <option value="delayed" @selected(($filters['freshness'] ?? '') === 'delayed')>Atrasado</option>
                    </select>
                </div>
                <div class="form-field" style="align-self: end;">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="table-shell" role="region" aria-label="Equipamentos" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Equipamento</th><th>Fabricante</th><th>Método</th><th>Política</th><th>Último backup</th><th>Última falha</th><th>Saúde</th><th>Falhas seguidas</th></tr></thead>
                <tbody>
                    @forelse($devices as $row)
                        <tr>
                            <td data-label="Equipamento">{{ $row['name'] }}</td>
                            <td data-label="Fabricante">{{ $row['vendor'] }}</td>
                            <td data-label="Método">{{ \App\Support\OperationalLabels::METHODS[$row['method'] ?? ''] ?? '—' }}</td>
                            <td data-label="Política">{{ $row['policy_name'] ?? '—' }}</td>
                            <td data-label="Último backup">{{ $row['last_backup_at'] ? app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row['last_backup_at'])) : 'Nunca' }}</td>
                            <td data-label="Última falha">{{ $row['last_failure_at'] ? app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row['last_failure_at'])) : '—' }}</td>
                            <td data-label="Saúde"><span class="badge badge--{{ \App\Support\HealthStatus::from($row['status'])->badgeVariant() }}">{{ \App\Support\HealthStatus::from($row['status'])->label() }}</span></td>
                            <td data-label="Falhas seguidas">{{ $row['consecutive_failures'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-table">Nenhum equipamento encontrado para os filtros selecionados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $devices->links() }}
    </section>
</div>
@endsection
