@extends('layouts.app')

@section('title', 'Relatórios — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Relatórios</h1>
        <p class="page-header__description">Consultas operacionais com filtros e exportação em CSV.</p>
    </div>
</header>
@endsection

@section('content')
<div class="grid grid--3">
    <a class="card" href="{{ route('reports.executions') }}">
        <div class="card__header"><h2 class="card__title">Execuções</h2></div>
        <div class="card__body"><p class="card__description">Histórico de backups com taxa de sucesso e filtros por site, equipamento, vendor, política e erro.</p></div>
    </a>
    <a class="card" href="{{ route('reports.devices') }}">
        <div class="card__header"><h2 class="card__title">Equipamentos</h2></div>
        <div class="card__body"><p class="card__description">Último backup, última falha, saúde e falhas consecutivas por equipamento.</p></div>
    </a>
    <a class="card" href="{{ route('reports.failures') }}">
        <div class="card__header"><h2 class="card__title">Falhas</h2></div>
        <div class="card__body"><p class="card__description">Erros agrupados por código, com equipamentos mais afetados.</p></div>
    </a>
    <a class="card" href="{{ route('backup-health.index') }}">
        <div class="card__header"><h2 class="card__title">Status dos backups</h2></div>
        <div class="card__body"><p class="card__description">Mesmo serviço do health do engine — saudável, atenção, crítico e sem histórico por equipamento.</p></div>
    </a>
    <a class="card" href="{{ route('reports.artifacts') }}">
        <div class="card__header"><h2 class="card__title">Artefatos</h2></div>
        <div class="card__body"><p class="card__description">Arquivos de backup armazenados, tamanho, hash e status de lifecycle.</p></div>
    </a>
    <a class="card" href="{{ route('reports.ftp') }}">
        <div class="card__header"><h2 class="card__title">FTP</h2></div>
        <div class="card__body"><p class="card__description">Contas de backup e file server: recebidos, quarentena e presos em processamento.</p></div>
    </a>
    <a class="card" href="{{ route('audit.index') }}">
        <div class="card__header"><h2 class="card__title">Auditoria</h2></div>
        <div class="card__body"><p class="card__description">Central de segurança e histórico completo de eventos administrativos.</p></div>
    </a>
</div>
@endsection
