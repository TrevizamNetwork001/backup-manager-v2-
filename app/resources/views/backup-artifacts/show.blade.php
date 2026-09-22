@extends('layouts.app')
@section('title', 'Artefato #'.$backupArtifact->id.' — Backup Manager')
@section('page-title', 'Artefato #'.$backupArtifact->id)
@section('page-description', 'Metadados do export validado.')
@section('content')
<div class="page-width"><article class="panel form-panel">
    <div class="panel-header"><div><h2>Metadados</h2></div></div>
    <p><strong>Equipamento:</strong> {{ $backupArtifact->device->name }}</p>
    <p><strong>Política:</strong> {{ $backupArtifact->backupPolicy->name }}</p>
    <p><strong>Execução:</strong> <a href="{{ route('backup-executions.show', $backupArtifact->backup_execution_id) }}">#{{ $backupArtifact->backup_execution_id }}</a></p>
    <p><strong>Tipo:</strong> {{ $backupArtifact->type }} · <strong>Storage:</strong> {{ $backupArtifact->storage }}</p>
    <p><strong>Tamanho:</strong> {{ number_format($backupArtifact->size_bytes) }} bytes</p>
    <p><strong>SHA256:</strong> <code>{{ $backupArtifact->sha256 }}</code></p>
    <p><strong>Path relativo:</strong> <code>{{ $backupArtifact->relative_path }}</code></p>
    <p><strong>Validado em:</strong> {{ $backupArtifact->validated_at?->format('d/m/Y H:i:s') }}</p>
</article></div>
@endsection
