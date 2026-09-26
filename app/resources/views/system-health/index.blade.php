@extends('layouts.app')

@section('title', 'Saúde do sistema — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Saúde do sistema</h1>
        <p class="page-header__description">Diagnóstico operacional somente leitura — engine, fila, storage, FTP e retenção.</p>
    </div>
</header>
@endsection

@php
    $statusOf = fn (string $status) => \App\Support\HealthStatus::from($status);
    $checksByName = collect($report['checks'])->keyBy('check');
    $statusCounts = collect($report['checks'])->countBy('status');
    $labels = [
        'database' => 'Banco de dados', 'redis' => 'Redis', 'engine' => 'Engine',
        'driver_registry' => 'Drivers', 'worker' => 'Worker', 'scheduler' => 'Scheduler',
        'queue' => 'Fila', 'stale_jobs' => 'Jobs presos', 'retry' => 'Retentativas',
        'failure' => 'Taxa de sucesso', 'devices' => 'Equipamentos', 'storage' => 'Armazenamento',
        'ftp' => 'FTP', 'file_server' => 'File server', 'retention' => 'Retenção',
    ];
@endphp

@section('content')
<div class="system-health-page stack">

    <section class="card system-health-overview" aria-labelledby="overall-health-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="overall-health-title">Status geral</h2>
                <p class="card__description">Verificado em {{ \Illuminate\Support\Carbon::parse($report['checked_at'])->format('d/m/Y H:i:s') }}</p>
            </div>
            <span class="badge badge--{{ $statusOf($report['overall_status'])->badgeVariant() }}">{{ $statusOf($report['overall_status'])->label() }}</span>
        </div>
        <div class="card__body">
            <div class="system-health-overview__counts" aria-label="Resumo das verificações">
                <span><strong>{{ $statusCounts['healthy'] ?? 0 }}</strong> saudáveis</span>
                <span><strong>{{ $statusCounts['warning'] ?? 0 }}</strong> em atenção</span>
                <span><strong>{{ $statusCounts['critical'] ?? 0 }}</strong> críticas</span>
                <span><strong>{{ $statusCounts['unknown'] ?? 0 }}</strong> desconhecidas</span>
            </div>
            @if ($report['alerts'] !== [])
                <p class="system-health-overview__alerts"><strong>Condições ativas:</strong> {{ implode(', ', $report['alerts']) }}</p>
            @endif
        </div>
    </section>

    <div class="section-header">
        <div class="section-header__content">
            <h2 class="section-header__title">Verificações</h2>
            <p class="section-header__description">Acompanhamento dos serviços e recursos da instância.</p>
        </div>
    </div>
    <div class="grid grid--3 system-health-checks">
        @foreach ($report['checks'] as $check)
            <article class="card system-health-check">
                <div class="card__header">
                    <h3 class="card__title">{{ $labels[$check['check']] ?? $check['check'] }}</h3>
                    <span class="badge badge--{{ $statusOf($check['status'])->badgeVariant() }}">{{ $statusOf($check['status'])->label() }}</span>
                </div>
                <div class="card__body">
                    <p class="card__description">{{ $check['message'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    @php $staleJobs = $checksByName['stale_jobs']['metadata'] ?? []; @endphp
    @if (($staleJobs['count'] ?? 0) > 0)
        <div class="card">
            <div class="card__header"><h2 class="card__title">Jobs presos</h2></div>
            <div class="card__body">
                <p>{{ $staleJobs['count'] }} execução(ões) aguardando recuperação automática — a mais antiga (#{{ $staleJobs['oldest_execution_id'] }}) há {{ $staleJobs['oldest_age_seconds'] }}s.</p>
                <p class="card__description">Equipamentos afetados: {{ implode(', ', $staleJobs['affected_device_ids'] ?? []) }}</p>
            </div>
        </div>
    @endif

    @php
        $problemDevices = $checksByName['devices']['metadata']['problem_devices'] ?? [];
        $deviceReasons = [
            'consecutive_failures' => 'falhas consecutivas', 'backup_stale' => 'backup desatualizado',
            'backup_delayed' => 'backup atrasado', 'scheduled_never_succeeded' => 'nunca concluiu um backup agendado',
            'manual_never_backed_up' => 'nunca fez backup (manual)',
        ];
    @endphp
    @if ($problemDevices !== [])
        <div class="card">
            <div class="card__header"><h2 class="card__title">Equipamentos com problemas</h2></div>
            <div class="table-shell" role="region" aria-label="Equipamentos com problemas" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Equipamento</th><th>Status</th><th>Motivo</th></tr></thead>
                    <tbody>
                        @foreach ($problemDevices as $device)
                            <tr>
                                <td>{{ $device['name'] }}</td>
                                <td><span class="badge badge--{{ $statusOf($device['status'])->badgeVariant() }}">{{ $statusOf($device['status'])->label() }}</span></td>
                                <td>{{ $deviceReasons[$device['reason']] ?? $device['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @php
        $topErrors = array_merge(
            $checksByName['failure']['metadata']['top_error_codes'] ?? [],
            $checksByName['retry']['metadata']['top_error_codes'] ?? []
        );
    @endphp
    @if ($topErrors !== [])
        <div class="card">
            <div class="card__header"><h2 class="card__title">Principais erros</h2></div>
            <div class="card__body">
                <ul>
                    @foreach ($topErrors as $code => $count)
                        <li>{{ $code }} — {{ $count }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <details class="card">
        <summary class="card__header" style="cursor: pointer;"><span class="card__title">Diagnóstico técnico</span></summary>
        <div class="card__body">
            <pre style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </details>

</div>
@endsection
