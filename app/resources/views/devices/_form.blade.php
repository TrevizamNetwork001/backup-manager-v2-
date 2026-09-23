@csrf

@if(isset($device))
    @method('PUT')
@endif

<div class="form-grid">

    <div class="field form-span-2">
        <label for="site_id">Site / POP *</label>

        <select id="site_id" name="site_id" required>
            <option value="">Selecione...</option>

            @foreach($sites as $site)
                <option
                    value="{{ $site->id }}"
                    @selected((string) old('site_id', $device->site_id ?? '') === (string) $site->id)
                >
                    {{ $site->name }}
                    @if($site->code)
                        — {{ $site->code }}
                    @endif
                </option>
            @endforeach
        </select>

        @error('site_id')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field form-span-2">
        <label for="name">Nome *</label>

        <input
            id="name"
            name="name"
            type="text"
            value="{{ old('name', $device->name ?? '') }}"
            maxlength="255"
            required
            autofocus
        >

        @error('name')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label for="hostname">Hostname</label>

        <input
            id="hostname"
            name="hostname"
            type="text"
            value="{{ old('hostname', $device->hostname ?? '') }}"
            maxlength="255"
            placeholder="router-borda-01"
        >

        @error('hostname')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label for="management_ip">IP de gerenciamento *</label>

        <input
            id="management_ip"
            name="management_ip"
            type="text"
            value="{{ old('management_ip', $device->management_ip ?? '') }}"
            maxlength="45"
            placeholder="192.0.2.10"
            required
        >

        @error('management_ip')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label for="vendor">Vendor *</label>

        <input
            id="vendor"
            name="vendor"
            type="text"
            value="{{ old('vendor', $device->vendor ?? '') }}"
            maxlength="100"
            placeholder="MikroTik"
            required
        >

        @error('vendor')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>
    <div class="field"><label for="platform">Tipo *</label><select id="platform" name="platform" required>
        <option value="network" @selected(old('platform', $device->platform ?? 'network') === 'network')>Roteador / switch</option>
        <option value="olt" @selected(old('platform', $device->platform ?? 'network') === 'olt')>OLT</option>
    </select>@error('platform') <span class="field-error">{{ $message }}</span> @enderror</div>

    <div class="field">
        <label for="model">Modelo</label>

        <input
            id="model"
            name="model"
            type="text"
            value="{{ old('model', $device->model ?? '') }}"
            maxlength="255"
            placeholder="CCR2004-1G-12S+2XS"
        >

        @error('model')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field form-span-2">
        <label for="os_version">Versão / OS</label>

        <input
            id="os_version"
            name="os_version"
            type="text"
            value="{{ old('os_version', $device->os_version ?? '') }}"
            maxlength="255"
            placeholder="RouterOS 7.x"
        >

        @error('os_version')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field form-span-2">
        <label for="notes">Observações</label>

        <textarea
            id="notes"
            name="notes"
            rows="4"
            maxlength="2000"
            placeholder="Informações adicionais sobre o equipamento."
        >{{ old('notes', $device->notes ?? '') }}</textarea>

        @error('notes')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field form-span-2">
        <input type="hidden" name="is_active" value="0">

        <label class="switch-row">
            <input
                type="checkbox"
                name="is_active"
                value="1"
                @checked(old('is_active', isset($device) ? $device->is_active : true))
            >

            <span>
                <strong>Equipamento ativo</strong>
                <small>
                    Equipamentos inativos continuam no histórico,
                    mas ficam fora da operação normal.
                </small>
            </span>
        </label>
    </div>

</div>

<div class="form-actions">
    <a href="{{ route('devices.index') }}" class="secondary-button">
        Cancelar
    </a>

    <button type="submit" class="primary-button inline-button">
        {{ isset($device) ? 'Salvar alterações' : 'Cadastrar equipamento' }}
    </button>
</div>
