@extends('layouts.app')
@section('title', 'Execução #'.$backupExecution->id.' — Backup Manager')
@section('page-title', 'Execução #'.$backupExecution->id)
@section('page-description', 'Estado e resultado da tentativa de backup.')
@section('content')
<div class="page-width">
@if(session('success')) <div class="alert-success">{{ session('success') }}</div> @endif
@error('status') <div class="alert-success">{{ $message }}</div> @enderror
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Dados da execução</h2></div><span class="badge {{ $backupExecution->status === 'succeeded' ? 'success' : ($backupExecution->status === 'failed' ? 'danger' : 'neutral') }}">{{ strtoupper($backupExecution->status) }}</span></div>
    <p><strong>Política:</strong> {{ $backupExecution->backupPolicy->name }} (#{{ $backupExecution->backup_policy_id }})</p>
    <p><strong>Equipamento:</strong> {{ $backupExecution->device->name }} (#{{ $backupExecution->device_id }})</p>
    <p><strong>Credencial:</strong> {{ $backupExecution->credential ? $backupExecution->credential->name.' · '.$backupExecution->credential->username.' · '.strtoupper($backupExecution->credential->type) : 'Conta FTP do equipamento' }}</p>
    @if($backupExecution->backupPolicy->method === 'ftp_push' && $backupExecution->origin === 'manual')
        <p><strong>Arquivo esperado:</strong> <code>bm-exec-{{ $backupExecution->id }}.cfg</code></p>
        <p>Na OLT previamente configurada, execute manualmente: <code>backup configuration ftp {{ config('backup.ftp_host') ?: 'IP_DO_SERVIDOR_FTP' }} bm-exec-{{ $backupExecution->id }}.cfg</code></p>
        <p>O arquivo deve chegar durante a execução. O envio da OLT não é iniciado pela V2.</p>
    @endif
    <p><strong>Associação:</strong> #{{ $backupExecution->device_backup_policy_id }}</p>
    <p><strong>Origem:</strong> {{ ucfirst($backupExecution->origin) }} · <strong>Tentativa:</strong> {{ $backupExecution->attempt }}</p>
    @if($backupExecution->origin === 'scheduler')<p><strong>Agendado para:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->scheduled_for) }}</p>@endif
    <p><strong>Criado em:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->created_at) }}</p>
    <p><strong>Iniciado em:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->started_at) ?? '—' }}</p>
    <p><strong>Finalizado em:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->finished_at) ?? '—' }}</p>
    @if($backupExecution->error_code)<p><strong>Código do erro:</strong> {{ $backupExecution->error_code }}</p>@endif
    @if($backupExecution->error_message)<p><strong>Erro:</strong> {{ $backupExecution->error_message }}</p>@endif
    @if($backupExecution->artifact)
        <p><strong>Artefato:</strong> <a href="{{ route('backup-artifacts.show', $backupExecution->artifact) }}">{{ $backupExecution->artifact->type }}</a> · {{ $backupExecution->artifact->statusLabel() }} · {{ number_format($backupExecution->artifact->size_bytes / 1024, 1, ',', '.') }} KB · SHA256: {{ substr($backupExecution->artifact->sha256, 0, 12) }}…</p>
        @if($backupExecution->artifact->deleted_at)<p>Artefato expirado/removido por {{ $backupExecution->artifact->deletionReasonLabel() }} em {{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->artifact->deleted_at) }}. A execução permanece concluída com sucesso.</p>@endif
    @endif
    <div class="form-actions"><a href="{{ route('backup-executions.index') }}" class="secondary-button">Voltar</a></div>
</article>
@if($backupExecution->origin === 'manual' && in_array($backupExecution->status, ['pending', 'queued'], true))
<article class="panel form-panel">
    <div class="panel-header"><div><h2>Execução manual</h2><p>Enfileirar libera o processamento assíncrono pelo engine.</p></div></div>
    <div class="form-actions">
        @foreach($backupExecution->status === 'pending' ? ['queue' => 'Enfileirar', 'cancel' => 'Cancelar'] : ['cancel' => 'Cancelar'] as $action => $label)
            <form method="POST" action="{{ route('backup-executions.'.$action, $backupExecution) }}">@csrf<button type="submit" class="secondary-button">{{ $label }}</button></form>
        @endforeach
    </div>
</article>
@endif
</div>
@endsection
