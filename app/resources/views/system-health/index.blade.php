@extends('layouts.app')

@section('title', 'Saúde do sistema — Backup Manager')

@section('page-header')
<span class="health-screen-reader">Diagnóstico operacional somente leitura.</span>
@endsection

@section('content')
@php
    $statusOf = fn (string $status) => \App\Support\HealthStatus::from($status);
    // O processador de tarefas só é avaliado enquanto há execução rodando; sem nenhuma, "ocioso" é o estado normal.
    $isIdleWorker = fn (array $check) => $check['check'] === 'worker' && ($check['code'] ?? '') === 'idle';
    $checkLabel = fn (array $check) => $isIdleWorker($check) ? 'Ocioso' : $statusOf($check['status'])->label();
    $checkMessage = fn (array $check) => $isIdleWorker($check)
        ? 'Nenhum backup em andamento. Este item só é avaliado enquanto há uma execução rodando; ocioso é o estado normal.'
        : $check['message'];
    $checksByName = collect($report['checks'])->keyBy('check');
    $statusCounts = collect($report['checks'])->countBy('status');
    $overall = $statusOf($report['overall_status']);
    $storage = $checksByName['storage']['metadata'] ?? [];
    $queue = $checksByName['queue']['metadata'] ?? [];
    $failures = $checksByName['failure']['metadata'] ?? [];
    $devices = $checksByName['devices']['metadata']['counts'] ?? [];
    $formatBytes = fn (float|int $bytes) => $bytes >= 1073741824
        ? number_format($bytes / 1073741824, 1, ',', '.').' GB'
        : number_format($bytes / 1048576, 1, ',', '.').' MB';
    $labels = [
        'database' => 'Banco de dados', 'redis' => 'Redis', 'engine' => 'Motor de backup',
        'driver_registry' => 'Drivers de backup', 'worker' => 'Processador de tarefas', 'scheduler' => 'Agendador',
        'queue' => 'Fila', 'stale_jobs' => 'Tarefas presas', 'retry' => 'Retentativas',
        'failure' => 'Taxa de sucesso', 'devices' => 'Equipamentos', 'storage' => 'Armazenamento',
        'ftp' => 'FTP', 'file_server' => 'Servidor de arquivos', 'retention' => 'Retenção',
    ];
    $icons = ['database' => 'database', 'redis' => 'layers', 'engine' => 'settings', 'driver_registry' => 'report', 'worker' => 'server', 'scheduler' => 'clock', 'queue' => 'layers', 'stale_jobs' => 'clock', 'retry' => 'refresh', 'failure' => 'check-circle', 'devices' => 'server', 'storage' => 'hard-drive', 'ftp' => 'ftp', 'file_server' => 'folder', 'retention' => 'database'];
    $overviewText = match ($overall->value) {
        'healthy' => 'Todos os componentes estão operando normalmente. O sistema de backup está estável e sem alertas no momento.',
        'warning' => 'Há componentes que precisam de atenção. Confira os alertas e o diagnóstico antes da próxima operação.',
        'critical' => 'Há componentes em estado crítico. Consulte os alertas para identificar os problemas encontrados.',
        default => 'Alguns componentes estão sem dados recentes de verificação. Consulte o diagnóstico para acompanhar a situação.',
    };
    $alerts = collect($report['checks'])->filter(fn ($check) => $check['status'] !== 'healthy');
    $memoryPercent = isset($hostResources['memory_used_bytes'], $hostResources['memory_total_bytes']) && $hostResources['memory_total_bytes'] > 0
        ? round(min(100, max(0, $hostResources['memory_used_bytes'] / $hostResources['memory_total_bytes'] * 100))) : null;
    $cpuPercent = isset($hostResources['cpu_percent']) ? min(100, max(0, (int) $hostResources['cpu_percent'])) : null;
    $storagePercent = isset($storage['used_percent']) ? min(100, max(0, $storage['used_percent'])) : null;
    $problemDevices = $checksByName['devices']['metadata']['problem_devices'] ?? [];
    $withoutHistory = collect($problemDevices)->filter(fn ($device) => in_array($device['reason'], ['manual_never_backed_up', 'scheduled_never_succeeded'], true));
    $deviceReasons = [
        'consecutive_failures' => 'Falhas consecutivas', 'backup_stale' => 'Backup desatualizado',
        'latest_backup_failed' => 'Última tentativa falhou',
        'backup_delayed' => 'Backup atrasado', 'scheduled_never_succeeded' => 'Backup agendado nunca concluído',
        'manual_never_backed_up' => 'Nunca fez backup (manual)',
    ];
@endphp
<div class="system-health-page health-reference">
    <section class="health-overview health-panel health-state--{{ $overall->value }}" aria-label="Status geral">
        <div class="health-overview__pulse"><x-icon name="health-pulse" /></div>
        <div class="health-overview__message">
            <h1>Saúde do sistema</h1>
            <h2>{{ $overall->label() }}</h2>
            <p>{{ $overviewText }}</p>
        </div>
        <div class="health-overview__summary">
            <div class="health-overview__checked"><x-icon name="calendar" size="lg" /><div><span>Última verificação</span><time datetime="{{ $report['checked_at'] }}">{{ app(\App\Services\InstanceTimezone::class)->format(\Illuminate\Support\Carbon::parse($report['checked_at']), 'd/m/Y H:i:s') }}</time></div></div>
            <div class="health-counts" aria-label="Resumo das verificações">
                @foreach(['healthy' => 'saudáveis', 'warning' => 'em atenção', 'critical' => 'críticas', 'unknown' => 'desconhecidas'] as $status => $label)
                    <span class="health-dot health-dot--{{ $status }}">{{ $statusCounts[$status] ?? 0 }} {{ $label }}</span>
                @endforeach
            </div>
        </div>
        <div class="health-overview__actions">
            <span class="badge badge--{{ $overall->badgeVariant() }}">{{ $overall->label() }}</span>
            <a class="btn btn--secondary health-diagnostic-link" href="#health-diagnostic" data-health-target="health-diagnostic"><x-icon name="report" />Ver diagnóstico técnico<x-icon name="arrow-right" size="sm" /></a>
        </div>
    </section>

    <div class="health-kpis" aria-label="Indicadores operacionais">
        <article class="health-panel health-kpi">
            <span class="health-icon"><x-icon name="cpu" /></span>
            <div class="health-kpi__content"><h2>CPU do host</h2><strong>{{ $cpuPercent !== null ? $cpuPercent.'%' : '—' }}</strong><p>Em uso · {{ $hostResources['cpu_count'] ?? '—' }} {{ ($hostResources['cpu_count'] ?? null) === 1 ? 'núcleo visível' : 'núcleos visíveis' }}</p>
                @if($cpuPercent !== null)
                    <div class="health-meter"><div class="health-meter__track" role="progressbar" aria-label="Uso da CPU" aria-valuenow="{{ $cpuPercent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $cpuPercent }}%"></span></div><span>{{ $cpuPercent }}%</span></div>
                @endif
                <small>Carga em 1 min: {{ isset($hostResources['load_1m']) ? number_format($hostResources['load_1m'], 2, ',', '.') : '—' }}</small></div>
        </article>
        <article class="health-panel health-kpi">
            <span class="health-icon"><x-icon name="memory" /></span>
            <div class="health-kpi__content"><h2>Memória do host</h2><strong>{{ isset($hostResources['memory_used_bytes']) ? $formatBytes($hostResources['memory_used_bytes']) : '—' }}</strong><p>{{ isset($hostResources['memory_total_bytes']) ? 'Em uso de '.$formatBytes($hostResources['memory_total_bytes']) : 'Capacidade indisponível' }}</p>
                @if($memoryPercent !== null)
                    <div class="health-meter"><div class="health-meter__track" role="progressbar" aria-label="Uso da memória" aria-valuenow="{{ $memoryPercent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $memoryPercent }}%"></span></div><span>{{ $memoryPercent }}%</span></div>
                @endif
            </div>
        </article>
        <article class="health-panel health-kpi">
            <span class="health-icon health-icon--{{ $checksByName['failure']['status'] ?? 'unknown' }}"><x-icon name="check-circle" /></span>
            <div class="health-kpi__content"><h2>Taxa de sucesso</h2><strong>{{ isset($failures['success_rate_percent']) ? number_format($failures['success_rate_percent'], 1, ',', '.').'%' : '—' }}</strong><p>Execuções concluídas na janela recente</p>
                @if(isset($failures['success_rate_percent']))
                    <div class="health-meter__track health-meter__track--{{ $checksByName['failure']['status'] }}" role="progressbar" aria-label="Taxa de sucesso" aria-valuenow="{{ min(100, max(0, $failures['success_rate_percent'])) }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ min(100, max(0, $failures['success_rate_percent'])) }}%"></span></div>
                @endif
            </div>
        </article>
        <article class="health-panel health-kpi">
            <span class="health-icon"><x-icon name="hard-drive" /></span>
            <div class="health-kpi__content"><h2>Uso do armazenamento</h2><strong>{{ $storagePercent !== null ? number_format($storagePercent, 1, ',', '.').'%' : '—' }}</strong><p>Ocupação do volume de backups</p>
                @if($storagePercent !== null)
                    <div class="health-meter__track" role="progressbar" aria-label="Uso do armazenamento" aria-valuenow="{{ $storagePercent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $storagePercent }}%"></span></div>
                @endif
                @if(isset($storage['total_bytes'], $storage['free_bytes']))
                    <small class="health-kpi__capacity">{{ $formatBytes($storage['total_bytes'] - $storage['free_bytes']) }} de {{ $formatBytes($storage['total_bytes']) }}</small>
                @else
                    <small>Os dados de capacidade não estão disponíveis nesta verificação.</small>
                @endif
            </div>
        </article>
    </div>

    <div class="health-columns">
        <section class="health-panel health-services" aria-labelledby="services-health-title">
            <div class="health-panel__heading"><span class="health-icon"><x-icon name="settings" /></span><div><h2 id="services-health-title">Saúde dos serviços</h2><p>Estado dos componentes principais do Backup Manager.</p></div></div>
            <ul>
                @foreach(['database', 'redis', 'engine', 'driver_registry', 'worker', 'scheduler', 'ftp', 'file_server'] as $name)
                    @if(isset($checksByName[$name]))
                        @php $check = $checksByName[$name]; @endphp
                        <li><a href="#health-check-{{ $name }}" data-health-target="health-check-{{ $name }}"><x-icon :name="$icons[$name]" /><span>{{ $labels[$name] }}</span><span class="badge badge--{{ $statusOf($check['status'])->badgeVariant() }}">{{ $checkLabel($check) }}</span></a></li>
                    @endif
                @endforeach
            </ul>
        </section>
        <div class="health-right-column">
            <section class="health-panel health-alerts" aria-labelledby="health-alerts-title">
                <div class="health-panel__heading"><span class="health-icon health-icon--warning"><x-icon name="bell" /></span><div><h2 id="health-alerts-title">Alertas e atenção</h2><p>Apenas componentes com problemas ou sem dados recentes.</p></div></div>
                <div class="health-counts health-alerts__counts">
                    @foreach(['critical' => 'críticas', 'warning' => 'em atenção', 'unknown' => 'desconhecidas'] as $status => $label)
                        <span class="health-dot health-dot--{{ $status }}">{{ $statusCounts[$status] ?? 0 }} {{ $label }}</span>
                    @endforeach
                </div>
                <ul>
                    @forelse($alerts as $check)
                        <li><a href="#health-check-{{ $check['check'] }}" data-health-target="health-check-{{ $check['check'] }}"><x-icon :name="$icons[$check['check']] ?? 'info'" /><span>{{ $labels[$check['check']] ?? $check['check'] }}</span><span class="badge badge--{{ $statusOf($check['status'])->badgeVariant() }}">{{ $checkLabel($check) }}</span><p>{{ $checkMessage($check) }}</p><x-icon name="chevron-right" size="sm" /></a></li>
                    @empty
                        <li class="health-empty"><x-icon name="check-circle" />Nenhum componente em alerta nesta verificação.</li>
                    @endforelse
                </ul>
            </section>
            <section class="health-panel health-no-history" aria-labelledby="health-no-history-title">
                <div class="health-panel__heading"><span class="health-icon"><x-icon name="hard-drive" /></span><div><h2 id="health-no-history-title">Equipamentos sem histórico</h2><p>Equipamentos que ainda não possuem backup concluído.</p></div><a class="btn btn--secondary btn--sm" href="{{ route('backup-health.index') }}">Ver todos</a></div>
                <div class="table-shell">
                    <table class="data-table"><thead><tr><th scope="col">Equipamento</th><th scope="col">Motivo</th></tr></thead><tbody>
                        @forelse($withoutHistory->take(4) as $device)
                            <tr><td data-label="Equipamento"><a href="{{ route('backup-executions.index', ['device_id' => $device['device_id']]) }}">{{ $device['name'] }}</a></td><td data-label="Motivo">{{ $deviceReasons[$device['reason']] }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="health-empty">Nenhum equipamento sem backup concluído nesta amostra.</td></tr>
                        @endforelse
                    </tbody></table>
                </div>
                @if(($checksByName['devices']['metadata']['problem_devices_total'] ?? 0) > count($problemDevices) || $withoutHistory->count() > 4)
                    <p class="health-sample-note">Amostra do diagnóstico. Consulte todos os equipamentos em Status dos backups.</p>
                @endif
            </section>
        </div>
    </div>

    <details class="health-panel health-diagnostic" id="health-diagnostic">
        <summary><span><x-icon name="report" />Diagnóstico técnico</span><x-icon name="chevron-down" /></summary>
        <div class="health-diagnostic__body">
            <h2 class="section-header__title">Detalhes das verificações</h2>
            <p class="card__description">Mensagens individuais e diagnóstico de cada componente.</p>
            <div class="health-extra-metrics"><div><span>Na fila</span><strong>{{ $queue['backlog'] ?? '—' }}</strong></div><div><span>Equipamentos em atenção</span><strong>{{ isset($devices['warning'], $devices['critical']) ? $devices['warning'] + $devices['critical'] : '—' }}</strong></div></div>
            @if(isset($storage['free_bytes'], $storage['total_bytes']))
                <p class="card__description">Armazenamento: {{ $formatBytes($storage['free_bytes']) }} disponíveis de {{ $formatBytes($storage['total_bytes']) }}.</p>
            @endif
            <div class="grid grid--3 system-health-checks">
                @foreach($report['checks'] as $check)
                    <details class="card system-health-check" id="health-check-{{ $check['check'] }}"><summary class="card__header"><h3 class="card__title">{{ $labels[$check['check']] ?? $check['check'] }}</h3><span class="badge badge--{{ $statusOf($check['status'])->badgeVariant() }}">{{ $checkLabel($check) }}</span></summary><div class="card__body"><p class="card__description">{{ $checkMessage($check) }}</p></div></details>
                @endforeach
            </div>
            @if($report['alerts'] !== [])
                <p class="card__description"><strong>Condições ativas:</strong> {{ implode(', ', $report['alerts']) }}</p>
            @endif
    @php $staleJobs = $checksByName['stale_jobs']['metadata'] ?? []; @endphp
    @if (($staleJobs['count'] ?? 0) > 0)
        <div class="card">
            <div class="card__header"><h2 class="card__title">Tarefas presas</h2></div>
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
            'latest_backup_failed' => 'última tentativa falhou',
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
        <summary class="card__header" style="cursor: pointer;"><span class="card__title">Relatório técnico completo</span></summary>
        <div class="card__body">
            <pre style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </details>

        </div>
    </details>
</div>
<script>
document.querySelectorAll('[data-health-target]').forEach(link => {
    link.addEventListener('click', event => {
        const target = document.getElementById(link.dataset.healthTarget);
        const diagnostic = document.getElementById('health-diagnostic');
        if (!target || !diagnostic) return;
        event.preventDefault();
        diagnostic.open = true;
        if (target instanceof HTMLDetailsElement) target.open = true;
        target.scrollIntoView({ block: 'start' });
        target.querySelector('summary')?.focus({ preventScroll: true });
    });
});
</script>
@endsection
