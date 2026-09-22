@extends('layouts.app')

@section('title', 'Políticas — Backup Manager')
@section('page-title', 'Políticas de Backup')
@section('page-description', 'Defina políticas reutilizáveis e associe equipamentos.')

@section('content')
@if(session('success')) <div class="alert-success">{{ session('success') }}</div> @endif
@if(session('warning')) <div class="alert-warning">{{ session('warning') }}</div> @endif

<div class="page-toolbar">
    <div><strong>{{ $policies->total() }}</strong><span>{{ $policies->total() === 1 ? 'política cadastrada' : 'políticas cadastradas' }}</span></div>
    <a href="{{ route('backup-policies.create') }}" class="primary-button inline-button">+ Nova Política</a>
</div>

<article class="panel table-panel">
@if($policies->isEmpty())
    <div class="empty-state">
        <div class="empty-icon">◷</div>
        <h3>Nenhuma política cadastrada</h3>
        <p>Crie uma política para definir método, agendamento e retenção.</p>
        <a href="{{ route('backup-policies.create') }}" class="primary-button empty-action">Cadastrar primeira política</a>
    </div>
@else
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Nome</th><th>Método</th><th>Artefato</th><th>Agendamento</th><th>Retenção</th><th>Equipamentos</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
        @foreach($policies as $policy)
            <tr>
                <td><strong>{{ $policy->name }}</strong></td>
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
                <td><span class="badge {{ $policy->is_active ? 'success' : 'neutral' }}">{{ $policy->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                <td class="table-actions">
                    <a href="{{ route('backup-policies.edit', $policy) }}" class="table-action">Editar / Gerenciar</a>
                    <form method="POST" action="{{ route('backup-policies.destroy', $policy) }}" onsubmit="return confirm('Remover esta política?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="table-action danger-text">Remover</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @if($policies->hasPages()) <div class="pagination-container">{{ $policies->links() }}</div> @endif
@endif
</article>
@endsection
