@extends('layouts.app')
@section('title', 'Gerenciar Política — Backup Manager')
@section('page-title', 'Gerenciar Política')
@section('page-description', 'Atualize a política e seus equipamentos associados.')
@section('page-header')
<header class="page-header">
    <div class="page-header__content"><h1 class="page-header__title">Editar política</h1><p class="page-header__description">Atualize a política e seus equipamentos associados.</p></div>
    <div class="page-header__actions"><a href="{{ route('backup-policies.index', request('page') > 1 ? ['page' => request('page')] : []) }}" class="btn btn--ghost">Voltar à lista</a></div>
</header>
@endsection
@section('content')
<div class="modern-form-page stack">
@if(session('success')) <div class="alert alert--success" role="status">{{ session('success') }}</div> @endif
@if(session('warning')) <div class="alert alert--warning" role="alert">{{ session('warning') }}</div> @endif
@error('association') <div class="alert alert--warning" role="alert">{{ $message }}</div> @enderror
<article class="card">
    <div class="card__header"><div><h2 class="card__title">{{ $backupPolicy->name }}</h2></div></div>
    <div class="card__body"><button type="button" class="btn btn--secondary" data-open-policy-edit>Editar dados da política</button></div>
</article>
<dialog class="modal form-create-modal" id="policy-edit-dialog" aria-labelledby="policy-edit-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="policy-edit-title">Editar política</h2><p class="modal__description">Atualize método, artefato, agendamento e retenção.</p></div>
            <button type="button" class="modal__close" data-close-policy-edit aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('backup-policies.update', $backupPolicy) }}">@include('backup-policies._form', ['editModal' => true])</form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('policy-edit-dialog');
    const listUrl = @json(route('backup-policies.index', request('page') > 1 ? ['page' => request('page')] : []));
    document.querySelector('[data-open-policy-edit]').addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-close-policy-edit]').forEach(button => button.addEventListener('click', () => location.replace(listUrl)));
    dialog.addEventListener('click', event => { if (event.target === dialog) location.replace(listUrl); });
    dialog.addEventListener('cancel', event => { event.preventDefault(); location.replace(listUrl); });
    dialog.showModal();
})();
</script>

<article class="card policy-associations">
    <div class="card__header"><div><h2 class="card__title">Equipamentos associados</h2><p class="card__description">Consulte os equipamentos que usam esta política. Para alterar vínculos, acesse Equipamentos → Ações → Política de backup.</p></div><a href="{{ route('devices.index') }}" class="btn btn--secondary btn--sm">Ver equipamentos</a></div>
    <div class="card__body">
    @if($backupPolicy->deviceBackupPolicies->isEmpty())
        <p class="muted-text">Nenhum equipamento associado.</p>
    @else
        <div class="table-shell" role="region" aria-label="Equipamentos associados à política" tabindex="0"><table class="data-table">
            <thead><tr><th>Equipamento</th><th>Credencial</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>@foreach($backupPolicy->deviceBackupPolicies as $association)
                <tr>
                    <td data-label="Equipamento"><strong>{{ $association->device->name }}</strong></td>
                    <td data-label="Credencial">{{ $association->credential ? $association->credential->name.' · SSH · '.$association->credential->username : 'Conta FTP do equipamento' }}</td>
                    <td data-label="Status"><span class="badge badge--{{ $association->is_active ? 'success' : 'neutral' }}">{{ $association->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                    <td data-label="Ações" class="table-actions">
                        @can('backup_executions.run')
                        @if($association->is_active && $backupPolicy->is_active && $association->device->is_active && ($association->credential?->is_active || ($backupPolicy->method === 'ftp_push' && $backupPolicy->schedule_type === 'manual' && $association->device->platform === 'olt')))
                            <form method="POST" action="{{ route('backup-policies.associations.executions.store', [$backupPolicy, $association]) }}">@csrf<button class="btn btn--ghost btn--sm" type="submit">Criar execução</button></form>
                        @elseif($backupPolicy->method === 'ftp_push' && $association->device->platform === 'network')
                            <span class="muted-text">Recebimento automático</span>
                        @endif
                        @endcan
                    </td>
                </tr>
            @endforeach</tbody>
        </table></div>
    @endif

    </div>
</article>
</div>
@endsection
