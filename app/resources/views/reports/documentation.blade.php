@extends('layouts.app')

@section('title', 'Documentação de equipamentos — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Documentação de equipamentos</h1>
        <p class="page-header__description"><a href="{{ route('reports.index') }}">Relatórios</a> · Inventário e dados de acesso por Site / POP.</p>
    </div>
    @can('reports.export')
        <div class="page-header__actions">
            <a class="btn btn--secondary" href="{{ route('reports.documentation.csv', ['site_id' => $siteId]) }}">Exportar CSV sem senhas</a>
            <a class="btn btn--primary" href="{{ route('reports.documentation.pdf', ['site_id' => $siteId]) }}">Exportar PDF com senhas</a>
        </div>
    @endcan
</header>
@endsection

@section('content')
<div class="stack report-detail-page">
    <section class="card">
        <div class="card__body">
            <form method="GET" action="{{ route('reports.documentation') }}" class="grid grid--4">
                <div class="form-field">
                    <label class="form-label" for="site_id">Site / POP</label>
                    <select class="form-control" id="site_id" name="site_id">
                        <option value="">Todos</option>
                        @foreach($sites as $site)
                            <option value="{{ $site->id }}" @selected($siteId === $site->id)>{{ $site->name }}@if($site->code) ({{ $site->code }})@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field" style="align-self: end;"><button type="submit" class="btn btn--primary">Filtrar</button></div>
            </form>
            <p class="form-help">{{ $popCount }} {{ $popCount === 1 ? 'POP' : 'POPs' }} com equipamentos · {{ $devices->count() }} {{ $devices->count() === 1 ? 'equipamento' : 'equipamentos' }} no relatório. O PDF contém as senhas cadastradas; guarde e compartilhe o arquivo com cuidado. O CSV não contém senhas.</p>
        </div>
    </section>

    <section class="card">
        <div class="table-shell" role="region" aria-label="Documentação de equipamentos" tabindex="0">
            <table class="data-table">
                <thead><tr><th>Site / POP</th><th>Equipamento</th><th>IP / hostname</th><th>Fabricante / modelo</th><th>Tipo / função</th><th>Acessos</th><th>Políticas</th></tr></thead>
                <tbody>
                    @forelse($devices as $device)
                        <tr>
                            <td data-label="Site / POP">{{ $device->site->name }}</td>
                            <td data-label="Equipamento">{{ $device->name }}</td>
                            <td data-label="IP / hostname">{{ $device->management_ip }}@if($device->hostname)<br><small>{{ $device->hostname }}</small>@endif</td>
                            <td data-label="Fabricante / modelo">{{ $device->vendor }}@if($device->model)<br><small>{{ $device->model }}</small>@endif</td>
                            <td data-label="Tipo / função">{{ $device->device_kind ?? '—' }} / {{ $device->device_function ?? '—' }}</td>
                            <td data-label="Acessos">
                                @forelse($device->credentials as $credential)
                                    <div>{{ strtoupper($credential->type) }} · {{ $credential->username }} · porta {{ $credential->port ?? '—' }}</div>
                                @empty
                                    @unless($device->ftpAccount) — @endunless
                                @endforelse
                                @if($device->ftpAccount)<div>FTP de backup · {{ $device->ftpAccount->username }} · porta 21</div>@endif
                            </td>
                            <td data-label="Políticas">
                                @forelse($device->deviceBackupPolicies as $association)
                                    <div>{{ $association->backupPolicy->name }} ({{ $association->is_active ? 'ativa' : 'inativa' }})</div>
                                @empty — @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-table">Nenhum equipamento encontrado para este Site / POP.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
