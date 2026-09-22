@extends('layouts.app')
@section('title', 'Execução #'.$backupExecution->id.' — Backup Manager')
@section('page-title', 'Execução #'.$backupExecution->id)
@section('page-description', 'Registro de tentativa de backup; nenhuma operação real é executada nesta etapa.')
@section('content')
<div class="page-width">
@if(session('success')) <div class="alert-success">{{ session('success') }}</div> @endif
@error('status') <div class="alert-success">{{ $message }}</div> @enderror
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Dados da execução</h2></div><span class="badge {{ $backupExecution->status === 'succeeded' ? 'success' : ($backupExecution->status === 'failed' ? 'danger' : 'neutral') }}">{{ strtoupper($backupExecution->status) }}</span></div>
    <p><strong>Política:</strong> {{ $backupExecution->backupPolicy->name }} (#{{ $backupExecution->backup_policy_id }})</p>
    <p><strong>Equipamento:</strong> {{ $backupExecution->device->name }} (#{{ $backupExecution->device_id }})</p>
    <p><strong>Credencial:</strong> {{ $backupExecution->credential->name }} · {{ $backupExecution->credential->username }} · {{ strtoupper($backupExecution->credential->type) }}</p>
    <p><strong>Associação:</strong> #{{ $backupExecution->device_backup_policy_id }}</p>
    <p><strong>Origem:</strong> {{ ucfirst($backupExecution->origin) }} · <strong>Tentativa:</strong> {{ $backupExecution->attempt }}</p>
    <p><strong>Criado em:</strong> {{ $backupExecution->created_at?->format('d/m/Y H:i:s') }}</p>
    <p><strong>Iniciado em:</strong> {{ $backupExecution->started_at?->format('d/m/Y H:i:s') ?? '—' }}</p>
    <p><strong>Finalizado em:</strong> {{ $backupExecution->finished_at?->format('d/m/Y H:i:s') ?? '—' }}</p>
    @if($backupExecution->error_code)<p><strong>Código do erro:</strong> {{ $backupExecution->error_code }}</p>@endif
    @if($backupExecution->error_message)<p><strong>Erro:</strong> {{ $backupExecution->error_message }}</p>@endif
    <div class="form-actions"><a href="{{ route('backup-executions.index') }}" class="secondary-button">Voltar</a></div>
</article>
@if(in_array($backupExecution->status, ['pending', 'queued', 'running'], true))
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Ações de desenvolvimento</h2><p>Simulam a mudança de estado. Não iniciam backup real.</p></div></div>
    <div class="form-actions">
        @foreach(match ($backupExecution->status) { 'pending' => ['queue' => 'Enfileirar', 'cancel' => 'Cancelar'], 'queued' => ['start' => 'Iniciar', 'cancel' => 'Cancelar'], 'running' => ['succeed' => 'Concluir com sucesso', 'fail' => 'Simular falha'] } as $action => $label)
            <form method="POST" action="{{ route('backup-executions.'.$action, $backupExecution) }}">@csrf<button type="submit" class="secondary-button">{{ $label }}</button></form>
        @endforeach
    </div>
</article>
@endif
</div>
@endsection
