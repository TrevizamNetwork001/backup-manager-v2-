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
            <a href="{{ route('backup-policies.create') }}" class="btn btn--primary"><x-icon name="add" size="sm" /> Nova Política</a>
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
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
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
                <div class="empty-state__actions"><a href="{{ route('backup-policies.create') }}" class="btn btn--secondary">Cadastrar primeira política</a></div>
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de políticas de backup" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th><th>Método</th><th>Artefato</th><th>Agendamento</th>
                        <th>Retenção</th><th>Equipamentos</th><th>Status</th><th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($policies as $policy)
                        <tr>
                            <td><span class="entity-cell__title">{{ $policy->name }}</span></td>
                            <td>{{ $policy->method === 'ssh_pull' ? 'SSH Pull' : 'FTP Push' }}</td>
                            <td>{{ ['config' => 'Config', 'binary' => 'Binário', 'both' => 'Ambos'][$policy->artifact_mode] }}</td>
                            <td>
                                @if($policy->schedule_type === 'manual') Manual
                                @elseif($policy->schedule_type === 'daily') Diário {{ substr($policy->schedule_time, 0, 5) }}
                                @else {{ \App\Models\BackupPolicy::WEEKDAYS[$policy->schedule_weekday] }} {{ substr($policy->schedule_time, 0, 5) }}
                                @endif
                            </td>
                            <td>
                                @if($policy->retention_days) {{ $policy->retention_days }} dias @endif
                                @if($policy->retention_days && $policy->retention_count) · @endif
                                @if($policy->retention_count) {{ $policy->retention_count }} arquivos @endif
                            </td>
                            <td>{{ $policy->device_backup_policies_count }}</td>
                            <td><span class="badge badge--{{ $policy->is_active ? 'success' : 'neutral' }}">{{ $policy->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                            <td>
                                <div class="table-actions">
                                    @can('backup_policies.manage')
                                        <a href="{{ route('backup-policies.edit', $policy) }}" class="btn btn--ghost btn--sm">Editar / Gerenciar</a>
                                    @endcan
                                    @can('backup_policies.delete')
                                        <form method="POST" action="{{ route('backup-policies.destroy', $policy) }}" onsubmit="return confirm('Remover esta política? Só é possível sem associações.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                        </form>
                                    @endcan
                                </div>
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
@endsection
