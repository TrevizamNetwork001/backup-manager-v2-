@extends('layouts.app')

@section('title', 'Novo Site / POP — Backup Manager')
@section('page-title', 'Novo Site / POP')
@section('page-description', 'Cadastre uma localização lógica ou física da infraestrutura.')

@section('content')

<div class="page-width">

    <article class="panel form-panel">
        <div class="panel-header">
            <div>
                <h2>Dados do Site / POP</h2>
                <p>Defina a identificação básica desta localização.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('sites.store') }}">
            @include('sites._form')
        </form>
    </article>

</div>

@endsection
