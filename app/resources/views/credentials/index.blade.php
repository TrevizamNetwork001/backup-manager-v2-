@extends('layouts.app')

@section('title', 'Credenciais — Backup Manager')
@section('page-title', 'Credenciais')
@section('page-description', 'Gerencie o acesso aos equipamentos.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Credenciais</h1>
        <p class="page-header__description">Gerencie o acesso aos equipamentos.</p>
    </div>
    <div class="page-header__actions">
        @can('credentials.manage')
            <button type="button" class="btn btn--primary" data-open-credential-create @disabled($devices->isEmpty())><x-icon name="add" size="sm" /> Nova Credencial</button>
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="credentials-list stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
    @endif
    @if($devices->isEmpty())
        <div class="alert alert--warning" role="status">
            Nenhum equipamento está disponível.
            @can('devices.manage') <a class="link" href="{{ route('devices.index') }}">Cadastre um equipamento</a> antes de adicionar credenciais. @else Cadastre um equipamento antes de adicionar credenciais. @endcan
        </div>
    @endif

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $credentials->total() }}</strong> {{ $credentials->total() === 1 ? 'credencial cadastrada' : 'credenciais cadastradas' }}</span>
        </div>
    </div>

    @if($credentials->isEmpty())
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="key" /></div>
            <h2 class="empty-state__title">Nenhuma credencial cadastrada</h2>
            <p class="empty-state__description">Cadastre uma credencial para um equipamento.</p>
            @can('credentials.manage')
                <div class="empty-state__actions"><button type="button" class="btn btn--secondary" data-open-credential-create @disabled($devices->isEmpty())>Cadastrar primeira credencial</button></div>
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de credenciais" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Equipamento</th>
                        <th>Tipo</th>
                        <th>Usuário</th>
                        <th>Porta</th>
                        <th>Status</th>
                        <th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($credentials as $credential)
                        <tr>
                            <td data-label="Nome"><span class="entity-cell__title">{{ $credential->name }}</span></td>
                            <td data-label="Equipamento">{{ $credential->device->name }}</td>
                            <td data-label="Tipo"><span class="tech-value">{{ strtoupper($credential->type) }}</span></td>
                            <td data-label="Usuário"><span class="tech-value">{{ $credential->username }}</span></td>
                            <td data-label="Porta">{{ $credential->port ?? '—' }}</td>
                            <td data-label="Status"><span class="badge badge--{{ $credential->is_active ? 'success' : 'neutral' }}">{{ $credential->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                            <td data-label="Ações">
                                @canany(['credentials.manage', 'credentials.disable'])
                                <details class="row-menu"><summary>Ações</summary><div class="table-actions">
                                    @can('credentials.manage')
                                        <a href="{{ route('credentials.edit', $credential) }}" class="btn btn--ghost btn--sm">Editar</a>
                                    @endcan
                                    @can('credentials.disable')
                                        <form method="POST" action="{{ route('credentials.destroy', $credential) }}" onsubmit="return confirm('Remover esta credencial? Só é possível sem vínculos ativos.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                        </form>
                                    @endcan
                                </div></details>
                                @endcanany
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($credentials->hasPages())
            <div class="pagination-container">{{ $credentials->links() }}</div>
        @endif
    @endif
</div>
@can('credentials.manage')
<dialog class="modal form-create-modal" id="credential-create-dialog" aria-labelledby="credential-create-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="credential-create-title">Nova Credencial</h2><p class="modal__description">Associe o acesso a um equipamento e informe os dados de autenticação.</p></div>
            <button type="button" class="modal__close" data-close-credential-create aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('credentials.store') }}">
            @include('credentials._form', ['createModal' => true])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('credential-create-dialog');
    document.querySelectorAll('[data-open-credential-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-credential-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endcan
@endsection
