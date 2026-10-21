@extends('layouts.app')

@section('title', 'Token criado — Backup Manager')
@section('page-title', 'Token de integração')

@section('content')
<div class="settings-page stack">
    <section class="card settings-card">
        <div class="card__header">
            <div>
                <h1 class="card__title">Token criado</h1>
                <p class="card__description">{{ $token->name }} · acesso somente leitura · válido até {{ $token->expires_at->format('d/m/Y H:i') }}</p>
            </div>
        </div>
        <div class="card__body stack">
            <div class="alert alert--warning" role="status">Copie este token agora e guarde em um cofre. Ele não poderá ser exibido novamente.</div>
            <div class="form-field">
                <label class="form-label" for="created_integration_token">Token</label>
                <input class="form-control" id="created_integration_token" value="{{ $plainToken }}" readonly autocomplete="off" spellcheck="false" onclick="this.select()">
            </div>
            <a class="btn btn--primary" href="{{ route('settings.edit', ['panel' => 'integration-api']) }}#integration-api">Voltar às configurações</a>
        </div>
    </section>
</div>
@endsection
