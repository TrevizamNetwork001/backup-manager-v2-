@extends('layouts.app')

@section('title', 'Configurações — Backup Manager')
@section('page-title', 'Configurações')
@section('page-description', 'Preferências operacionais da instância.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Configurações</h1>
        <p class="page-header__description">Preferências operacionais da instância.</p>
    </div>
</header>
@endsection

@section('content')
<div class="settings-page stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif

    <section class="card settings-card" aria-labelledby="timezone-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="timezone-title">Fuso horário</h2>
                <p class="card__description">Defina a região usada para exibir horários e agendar backups.</p>
            </div>
        </div>

        <div class="card__body">
            <p class="settings-current-time">Hora atual: <strong>{{ $currentTime }}</strong> <span>({{ $timezone }})</span></p>

            <form method="POST" action="{{ route('settings.update') }}" class="settings-form">
                @csrf
                @method('PUT')

                <div class="form-field">
                    <label class="form-label" for="timezone">Estado e fuso horário da instância</label>
                    <select class="form-control" id="timezone" name="timezone" required @error('timezone') aria-invalid="true" aria-describedby="timezone-error" @enderror>
                        @foreach($timezoneOptions as $identifier => $region)
                            <option value="{{ $identifier }}" @selected(old('timezone', $timezone) === $identifier)>{{ $region }}</option>
                        @endforeach
                    </select>
                    <small class="form-help">Escolha a região onde o servidor está instalado.</small>
                    @error('timezone') <span class="form-error" id="timezone-error">{{ $message }}</span> @enderror
                </div>

                @can('settings.manage')
                    <div class="settings-form__actions"><button class="btn btn--primary" type="submit">Salvar alterações</button></div>
                @endcan
            </form>
        </div>
    </section>
</div>
@endsection
