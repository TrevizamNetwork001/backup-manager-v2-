@extends('layouts.app')
@section('title', 'Dashboard — Backup Manager')
@section('page-title', 'Dashboard')
@section('page-description', 'Visão geral do ambiente de backup da infraestrutura.')
@section('content')
<div class="reference-dashboard">
    <div class="dashboard-date">{{ ucfirst($localNow->locale('pt_BR')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }} <span>◷ {{ $localNow->format('H:i') }}</span></div>
    <div class="dashboard-kpis">
        @foreach ([
            ['Sites / POPs', $siteCount, 'Total cadastrados', 'sites.index', 'server', 'blue'],
            ['Equipamentos', $deviceCount, 'Total cadastrados', 'devices.index', 'server', 'sky'],
            ['Backups (24h)', $successCount, 'Execuções concluídas', 'backup-executions.index', 'file', 'green'],
            ['Falhas (24h)', $failureCount, 'Execuções com erro', 'backup-executions.index', 'error', 'red'],
            ['Artefatos', $artifactCount, 'Arquivos armazenados', 'backup-artifacts.index', 'folder', 'amber']
        ] as [$label, $value, $detail, $route, $icon, $color])
            <a class="reference-stat" href="{{ route($route) }}"><span class="reference-stat__icon {{ $color }}"><x-icon :name="$icon" /></span><span><small>{{ $label }}</small><strong>{{ number_format($value, 0, ',', '.') }}</strong><em>{{ $detail }}</em></span></a>
        @endforeach
    </div>
    <div class="dashboard-overview">
        <section class="reference-panel chart-panel">
            <h2>Execuções de backup <small>{{ $chartLabel }}</small></h2>
            <form method="GET" action="{{ route('dashboard') }}" class="chart-filters">
                <label>Período
                    <select name="period" aria-label="Período do gráfico">
                        <option value="7d" @selected(old('period', $period) === '7d')>Últimos 7 dias</option>
                        <option value="14d" @selected(old('period', $period) === '14d')>Últimos 14 dias</option>
                        <option value="30d" @selected(old('period', $period) === '30d')>Últimos 30 dias</option>
                        <option value="custom" @selected(old('period', $period) === 'custom')>Datas específicas</option>
                    </select>
                </label>
                <label>De <input type="date" name="start_date" value="{{ old('start_date', $chartStart->format('Y-m-d')) }}" onchange="this.form.elements.period.value='custom'"></label>
                <label>Até <input type="date" name="end_date" value="{{ old('end_date', $chartEnd->format('Y-m-d')) }}" onchange="this.form.elements.period.value='custom'"></label>
                <button type="submit">Aplicar</button>
            </form>
            @error('period') <p class="chart-filters__error">{{ $message }}</p> @enderror
            @error('start_date') <p class="chart-filters__error">{{ $message }}</p> @enderror
            @error('end_date') <p class="chart-filters__error">{{ $message }}</p> @enderror
            <div class="backup-chart-scroll"><div class="backup-chart" role="img" aria-label="Execuções de backup de {{ $chartStart->format('d/m/Y') }} até {{ $chartEnd->format('d/m/Y') }}" style="--chart-days: {{ $days->count() }}">
                @foreach ($days as $day)
                    @php $total = $day['success'] + $day['failure'] + $day['other']; @endphp
                    <div class="backup-chart__day" title="{{ $day['label'] }}: {{ $day['success'] }} concluídas, {{ $day['failure'] }} falhas, {{ $day['other'] }} outras">
                        <div class="backup-chart__track"><div class="backup-chart__bar" style="height: {{ $total ? max(4, $total / $chartMax * 100) : 0 }}%"><span class="is-success" style="flex: {{ $day['success'] }}"></span><span class="is-other" style="flex: {{ $day['other'] }}"></span><span class="is-failure" style="flex: {{ $day['failure'] }}"></span></div></div><span>{{ $day['label'] }}</span>
                    </div>
                @endforeach
            </div></div><div class="chart-legend"><span><i class="green-dot"></i>Concluído</span><span><i class="amber-dot"></i>Outros</span><span><i class="red-dot"></i>Erro</span></div>
        </section>
        <section class="reference-panel status-panel">
            <h2>Status dos equipamentos</h2>
            <div class="device-status">
                <div class="device-ring {{ $deviceCount === 0 ? 'is-empty' : '' }}" style="--active: {{ $deviceCount ? $activeDevices / $deviceCount * 100 : 0 }}%">
                    <div class="device-ring__content"><strong>{{ $deviceCount }}</strong><span>equipamentos</span></div>
                </div>
                <div class="status-legend">
                    <div><i class="green-dot"></i><span>Ativos</span><strong>{{ $activeDevices }}</strong><small>{{ $activeDevicePercent }}%</small></div>
                    <div><i class="red-dot"></i><span>Inativos</span><strong>{{ $inactiveDevices }}</strong><small>{{ $inactiveDevicePercent }}%</small></div>
                </div>
            </div>
        </section>
        <section class="reference-panel ftp-summary">
            <div class="reference-panel__heading"><h2>Contas FTP</h2><a href="{{ route('ftp.index') }}">Ver todas →</a></div>
            <dl>
                <div><dt><span class="ftp-summary-icon ftp-summary-icon--blue"><x-icon name="database" /></span> Total de contas</dt><dd>{{ $ftpCount }}</dd></div>
                <div><dt><span class="ftp-summary-icon ftp-summary-icon--green"><x-icon name="check-circle" /></span> Ativas</dt><dd>{{ $activeFtpCount }}</dd></div>
                <div><dt><span class="ftp-summary-icon ftp-summary-icon--red"><x-icon name="error" /></span> Desativadas</dt><dd>{{ $inactiveFtpCount }}</dd></div>
                <div><dt><span class="ftp-summary-icon ftp-summary-icon--amber"><x-icon name="folder" /></span> Servidor de arquivos</dt><dd>{{ $fileServerCount }}</dd></div>
                <div><dt><span class="ftp-summary-icon ftp-summary-icon--sky"><x-icon name="server" /></span> Backup (equipamentos)</dt><dd>{{ $backupFtpCount }}</dd></div>
            </dl>
        </section>
    </div>
    <div class="dashboard-bottom">
        <section class="reference-panel">
            <div class="reference-panel__heading"><h2>Últimas execuções</h2><a href="{{ route('backup-executions.index') }}">Ver todas →</a></div>
            <div class="table-shell"><table class="data-table">
                <thead><tr><th>Início</th><th>Equipamento</th><th>Tipo</th><th>Status</th><th>Duração</th><th>Ação</th></tr></thead>
                <tbody>
                @forelse ($recentExecutions as $execution)
                    @php
                        $durationSeconds = $execution->started_at && $execution->finished_at
                            ? max(0, (int) $execution->started_at->diffInSeconds($execution->finished_at))
                            : null;
                    @endphp
                    <tr>
                        <td data-label="Início">{{ ($execution->started_at ?? $execution->created_at)?->setTimezone($instanceTimezone)->format('d/m/Y H:i') }}</td>
                        <td data-label="Equipamento">{{ $execution->device?->name ?? '—' }}</td>
                        <td data-label="Tipo">{{ $execution->origin === 'ftp_received' ? 'FTP' : 'Backup' }}</td>
                        <td data-label="Status"><span class="badge badge--{{ $execution->status === 'succeeded' ? 'success' : (in_array($execution->status, ['failed', 'timed_out']) ? 'danger' : 'neutral') }}">{{ ['succeeded' => 'Concluído', 'failed' => 'Erro', 'timed_out' => 'Tempo esgotado', 'running' => 'Em andamento', 'queued' => 'Na fila', 'pending' => 'Pendente', 'retry_wait' => 'Nova tentativa', 'cancelled' => 'Cancelado'][$execution->status] ?? $execution->status }}</span></td>
                        <td data-label="Duração" class="duration-cell">{{ $durationSeconds === null ? '—' : (intdiv($durationSeconds, 3600) ? intdiv($durationSeconds, 3600).'h ' : '').intdiv($durationSeconds % 3600, 60).'m '.($durationSeconds % 60).'s' }}</td>
                        <td data-label="Ação"><a class="row-action" href="{{ route('backup-executions.show', $execution) }}" aria-label="Abrir execução"><x-icon name="more-horizontal" /></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-table">Nenhuma execução registrada.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="reference-panel">
            <div class="reference-panel__heading"><h2>Recebimentos FTP recentes</h2><a href="{{ route('ftp.index') }}">Ver todas →</a></div>
            <div class="table-shell"><table class="data-table">
                <thead><tr><th>Data/Hora</th><th>Conta</th><th>Arquivo</th><th>Tamanho</th><th>Status</th><th aria-label="Ações"></th></tr></thead>
                <tbody>
                @forelse ($recentReceipts as $receipt)
                    @php
                        $bytes = $receipt->size_bytes;
                        $size = $bytes === null ? '—' : ($bytes < 1024
                            ? number_format($bytes, 0, ',', '.').' B'
                            : ($bytes < 1048576
                                ? number_format($bytes / 1024, 1, ',', '.').' KB'
                                : ($bytes < 1073741824
                                    ? number_format($bytes / 1048576, 1, ',', '.').' MB'
                                    : number_format($bytes / 1073741824, 1, ',', '.').' GB')));
                    @endphp
                    <tr>
                        <td data-label="Data/Hora">{{ \Illuminate\Support\Carbon::parse($receipt->received_at, 'UTC')->setTimezone($instanceTimezone)->format('d/m/Y H:i') }}</td>
                        <td data-label="Conta"><a href="{{ route('ftp.show', $receipt->account_id) }}">{{ $receipt->username }}</a></td>
                        <td data-label="Arquivo" class="filename-cell"><span class="filename-short" title="{{ $receipt->original_filename }}">{{ $receipt->original_filename }}</span></td>
                        <td data-label="Tamanho" class="size-cell">{{ $size }}</td>
                        <td data-label="Status"><span class="badge badge--{{ $receipt->status === 'stored' ? 'success' : 'neutral' }}">{{ $receipt->status === 'stored' ? 'Armazenado' : ucfirst($receipt->status) }}</span></td>
                        <td data-label="Ação"><a class="row-action" href="{{ route('ftp.show', $receipt->account_id) }}" aria-label="Abrir conta FTP {{ $receipt->username }}"><x-icon name="more-horizontal" /></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-table">Nenhum arquivo recebido recentemente.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
@endsection
