@extends('layouts.app')
@section('title', 'Configurações — Backup Manager')
@section('page-title', 'Configurações')
@section('page-description', 'Preferências operacionais da instância.')
@section('content')
<div class="page-width">
    @if(session('success')) <div class="alert-success">{{ session('success') }}</div> @endif
    <article class="panel form-panel">
        <div class="panel-header"><div><h2>Fuso horário</h2></div></div>
        <p><strong>Hora atual:</strong> {{ $currentTime }} ({{ $timezone }})</p>
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf @method('PUT')
            <div class="field">
                <label for="timezone">Fuso horário da instância</label>
                <select id="timezone" name="timezone" required>
                    @foreach($timezones as $identifier)
                        <option value="{{ $identifier }}" @selected(old('timezone', $timezone) === $identifier)>{{ $identifier }}</option>
                    @endforeach
                </select>
                @error('timezone') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-actions"><button type="submit">Salvar</button></div>
        </form>
    </article>
</div>
@endsection
