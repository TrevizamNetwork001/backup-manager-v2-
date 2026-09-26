@extends('layouts.app')

@section('title', 'Auditoria — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Auditoria</h1>
        <p class="page-header__description">Consulta de eventos administrativos registrados no sistema.</p>
    </div>
</header>
@endsection

@section('content')
<div class="audit-page stack">

    <section class="card audit-security" aria-labelledby="audit-security-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="audit-security-title">Central de segurança</h2>
                <p class="card__description">Tentativas de acesso por IP, conta e período.</p>
            </div>
            <span class="badge badge--{{ $security['failed_10m'] > 0 ? 'warning' : 'success' }}">{{ $security['failed_10m'] > 0 ? 'Atenção' : 'Normal' }}</span>
        </div>
        <div class="card__body">
            <div class="audit-security__metrics">
                <div><strong>{{ $security['failed_10m'] }}</strong><span>Falhas em 10 minutos</span></div>
                <div><strong>{{ $security['failed_24h'] }}</strong><span>Falhas nas últimas 24h</span></div>
                <div><strong>{{ $security['distinct_ips'] }}</strong><span>Endereços IP distintos</span></div>
            </div>
        </div>
    </section>

    <div class="audit-security__rankings">
        <section class="card" aria-labelledby="audit-top-ips-title">
            <div class="card__header"><div><h2 class="card__title" id="audit-top-ips-title">IPs com mais tentativas</h2><p class="card__description">Últimas 24 horas</p></div></div>
            <div class="table-shell">
                <table class="data-table audit-compact-table">
                    <thead><tr><th>Endereço IP</th><th>Tentativas</th><th>Última atividade</th></tr></thead>
                    <tbody>
                        @forelse($security['top_ips'] as $ip)
                            <tr><td data-label="Endereço IP"><span class="tech-value">{{ $ip->ip_address ?: 'Não informado' }}</span></td><td data-label="Tentativas">{{ $ip->attempts }}</td><td data-label="Última atividade">{{ app(\App\Services\InstanceTimezone::class)->format(\Carbon\CarbonImmutable::parse($ip->last_at, 'UTC'), 'd/m/Y H:i') }}</td></tr>
                        @empty
                            <tr><td colspan="3">Nenhuma tentativa registrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card" aria-labelledby="audit-top-accounts-title">
            <div class="card__header"><div><h2 class="card__title" id="audit-top-accounts-title">Contas mais visadas</h2><p class="card__description">Últimas 24 horas</p></div></div>
            <div class="table-shell">
                <table class="data-table audit-compact-table">
                    <thead><tr><th>Conta</th><th>Tentativas</th><th>IPs</th></tr></thead>
                    <tbody>
                        @forelse($security['top_accounts'] as $account)
                            <tr><td data-label="Conta">{{ $account->resource_label ?: 'Não informado' }}</td><td data-label="Tentativas">{{ $account->attempts }}</td><td data-label="IPs">{{ $account->ips }}</td></tr>
                        @empty
                            <tr><td colspan="3">Nenhuma conta visada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="audit-auth-title">
        <div class="card__header">
            <div><h2 class="card__title" id="audit-auth-title">Eventos recentes de autenticação</h2><p class="card__description">As 20 ocorrências mais recentes de acesso.</p></div>
            <a class="btn btn--ghost btn--sm" href="#audit-history">Ver histórico completo</a>
        </div>
        <div class="table-shell">
            <table class="data-table audit-compact-table">
                <thead><tr><th>Data e hora</th><th>Evento</th><th>Conta</th><th>IP de origem</th></tr></thead>
                <tbody>
                    @forelse($security['recent'] as $authEvent)
                        <tr>
                            <td data-label="Data e hora">{{ app(\App\Services\InstanceTimezone::class)->format($authEvent->created_at, 'd/m/Y H:i') }}</td>
                            <td data-label="Evento"><span class="badge badge--{{ $authEvent->action === 'auth.login' ? 'success' : 'danger' }}">{{ $authEvent->action === 'auth.login' ? 'Login válido' : 'Tentativa inválida' }}</span></td>
                            <td data-label="Conta">{{ $authEvent->resource_label ?: 'Não informado' }}</td>
                            <td data-label="IP de origem"><span class="tech-value">{{ $authEvent->ip_address ?: '—' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhum evento de autenticação.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="section-header" id="audit-history">
        <div class="section-header__content">
            <h2 class="section-header__title">Histórico completo</h2>
            <p class="section-header__description">Consulte os eventos registrados e filtre os resultados.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('audit.index') }}" class="toolbar audit-filters" id="audit-filters-form">
        <div class="form-field">
            <label class="form-label" for="filter-period">Período</label>
            <select class="form-control" id="filter-period" name="period">
                <option value="" @selected(($filters['period'] ?? '') === '')>Todos</option>
                <option value="today" @selected(($filters['period'] ?? '') === 'today')>Hoje</option>
                <option value="7d" @selected(($filters['period'] ?? '') === '7d')>Últimos 7 dias</option>
                <option value="30d" @selected(($filters['period'] ?? '') === '30d')>Últimos 30 dias</option>
                <option value="custom" @selected(($filters['period'] ?? '') === 'custom')>Personalizado</option>
            </select>
        </div>
        <div class="form-field" id="audit-date-from-field">
            <label class="form-label" for="filter-date-from">De</label>
            <input class="form-control" type="date" id="filter-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="form-field" id="audit-date-to-field">
            <label class="form-label" for="filter-date-to">Até</label>
            <input class="form-control" type="date" id="filter-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="form-field">
            <label class="form-label" for="filter-actor">Usuário</label>
            <select class="form-control" id="filter-actor" name="actor">
                <option value="">Todos</option>
                <option value="system" @selected(($filters['actor'] ?? '') === 'system')>Sistema</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected((string) ($filters['actor'] ?? '') === (string) $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label class="form-label" for="filter-action">Ação</label>
            <select class="form-control" id="filter-action" name="action">
                <option value="">Todas</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ app(\App\Services\AuditPresenter::class)->actionLabel($action) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label class="form-label" for="filter-resource-type">Recurso</label>
            <select class="form-control" id="filter-resource-type" name="resource_type">
                <option value="">Todos</option>
                @foreach($resourceTypes as $resourceType)
                    <option value="{{ $resourceType }}" @selected(($filters['resource_type'] ?? '') === $resourceType)>{{ app(\App\Services\AuditPresenter::class)->resourceTypeLabel($resourceType) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label class="form-label" for="filter-result">Resultado</label>
            <select class="form-control" id="filter-result" name="result">
                <option value="">Todos</option>
                @foreach($results as $result)
                    <option value="{{ $result }}" @selected(($filters['result'] ?? '') === $result)>{{ app(\App\Services\AuditPresenter::class)->resultBadge($result)['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field audit-filters__search">
            <label class="form-label" for="filter-q">Busca</label>
            <input class="form-control" type="text" id="filter-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Recurso, ação, IP...">
        </div>
        <div class="toolbar__actions audit-filters__actions">
            <button type="submit" class="btn btn--primary">Filtrar</button>
            <a href="{{ route('audit.index') }}" class="btn btn--ghost">Limpar</a>
        </div>
    </form>

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $events->total() }}</strong> {{ $events->total() === 1 ? 'evento' : 'eventos' }}</span>
        </div>
    </div>

    @if($events->isEmpty())
        <div class="empty-state">
            <h2 class="empty-state__title">
                @if(collect($filters)->filter()->isNotEmpty())
                    Nenhum evento corresponde aos filtros selecionados.
                @else
                    Nenhum evento de auditoria encontrado.
                @endif
            </h2>
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Eventos de auditoria" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Data/hora</th>
                        <th>Usuário</th>
                        <th>Ação</th>
                        <th>Recurso</th>
                        <th>Resultado</th>
                        <th>IP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                        @php
                            $presenter = app(\App\Services\AuditPresenter::class);
                            $badge = $presenter->resultBadge($event->result);
                        @endphp
                        <tr>
                            <td data-label="Data/hora">{{ app(\App\Services\InstanceTimezone::class)->format($event->created_at, 'd/m/Y H:i') }}</td>
                            <td data-label="Usuário">{{ $presenter->actorLabel($event->actor) }}</td>
                            <td data-label="Ação">{{ $presenter->actionLabel($event->action) }}</td>
                            <td data-label="Recurso">{{ $presenter->resourceTypeLabel($event->resource_type) }} @if($event->resource_label)· <code class="tech-value">{{ $event->resource_label }}</code>@endif</td>
                            <td data-label="Resultado"><span class="badge badge--{{ $badge['variant'] }}">{{ $badge['label'] }}</span></td>
                            <td data-label="IP">{{ $event->ip_address ?? '—' }}</td>
                            <td data-label="Detalhes"><a class="btn btn--ghost btn--sm" href="{{ route('audit.show', $event) }}">Ver</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $events->links() }}
    @endif
</div>
<script>
(() => {
    const period = document.getElementById('filter-period');
    const from = document.getElementById('audit-date-from-field');
    const to = document.getElementById('audit-date-to-field');
    const sync = () => { const custom = period.value === 'custom'; from.hidden = !custom; to.hidden = !custom; };
    period.addEventListener('change', sync); sync();
})();
</script>
@endsection
