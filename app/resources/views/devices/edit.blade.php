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

</div>

@endsection
