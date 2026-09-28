@extends('layouts.app')
@section('title', 'Status dos backups — Backup Manager')
@section('page-title', 'Status dos backups')
@section('page-description', 'Situação dos backups dos equipamentos ativos.')
@section('content')
@php
    $statuses = ['warning' => 'Atenção', 'critical' => 'Crítico', 'unknown' => 'Sem histórico'];
    $reasons = [
        'consecutive_failures' => 'Falhas consecutivas',
        'backup_stale' => 'Backup desatualizado',
        'backup_delayed' => 'Backup atrasado',
        'scheduled_never_succeeded' => 'Backup agendado nunca concluído',
        'manual_never_backed_up' => 'Nenhum backup registrado (sem agendamento ativo)',
    ];
@endphp
<div class="page-width backup-health-page">
    <a class="backup-health-back" href="{{ route('dashboard') }}">← Voltar ao Dashboard</a>
    <div class="backup-health-counts">
        @foreach (['healthy' => 'Em dia', 'warning' => 'Atenção', 'critical' => 'Crítico', 'unknown' => 'Sem histórico'] as $status => $label)
            <div class="backup-health-count backup-health-count--{{ $status }}"><span>{{ $label }}</span><strong>{{ $summary['counts'][$status] }}</strong></div>
        @endforeach
    </div>
    <article class="panel form-panel">
        <div class="panel-header"><div><h2>Equipamentos para acompanhar</h2><p>Equipamentos inativos não entram nesta avaliação. Um equipamento manual sem backup aparece como “Sem histórico”.</p></div></div>
        @if ($devices->isEmpty())
            <p class="muted-text">Nenhum equipamento precisa de acompanhamento.</p>
        @else
            <div class="table-responsive"><table class="data-table">
                <thead><tr><th>Equipamento</th><th>Situação</th><th>Motivo</th><th>Histórico</th></tr></thead>
                <tbody>
                    @foreach ($devices as $device)
                        <tr>
                            <td>{{ $device['name'] }}</td>
                            <td><span class="badge badge--{{ \App\Support\HealthStatus::from($device['status'])->badgeVariant() }}">{{ $statuses[$device['status']] ?? $device['status'] }}</span></td>
                            <td>{{ $reasons[$device['reason']] ?? $device['reason'] }}</td>
                            <td><a class="table-action" href="{{ route('backup-executions.index', ['device_id' => $device['device_id']]) }}">Ver execuções</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            {{ $devices->links() }}
        @endif
    </article>
</div>
@endsection
