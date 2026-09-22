@extends('layouts.app')

@section('title', 'Editar Site / POP — Backup Manager')
@section('page-title', 'Editar Site / POP')
@section('page-description', 'Atualize as informações desta localização.')

@section('content')

<div class="page-width">

    <article class="panel form-panel">
        <div class="panel-header">
            <div>
                <h2>{{ $site->name }}</h2>
                <p>{{ $site->code ?: 'Sem código definido' }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('sites.update', $site) }}">
            @include('sites._form')
        </form>
    </article>

</div>

@endsection
