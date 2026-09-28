@extends('layouts.app')

@section('title', 'Editar Credencial — Backup Manager')
@section('page-title', 'Editar Credencial')
@section('page-description', 'Atualize os dados da credencial.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content"><h1 class="page-header__title">Editar credencial</h1><p class="page-header__description">Atualize os dados da credencial.</p></div>
    <div class="page-header__actions"><a href="{{ route('credentials.index') }}" class="btn btn--ghost">Voltar à lista</a></div>
</header>
@endsection
@section('content')
<div class="modern-form-page stack">
    <article class="card">
        <div class="card__header">
            <div><h2 class="card__title">{{ $credential->name }}</h2></div>
        </div>

        <form class="card__body" method="POST" action="{{ route('credentials.update', $credential) }}">
            @include('credentials._form')
        </form>
    </article>
</div>
@endsection
