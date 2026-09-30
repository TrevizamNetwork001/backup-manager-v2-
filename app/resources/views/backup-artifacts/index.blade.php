@extends('layouts.app')

@section('title', 'Artefatos — Backup Manager')
@section('page-title', 'Artefatos')
@section('page-description', 'Visualize, filtre e baixe os arquivos de configuração coletados.')

@section('page-header')
<header class="page-header artifact-page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Artefatos de backup</h1>
        <p class="page-header__description">Visualize, filtre e baixe os arquivos de configuração coletados.</p>
    </div>
    <nav class="artifact-breadcrumb" aria-label="Caminho"><a href="{{ route('dashboard') }}">Home</a><x-icon name="chevron-right" size="sm" /><span>Artefatos</span></nav>
</header>
@endsection

@section('content')
@php
    $totalArtifacts = $statusCounts->sum();
    $availableArtifacts = $statusCounts->get('available', 0);
    $deletedArtifacts = $statusCounts->get('deleted', 0);
    $missingArtifacts = $statusCounts->get('missing', 0);
@endphp
<div class="backup-artifacts-page artifact-index">
    <section class="artifact-summary" aria-label="Resumo dos artefatos">
        <div class="artifact-summary-card"><span class="artifact-summary-icon artifact-summary-icon--total"><x-icon name="dashboard-artifacts" /></span><div><strong>{{ number_format($totalArtifacts, 0, ',', '.') }}</strong><span>Total de artefatos</span></div></div>
        <div class="artifact-summary-card"><span class="artifact-summary-icon artifact-summary-icon--available"><x-icon name="check-circle" /></span><div><strong>{{ number_format($availableArtifacts, 0, ',', '.') }}</strong><span>Disponíveis</span></div><small>{{ $totalArtifacts ? number_format($availableArtifacts * 100 / $totalArtifacts, 1, ',', '.') : '0,0' }}%</small></div>
        <div class="artifact-summary-card"><span class="artifact-summary-icon artifact-summary-icon--deleted"><x-icon name="archive" /></span><div><strong>{{ number_format($deletedArtifacts, 0, ',', '.') }}</strong><span>Removidos</span></div><small>{{ $totalArtifacts ? number_format($deletedArtifacts * 100 / $totalArtifacts, 1, ',', '.') : '0,0' }}%</small></div>
        <div class="artifact-summary-card"><span class="artifact-summary-icon artifact-summary-icon--missing"><x-icon name="error" /></span><div><strong>{{ number_format($missingArtifacts, 0, ',', '.') }}</strong><span>Ausentes</span></div><small>{{ $totalArtifacts ? number_format($missingArtifacts * 100 / $totalArtifacts, 1, ',', '.') : '0,0' }}%</small></div>
    </section>

    <form method="GET" action="{{ route('backup-artifacts.index') }}" class="artifact-filter-bar" aria-label="Filtrar artefatos">
        <label class="artifact-filter artifact-filter--search"><span>Buscar</span><input class="form-control" name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nome do arquivo, equipamento, IP, site..."></label>
        <label class="artifact-filter"><span>Período</span><select class="form-control" name="period"><option value="7" @selected(($filters['period'] ?? '30') === '7')>Últimos 7 dias</option><option value="30" @selected(($filters['period'] ?? '30') === '30')>Últimos 30 dias</option><option value="90" @selected(($filters['period'] ?? '30') === '90')>Últimos 90 dias</option><option value="all" @selected(($filters['period'] ?? '30') === 'all')>Todo o período</option></select></label>
        <label class="artifact-filter"><span>Site / POP</span><select class="form-control" name="site_id"><option value="">Todos</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected((string) ($filters['site_id'] ?? '') === (string) $site->id)>{{ $site->name }}</option>@endforeach</select></label>
        <label class="artifact-filter"><span>Fabricante</span><select class="form-control" name="vendor"><option value="">Todos</option>@foreach($vendors as $vendor)<option value="{{ $vendor }}" @selected(($filters['vendor'] ?? '') === $vendor)>{{ $vendor }}</option>@endforeach</select></label>
        <label class="artifact-filter"><span>Tipo</span><select class="form-control" name="type"><option value="">Todos</option>@foreach($types as $type)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></label>
        <label class="artifact-filter"><span>Status</span><select class="form-control" name="status"><option value="">Todos</option><option value="available" @selected(($filters['status'] ?? '') === 'available')>Disponível</option><option value="deleted" @selected(($filters['status'] ?? '') === 'deleted')>Removido</option><option value="missing" @selected(($filters['status'] ?? '') === 'missing')>Ausente</option></select></label>
        <div class="artifact-filter-actions"><button class="btn btn--primary" type="submit">Filtrar</button><a class="btn btn--secondary" href="{{ route('backup-artifacts.index') }}"><x-icon name="refresh" size="sm" /> Limpar</a></div>
    </form>

    <div class="artifact-workspace">
        <section class="artifact-list" aria-label="Lista de artefatos de backup">
            @if($artifacts->isEmpty())
                <div class="empty-state"><div class="empty-state__icon" aria-hidden="true"><x-icon name="file" /></div><h2 class="empty-state__title">Nenhum artefato encontrado</h2><p class="empty-state__description">Altere os filtros ou aguarde a coleta de novos arquivos.</p></div>
            @else
                <div class="table-shell" role="region" aria-label="Tabela de artefatos" tabindex="0">
                    <table class="data-table artifact-table">
                        <thead><tr><th class="artifact-select-cell"><input type="checkbox" class="form-control artifact-select-checkbox" data-select-all aria-label="Selecionar todos os artefatos desta página"></th><th>Data/hora</th><th>Equipamento</th><th>Site / POP</th><th>Fabricante</th><th>Tipo</th><th>Arquivo</th><th>Tamanho</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                            @foreach($artifacts as $artifact)
                                <tr @class(['is-selected' => $selectedArtifact?->id === $artifact->id])>
                                    <td data-label="Selecionar" class="artifact-select-cell"><input type="checkbox" class="form-control artifact-select-checkbox" data-artifact-select value="{{ $artifact->id }}" @checked($selectedArtifact?->id === $artifact->id) aria-label="Selecionar artefato {{ $artifact->original_filename }}"></td>
                                    <td data-label="Data/hora" class="artifact-date">{{ app(\App\Services\InstanceTimezone::class)->format($artifact->created_at, 'd/m/Y H:i') }}</td>
                                    <td data-label="Equipamento"><strong>{{ $artifact->device?->name ?? 'Equipamento removido' }}</strong></td>
                                    <td data-label="Site / POP">{{ $artifact->device?->site?->name ?? '—' }}</td>
                                    <td data-label="Fabricante"><span class="vendor-cell"><span>{{ $artifact->device?->vendor ?? '—' }}</span></span></td>
                                    <td data-label="Tipo"><span class="artifact-type">{{ $artifact->type }}</span></td>
                                    <td data-label="Arquivo" class="artifact-filename" title="{{ $artifact->original_filename }}">{{ $artifact->original_filename ?: 'Artefato #'.$artifact->id }}</td>
                                    <td data-label="Tamanho">{{ number_format($artifact->size_bytes / 1024, 1, ',', '.') }} KB</td>
                                    <td data-label="Status"><span class="badge badge--{{ $artifact->status === 'available' ? 'success' : ($artifact->status === 'missing' ? 'danger' : 'warning') }}">{{ $artifact->statusLabel() }}</span></td>
                                    <td data-label="Ações">
                                        <div class="artifact-row-actions">
                                            @can('backup_artifacts.download')
                                                @if($artifact->status === 'available')
                                                    <a href="{{ route('backup-artifacts.download', $artifact) }}" aria-label="Baixar {{ $artifact->original_filename }}" title="Baixar"><x-icon name="backup" size="sm" /><span>Baixar</span></a>
                                                @endif
                                            @endcan
                                            <a href="{{ route('backup-artifacts.index', array_merge(request()->except('artifact'), ['artifact' => $artifact->id])) }}" aria-label="Selecionar detalhes de {{ $artifact->original_filename }}" title="Ver detalhes"><x-icon name="eye" size="sm" /><span>Ver</span></a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <details class="artifact-detail-panel" aria-label="Detalhes do artefato" @if($selectedArtifact) open @endif>
            <summary class="artifact-detail-panel__header"><h2>Detalhes do artefato</h2><x-icon name="chevron-down" size="sm" /></summary>
            @if($selectedArtifact)
                <div class="artifact-detail-panel__body">
                    <div class="artifact-detail-panel__identity"><strong>{{ $selectedArtifact->device?->name ?? 'Equipamento removido' }}</strong><span class="badge badge--{{ $selectedArtifact->status === 'available' ? 'success' : ($selectedArtifact->status === 'missing' ? 'danger' : 'warning') }}">{{ $selectedArtifact->statusLabel() }}</span></div>
                    <p class="artifact-detail-panel__device"><span class="vendor-cell"><span>{{ $selectedArtifact->device?->vendor ?? 'Fabricante desconhecido' }}</span></span> · {{ $selectedArtifact->device?->model ?: ($selectedArtifact->device?->platform ?? $selectedArtifact->type) }}</p>
                    <dl class="artifact-detail-list">
                        <div><dt>Data e hora</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($selectedArtifact->created_at, 'd/m/Y H:i:s') }}</dd></div>
                        <div><dt>Equipamento</dt><dd>{{ $selectedArtifact->device?->name ?? '—' }}</dd></div>
                        <div><dt>Site / POP</dt><dd>{{ $selectedArtifact->device?->site?->name ?? '—' }}</dd></div>
                        <div><dt>Arquivo</dt><dd>{{ $selectedArtifact->original_filename ?: 'Artefato #'.$selectedArtifact->id }}</dd></div>
                        <div><dt>Tamanho</dt><dd>{{ number_format($selectedArtifact->size_bytes / 1024, 1, ',', '.') }} KB ({{ number_format($selectedArtifact->size_bytes, 0, ',', '.') }} bytes)</dd></div>
                        <div><dt>SHA256</dt><dd class="text-technical">{{ $selectedArtifact->sha256 }}</dd></div>
                        <div><dt>Política</dt><dd>{{ $selectedArtifact->backupPolicy?->name ?? '—' }}</dd></div>
                        <div><dt>Execução</dt><dd>#{{ $selectedArtifact->backup_execution_id }}</dd></div>
                        <div><dt>Tipo</dt><dd>{{ $selectedArtifact->type }}</dd></div>
                        <div><dt>Validação registrada</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($selectedArtifact->validated_at, 'd/m/Y H:i') }}</dd></div>
                    </dl>
                    <div class="artifact-detail-actions">
                        @can('backup_artifacts.download')
                            @if($selectedArtifact->status === 'available')
                                <a class="btn btn--primary" href="{{ route('backup-artifacts.download', $selectedArtifact) }}"><x-icon name="backup" size="sm" /> Baixar</a>
                            @endif
                        @endcan
                        <a class="btn btn--secondary" href="{{ route('backup-artifacts.show', $selectedArtifact) }}"><x-icon name="eye" size="sm" /> Ver detalhes</a>
                    </div>
                </div>
            @endif
        </details>
    </div>
    <div class="artifact-list-footer"><span>Mostrando {{ $artifacts->firstItem() ?? 0 }} a {{ $artifacts->lastItem() ?? 0 }} de {{ number_format($artifacts->total(), 0, ',', '.') }} artefatos</span>@if($artifacts->hasPages())<div class="pagination-container">{{ $artifacts->links() }}</div>@endif</div>
</div>
<script>
    (() => {
        const selectAll = document.querySelector('[data-select-all]');
        const artifactCheckboxes = [...document.querySelectorAll('[data-artifact-select]')];
        const current = new URL(window.location.href);

        const updateDetailsSelection = (artifactId) => {
            if (artifactId) {
                current.searchParams.set('artifact', artifactId);
            } else {
                current.searchParams.delete('artifact');
            }
            current.searchParams.delete('page');
            window.location.assign(current.toString());
        };

        selectAll?.addEventListener('change', () => {
            artifactCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
        });

        artifactCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    artifactCheckboxes.forEach((other) => {
                        if (other !== checkbox) {
                            other.checked = false;
                        }
                    });
                    updateDetailsSelection(checkbox.value);
                    return;
                }

                if (Number(current.searchParams.get('artifact')) === Number(checkbox.value)) {
                    updateDetailsSelection(null);
                }
            });
        });
    })();
</script>
@endsection
