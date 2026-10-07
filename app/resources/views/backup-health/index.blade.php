@extends('layouts.app')
@section('title', 'Status dos backups — Backup Manager')
@section('page-title', 'Status dos backups')
@section('page-description', 'Situação dos backups dos equipamentos ativos.')
@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Status dos backups</h1>
        <p class="page-header__description">Situação dos backups dos equipamentos ativos.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn btn--ghost" href="{{ route('dashboard') }}">Voltar ao Dashboard</a>
    </div>
</header>
@endsection
@section('content')
@php
    $statuses = ['warning' => 'Atenção', 'critical' => 'Crítico', 'unknown' => 'Sem histórico'];
    $reasons = [
        'consecutive_failures' => 'Falhas consecutivas',
        'latest_backup_failed' => 'Última tentativa falhou',
        'backup_stale' => 'Backup desatualizado',
        'backup_delayed' => 'Backup atrasado',
        'scheduled_never_succeeded' => 'Backup agendado nunca concluído',
        'manual_never_backed_up' => 'Nenhum backup registrado (sem agendamento ativo)',
        'ftp_never_received' => 'FTP esperado ainda não recebido',
        'ftp_backup_delayed' => 'FTP esperado está atrasado',
        'ftp_backup_stale' => 'FTP esperado está muito atrasado',
    ];
@endphp
<div class="backup-health-page stack">
    <div class="backup-health-counts">
        @foreach (['healthy' => 'Em dia', 'warning' => 'Atenção', 'critical' => 'Crítico', 'unknown' => 'Sem histórico'] as $status => $label)
            <div class="card backup-health-count backup-health-count--{{ $status }}"><span>{{ $label }}</span><strong>{{ $summary['counts'][$status] }}</strong></div>
        @endforeach
    </div>
    <section class="card" aria-labelledby="backup-health-devices-title">
        <div class="card__header"><div><h2 class="card__title" id="backup-health-devices-title">Equipamentos para acompanhar</h2><p class="card__description">Equipamentos inativos não entram nesta avaliação. Um equipamento manual sem backup aparece como “Sem histórico”. Atraso FTP significa ausência de arquivo novo; o equipamento pode não enviar quando não houve alteração.</p></div></div>
        @if ($devices->isEmpty())
            <div class="empty-state"><div class="empty-state__icon" aria-hidden="true"><x-icon name="check-circle" /></div><h3 class="empty-state__title">Nenhum equipamento precisa de acompanhamento.</h3></div>
        @else
            <div class="table-shell" role="region" aria-label="Equipamentos para acompanhar" tabindex="0"><table class="data-table">
                <thead><tr><th>Equipamento</th><th>Situação</th><th>Motivo</th><th>Último FTP</th><th>Histórico</th></tr></thead>
                <tbody>
                    @foreach ($devices as $device)
                        <tr>
                            <td data-label="Equipamento"><span class="entity-cell__title">{{ $device['name'] }}</span></td>
                            <td data-label="Situação"><span class="badge badge--{{ \App\Support\HealthStatus::from($device['status'])->badgeVariant() }}">{{ $statuses[$device['status']] ?? $device['status'] }}</span></td>
                            <td data-label="Motivo">{{ $reasons[$device['reason']] ?? $device['reason'] }}</td>
                            <td data-label="Último FTP">{{ $device['last_ftp_success_at'] ? app(\App\Services\InstanceTimezone::class)->format($device['last_ftp_success_at']) : '—' }}</td>
                            <td data-label="Histórico"><a class="table-action" href="{{ route('backup-executions.index', ['device_id' => $device['device_id']]) }}">Ver execuções</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if($devices->hasPages())
                <div class="pagination-container">{{ $devices->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
