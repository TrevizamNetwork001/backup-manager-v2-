@extends('layouts.app')

@section('title', 'Relatório de falhas — Backup Manager')

@section('page-header')
<header class="page-header failures-header">
    <div class="page-header__content">
        <p class="failures-header__eyebrow"><a href="{{ route('reports.index') }}">Relatórios</a> / Falhas</p>
        <h1 class="page-header__title">Relatório de falhas</h1>
        <p class="page-header__description">Códigos de erro e equipamentos mais afetados · {{ \App\Support\ReportPeriod::label($filters['period'] ?? null) }}</p>
    </div>
    @can('reports.export')
        <a class="btn btn--primary" href="{{ route('reports.failures.export', $filters) }}">Exportar CSV</a>
    @endcan
</header>
@endsection

@section('content')
<div class="stack report-detail-page failures-report">
    <section class="card failures-filter-card" aria-label="Filtros do relatório">
        <div class="card__body">
            <form method="GET" action="{{ route('reports.failures') }}" class="failures-filter-form">
                <div class="form-field">
                    <label class="form-label" for="period">Período</label>
                    <select class="form-control" id="period" name="period">
                        <option value="">Todos os períodos</option>
                        @foreach(\App\Support\ReportPeriod::OPTIONS as $option)
                            <option value="{{ $option }}" @selected(($filters['period'] ?? '') === $option)>{{ \App\Support\ReportPeriod::label($option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="failures-filter-actions">
                    <button type="submit" class="btn btn--primary">Aplicar filtro</button>
                    @if(array_filter($filters))
                        <a class="btn btn--secondary" href="{{ route('reports.failures') }}">Limpar</a>
                    @endif
                </div>
            </form>
        </div>
    </section>

    <section class="card failures-section" aria-labelledby="failures-codes-title">
        <div class="card__header failures-section__header">
            <div>
                <span class="failures-section__eyebrow">Visão por código</span>
                <h2 class="card__title" id="failures-codes-title">Erros mais frequentes</h2>
                <p class="card__description">Ocorrências de backup agrupadas pelo código de erro.</p>
            </div>
            <span class="failures-section__count">{{ count($byErrorCode) }} {{ count($byErrorCode) === 1 ? 'código' : 'códigos' }}</span>
        </div>
        @if($byErrorCode !== [])
            <ol class="failures-list">
                @foreach($byErrorCode as $row)
                    <li class="failures-list__item">
                        <div class="failures-list__identity">
                            <span class="failures-list__rank">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <div class="failures-list__name">
                                <a href="{{ route('reports.executions', ['error_code' => $row['error_code']]) }}">{{ \App\Support\ErrorCodes::message($row['error_code']) }}</a>
                                <span class="failures-list__detail">Código <code>{{ $row['error_code'] }}</code> · Última ocorrência: {{ app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row['last_seen_at'])) }}</span>
                            </div>
                        </div>
                        <div class="failures-list__meta">
                            <span class="badge badge--{{ $row['retryable'] ? 'info' : 'neutral' }}">{{ $row['retryable'] ? 'Permite nova tentativa' : 'Sem nova tentativa' }}</span>
                            <strong class="failures-list__total">{{ number_format($row['total'], 0, ',', '.') }} <span>{{ $row['total'] == 1 ? 'falha' : 'falhas' }}</span></strong>
                        </div>
                    </li>
                @endforeach
            </ol>
        @else
            <div class="failures-empty"><x-icon name="alert" /><p>Nenhuma falha no período selecionado.</p></div>
        @endif
    </section>

    <section class="card failures-section" aria-labelledby="failures-devices-title">
        <div class="card__header failures-section__header">
            <div>
                <span class="failures-section__eyebrow">Visão por equipamento</span>
                <h2 class="card__title" id="failures-devices-title">Equipamentos mais afetados</h2>
                <p class="card__description">Dispositivos com mais falhas no período selecionado.</p>
            </div>
            <span class="failures-section__count">{{ count($byDevice) }} {{ count($byDevice) === 1 ? 'equipamento' : 'equipamentos' }}</span>
        </div>
        @if($byDevice !== [])
            <ol class="failures-list">
                @foreach($byDevice as $row)
                    <li class="failures-list__item">
                        <div class="failures-list__identity">
                            <span class="failures-list__rank">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <div class="failures-list__name">
                                <a href="{{ route('reports.executions', ['device_id' => $row->device_id]) }}">{{ $row->name }}</a>
                                <span class="failures-list__detail">{{ $row->vendor }} · Última ocorrência: {{ app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($row->last_seen_at)) }}</span>
                            </div>
                        </div>
                        <strong class="failures-list__total">{{ number_format($row->total, 0, ',', '.') }} <span>{{ $row->total == 1 ? 'falha' : 'falhas' }}</span></strong>
                    </li>
                @endforeach
            </ol>
        @else
            <div class="failures-empty"><x-icon name="server" /><p>Nenhum equipamento com falhas no período selecionado.</p></div>
        @endif
    </section>
</div>
@endsection
