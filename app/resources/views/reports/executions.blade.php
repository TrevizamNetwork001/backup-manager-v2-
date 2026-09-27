@extends('layouts.app')

@section('title', 'Relatório de execuções — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatório de execuções</h1>
        <p class="page-header__description">{{ $summary['period_label'] }} — <a href="{{ route('reports.index') }}">Relatórios</a></p>
    </div>
    @can('reports.export')
        <a class="btn btn--primary" href="{{ route('reports.executions.export', $filters) }}">Exportar CSV</a>
    @endcan
</header>
@endsection

@section('content')
<div class="stack">
    <section class="card">
        <div class="card__body">
            <form method="GET" action="{{ route('reports.executions') }}" class="grid grid--4">
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
                    <label class="form-label" for="date_from">De</label>
                    <input class="form-control" type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="form-field">
                    <label class="form-label" for="date_to">Até</label>
                    <input class="form-control" type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="form-field">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">Todos</option>
                        @foreach(\App\Models\BackupExecution::STATUSES as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ strtoupper($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="error_code">Código de erro</label>
                    <input class="form-control" type="text" id="error_code" name="error_code" value="{{ $filters['error_code'] ?? '' }}" placeholder="ex.: SSH_TIMEOUT">
                </div>
                <div class="form-field" style="align-self: end;">
                    <button type="submit" class="btn btn--primary">Filtrar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="grid grid--4">
        <article class="card"><div class="card__body"><span class="card__description">Total</span><h2 class="card__title">{{ $summary['total'] }}</h2></div></article>
        <article class="card"><div class="card__body"><span class="card__description">Sucesso</span><h2 class="card__title">{{ $summary['succeeded'] }}</h2></div></article>
        <article class="card"><div class="card__body"><span class="card__description">Falhas (falhou + expirou)</span><h2 class="card__title">{{ $summary['failed'] + $summary['timed_out'] }}</h2></div></article>
        <article class="card"><div class="card__body"><span class="card__description">Taxa de sucesso</span><h2 class="card__title">{{ $summary['success_rate_percent'] !== null ? $summary['success_rate_percent'].'%' : '—' }}</h2></div></article>
    </section>

    <section class="card">
        <div class="table-shell" role="region" aria-label="Execuções" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Data/Hora</th><th>Site</th><th>Equipamento</th><th>Política</th><th>Status</th><th>Tentativa</th><th>Tamanho</th><th>Erro</th></tr></thead>
                <tbody>
                    @forelse($executions as $execution)
                        <tr>
                            <td>{{ app(\App\Services\InstanceTimezone::class)->format($execution->created_at) }}</td>
                            <td>{{ $execution->device?->site?->name }}</td>
                            <td>{{ $execution->device?->name }}</td>
                            <td>{{ $execution->backupPolicy?->name }}</td>
                            <td><span class="badge badge--{{ in_array($execution->status, ['succeeded'], true) ? 'success' : (in_array($execution->status, ['failed', 'timed_out'], true) ? 'danger' : 'neutral') }}">{{ strtoupper($execution->status) }}</span></td>
                            <td>{{ $execution->attempt }}</td>
                            <td>{{ $execution->artifact?->size_bytes ? number_format($execution->artifact->size_bytes / 1024, 1).' KB' : '—' }}</td>
                            <td>{{ $execution->error_code }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-table">Nenhuma execução encontrada para os filtros selecionados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $executions->links() }}
    </section>
</div>
@endsection
