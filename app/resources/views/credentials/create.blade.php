@extends('layouts.app')

@section('title', 'Nova Credencial — Backup Manager')
@section('page-title', 'Nova Credencial')
@section('page-description', 'Cadastre o acesso a um equipamento.')

@section('content')
<div class="page-width">
    <article class="panel form-panel">
        <div class="panel-header">
            <div><h2>Dados da credencial</h2><p>Associe a credencial a um equipamento.</p></div>
        </div>

        @if($devices->isEmpty())
            <div class="alert-warning">Nenhum equipamento está disponível. Cadastre um equipamento antes de adicionar credenciais.</div>
        @endif

        <form method="POST" action="{{ route('credentials.store') }}">
            @include('credentials._form')
        </form>
    </article>
</div>
@endsection
