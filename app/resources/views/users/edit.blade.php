@extends('layouts.app')

@section('title', 'Editar usuário — Backup Manager')
@section('page-title', 'Editar usuário')
@section('page-description', 'Atualize dados, papel e senha desta conta.')

@section('content')
<div class="page-width stack">

    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif

    <article class="panel form-panel">
        <div class="panel-header">
            <div>
                <h2>{{ $user->name }}</h2>
                <p>{{ $user->email }} · {{ \App\Support\Rbac::roleLabel($user->role) }} · {{ $user->is_active ? 'Ativo' : 'Desativado' }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="form-grid">
                <div class="field">
                    <label for="name">Nome *</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="255" required autofocus>
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="email">E-mail *</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" required>
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="role">Papel *</label>
                    <select id="role" name="role" required>
                        @foreach(\App\Support\Rbac::ROLES as $role)
                            <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ \App\Support\Rbac::roleLabel($role) }}</option>
                        @endforeach
                    </select>
                    @error('role')<span class="field-error">{{ $message }}</span>@enderror
                    @if($user->isLastActiveAdmin())
                        <p class="muted-text">Este é o último administrador ativo — o papel não pode ser alterado.</p>
                    @endif
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('users.index') }}" class="secondary-button">Cancelar</a>
                <button type="submit" class="primary-button inline-button">Salvar alterações</button>
            </div>
        </form>
    </article>

    <article class="panel form-panel">
        <div class="panel-header">
            <div>
                <h2>Redefinir senha</h2>
                <p>Define uma nova senha para esta conta. O usuário não é notificado por e-mail.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('users.reset-password', $user) }}">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="password">Nova senha *</label>
                    <input id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirmar nova senha *</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="primary-button inline-button">Redefinir senha</button>
            </div>
        </form>
    </article>

</div>
@endsection
