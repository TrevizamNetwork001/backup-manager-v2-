@extends('layouts.app')

@section('title', 'Novo Equipamento — Backup Manager')
@section('page-title', 'Novo Equipamento')
@section('page-description', 'Cadastre um equipamento da infraestrutura.')

@section('content')

<div class="modern-form-page stack">

    <article class="card">

        <div class="card__header">
            <div>
                <h2 class="card__title">Dados do equipamento</h2>
                <p class="card__description">Defina a identificação e localização do equipamento.</p>
            </div>
        </div>

        @if($sites->isEmpty())
            <div class="alert-warning">
                Nenhum Site / POP ativo está disponível.
                Cadastre ou ative um Site / POP antes de adicionar equipamentos.
            </div>
        @endif

        <form class="card__body" method="POST" action="{{ route('devices.store') }}">
            @include('devices._form')
        </form>

    </article>

</div>

@endsection
