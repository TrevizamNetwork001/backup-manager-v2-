@extends('layouts.app')

@section('title', 'Editar Credencial — Backup Manager')
@section('page-title', 'Editar Credencial')
@section('page-description', 'Atualize os dados da credencial.')

@section('content')
<div class="page-width">
    <article class="panel form-panel">
        <div class="panel-header">
            <div><h2>{{ $credential->name }}</h2></div>
        </div>

        <form method="POST" action="{{ route('credentials.update', $credential) }}">
            @include('credentials._form')
        </form>
    </article>
</div>
@endsection
