@extends('layouts.app')

@section('title', 'Credenciais — Backup Manager')
@section('page-title', 'Credenciais')
@section('page-description', 'Gerencie o acesso aos equipamentos.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Credenciais</h1>
        <p class="page-header__description">Gerencie o acesso aos equipamentos.</p>
    </div>
    <div class="page-header__actions">
        @can('credentials.manage')
            <a href="{{ route('credentials.create') }}" class="btn btn--primary"><x-icon name="add" size="sm" /> Nova Credencial</a>
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="credentials-list stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
    @endif

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $credentials->total() }}</strong> {{ $credentials->total() === 1 ? 'credencial cadastrada' : 'credenciais cadastradas' }}</span>
        </div>
    </div>

    @if($credentials->isEmpty())
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="key" /></div>
            <h2 class="empty-state__title">Nenhuma credencial cadastrada</h2>
            <p class="empty-state__description">Cadastre uma credencial para um equipamento.</p>
            @can('credentials.manage')
                <div class="empty-state__actions"><a href="{{ route('credentials.create') }}" class="btn btn--secondary">Cadastrar primeira credencial</a></div>
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de credenciais" tabindex="0">
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
                            <td><span class="entity-cell__title">{{ $credential->name }}</span></td>
                            <td>{{ $credential->device->name }}</td>
                            <td><span class="tech-value">{{ strtoupper($credential->type) }}</span></td>
                            <td><span class="tech-value">{{ $credential->username }}</span></td>
                            <td>{{ $credential->port ?? '—' }}</td>
                            <td><span class="badge badge--{{ $credential->is_active ? 'success' : 'neutral' }}">{{ $credential->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                            <td>
                                <div class="table-actions">
                                    @can('credentials.manage')
                                        <a href="{{ route('credentials.edit', $credential) }}" class="btn btn--ghost btn--sm">Editar</a>
                                    @endcan
                                    @can('credentials.disable')
                                        <form method="POST" action="{{ route('credentials.destroy', $credential) }}" onsubmit="return confirm('Remover esta credencial? Só é possível sem vínculos ativos.');">
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
        @if($credentials->hasPages())
            <div class="pagination-container">{{ $credentials->links() }}</div>
        @endif
    @endif
</div>
@endsection
