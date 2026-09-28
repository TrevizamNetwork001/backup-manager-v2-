@csrf

@if(isset($credential))
    @method('PUT')
@endif

@if($createModal ?? false)
    <div class="modal__body form-create-modal__body">
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
        <label class="form-label" for="type">Tipo *</label>
        <select class="form-control" id="type" name="type" required>
            <option value="">Selecione...</option>
            @foreach(\App\Models\Credential::TYPES as $type)
                <option value="{{ $type }}" @selected(old('type', $credential->type ?? '') === $type)>{{ strtoupper($type) }}</option>
            @endforeach
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
        <label class="form-label" for="secret">Segredo {{ isset($credential) ? '' : '*' }}</label>
        <input class="form-control" id="secret" name="secret" type="password" value="" autocomplete="new-password" @if(isset($credential)) placeholder="••••••••••••" @endif @required(! isset($credential))>
        @if(isset($credential))
            <small>Deixe em branco para manter o segredo atual.</small>
        @endif
        @error('secret') <span class="form-error">{{ $message }}</span> @enderror
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

@if($createModal ?? false)
    </div>
@endif

<div class="form-actions {{ ($createModal ?? false) ? 'modal__footer form-create-modal__footer' : '' }}">
    @if($createModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-credential-create>Cancelar</button>
    @else
        <a href="{{ route('credentials.index') }}" class="btn btn--ghost">Cancelar</a>
    @endif
    <button type="submit" class="btn btn--primary">{{ isset($credential) ? 'Salvar alterações' : 'Cadastrar credencial' }}</button>
</div>
