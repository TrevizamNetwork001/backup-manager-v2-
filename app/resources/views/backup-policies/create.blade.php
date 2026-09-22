@extends('layouts.app')
@section('title', 'Nova Política — Backup Manager')
@section('page-title', 'Nova Política')
@section('page-description', 'Defina método, artefato, agendamento e retenção.')
@section('content')
<div class="page-width"><article class="panel form-panel">
    <div class="panel-header"><div><h2>Dados da política</h2></div></div>
    <form method="POST" action="{{ route('backup-policies.store') }}">@include('backup-policies._form')</form>
</article></div>
@endsection
