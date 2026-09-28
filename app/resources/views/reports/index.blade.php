@extends('layouts.app')

@section('title', 'Relatórios — Backup Manager')

@section('page-header')
<header class="page-header reports-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatórios</h1>
        <p class="page-header__description">Consultas operacionais com filtros e exportação em CSV.</p>
    </div>
</header>
@endsection

@section('content')
@php
    $reports = [
        'executions' => ['title' => 'Execuções', 'icon' => 'play-circle', 'tone' => 'blue', 'route' => 'reports.executions', 'description' => 'Histórico de backups com taxa de sucesso e filtros por site, equipamento, fabricante, política e erro.'],
        'devices' => ['title' => 'Equipamentos', 'icon' => 'server', 'tone' => 'blue', 'route' => 'reports.devices', 'description' => 'Último backup, última falha, saúde e falhas consecutivas por equipamento.'],
        'failures' => ['title' => 'Falhas', 'icon' => 'alert', 'tone' => 'red', 'route' => 'reports.failures', 'description' => 'Erros agrupados por código, com equipamentos mais afetados.'],
        'health' => ['title' => 'Status dos backups', 'icon' => 'database', 'tone' => 'teal', 'route' => 'backup-health.index', 'description' => 'Situação dos backups — saudável, atenção, crítico e sem histórico por equipamento.'],
        'artifacts' => ['title' => 'Artefatos', 'icon' => 'file', 'tone' => 'purple', 'route' => 'reports.artifacts', 'description' => 'Arquivos de backup armazenados, tamanho, hash e estado do ciclo de vida.'],
        'ftp' => ['title' => 'FTP', 'icon' => 'ftp', 'tone' => 'sky', 'route' => 'reports.ftp', 'description' => 'Contas de backup e servidor de arquivos: recebidos, quarentena e presos em processamento.'],
        'audit' => ['title' => 'Auditoria', 'icon' => 'shield', 'tone' => 'green', 'route' => 'audit.index', 'description' => 'Central de segurança e histórico completo de eventos administrativos.'],
    ];
    $filterKeys = [
        'executions' => ['period', 'date_from', 'date_to', 'site_id', 'device_id', 'vendor', 'backup_policy_id', 'status', 'method', 'error_code'],
        'devices' => ['site_id', 'vendor', 'status', 'policy', 'freshness'],
        'failures' => ['period', 'date_from', 'date_to', 'site_id', 'vendor'],
        'artifacts' => ['period', 'date_from', 'date_to', 'site_id', 'device_id', 'status', 'min_size', 'max_size'],
        'ftp' => [],
    ];
@endphp
<div class="reports-page">
    <div class="reports-grid" aria-label="Tipos de relatório">
        @foreach($reports as $type => $report)
            @if($type !== 'audit' || auth()->user()->can('audit.view'))
                <a class="report-card report-card--{{ $report['tone'] }}" href="{{ route($report['route']) }}">
                    <span class="report-icon"><x-icon :name="$report['icon']" /></span>
                    <div class="report-card__content">
                        <div class="report-card__heading">
                            <h2>{{ $report['title'] }}</h2>
                            <span class="report-card__count" aria-label="{{ number_format($counts[$type], 0, ',', '.') }} registros">
                                {{ number_format($counts[$type], 0, ',', '.') }} <x-icon name="arrow-right" size="sm" />
                            </span>
                        </div>
                        <p>{{ $report['description'] }}</p>
                    </div>
                </a>
            @endif
        @endforeach
    </div>

    <section class="reports-recent" aria-labelledby="reports-recent-title">
        <div class="reports-recent__header">
            <span class="reports-recent__icon"><x-icon name="clock" size="lg" /></span>
            <div>
                <h2 id="reports-recent-title">Relatórios recentes</h2>
                <p>Últimas exportações realizadas{{ auth()->user()->can('audit.view') ? '.' : ' por você.' }}</p>
            </div>
            @can('audit.view')
                <a class="reports-recent__all" href="{{ route('audit.index', ['action' => 'report.exported', 'resource_type' => 'report']) }}">Ver todos <x-icon name="arrow-right" size="sm" /></a>
            @endcan
        </div>
        <div class="table-shell reports-recent__table">
            <table class="data-table">
                <thead>
                    <tr><th scope="col">Relatório</th><th scope="col">Descrição</th><th scope="col">Gerado em</th><th scope="col">Status</th><th scope="col">Ações</th></tr>
                </thead>
                <tbody>
                    @forelse($recentReports as $event)
                        @php
                            $report = $reports[$event->resource_id];
                            $metadata = $event->metadata ?? [];
                            $filters = array_intersect_key($metadata['filters'] ?? [], array_flip($filterKeys[$event->resource_id]));
                            $reportUrl = route($report['route'], $filters);
                            $exportUrl = route($report['route'] . '.export', $filters);
                        @endphp
                        <tr>
                            <td data-label="Relatório">
                                <a class="report-entity report-card--{{ $report['tone'] }}" href="{{ $reportUrl }}">
                                    <span class="report-icon report-icon--small"><x-icon :name="$report['icon']" /></span>
                                    <strong>{{ $report['title'] }}</strong>
                                </a>
                            </td>
                            <td data-label="Descrição">
                                <strong class="report-description">CSV exportado</strong>
                                <span class="report-meta">
                                    Filtro: {{ $event->resource_id === 'devices' && $filters !== [] ? 'Personalizado' : \App\Support\ReportPeriod::label($filters['period'] ?? null) }}
                                    @if(isset($metadata['row_count']))
                                        · {{ number_format($metadata['row_count'], 0, ',', '.') }} {{ $metadata['row_count'] === 1 ? 'registro' : 'registros' }}
                                    @endif
                                </span>
                            </td>
                            <td data-label="Gerado em">
                                <time datetime="{{ $event->created_at->toIso8601String() }}">{{ app(\App\Services\InstanceTimezone::class)->format($event->created_at, 'd/m/Y H:i') }}</time>
                                <span class="report-meta">por {{ $event->actor?->name ?? 'Sistema' }}</span>
                            </td>
                            <td data-label="Status"><span class="report-status report-status--{{ $event->result === 'success' ? 'success' : 'danger' }}">{{ $event->result === 'success' ? 'Concluído' : 'Falhou' }}</span></td>
                            <td data-label="Ações">
                                <div class="report-actions">
                                    @can('reports.export')
                                        <a class="btn btn--secondary btn--sm btn--icon" href="{{ $exportUrl }}" aria-label="Exportar novamente {{ $report['title'] }} em CSV" title="Exportar novamente em CSV"><x-icon name="backup" /></a>
                                    @endcan
                                    <details class="row-menu report-row-menu">
                                        <summary aria-label="Mais ações para {{ $report['title'] }}"><x-icon name="more-horizontal" /></summary>
                                        <div class="table-actions">
                                            <a class="btn btn--ghost btn--sm" href="{{ $reportUrl }}">Abrir relatório</a>
                                            @can('audit.view')
                                                <a class="btn btn--ghost btn--sm" href="{{ route('audit.show', $event) }}">Ver detalhes</a>
                                            @endcan
                                        </div>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="reports-empty"><x-icon name="report" size="lg" /><strong>Nenhum relatório exportado ainda.</strong><span>Selecione um relatório acima e exporte em CSV para consultar o histórico aqui.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($recentReports->isNotEmpty())
            <p class="reports-recent__note">A exportação em CSV gera um novo arquivo com os dados atuais e os filtros da consulta original.</p>
        @endif
    </section>
</div>
@endsection
