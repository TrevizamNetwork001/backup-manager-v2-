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
    $storage = $checksByName['storage']['metadata'] ?? [];
    $queue = $checksByName['queue']['metadata'] ?? [];
    $failures = $checksByName['failure']['metadata'] ?? [];
    $devices = $checksByName['devices']['metadata']['counts'] ?? [];
    $formatBytes = fn (float|int $bytes) => $bytes >= 1073741824
        ? number_format($bytes / 1073741824, 1, ',', '.').' GB'
        : number_format($bytes / 1048576, 1, ',', '.').' MB';
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

    <div class="grid grid--3 system-health-metrics" aria-label="Indicadores operacionais">
        <article class="card">
            <div class="card__body">
                <span>CPU do host</span>
                <strong>{{ $hostResources['cpu_count'] ?? '—' }}</strong>
                <small>{{ $hostResources['cpu_count'] === 1 ? 'núcleo visível' : 'núcleos visíveis' }} · Carga em 1 min: {{ isset($hostResources['load_1m']) ? number_format($hostResources['load_1m'], 2, ',', '.') : '—' }}</small>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span>Memória do host</span>
                <strong>{{ isset($hostResources['memory_used_bytes']) ? $formatBytes($hostResources['memory_used_bytes']) : '—' }}</strong>
                <small>{{ isset($hostResources['memory_total_bytes']) ? 'Em uso de '.$formatBytes($hostResources['memory_total_bytes']) : 'Capacidade indisponível' }}</small>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span>Taxa de sucesso</span>
                <strong>{{ isset($failures['success_rate_percent']) ? number_format($failures['success_rate_percent'], 1, ',', '.').'%' : '—' }}</strong>
                <small>Execuções concluídas na janela recente</small>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span>Na fila</span>
                <strong>{{ $queue['backlog'] ?? '—' }}</strong>
                <small>Execuções pendentes ou em espera</small>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span>Equipamentos em atenção</span>
                <strong>{{ isset($devices['warning'], $devices['critical']) ? $devices['warning'] + $devices['critical'] : '—' }}</strong>
                <small>Com falhas ou backup atrasado</small>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span>Uso do armazenamento</span>
                <strong>{{ isset($storage['used_percent']) ? number_format($storage['used_percent'], 1, ',', '.').'%' : '—' }}</strong>
                <small>Ocupação do volume de backups</small>
            </div>
        </article>
    </div>

    <div class="grid grid--2 system-health-overviews">
        <section class="card" aria-labelledby="services-health-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="services-health-title">Saúde dos serviços</h2>
                    <p class="card__description">Estado dos componentes principais do Backup Manager.</p>
                </div>
            </div>
            <ul class="system-health-services">
                @foreach ($report['checks'] as $check)
                    <li>
                        <span>{{ $labels[$check['check']] ?? $check['check'] }}</span>
                        <span class="badge badge--{{ $statusOf($check['status'])->badgeVariant() }}">{{ $statusOf($check['status'])->label() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
        <section class="card" aria-labelledby="storage-health-title">
            <div class="card__header">
                <div>
                    <h2 class="card__title" id="storage-health-title">Armazenamento</h2>
                    <p class="card__description">Capacidade do volume usado para os backups.</p>
                </div>
            </div>
            <div class="card__body">
                @if(isset($storage['used_percent'], $storage['free_bytes'], $storage['total_bytes']))
                    <div class="system-health-storage__value"><strong>{{ number_format($storage['used_percent'], 1, ',', '.') }}%</strong><span>utilizado</span></div>
                    <div class="system-health-storage__track" role="progressbar" aria-label="Uso do armazenamento" aria-valuenow="{{ $storage['used_percent'] }}" aria-valuemin="0" aria-valuemax="100">
                        <span style="width: {{ min(100, max(0, $storage['used_percent'])) }}%"></span>
                    </div>
                    <dl class="system-health-storage__facts">
                        <div><dt>Capacidade total</dt><dd>{{ $formatBytes($storage['total_bytes']) }}</dd></div>
                        <div><dt>Espaço utilizado</dt><dd>{{ $formatBytes($storage['total_bytes'] - $storage['free_bytes']) }}</dd></div>
                        <div><dt>Espaço disponível</dt><dd>{{ $formatBytes($storage['free_bytes']) }}</dd></div>
                    </dl>
                @else
                    <p class="card__description">Os dados de capacidade não estão disponíveis nesta verificação.</p>
                @endif
            </div>
        </section>
    </div>

    <div class="section-header">
        <div class="section-header__content">
            <h2 class="section-header__title">Detalhes das verificações</h2>
            <p class="section-header__description">Mensagens individuais e diagnóstico de cada componente.</p>
        </div>
    </div>
    <div class="grid grid--3 system-health-checks">
        @foreach ($report['checks'] as $check)
            <details class="card system-health-check">
                <summary class="card__header">
                    <h3 class="card__title">{{ $labels[$check['check']] ?? $check['check'] }}</h3>
                    <span class="badge badge--{{ $statusOf($check['status'])->badgeVariant() }}">{{ $statusOf($check['status'])->label() }}</span>
                </summary>
                <div class="card__body">
                    <p class="card__description">{{ $check['message'] }}</p>
                </div>
            </details>
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
