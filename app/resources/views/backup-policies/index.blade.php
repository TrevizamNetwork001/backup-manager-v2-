@extends('layouts.app')

@section('title', 'Políticas — Backup Manager')
@section('page-title', 'Políticas de Backup')
@section('page-description', 'Defina políticas reutilizáveis e associe equipamentos.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Políticas de Backup</h1>
        <p class="page-header__description">Defina políticas reutilizáveis e associe equipamentos.</p>
    </div>
    <div class="page-header__actions">
        @can('backup_policies.manage')
            <button type="button" class="btn btn--primary" data-open-policy-create><x-icon name="add" size="sm" /> Nova Política</button>
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="backup-policies-list stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert--warning" role="alert" data-auto-dismiss>{{ session('warning') }}</div>
    @endif

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $policies->total() }}</strong> {{ $policies->total() === 1 ? 'política cadastrada' : 'políticas cadastradas' }}</span>
        </div>
    </div>

    @if($policies->isEmpty())
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="clock" /></div>
            <h2 class="empty-state__title">Nenhuma política cadastrada</h2>
            <p class="empty-state__description">Crie uma política para definir método, agendamento e retenção.</p>
            @can('backup_policies.manage')
                <div class="empty-state__actions"><button type="button" class="btn btn--secondary" data-open-policy-create>Cadastrar primeira política</button></div>
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de políticas de backup" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th><th>Método</th><th>Artefato</th><th>Início do backup</th>
                        <th>Retenção</th><th>Equipamentos</th><th>Status</th><th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($policies as $policy)
                        <tr>
                            <td data-label="Nome"><span class="entity-cell__title">{{ $policy->name }}</span></td>
                            <td data-label="Método">{{ $policy->method === 'ssh_pull' ? 'Coleta via SSH' : 'Envio via FTP' }}</td>
                            <td data-label="Artefato">{{ ['config' => 'Configuração', 'binary' => 'Binário', 'both' => 'Ambos'][$policy->artifact_mode] }}</td>
                            <td data-label="Início do backup">
                                @if($policy->method === 'ftp_push') Envio pelo equipamento
                                @elseif($policy->schedule_type === 'manual') Manual
                                @elseif($policy->schedule_type === 'daily') Todos os dias às {{ substr($policy->schedule_time, 0, 5) }}
                                @else {{ \App\Models\BackupPolicy::WEEKDAYS[$policy->schedule_weekday] }} {{ substr($policy->schedule_time, 0, 5) }}
                                @endif
                            </td>
                            <td data-label="Retenção">
                                @if($policy->retention_days) {{ $policy->retention_days }} dias @endif
                                @if($policy->retention_days && $policy->retention_count) · @endif
                                @if($policy->retention_count) {{ $policy->retention_count }} arquivos @endif
                            </td>
                            <td data-label="Equipamentos">{{ $policy->device_backup_policies_count }}</td>
                            <td data-label="Status"><span class="badge badge--{{ $policy->is_active ? 'success' : 'neutral' }}">{{ $policy->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                            <td data-label="Ações">
                                @canany(['backup_policies.manage', 'backup_policies.delete'])
                                <details class="row-menu">
                                    <summary>Ações</summary>
                                    <div class="table-actions">
                                    @can('backup_policies.manage')
                                        <a href="{{ route('backup-policies.edit', [$policy, 'page' => $policies->currentPage()]) }}" class="btn btn--ghost btn--sm">Editar</a>
                                    @endcan
                                    @can('backup_policies.delete')
                                        <form method="POST" action="{{ route('backup-policies.destroy', $policy) }}" onsubmit="return confirm('Remover esta política do catálogo? Vínculos ativos precisam ser desativados; o histórico será preservado.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                        </form>
                                    @endcan
                                    </div>
                                </details>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($policies->hasPages())
            <div class="pagination-container">{{ $policies->links() }}</div>
        @endif
    @endif
</div>
@can('backup_policies.manage')
<dialog class="modal form-create-modal" id="policy-create-dialog" aria-labelledby="policy-create-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="policy-create-title">Nova Política</h2><p class="modal__description">Defina método, artefato, agendamento e retenção.</p></div>
            <button type="button" class="modal__close" data-close-policy-create aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('backup-policies.store') }}">
            @include('backup-policies._form', ['createModal' => true])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('policy-create-dialog');
    document.querySelectorAll('[data-open-policy-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-policy-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endcan
@endsection
