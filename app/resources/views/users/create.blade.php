@extends('layouts.app')

@section('title', 'Novo usuário — Backup Manager')
@section('page-title', 'Novo usuário')
@section('page-description', 'Cadastre uma nova conta de acesso ao sistema.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Novo usuário</h1>
        <p class="page-header__description">Cadastre uma nova conta de acesso ao sistema.</p>
    </div>
</header>
@endsection

@section('content')
<div class="user-create-page stack">
    <section class="card" aria-labelledby="user-create-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="user-create-title">Dados do usuário</h2>
                <p class="card__description">Defina identificação, papel e senha inicial.</p>
            </div>
        </div>
        <div class="card__body">
            <form method="POST" action="{{ route('users.store') }}">
                @include('users._form')
            </form>
        </div>
    </section>
</div>
@endsection
