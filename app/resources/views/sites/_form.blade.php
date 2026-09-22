@csrf

@if(isset($site))
    @method('PUT')
@endif

<div class="form-grid">

    <div class="field form-span-2">
        <label for="name">Nome *</label>

        <input
            id="name"
            name="name"
            type="text"
            value="{{ old('name', $site->name ?? '') }}"
            maxlength="255"
            required
            autofocus
        >

        @error('name')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label for="code">Código</label>

        <input
            id="code"
            name="code"
            type="text"
            value="{{ old('code', $site->code ?? '') }}"
            maxlength="50"
            placeholder="POP-SP-01"
        >

        @error('code')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label for="location">Localização</label>

        <input
            id="location"
            name="location"
            type="text"
            value="{{ old('location', $site->location ?? '') }}"
            maxlength="255"
            placeholder="São Paulo - SP"
        >

        @error('location')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field form-span-2">
        <label for="description">Descrição</label>

        <textarea
            id="description"
            name="description"
            rows="5"
            maxlength="2000"
            placeholder="Informações opcionais sobre este Site / POP."
        >{{ old('description', $site->description ?? '') }}</textarea>

        @error('description')
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
                @checked(old('is_active', isset($site) ? $site->is_active : true))
            >

            <span>
                <strong>Site ativo</strong>
                <small>Sites inativos continuam no histórico, mas ficam fora da operação normal.</small>
            </span>
        </label>
    </div>

</div>

<div class="form-actions">
    <a href="{{ route('sites.index') }}" class="secondary-button">
        Cancelar
    </a>

    <button type="submit" class="primary-button inline-button">
        {{ isset($site) ? 'Salvar alterações' : 'Cadastrar Site / POP' }}
    </button>
</div>
