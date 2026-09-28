@extends('layouts.app')

@section('title', 'Editar usuário — Backup Manager')
@section('page-title', 'Editar usuário')
@section('page-description', 'Atualize dados, papel e senha desta conta.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Editar usuário</h1>
        <p class="page-header__description">Atualize dados, papel e senha desta conta.</p>
    </div>
    <div class="page-header__actions"><a href="{{ route('users.index') }}" class="btn btn--ghost">Voltar aos usuários</a></div>
</header>
@endsection

@section('content')
<div class="user-create-page stack">

    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif

    <article class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">{{ $user->name }}</h2>
                <p class="card__description">{{ $user->email }} · {{ \App\Support\Rbac::roleLabel($user->role) }} · {{ $user->is_active ? 'Ativo' : 'Desativado' }}</p>
            </div>
        </div>

        <form class="card__body" method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="user-create-grid">
                <div class="form-field">
                    <label class="form-label" for="name">Nome *</label>
                    <input class="form-control" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="255" required autofocus>
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label class="form-label" for="email">E-mail *</label>
                    <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" required>
                    @error('email')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label class="form-label" for="role">Papel *</label>
                    <select class="form-control" id="role" name="role" required>
                        @foreach(\App\Support\Rbac::ROLES as $role)
                            <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ \App\Support\Rbac::roleLabel($role) }}</option>
                        @endforeach
                    </select>
                    @error('role')<span class="form-error">{{ $message }}</span>@enderror
                    @if($user->isLastActiveAdmin())
                        <p class="muted-text">Este é o último administrador ativo — o papel não pode ser alterado.</p>
                    @endif
                </div>
            </div>

            <div class="form-actions user-create-actions">
                <a href="{{ route('users.index') }}" class="btn btn--ghost">Cancelar</a>
                <button type="submit" class="btn btn--primary">Salvar alterações</button>
            </div>
        </form>
    </article>

    <article class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Redefinir senha</h2>
                <p class="card__description">Define uma nova senha para esta conta. O usuário não é notificado por e-mail.</p>
            </div>
        </div>

        <form class="card__body" method="POST" action="{{ route('users.reset-password', $user) }}">
            @csrf
            <div class="user-create-grid">
                <div class="form-field">
                    <label class="form-label" for="password">Nova senha *</label>
                    <input class="form-control" id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                    @error('password')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label class="form-label" for="password_confirmation">Confirmar nova senha *</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                </div>
            </div>
            <div class="form-actions user-create-actions">
                <button type="submit" class="btn btn--primary">Redefinir senha</button>
            </div>
        </form>
    </article>

</div>
@endsection
