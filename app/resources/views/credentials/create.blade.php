@extends('layouts.app')

@section('title', 'Nova Credencial — Backup Manager')
@section('page-title', 'Nova Credencial')
@section('page-description', 'Cadastre o acesso a um equipamento.')

@section('content')
<div class="modern-form-page stack">
    <article class="card">
        <div class="card__header">
            <div><h2 class="card__title">Dados da credencial</h2><p class="card__description">Associe a credencial a um equipamento.</p></div>
        </div>

        @if($devices->isEmpty())
            <div class="alert-warning">Nenhum equipamento está disponível. Cadastre um equipamento antes de adicionar credenciais.</div>
        @endif

        <form class="card__body" method="POST" action="{{ route('credentials.store') }}">
            @include('credentials._form')
        </form>
    </article>
</div>
@endsection
