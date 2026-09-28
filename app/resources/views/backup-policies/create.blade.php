@extends('layouts.app')
@section('title', 'Nova Política — Backup Manager')
@section('page-title', 'Nova Política')
@section('page-description', 'Defina método, artefato, agendamento e retenção.')
@section('content')
<div class="modern-form-page stack"><article class="card">
    <div class="card__header"><div><h2 class="card__title">Dados da política</h2></div></div>
    <form class="card__body" method="POST" action="{{ route('backup-policies.store') }}">@include('backup-policies._form')</form>
</article></div>
@endsection
