@csrf

@if(isset($credential))
    @method('PUT')
    <input type="hidden" name="credential_id" value="{{ $credential->id }}">
@endif

@if(($createModal ?? false) || ($editModal ?? false))
    <div class="modal__body form-create-modal__body">
@endif

@if(($editModal ?? false) && request()->boolean('ssh_test') && session('success'))
    <div class="alert alert--success" role="status">{{ session('success') }}</div>
@endif

<div class="form-create-grid">
    <div class="form-field form-span-2">
        <label class="form-label" for="device_id">Equipamento *</label>
        <select class="form-control" id="device_id" name="device_id" required>
            <option value="">Selecione...</option>
            @foreach($devices as $device)
                <option value="{{ $device->id }}" @selected((string) old('device_id', $credential->device_id ?? '') === (string) $device->id)>{{ $device->name }}</option>
            @endforeach
        </select>
        @error('device_id') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="name">Nome *</label>
        <input class="form-control" id="name" name="name" type="text" value="{{ old('name', $credential->name ?? '') }}" maxlength="255" required autofocus>
        @error('name') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="type">Tipo de acesso *</label>
        <select class="form-control" id="type" name="type" required>
            @foreach(\App\Models\Credential::SELECTABLE_TYPES as $type)
                <option value="{{ $type }}" @selected(old('type', $credential->type ?? 'ssh') === $type)>{{ strtoupper($type) }}</option>
            @endforeach
            @if(isset($credential) && ! in_array($credential->type, \App\Models\Credential::SELECTABLE_TYPES, true))
                <option value="{{ $credential->type }}" @selected(old('type', $credential->type) === $credential->type)>{{ strtoupper($credential->type) }} (legado)</option>
            @endif
        </select>
        @error('type') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="username">Usuário *</label>
        <input class="form-control" id="username" name="username" type="text" value="{{ old('username', $credential->username ?? '') }}" maxlength="255" autocomplete="off" required>
        @error('username') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="port">Porta</label>
        <input class="form-control" id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $credential->port ?? '') }}">
        @error('port') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field form-span-2">
        <label class="form-label" for="secret"><span data-credential-secret-label>{{ in_array(old('type', $credential->type ?? 'ssh'), \App\Models\Credential::SELECTABLE_TYPES, true) ? 'Senha' : 'Segredo' }}</span> {{ isset($credential) ? '' : '*' }}</label>
        <div class="credential-secret-control">
            <input class="form-control" id="secret" name="secret" type="password" value="" autocomplete="new-password" @if(isset($credential)) placeholder="••••••••••••" @endif @required(! isset($credential))>
            <button type="button" class="credential-secret-control__toggle" data-toggle-credential-secret @isset($credential) data-credential-reveal-url="{{ route('credentials.secret.reveal', $credential) }}" @endisset aria-label="Mostrar {{ in_array(old('type', $credential->type ?? 'ssh'), \App\Models\Credential::SELECTABLE_TYPES, true) ? 'senha' : 'segredo' }} {{ isset($credential) ? 'atual' : 'digitado' }}" aria-pressed="false"><x-icon name="eye" size="sm" /></button>
        </div>
        @if(isset($credential))
            <small>Deixe em branco para manter <span data-credential-secret-hint>{{ in_array(old('type', $credential->type ?? 'ssh'), \App\Models\Credential::SELECTABLE_TYPES, true) ? 'a senha atual' : 'o segredo atual' }}</span>. Use o olho para visualizar.</small>
        @endif
        <span class="form-error" data-credential-secret-error hidden></span>
        @error('secret') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field form-span-2" data-credential-ssh-test @if(old('type', $credential->type ?? '') !== 'ssh') hidden @endif>
        <button type="button" class="btn btn--secondary" data-run-credential-ssh-test>Testar conexão SSH</button>
        <small>Confere a conexão e o login com os dados informados. Não executa backup.</small>
        <p class="alert" data-credential-ssh-result role="status" hidden></p>
    </div>

    <div class="form-field form-span-2">
        <label class="form-label" for="notes">Observações</label>
        <textarea class="form-control" id="notes" name="notes" rows="4" maxlength="2000" placeholder="Informações adicionais sobre a credencial.">{{ old('notes', $credential->notes ?? '') }}</textarea>
        @error('notes') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field form-span-2">
        <input type="hidden" name="is_active" value="0">
        <label class="switch-row">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', isset($credential) ? $credential->is_active : true))>
            <span><strong>Credencial ativa</strong><small>Credenciais inativas permanecem cadastradas.</small></span>
        </label>
    </div>
</div>

@if(($createModal ?? false) || ($editModal ?? false))
    </div>
@endif

<div class="form-actions {{ (($createModal ?? false) || ($editModal ?? false)) ? 'modal__footer form-create-modal__footer' : '' }}">
    @if($createModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-credential-create>Cancelar</button>
    @elseif($editModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-credential-edit>Cancelar</button>
    @else
        <a href="{{ route('credentials.index') }}" class="btn btn--ghost">Cancelar</a>
    @endif
    <button type="submit" class="btn btn--primary">{{ isset($credential) ? 'Salvar alterações' : 'Cadastrar credencial' }}</button>
</div>
