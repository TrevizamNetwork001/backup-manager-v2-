@csrf

@if(isset($device))
    @method('PUT')
@endif

@if(($createModal ?? false) || ($editModal ?? false))
    <div class="modal__body form-create-modal__body">
@endif

<div class="form-create-grid device-form-grid">

    <div class="form-field">
        <label class="form-label" for="name">Nome do equipamento *</label>

        <input class="form-control"
            id="name"
            name="name"
            type="text"
            value="{{ old('name', $device->name ?? '') }}"
            maxlength="255"
            required
            autofocus
        >

        <small class="form-help">Identificador principal nas telas e no histórico.</small>
        @error('name')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="management_ip">IP de gerenciamento *</label>

        <input class="form-control"
            id="management_ip"
            name="management_ip"
            type="text"
            value="{{ old('management_ip', $device->management_ip ?? '') }}"
            maxlength="45"
            placeholder="192.0.2.10"
            required
        >

        @error('management_ip')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="site_id">Site / POP *</label>

        <select class="form-control" id="site_id" name="site_id" required>
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
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        @php
            $selectedKind = old('device_kind', $device->device_kind ?? (($device->platform ?? null) === 'olt' ? 'olt' : null));
        @endphp
        <label class="form-label" for="device_kind">Tipo *</label>
        <select class="form-control" id="device_kind" name="device_kind" required>
            <option value="" @selected($selectedKind === null || $selectedKind === '')>Selecione...</option>
            <option value="olt" @selected($selectedKind === 'olt')>OLT</option>
            <option value="switch" @selected($selectedKind === 'switch')>Switch</option>
            <option value="router" @selected($selectedKind === 'router')>Roteador</option>
            <option value="firewall" @selected($selectedKind === 'firewall')>Firewall</option>
            @if($selectedKind === 'network')
                <option value="network" selected>Roteador / switch (não classificado)</option>
            @endif
        </select>
        @error('device_kind')
            <span class="form-error">{{ $message }}</span>
        @enderror
        @error('platform')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="device_function">Função</label>
        <input class="form-control" id="device_function" name="device_function" type="text"
            value="{{ old('device_function', $device->device_function ?? '') }}" maxlength="100" placeholder="Ex.: BGP, BNG, Core">
        <small class="form-help">Informe a função do equipamento, como BGP, BNG, Core, Firewall, CGNAT ou Acesso.</small>
        @error('device_function')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="vendor">Fabricante *</label>

        @php
            $vendorValue = old('vendor', $device->vendor ?? '');
            $selectedVendor = is_string($vendorValue) ? \App\Models\Device::normalizeVendor($vendorValue) : '';
        @endphp
        <select class="form-control" id="vendor" name="vendor" required>
            <option value="" @selected($selectedVendor === '')>Selecione...</option>
            @foreach(\App\Models\Device::vendorOptions($device->vendor ?? null) as $vendor)
                <option value="{{ $vendor }}" @selected($selectedVendor === $vendor)>{{ $vendor }}{{ in_array($vendor, \App\Models\Device::VENDORS, true) ? '' : ' (legado)' }}</option>
            @endforeach
        </select>

        @error('vendor')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="model">Modelo</label>

        <input class="form-control"
            id="model"
            name="model"
            type="text"
            value="{{ old('model', $device->model ?? '') }}"
            maxlength="255"
            placeholder="CCR2004-1G-12S+2XS"
        >

        @error('model')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="os_version">Versão / OS</label>

        <input class="form-control"
            id="os_version"
            name="os_version"
            type="text"
            value="{{ old('os_version', $device->os_version ?? '') }}"
            maxlength="255"
            placeholder="RouterOS 7.x"
        >

        @error('os_version')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field" data-a10-interface-field @if(old('vendor', $device->vendor ?? '') !== 'A10 Networks') hidden @endif>
        <label class="form-label" for="a10_transfer_interface">Saída do backup A10 *</label>
        <select class="form-control" id="a10_transfer_interface" name="a10_transfer_interface">
            <option value="">Selecione...</option>
            <option value="management" @selected(old('a10_transfer_interface', $device->a10_transfer_interface ?? '') === 'management')>Management</option>
            <option value="data" @selected(old('a10_transfer_interface', $device->a10_transfer_interface ?? '') === 'data')>Data</option>
        </select>
        <small class="form-help">Interface usada pelo A10 para enviar o backup ao servidor.</small>
        @error('a10_transfer_interface') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="expected_ftp_interval_hours">Intervalo esperado do FTP (horas)</label>
        <input class="form-control" id="expected_ftp_interval_hours" name="expected_ftp_interval_hours" type="number"
            min="1" max="720" value="{{ old('expected_ftp_interval_hours', $device->expected_ftp_interval_hours ?? '') }}"
            placeholder="24">
        <small class="form-help">Monitora a chegada dos arquivos, sem agendar o envio. Em Huawei, a gravação automática pode depender de mudanças na configuração; o alerta indica ausência de arquivo novo, não comprova falha. Deixe vazio se não houver prazo esperado.</small>
        @error('expected_ftp_interval_hours') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <div class="form-field form-span-2">
        <label class="form-label" for="notes">Observações</label>

        <textarea class="form-control"
            id="notes"
            name="notes"
            rows="4"
            maxlength="2000"
            placeholder="Informações adicionais sobre o equipamento."
        >{{ old('notes', $device->notes ?? '') }}</textarea>

        @error('notes')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field form-span-2">
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
<script>
(() => {
    const field = document.querySelector('[data-a10-interface-field]');
    const form = field?.closest('form');
    const vendor = form?.querySelector('[name="vendor"]');
    const select = field?.querySelector('select');
    if (!field || !vendor || !select) return;
    const sync = () => {
        const a10 = vendor.value === 'A10 Networks';
        field.hidden = !a10;
        select.disabled = !a10;
        select.required = a10;
    };
    vendor.addEventListener('change', sync);
    sync();
})();
</script>

@if(($createModal ?? false) || ($editModal ?? false))
    </div>
@endif

<div class="form-actions {{ (($createModal ?? false) || ($editModal ?? false)) ? 'modal__footer form-create-modal__footer' : '' }}">
    @if($createModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-device-create>Cancelar</button>
    @elseif($editModal ?? false)
        <a href="{{ route('devices.index') }}" class="btn btn--ghost" data-close-device-edit>Cancelar</a>
    @else
        <a href="{{ route('devices.index') }}" class="btn btn--ghost">Cancelar</a>
    @endif

    <button type="submit" class="btn btn--primary">
        {{ isset($device) ? 'Salvar alterações' : 'Cadastrar equipamento' }}
    </button>
</div>
