@csrf

@if(isset($site))
    @method('PUT')
@endif

@if($createModal ?? false)
    <div class="modal__body form-create-modal__body">
@endif

<div class="form-create-grid">

    <div class="form-field form-span-2">
        <label class="form-label" for="name">Nome *</label>

        <input class="form-control"
            id="name"
            name="name"
            type="text"
            value="{{ old('name', $site->name ?? '') }}"
            maxlength="255"
            required
            autofocus
        >

        @error('name')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="code">Código</label>

        <input class="form-control"
            id="code"
            name="code"
            type="text"
            value="{{ old('code', $site->code ?? '') }}"
            maxlength="50"
            placeholder="POP-SP-01"
        >

        @error('code')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="location">Localização</label>

        <input class="form-control"
            id="location"
            name="location"
            type="text"
            value="{{ old('location', $site->location ?? '') }}"
            maxlength="255"
            placeholder="São Paulo - SP"
        >

        @error('location')
            <span class="form-error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-field form-span-2">
        <label class="form-label" for="description">Descrição</label>

        <textarea class="form-control"
            id="description"
            name="description"
            rows="5"
            maxlength="2000"
            placeholder="Informações opcionais sobre este Site / POP."
        >{{ old('description', $site->description ?? '') }}</textarea>

        @error('description')
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
                @checked(old('is_active', isset($site) ? $site->is_active : true))
            >

            <span>
                <strong>Site ativo</strong>
                <small>Sites inativos continuam no histórico, mas ficam fora da operação normal.</small>
            </span>
        </label>
    </div>

</div>

@if($createModal ?? false)
    </div>
@endif

<div class="form-actions {{ ($createModal ?? false) ? 'modal__footer form-create-modal__footer' : '' }}">
    @if($createModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-site-create>Cancelar</button>
    @else
        <a href="{{ route('sites.index') }}" class="btn btn--ghost">Cancelar</a>
    @endif

    <button type="submit" class="btn btn--primary">
        {{ isset($site) ? 'Salvar alterações' : 'Cadastrar Site / POP' }}
    </button>
</div>
