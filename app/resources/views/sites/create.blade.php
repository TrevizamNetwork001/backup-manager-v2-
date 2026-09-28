@extends('layouts.app')

@section('title', 'Novo Site / POP — Backup Manager')
@section('page-title', 'Novo Site / POP')
@section('page-description', 'Cadastre uma localização lógica ou física da infraestrutura.')

@section('content')

<div class="modern-form-page stack">

    <article class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Dados do Site / POP</h2>
                <p class="card__description">Defina a identificação básica desta localização.</p>
            </div>
        </div>

        <form class="card__body" method="POST" action="{{ route('sites.store') }}">
            @include('sites._form')
        </form>
    </article>

</div>

@endsection
