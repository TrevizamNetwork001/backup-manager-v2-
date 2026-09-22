@csrf

@if(isset($credential))
    @method('PUT')
@endif

<div class="form-grid">
    <div class="field form-span-2">
        <label for="device_id">Equipamento *</label>
        <select id="device_id" name="device_id" required>
            <option value="">Selecione...</option>
            @foreach($devices as $device)
                <option value="{{ $device->id }}" @selected((string) old('device_id', $credential->device_id ?? '') === (string) $device->id)>{{ $device->name }}</option>
            @endforeach
        </select>
        @error('device_id') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field">
        <label for="name">Nome *</label>
        <input id="name" name="name" type="text" value="{{ old('name', $credential->name ?? '') }}" maxlength="255" required autofocus>
        @error('name') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field">
        <label for="type">Tipo *</label>
        <select id="type" name="type" required>
            <option value="">Selecione...</option>
            @foreach(\App\Models\Credential::TYPES as $type)
                <option value="{{ $type }}" @selected(old('type', $credential->type ?? '') === $type)>{{ strtoupper($type) }}</option>
            @endforeach
        </select>
        @error('type') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field">
        <label for="username">Usuário *</label>
        <input id="username" name="username" type="text" value="{{ old('username', $credential->username ?? '') }}" maxlength="255" autocomplete="off" required>
        @error('username') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field">
        <label for="port">Porta</label>
        <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $credential->port ?? '') }}">
        @error('port') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field form-span-2">
        <label for="secret">Segredo {{ isset($credential) ? '' : '*' }}</label>
        <input id="secret" name="secret" type="password" value="" autocomplete="new-password" @required(! isset($credential))>
        @if(isset($credential))
            <small>Deixe em branco para manter o segredo atual.</small>
        @endif
        @error('secret') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field form-span-2">
        <label for="notes">Observações</label>
        <textarea id="notes" name="notes" rows="4" maxlength="2000" placeholder="Informações adicionais sobre a credencial.">{{ old('notes', $credential->notes ?? '') }}</textarea>
        @error('notes') <span class="field-error">{{ $message }}</span> @enderror
    </div>

    <div class="field form-span-2">
        <input type="hidden" name="is_active" value="0">
        <label class="switch-row">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', isset($credential) ? $credential->is_active : true))>
            <span><strong>Credencial ativa</strong><small>Credenciais inativas permanecem cadastradas.</small></span>
        </label>
    </div>
</div>

<div class="form-actions">
    <a href="{{ route('credentials.index') }}" class="secondary-button">Cancelar</a>
    <button type="submit" class="primary-button inline-button">{{ isset($credential) ? 'Salvar alterações' : 'Cadastrar credencial' }}</button>
</div>
