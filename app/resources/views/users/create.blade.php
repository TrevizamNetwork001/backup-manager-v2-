@extends('layouts.app')

@section('title', 'Novo usuário — Backup Manager')
@section('page-title', 'Novo usuário')
@section('page-description', 'Cadastre uma nova conta de acesso ao sistema.')

@section('content')
<div class="page-width">
    <article class="panel form-panel">
        <div class="panel-header">
            <div>
                <h2>Dados do usuário</h2>
                <p>Defina identificação, papel e senha inicial.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('users.store') }}">
            @csrf

            <div class="form-grid">
                <div class="field">
                    <label for="name">Nome *</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="255" required autofocus>
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="email">E-mail *</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required>
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="role">Papel *</label>
                    <select id="role" name="role" required>
                        @foreach(\App\Support\Rbac::ROLES as $role)
                            <option value="{{ $role }}" @selected(old('role') === $role)>{{ \App\Support\Rbac::roleLabel($role) }}</option>
                        @endforeach
                    </select>
                    @error('role')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="password">Senha *</label>
                    <input id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirmar senha *</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                </div>

                <div class="field form-span-2">
                    <input type="hidden" name="is_active" value="0">
                    <label class="switch-row">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                        <span>
                            <strong>Usuário ativo</strong>
                            <small>Usuários desativados não conseguem fazer login.</small>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('users.index') }}" class="secondary-button">Cancelar</a>
                <button type="submit" class="primary-button inline-button">Cadastrar usuário</button>
            </div>
        </form>
    </article>
</div>
@endsection
