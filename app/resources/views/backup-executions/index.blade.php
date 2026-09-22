@extends('layouts.app')
@section('title', 'Execuções — Backup Manager')
@section('page-title', 'Execuções de Backup')
@section('page-description', 'Histórico de tentativas e resultados de backup.')
@section('content')
<div class="page-width">
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Filtros</h2></div></div>
    <form method="GET" action="{{ route('backup-executions.index') }}" class="form-grid">
        <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">Todos</option>@foreach(\App\Models\BackupExecution::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ strtoupper($status) }}</option>@endforeach</select></div>
        <div class="field"><label for="origin">Origem</label><select id="origin" name="origin"><option value="">Todas</option>@foreach(\App\Models\BackupExecution::ORIGINS as $origin)<option value="{{ $origin }}" @selected(($filters['origin'] ?? '') === $origin)>{{ ucfirst($origin) }}</option>@endforeach</select></div>
        <div class="field"><label for="device_id">Equipamento</label><select id="device_id" name="device_id"><option value="">Todos</option>@foreach($devices as $device)<option value="{{ $device->id }}" @selected((string) ($filters['device_id'] ?? '') === (string) $device->id)>{{ $device->name }}</option>@endforeach</select></div>
        <div class="form-actions"><button type="submit" class="primary-button inline-button">Filtrar</button></div>
    </form>
</article>
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Histórico de execuções</h2></div></div>
    @if($executions->isEmpty())<p class="muted-text">Nenhuma execução encontrada.</p>@else
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>ID</th><th>Equipamento</th><th>Política</th><th>Origem</th><th>Status</th><th>Tentativa</th><th>Criado em</th><th>Iniciado em</th><th>Finalizado em</th></tr></thead>
        <tbody>@foreach($executions as $execution)<tr>
            <td><a class="table-action" href="{{ route('backup-executions.show', $execution) }}">#{{ $execution->id }}</a></td>
            <td>{{ $execution->device->name }}</td><td>{{ $execution->backupPolicy->name }}</td>
            <td>{{ ucfirst($execution->origin) }}</td><td><span class="badge {{ $execution->status === 'succeeded' ? 'success' : ($execution->status === 'failed' ? 'danger' : 'neutral') }}">{{ strtoupper($execution->status) }}</span></td>
            <td>{{ $execution->attempt }}</td><td>{{ app(\App\Services\InstanceTimezone::class)->format($execution->created_at, 'd/m/Y H:i') }}</td><td>{{ app(\App\Services\InstanceTimezone::class)->format($execution->started_at, 'd/m/Y H:i') ?? '—' }}</td><td>{{ app(\App\Services\InstanceTimezone::class)->format($execution->finished_at, 'd/m/Y H:i') ?? '—' }}</td>
        </tr>@endforeach</tbody>
    </table></div>
    {{ $executions->links() }}
    @endif
</article>
</div>
@endsection
