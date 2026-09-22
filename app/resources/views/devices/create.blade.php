@extends('layouts.app')

@section('title', 'Novo Equipamento — Backup Manager')
@section('page-title', 'Novo Equipamento')
@section('page-description', 'Cadastre um equipamento da infraestrutura.')

@section('content')

<div class="page-width">

    <article class="panel form-panel">

        <div class="panel-header">
            <div>
                <h2>Dados do equipamento</h2>
                <p>Defina a identificação e localização do equipamento.</p>
            </div>
        </div>

        @if($sites->isEmpty())
            <div class="alert-warning">
                Nenhum Site / POP ativo está disponível.
                Cadastre ou ative um Site / POP antes de adicionar equipamentos.
            </div>
        @endif

        <form method="POST" action="{{ route('devices.store') }}">
            @include('devices._form')
        </form>

    </article>

</div>

@endsection
