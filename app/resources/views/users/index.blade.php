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

@if(session('success') && !isset($editingUser))
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
            @include('users._form', ['createModal' => true, 'editingUser' => null])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('user-create-dialog');
    document.querySelectorAll('[data-open-user-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-user-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any() && !isset($editingUser)) dialog.showModal(); @endif
})();
</script>
@endcan
@isset($editingUser)
<dialog class="modal form-create-modal" id="user-edit-dialog" aria-labelledby="user-edit-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="user-edit-title">Editar usuário</h2><p class="modal__description">Atualize os dados de {{ $editingUser->name }}.</p></div>
            <a href="{{ route('users.index') }}" class="modal__close" aria-label="Fechar"><x-icon name="close" /></a>
        </div>
        <form method="POST" action="{{ route('users.update', $editingUser) }}">
            @include('users._form', ['editModal' => true])
        </form>
    </div>
</dialog>
<dialog class="modal form-create-modal" id="user-password-dialog" aria-labelledby="user-password-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="user-password-title">Redefinir senha</h2><p class="modal__description">Defina uma nova senha para {{ $editingUser->name }}. O usuário não será notificado por e-mail.</p></div>
            <a href="{{ route('users.edit', $editingUser) }}" class="modal__close" aria-label="Fechar"><x-icon name="close" /></a>
        </div>
        <form method="POST" action="{{ route('users.reset-password', $editingUser) }}">
            @csrf
            <div class="modal__body form-create-modal__body">
                @if($errors->has('password'))<div class="alert alert--warning" role="alert">{{ $errors->first('password') }}</div>@endif
                <div class="user-create-grid">
                    <div class="form-field">
                        <label class="form-label" for="reset-password">Nova senha *</label>
                        <input class="form-control" id="reset-password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="reset-password-confirmation">Confirmar nova senha *</label>
                        <input class="form-control" id="reset-password-confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                    </div>
                </div>
            </div>
            <div class="modal__footer form-create-modal__footer">
                <a href="{{ route('users.edit', $editingUser) }}" class="btn btn--ghost">Cancelar</a>
                <button type="submit" class="btn btn--primary">Redefinir senha</button>
            </div>
        </form>
    </div>
</dialog>
<script>
(() => {
    const editDialog = document.getElementById('user-edit-dialog');
    const passwordDialog = document.getElementById('user-password-dialog');
    const closeToList = () => { window.location.href = @json(route('users.index')); };
    editDialog.showModal();
    editDialog.addEventListener('click', event => { if (event.target === editDialog) closeToList(); });
    passwordDialog.addEventListener('click', event => { if (event.target === passwordDialog) window.location.href = @json(route('users.edit', $editingUser)); });
    editDialog.addEventListener('cancel', event => { event.preventDefault(); closeToList(); });
    passwordDialog.addEventListener('cancel', event => { event.preventDefault(); window.location.href = @json(route('users.edit', $editingUser)); });
    document.getElementById('open-user-password').addEventListener('click', () => {
        editDialog.close();
        passwordDialog.showModal();
    });
    @if($errors->has('password'))
        editDialog.close();
        passwordDialog.showModal();
    @endif
})();
</script>
@endisset
@endsection
