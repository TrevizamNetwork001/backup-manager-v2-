@extends('layouts.app')

@section('title', 'Dashboard — Backup Manager')

@section('page-title', 'Backup Health')

@section('page-description', 'Visão geral da proteção da infraestrutura.')

@section('content')

<div class="metrics-grid">

    <article class="metric-card">
        <span class="metric-label">Equipamentos</span>
        <strong class="metric-value">{{ $deviceCount }}</strong>
        <span class="metric-detail">cadastrados</span>
    </article>

    <article class="metric-card">
        <span class="metric-label">Políticas</span>
        <strong class="metric-value">{{ $policyCount }}</strong>
        <span class="metric-detail">cadastradas</span>
    </article>

    <article class="metric-card">
        <span class="metric-label">Execuções</span>
        <strong class="metric-value">{{ $executionCount }}</strong>
        <span class="metric-detail">registradas</span>
    </article>

</div>

<div class="dashboard-grid">

    <article class="panel">
        <div class="panel-header">
            <div>
                <h2>Execução de backups</h2>
                <p>O motor de backup ainda não está disponível.</p>
            </div>

            <span class="badge neutral">Sem dados</span>
        </div>

        <div class="empty-state">
            <div class="empty-icon">↻</div>

            <h3>Sem dados operacionais</h3>

            <p>
                As execuções cadastradas nesta etapa são registros de teste e preparação.
            </p>
        </div>
    </article>

    <article class="panel">
        <div class="panel-header">
            <div>
                <h2>Armazenamento</h2>
                <p>Estado das cópias locais e externas.</p>
            </div>
        </div>

        <div class="storage-row">
            <div>
                <strong>Storage local</strong>
                <span>Não configurado</span>
            </div>

            <span class="badge neutral">Pendente</span>
        </div>

        <div class="storage-row">
            <div>
                <strong>Réplica externa</strong>
                <span>Não configurada</span>
            </div>

            <span class="badge neutral">Pendente</span>
        </div>
    </article>

</div>

@endsection
