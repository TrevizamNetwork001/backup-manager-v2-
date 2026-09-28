@csrf

@if($createModal ?? false)
    <div class="modal__body form-create-modal__body">
@endif

@if($errors->any())
    <div class="alert alert--warning" role="alert">{{ $errors->first() }}</div>
@endif

<div class="user-create-grid">
    <div class="form-field">
        <label class="form-label" for="name">Nome *</label>
        <input class="form-control" id="name" name="name" type="text" value="{{ old('name') }}" maxlength="255" @error('name') aria-invalid="true" aria-describedby="user-name-error" @enderror required autofocus>
        @error('name')<span class="form-error" id="user-name-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="email">E-mail *</label>
        <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" @error('email') aria-invalid="true" aria-describedby="user-email-error" @enderror required>
        @error('email')<span class="form-error" id="user-email-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="role">Papel *</label>
        <select class="form-control" id="role" name="role" @error('role') aria-invalid="true" aria-describedby="user-role-error" @enderror required>
            @foreach(\App\Support\Rbac::ROLES as $role)
                <option value="{{ $role }}" @selected(old('role') === $role)>{{ \App\Support\Rbac::roleLabel($role) }}</option>
            @endforeach
        </select>
        @error('role')<span class="form-error" id="user-role-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="password">Senha *</label>
        <input class="form-control" id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" @error('password') aria-invalid="true" aria-describedby="user-password-error" @enderror required>
        @error('password')<span class="form-error" id="user-password-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="password_confirmation">Confirmar senha *</label>
        <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
    </div>

    <div class="form-field user-create-span">
        <input type="hidden" name="is_active" value="0">
        <label class="switch-row user-create-switch">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
            <span><strong>Usuário ativo</strong><small>Usuários desativados não conseguem fazer login.</small></span>
        </label>
    </div>
</div>

@if($createModal ?? false)
    </div>
@endif

<div class="form-actions user-create-actions {{ ($createModal ?? false) ? 'modal__footer form-create-modal__footer' : '' }}">
    @if($createModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-user-create>Cancelar</button>
    @else
        <a href="{{ route('users.index') }}" class="btn btn--ghost">Cancelar</a>
    @endif
    <button type="submit" class="btn btn--primary">Cadastrar usuário</button>
</div>
