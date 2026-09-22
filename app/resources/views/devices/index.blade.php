@extends('layouts.app')

@section('title', 'Equipamentos — Backup Manager')
@section('page-title', 'Equipamentos')
@section('page-description', 'Gerencie os equipamentos protegidos pelo Backup Manager.')

@section('content')

@if(session('success'))
    <div class="alert-success">
        {{ session('success') }}
    </div>
@endif

@if(session('warning'))
    <div class="alert-warning">{{ session('warning') }}</div>
@endif

<div class="page-toolbar">

    <div>
        <strong>{{ $devices->total() }}</strong>
        <span>{{ $devices->total() === 1 ? 'equipamento cadastrado' : 'equipamentos cadastrados' }}</span>
    </div>

    <a href="{{ route('devices.create') }}" class="primary-button inline-button">
        + Novo Equipamento
    </a>

</div>

<article class="panel table-panel">

@if($devices->isEmpty())

    <div class="empty-state">

        <div class="empty-icon">▣</div>

        <h3>Nenhum equipamento cadastrado</h3>

        <p>
            Cadastre o primeiro equipamento para começar
            a estruturar a operação de backup.
        </p>

        <a href="{{ route('devices.create') }}" class="primary-button empty-action">
            Cadastrar primeiro equipamento
        </a>

    </div>

@else

    <div class="table-responsive">

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
                        <strong>{{ $device->name }}</strong>

                        @if($device->hostname)
                            <small>{{ $device->hostname }}</small>
                        @endif
                    </td>

                    <td>
                        {{ $device->management_ip }}
                    </td>

                    <td>
                        <strong>{{ $device->vendor }}</strong>

                        @if($device->model)
                            <small>{{ $device->model }}</small>
                        @endif
                    </td>

                    <td>
                        <strong>{{ $device->site->name }}</strong>

                        @if($device->site->code)
                            <small>{{ $device->site->code }}</small>
                        @endif
                    </td>

                    <td>
                        @if($device->is_active)
                            <span class="badge success">Ativo</span>
                        @else
                            <span class="badge neutral">Inativo</span>
                        @endif
                    </td>

                    <td class="table-actions">

                        <a
                            href="{{ route('devices.edit', $device) }}"
                            class="table-action"
                        >
                            Editar
                        </a>

                        <form
                            method="POST"
                            action="{{ route('devices.destroy', $device) }}"
                            onsubmit="return confirm('Remover este equipamento?');"
                        >
                            @csrf
                            @method('DELETE')

                            <button type="submit" class="table-action danger-text">
                                Remover
                            </button>
                        </form>

                    </td>

                </tr>

            @endforeach
            </tbody>
        </table>

    </div>

    @if($devices->hasPages())
        <div class="pagination-container">
            {{ $devices->links() }}
        </div>
    @endif

@endif

</article>

@endsection
