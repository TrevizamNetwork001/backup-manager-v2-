@extends('layouts.app')

@section('title', 'Usuários — Backup Manager')
@section('page-title', 'Usuários')
@section('page-description', 'Gerencie contas, papéis e acesso ao sistema.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Usuários</h1>
        <p class="page-header__description">Gerencie contas, papéis e acesso ao sistema.</p>
    </div>
    <div class="page-header__actions">
        @can('users.manage')
            <button type="button" class="btn btn--primary" data-open-user-create><x-icon name="add" size="sm" /> Novo usuário</button>
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="users-list stack">

@if(session('success'))
    <div class="alert alert--success" role="status">{{ session('success') }}</div>
@endif
<div class="toolbar">
    <div class="toolbar__primary">
        <span class="toolbar__count"><strong>{{ $users->total() }}</strong> {{ $users->total() === 1 ? 'usuário cadastrado' : 'usuários cadastrados' }}</span>
    </div>
</div>

@if($users->isEmpty())
    <div class="empty-state">
        <h2 class="empty-state__title">Nenhum usuário cadastrado</h2>
        @can('users.manage')
            <div class="empty-state__actions"><button type="button" class="btn btn--secondary" data-open-user-create>Cadastrar primeiro usuário</button></div>
        @endcan
    </div>
@else
    <div class="table-shell" role="region" aria-label="Lista de usuários" tabindex="0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Papel</th>
                    <th>Status</th>
                    <th>Criado em</th>
                    <th class="table-actions-column">Ações</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>
                            <div class="entity-cell">
                                <span class="entity-cell__title">{{ $user->name }}</span>
                                @if($user->id === auth()->id())
                                    <span class="entity-cell__meta">Você</span>
                                @endif
                            </div>
                        </td>
                        <td><span class="tech-value">{{ $user->email }}</span></td>
                        <td><span class="badge badge--{{ $user->hasRole('admin') ? 'info' : 'neutral' }}">{{ \App\Support\Rbac::roleLabel($user->role) }}</span></td>
                        <td>
                            @if($user->is_active)
                                <span class="badge badge--success">Ativo</span>
                            @else
                                <span class="badge badge--neutral">Desativado</span>
                            @endif
                        </td>
                        <td>{{ app(\App\Services\InstanceTimezone::class)->format($user->created_at, 'd/m/Y') }}</td>
                        <td>
                            <div class="table-actions">
                                <a href="{{ route('users.edit', $user) }}" class="btn btn--ghost btn--sm">Editar</a>
                                @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('users.status', $user) }}"
                                        onsubmit="return confirm('{{ $user->is_active ? 'Desativar' : 'Ativar' }} este usuário?');">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}">
                                        <button type="submit" class="btn btn--ghost btn--sm {{ $user->is_active ? 'table-actions__danger' : '' }}">
                                            {{ $user->is_active ? 'Desativar' : 'Ativar' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($users->hasPages())
        <div class="pagination-container">{{ $users->links() }}</div>
    @endif
@endif

</div>
@can('users.manage')
<dialog class="modal form-create-modal" id="user-create-dialog" aria-labelledby="user-create-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="user-create-title">Novo usuário</h2><p class="modal__description">Cadastre uma nova conta e defina o papel de acesso.</p></div>
            <button type="button" class="modal__close" data-close-user-create aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('users.store') }}">
            @include('users._form', ['createModal' => true])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('user-create-dialog');
    document.querySelectorAll('[data-open-user-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-user-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endcan
@endsection
