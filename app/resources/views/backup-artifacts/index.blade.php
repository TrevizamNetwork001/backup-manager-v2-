@extends('layouts.app')
@section('title', 'Artefatos — Backup Manager')
@section('page-title', 'Artefatos')
@section('page-description', 'Exports de configuração validados e armazenados localmente.')
@section('content')
<div class="page-width"><article class="panel form-panel">
    <div class="panel-header"><div><h2>Artefatos</h2></div></div>
    @if($artifacts->isEmpty())<p class="muted-text">Nenhum artefato encontrado.</p>@else
    <div class="table-responsive"><table class="data-table"><thead><tr><th>ID</th><th>Equipamento</th><th>Política</th><th>Execução</th><th>Estado</th><th>Tipo</th><th>Tamanho</th><th>SHA256</th><th>Criado em</th></tr></thead><tbody>
    @foreach($artifacts as $artifact)<tr>
        <td><a class="table-action" href="{{ route('backup-artifacts.show', $artifact) }}">#{{ $artifact->id }}</a></td>
        <td>{{ $artifact->device->name }}</td><td>{{ $artifact->backupPolicy->name }}</td>
        <td><a class="table-action" href="{{ route('backup-executions.show', $artifact->backup_execution_id) }}">#{{ $artifact->backup_execution_id }}</a></td>
        <td>{{ $artifact->statusLabel() }}@if($artifact->deleted_at)<br><small>{{ app(\App\Services\InstanceTimezone::class)->format($artifact->deleted_at, 'd/m/Y H:i') }} · {{ $artifact->deletionReasonLabel() }}</small>@endif</td>
        <td>{{ $artifact->type }}</td><td>{{ number_format($artifact->size_bytes / 1024, 1, ',', '.') }} KB</td>
        <td><code>{{ substr($artifact->sha256, 0, 12) }}…</code></td><td>{{ app(\App\Services\InstanceTimezone::class)->format($artifact->created_at, 'd/m/Y H:i') }}</td>
    </tr>@endforeach
    </tbody></table></div>{{ $artifacts->links() }}@endif
</article></div>
@endsection
