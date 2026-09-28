@extends('layouts.app')

@section('title', 'Relatório de FTP — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatório de FTP</h1>
        <p class="page-header__description"><a href="{{ route('reports.index') }}">Relatórios</a></p>
    </div>
    @can('reports.export')
        <a class="btn btn--primary" href="{{ route('reports.ftp.export') }}">Exportar CSV</a>
    @endcan
</header>
@endsection

@section('content')
<div class="stack report-detail-page">
    <section class="card">
        <div class="card__header"><h2 class="card__title">Contas de backup</h2></div>
        <div class="table-shell" role="region" aria-label="Contas de backup" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Equipamento</th><th>Organização dos arquivos</th><th>Ativa</th><th>Último recebimento</th><th>Armazenados</th><th>Quarentena</th><th>Presos</th></tr></thead>
                <tbody>
                    @forelse($backupAccounts as $row)
                        <tr>
                            <td data-label="Equipamento">{{ $row['device_name'] ?? '—' }}</td>
                            <td data-label="Organização dos arquivos">{{ \App\Support\OperationalLabels::FTP_LAYOUTS[$row['home_layout'] ?? 'legacy'] ?? $row['home_layout'] }}</td>
                            <td data-label="Ativa"><span class="badge badge--{{ $row['is_active'] ? 'success' : 'neutral' }}">{{ $row['is_active'] ? 'Sim' : 'Não' }}</span></td>
                            <td data-label="Último recebimento">{{ $row['last_received_at'] ? app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row['last_received_at'])) : '—' }}</td>
                            <td data-label="Armazenados">{{ $row['stored_count'] }}</td>
                            <td data-label="Quarentena">{{ $row['quarantined_count'] }}</td>
                            <td data-label="Presos">{{ $row['stuck_count'] > 0 ? '⚠ '.$row['stuck_count'] : 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-table">Nenhuma conta de backup FTP.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Contas de servidor de arquivos</h2></div>
        <div class="table-shell" role="region" aria-label="Contas de servidor de arquivos" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Conta</th><th>Organização dos arquivos</th><th>Ativa</th><th>Último recebimento</th><th>Armazenados</th><th>Quarentena</th><th>Presos</th></tr></thead>
                <tbody>
                    @forelse($fileServerAccounts as $row)
                        <tr>
                            <td data-label="Conta">{{ $row['username'] }}</td>
                            <td data-label="Organização dos arquivos">{{ \App\Support\OperationalLabels::FTP_LAYOUTS[$row['home_layout'] ?? 'legacy'] ?? $row['home_layout'] }}</td>
                            <td data-label="Ativa"><span class="badge badge--{{ $row['is_active'] ? 'success' : 'neutral' }}">{{ $row['is_active'] ? 'Sim' : 'Não' }}</span></td>
                            <td data-label="Último recebimento">{{ $row['last_received_at'] ? app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row['last_received_at'])) : '—' }}</td>
                            <td data-label="Armazenados">{{ $row['stored_count'] }}</td>
                            <td data-label="Quarentena">{{ $row['quarantined_count'] }}</td>
                            <td data-label="Presos">{{ $row['stuck_count'] > 0 ? '⚠ '.$row['stuck_count'] : 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-table">Nenhuma conta de servidor de arquivos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
