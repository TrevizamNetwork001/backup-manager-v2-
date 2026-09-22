@extends('layouts.app')

@section('title', 'Dashboard — Backup Manager')

@section('page-title', 'Backup Health')

@section('page-description', 'Visão geral da proteção da infraestrutura.')

@section('content')

<div class="metrics-grid">

    <article class="metric-card">
        <span class="metric-label">Equipamentos</span>
        <strong class="metric-value">0</strong>
        <span class="metric-detail">cadastrados</span>
    </article>

    <article class="metric-card healthy">
        <span class="metric-label">Protegidos</span>
        <strong class="metric-value">0</strong>
        <span class="metric-detail">backup atualizado</span>
    </article>

    <article class="metric-card warning">
        <span class="metric-label">Atrasados</span>
        <strong class="metric-value">0</strong>
        <span class="metric-detail">requerem atenção</span>
    </article>

    <article class="metric-card danger">
        <span class="metric-label">Falhando</span>
        <strong class="metric-value">0</strong>
        <span class="metric-detail">com erro</span>
    </article>

</div>

<div class="dashboard-grid">

    <article class="panel">
        <div class="panel-header">
            <div>
                <h2>Estado dos backups</h2>
                <p>Resumo operacional dos equipamentos.</p>
            </div>

            <span class="badge neutral">Sem dados</span>
        </div>

        <div class="empty-state">
            <div class="empty-icon">↻</div>

            <h3>Nenhum equipamento cadastrado</h3>

            <p>
                Quando os primeiros equipamentos forem adicionados,
                a saúde dos backups aparecerá aqui.
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
