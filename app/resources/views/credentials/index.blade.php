@extends('layouts.app')

@section('title', 'Credenciais — Backup Manager')
@section('page-title', 'Credenciais')
@section('page-description', 'Gerencie o acesso aos equipamentos.')

@section('content')

@if(session('success'))
    <div class="alert-success">{{ session('success') }}</div>
@endif

@if(session('warning'))
    <div class="alert-warning">{{ session('warning') }}</div>
@endif

<div class="page-toolbar">
    <div>
        <strong>{{ $credentials->total() }}</strong>
        <span>{{ $credentials->total() === 1 ? 'credencial cadastrada' : 'credenciais cadastradas' }}</span>
    </div>

    @can('credentials.manage')<a href="{{ route('credentials.create') }}" class="primary-button inline-button">+ Nova Credencial</a>@endcan
</div>

<article class="panel table-panel">
    @if($credentials->isEmpty())
        <div class="empty-state">
            <div class="empty-icon">◆</div>
            <h3>Nenhuma credencial cadastrada</h3>
            <p>Cadastre uma credencial para um equipamento.</p>
            @can('credentials.manage')<a href="{{ route('credentials.create') }}" class="primary-button empty-action">Cadastrar primeira credencial</a>@endcan
        </div>
    @else
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Equipamento</th>
                        <th>Tipo</th>
                        <th>Usuário</th>
                        <th>Porta</th>
                        <th>Status</th>
                        <th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($credentials as $credential)
                        <tr>
                            <td><strong>{{ $credential->name }}</strong></td>
                            <td>{{ $credential->device->name }}</td>
                            <td>{{ strtoupper($credential->type) }}</td>
                            <td>{{ $credential->username }}</td>
                            <td>{{ $credential->port ?? '—' }}</td>
                            <td>
                                @if($credential->is_active)
                                    <span class="badge success">Ativa</span>
                                @else
                                    <span class="badge neutral">Inativa</span>
                                @endif
                            </td>
                            <td class="table-actions">
                                @can('credentials.manage')
                                <a href="{{ route('credentials.edit', $credential) }}" class="table-action">Editar</a>
                                @endcan
                                @can('credentials.disable')
                                <form method="POST" action="{{ route('credentials.destroy', $credential) }}" onsubmit="return confirm('Remover esta credencial? Só é possível sem vínculos ativos.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="table-action danger-text">Remover</button>
                                </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($credentials->hasPages())
            <div class="pagination-container">{{ $credentials->links() }}</div>
        @endif
    @endif
</article>

@endsection
