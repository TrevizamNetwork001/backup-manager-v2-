@extends('layouts.app')

@section('title', 'Execuções — Backup Manager')
@section('page-title', 'Execuções de Backup')
@section('page-description', 'Histórico de tentativas e resultados de backup.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Execuções de backup</h1>
        <p class="page-header__description">Histórico de tentativas e resultados de backup.</p>
    </div>
</header>
@endsection

@section('content')
<div class="backup-executions-page stack">
    <section class="card" aria-labelledby="execution-filters-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="execution-filters-title">Filtros</h2>
                <p class="card__description">Encontre execuções por status, origem ou equipamento.</p>
            </div>
        </div>
        <div class="card__body">
            <form method="GET" action="{{ route('backup-executions.index') }}" class="backup-executions-filters">
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
                    <label class="form-label" for="origin">Origem</label>
                    <select class="form-control" id="origin" name="origin">
                        <option value="">Todas</option>
                        @foreach(\App\Models\BackupExecution::ORIGINS as $origin)
                            <option value="{{ $origin }}" @selected(($filters['origin'] ?? '') === $origin)>{{ $origin === 'ftp_received' ? 'FTP recebido' : ucfirst($origin) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="device_id">Equipamento</label>
                    <select class="form-control" id="device_id" name="device_id">
                        <option value="">Todos</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}" @selected((string) ($filters['device_id'] ?? '') === (string) $device->id)>{{ $device->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn--primary">Filtrar</button>
            </form>
        </div>
    </section>

    <section class="card" aria-labelledby="execution-history-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="execution-history-title">Histórico de execuções</h2>
                <p class="card__description">{{ $executions->total() }} {{ $executions->total() === 1 ? 'execução encontrada' : 'execuções encontradas' }}</p>
            </div>
        </div>
        @if($executions->isEmpty())
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true"><x-icon name="refresh" /></div>
                <h3 class="empty-state__title">Nenhuma execução encontrada</h3>
                <p class="empty-state__description">Ajuste os filtros para consultar o histórico de backups.</p>
            </div>
        @else
            <div class="table-shell" role="region" aria-label="Histórico de execuções de backup" tabindex="0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>Equipamento</th><th>Política</th><th>Origem</th><th>Status</th>
                            <th>Tentativa</th><th>Criado em</th><th>Iniciado em</th><th>Finalizado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($executions as $execution)
                            <tr>
                                <td data-label="ID"><a class="btn btn--ghost btn--sm" href="{{ route('backup-executions.show', $execution) }}">#{{ $execution->id }}</a></td>
                                <td data-label="Equipamento"><span class="entity-cell__title">{{ $execution->device->name }}</span></td>
                                <td data-label="Política">{{ $execution->backupPolicy->name }}</td>
                                <td data-label="Origem">{{ $execution->origin === 'ftp_received' ? 'FTP recebido' : ucfirst($execution->origin) }}</td>
                                <td data-label="Status"><span class="badge badge--{{ $execution->status === 'succeeded' ? 'success' : ($execution->status === 'failed' ? 'danger' : 'neutral') }}">{{ strtoupper($execution->status) }}</span></td>
                                <td data-label="Tentativa">{{ $execution->attempt }}</td>
                                <td data-label="Criado em"><span class="tech-value">{{ app(\App\Services\InstanceTimezone::class)->format($execution->created_at, 'd/m/Y H:i') }}</span></td>
                                <td data-label="Iniciado em"><span class="tech-value">{{ app(\App\Services\InstanceTimezone::class)->format($execution->started_at, 'd/m/Y H:i') ?? '—' }}</span></td>
                                <td data-label="Finalizado em"><span class="tech-value">{{ app(\App\Services\InstanceTimezone::class)->format($execution->finished_at, 'd/m/Y H:i') ?? '—' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($executions->hasPages())
                <div class="pagination-container">{{ $executions->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
