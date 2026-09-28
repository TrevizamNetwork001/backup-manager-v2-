@extends('layouts.app')

@section('title', 'Editar Site / POP — Backup Manager')
@section('page-title', 'Editar Site / POP')
@section('page-description', 'Atualize as informações desta localização.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content"><h1 class="page-header__title">Editar Site / POP</h1><p class="page-header__description">Atualize as informações desta localização.</p></div>
    <div class="page-header__actions"><a href="{{ route('sites.index') }}" class="btn btn--ghost">Voltar à lista</a></div>
</header>
@endsection
@section('content')

<div class="modern-form-page stack">

    <article class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">{{ $site->name }}</h2>
                <p class="card__description">{{ $site->code ?: 'Sem código definido' }}</p>
            </div>
        </div>

        <form class="card__body" method="POST" action="{{ route('sites.update', $site) }}">
            @include('sites._form')
        </form>
    </article>

</div>

@endsection
