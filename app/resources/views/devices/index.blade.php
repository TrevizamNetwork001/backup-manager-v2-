@extends('layouts.app')

@section('title', 'Equipamentos — Backup Manager')
@section('page-title', 'Equipamentos')
@section('page-description', 'Gerencie os equipamentos protegidos pelo Backup Manager.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Equipamentos</h1>
        <p class="page-header__description">Gerencie os equipamentos protegidos pelo Backup Manager.</p>
    </div>
    <div class="page-header__actions">
        @can('devices.manage')
            <a href="{{ route('devices.create') }}" class="btn btn--primary"><x-icon name="add" size="sm" /> Novo Equipamento</a>
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="devices-list stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
    @endif

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $devices->total() }}</strong> {{ $devices->total() === 1 ? 'equipamento cadastrado' : 'equipamentos cadastrados' }}</span>
        </div>
    </div>

    @if($devices->isEmpty())
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="device" /></div>
            <h2 class="empty-state__title">Nenhum equipamento cadastrado</h2>
            <p class="empty-state__description">Cadastre o primeiro equipamento para começar a estruturar a operação de backup.</p>
            @can('devices.manage')
                <div class="empty-state__actions"><a href="{{ route('devices.create') }}" class="btn btn--secondary">Cadastrar primeiro equipamento</a></div>
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de equipamentos" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>IP</th>
                        <th>Vendor / Modelo</th>
                        <th>Site / POP</th>
                        <th>Status</th>
                        <th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $device)
                        <tr>
                            <td>
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ $device->name }}</span>
                                    @if($device->hostname)<span class="entity-cell__meta">{{ $device->hostname }}</span>@endif
                                </div>
                            </td>
                            <td><span class="tech-value">{{ $device->management_ip }}</span></td>
                            <td>
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ $device->vendor }}</span>
                                    @if($device->model)<span class="entity-cell__meta">{{ $device->model }}</span>@endif
                                </div>
                            </td>
                            <td>
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ $device->site->name }}</span>
                                    @if($device->site->code)<span class="entity-cell__meta">{{ $device->site->code }}</span>@endif
                                </div>
                            </td>
                            <td><span class="badge badge--{{ $device->is_active ? 'success' : 'neutral' }}">{{ $device->is_active ? 'Ativo' : 'Inativo' }}</span></td>
                            <td>
                                <div class="table-actions">
                                    @can('devices.manage')
                                        <a href="{{ route('devices.edit', $device) }}" class="btn btn--ghost btn--sm">Editar</a>
                                    @endcan
                                    @can('devices.delete')
                                        <form method="POST" action="{{ route('devices.destroy', $device) }}" onsubmit="return confirm('Remover este equipamento? Só é possível sem histórico ou vínculos.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($devices->hasPages())
            <div class="pagination-container">{{ $devices->links() }}</div>
        @endif
    @endif
</div>
@endsection
