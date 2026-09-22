@extends('layouts.app')

@section('title', 'Editar Equipamento — Backup Manager')
@section('page-title', 'Editar Equipamento')
@section('page-description', 'Atualize as informações do equipamento.')

@section('content')

<div class="page-width">

    <article class="panel form-panel">

        <div class="panel-header">
            <div>
                <h2>{{ $device->name }}</h2>
                <p>{{ $device->management_ip }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('devices.update', $device) }}">
            @include('devices._form')
        </form>

    </article>

    <article class="panel form-panel">
        <div class="panel-header"><h2>SSH Host Key</h2></div>
        @php
            $mismatch = $device->ssh_host_key_fingerprint && $device->ssh_observed_fingerprint &&
                ($device->ssh_host_key_algorithm !== $device->ssh_observed_algorithm ||
                 $device->ssh_host_key_fingerprint !== $device->ssh_observed_fingerprint);
        @endphp
        <p>Status: {{ $mismatch ? 'Chave alterada — backups bloqueados até aprovação' : ($device->ssh_host_key_fingerprint ? 'Confiada' : 'Não confiada') }}</p>
        <p>Algoritmo confiado: {{ $device->ssh_host_key_algorithm ?? '—' }}</p>
        <p>Fingerprint confiado: {{ $device->ssh_host_key_fingerprint ?? '—' }}</p>
        <p>Algoritmo observado: {{ $device->ssh_observed_algorithm ?? '—' }}</p>
        <p>Fingerprint observado: {{ $device->ssh_observed_fingerprint ?? '—' }}</p>
        @if ($device->ssh_host_key_trusted_at)
            <p>Confiada em: {{ $device->ssh_host_key_trusted_at->format('d/m/Y H:i') }}</p>
        @endif
        @error('ssh_host_key') <p>{{ $message }}</p> @enderror
        @if ($device->ssh_observed_fingerprint && ($mismatch || ! $device->ssh_host_key_fingerprint))
            <form method="POST" action="{{ route('devices.ssh-host-key.trust', $device) }}">
                @csrf
                <button type="submit">Confiar nesta chave observada</button>
            </form>
        @endif
    </article>

</div>

@endsection
