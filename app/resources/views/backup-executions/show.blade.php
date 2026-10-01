@extends('layouts.app')
@section('title', 'Execução #'.$backupExecution->id.' — Backup Manager')
@section('page-title', 'Execução #'.$backupExecution->id)
@section('page-description', 'Acompanhe o processamento e consulte o resultado do backup.')
@section('page-header')
<header class="page-header execution-page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Execução de backup</h1>
        <p class="page-header__description">Detalhes da execução #{{ $backupExecution->id }} e do arquivo coletado.</p>
    </div>
    <nav class="execution-breadcrumb" aria-label="Caminho"><a href="{{ route('backup-executions.index') }}">Execuções</a><x-icon name="chevron-right" size="sm" /><span>#{{ $backupExecution->id }}</span></nav>
</header>
@endsection
@section('content')
@php
    $statusLabels = \App\Support\OperationalLabels::EXECUTION_STATUSES;
    $statusLabel = $statusLabels[$backupExecution->status] ?? $backupExecution->status;
    $statusVariant = $backupExecution->status === 'succeeded' ? 'success' : (in_array($backupExecution->status, ['failed', 'timed_out'], true) ? 'danger' : (in_array($backupExecution->status, ['running', 'retry_wait'], true) ? 'warning' : 'neutral'));
    $isActive = in_array($backupExecution->status, ['pending', 'queued', 'running', 'retry_wait'], true);
@endphp
<div class="execution-detail-page execution-show-page stack">
    @if(session('success'))
        <div class="alert alert--success" role="status" @if(session('success_persistent')) data-persist="true" @endif>{{ session('success') }}</div>
    @endif
    @error('status')
        <div class="alert alert--danger" role="alert">{{ $message }}</div>
    @enderror

    <section class="card execution-overview execution-overview--reference" aria-labelledby="execution-overview-title">
        <div class="card__header execution-overview__header">
            <div class="execution-overview__identity">
                <span class="execution-overview__icon execution-overview__icon--{{ $statusVariant }}"><x-icon name="backup-status" size="lg" /></span>
                <div>
                <p class="execution-eyebrow">Execução #{{ $backupExecution->id }}</p>
                <h2 class="card__title" id="execution-overview-title">{{ $backupExecution->backupPolicy->name }}</h2>
                <p class="card__description">{{ $backupExecution->device->name }} · {{ $backupExecution->device->management_ip }} · {{ $backupExecution->device->site?->name ?? 'Sem site' }}</p>
                </div>
            </div>
            <span class="badge badge--{{ $statusVariant }}" data-execution-status="{{ $backupExecution->status }}" @if($isActive) data-status-url="{{ route('backup-executions.status', $backupExecution) }}" @endif aria-live="polite">{{ $statusLabel }}</span>
        </div>
        <div class="execution-overview__tags"><span>{{ \App\Support\OperationalLabels::EXECUTION_ORIGINS[$backupExecution->origin] ?? $backupExecution->origin }}</span><span>Tentativa {{ $backupExecution->attempt }}</span><span class="vendor-cell"><span>{{ $backupExecution->device->vendor }}{{ $backupExecution->device->model ? ' · '.$backupExecution->device->model : '' }}</span></span></div>
        <div class="card__body execution-overview__body">
            <dl class="execution-detail-grid execution-detail-grid--overview">
                <div class="execution-detail-field"><dt>Equipamento</dt><dd>{{ $backupExecution->device->name }} <span>#{{ $backupExecution->device_id }}</span></dd></div>
                <div class="execution-detail-field"><dt>Política</dt><dd>{{ $backupExecution->backupPolicy->name }} <span>#{{ $backupExecution->backup_policy_id }}</span></dd></div>
                <div class="execution-detail-field"><dt>Credencial</dt><dd>{{ $backupExecution->credential ? $backupExecution->credential->name.' · '.$backupExecution->credential->username.' · '.strtoupper($backupExecution->credential->type) : 'Conta FTP do equipamento' }}</dd></div>
                <div class="execution-detail-field"><dt>Associação</dt><dd>#{{ $backupExecution->device_backup_policy_id }}</dd></div>
                <div class="execution-detail-field"><dt>Criada em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->created_at) }}</dd></div>
                <div class="execution-detail-field"><dt>Iniciada em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->started_at) ?? 'Aguardando início' }}</dd></div>
                <div class="execution-detail-field"><dt>Finalizada em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->finished_at) ?? '—' }}</dd></div>
                @if($backupExecution->origin === 'scheduler')
                    <div class="execution-detail-field"><dt>Agendado para:</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->scheduled_for) ?? '—' }}</dd></div>
                @endif
            </dl>
        </div>
    </section>

    @if($backupExecution->origin === 'manual' && $backupExecution->backupPolicy->method === 'ftp_push' && $backupExecution->device->platform === 'olt')
        <section class="alert alert--info execution-instruction" aria-label="Orientação de envio FTP">
            <h3 class="alert__title">Envio manual pela OLT</h3>
            <p class="alert__description">Na OLT previamente configurada, envie o arquivo <code>bm-exec-{{ $backupExecution->id }}.cfg</code> para <code>{{ app(\App\Services\FtpServerSettings::class)->get()['host'] ?: 'IP_DO_SERVIDOR_FTP' }}</code>. O arquivo precisa chegar enquanto esta execução estiver ativa; a V2 não inicia o envio pela OLT.</p>
            <p class="alert__description">Execute manualmente: <code>backup configuration ftp {{ app(\App\Services\FtpServerSettings::class)->get()['host'] ?: 'IP_DO_SERVIDOR_FTP' }} bm-exec-{{ $backupExecution->id }}.cfg</code></p>
        </section>
    @endif

    @if($backupExecution->origin === 'manual' && $backupExecution->backupPolicy->method === 'ftp_push' && $backupExecution->device->platform === 'network')
        <section class="alert alert--info execution-instruction" aria-label="Orientação de envio FTP">
            <h3 class="alert__title">Envio FTP automático pelo roteador</h3>
            <p class="alert__description">Este equipamento envia os backups conforme o intervalo configurado no próprio Huawei. Não use uma execução manual FTP para roteadores ou switches; aguarde o próximo envio automático, que aparecerá como uma execução com origem “FTP recebido”.</p>
        </section>
    @endif

    @if($backupExecution->origin === 'ftp_received')
        <section class="card" aria-labelledby="ftp-received-title">
            <div class="card__header"><div><h2 class="card__title" id="ftp-received-title">Recebimento FTP</h2><p class="card__description">Dados do arquivo recebido e processado.</p></div></div>
            <div class="card__body">
                <dl class="execution-detail-grid execution-detail-grid--compact">
                    <div class="execution-detail-field"><dt>Conta FTP</dt><dd>#{{ $backupExecution->ftp_account_id }}</dd></div>
                    <div class="execution-detail-field"><dt>Arquivo</dt><dd class="text-technical">{{ $backupExecution->received_filename }}</dd></div>
                    <div class="execution-detail-field"><dt>Recebido em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->received_at) ?? '—' }}</dd></div>
                    <div class="execution-detail-field"><dt>Processado em</dt><dd>{{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->processing_at) ?? '—' }}</dd></div>
                </dl>
            </div>
        </section>
    @endif

    @if($backupExecution->error_code || $backupExecution->error_message)
        <section class="alert alert--danger execution-error" aria-labelledby="execution-error-title">
            <h3 class="alert__title" id="execution-error-title">Detalhes do erro</h3>
            @if($backupExecution->error_code)<p class="alert__description"><strong>Código:</strong> <code>{{ $backupExecution->error_code }}</code></p>@endif
            @if($backupExecution->error_message)<p class="alert__description">{{ $backupExecution->error_message }}</p>@endif
            @if(in_array($backupExecution->error_code, ['SSH_HOST_KEY_UNKNOWN', 'SSH_HOST_KEY_MISMATCH'], true) && $backupExecution->credential_id && auth()->user()->can('credentials.manage'))
                <a href="{{ route('credentials.index') }}" class="btn btn--secondary">Ver chave SSH em Credenciais</a>
            @endif
        </section>
    @endif

    @if($backupExecution->artifact)
        <section class="card" aria-labelledby="execution-artifact-title">
            <div class="card__header"><div><h2 class="card__title" id="execution-artifact-title">Artefato gerado</h2><p class="card__description">Arquivo associado a esta execução.</p></div><span class="badge badge--{{ $backupExecution->artifact->status === 'available' ? 'success' : ($backupExecution->artifact->status === 'deleted' ? 'neutral' : 'warning') }}">{{ $backupExecution->artifact->statusLabel() }}</span></div>
            <div class="card__body">
                <dl class="execution-detail-grid execution-detail-grid--compact">
                    <div class="execution-detail-field"><dt>Tipo</dt><dd><a class="link" href="{{ route('backup-artifacts.show', $backupExecution->artifact) }}">{{ $backupExecution->artifact->type }}</a></dd></div>
                    <div class="execution-detail-field"><dt>Tamanho</dt><dd>{{ number_format($backupExecution->artifact->size_bytes / 1024, 1, ',', '.') }} KB</dd></div>
                    <div class="execution-detail-field execution-detail-field--wide"><dt>SHA256</dt><dd class="text-technical">{{ $backupExecution->artifact->sha256 }}</dd></div>
                    @if($backupExecution->backupPolicy->method === 'ftp_push')
                        <div class="execution-detail-field"><dt>Armazenamento</dt><dd>OK</dd></div>
                        <div class="execution-detail-field"><dt>Integridade</dt><dd>OK</dd></div>
                        <div class="execution-detail-field"><dt>Análise de conteúdo</dt><dd>{{ ['recognized' => 'Reconhecido', 'warning' => 'Aviso', 'unknown' => 'Não reconhecido'][$contentAnalysis['status'] ?? ''] ?? 'Indisponível' }}</dd></div>
                    @endif
                </dl>
                @if($backupExecution->artifact->deleted_at)
                    <p class="execution-artifact-note">Arquivo {{ strtolower($backupExecution->artifact->deletionReasonLabel()) }} em {{ app(\App\Services\InstanceTimezone::class)->format($backupExecution->artifact->deleted_at) }}. A execução permanece concluída com sucesso.</p>
                @endif
            </div>
        </section>
    @endif

    @if($backupExecution->origin === 'manual' && in_array($backupExecution->status, ['pending', 'queued'], true))
        <section class="card execution-manual-actions" aria-labelledby="manual-actions-title">
            <div class="card__header"><div><h2 class="card__title" id="manual-actions-title">Execução manual</h2><p class="card__description">O processamento é executado em segundo plano pelo engine.</p></div></div>
            <div class="card__body execution-manual-actions__buttons">
                @if($backupExecution->status === 'pending')
                    <form method="POST" action="{{ route('backup-executions.queue', $backupExecution) }}">@csrf<button type="submit" class="btn btn--primary">Enfileirar execução</button></form>
                @endif
                <form method="POST" action="{{ route('backup-executions.cancel', $backupExecution) }}">@csrf<button type="submit" class="btn btn--secondary">Cancelar execução</button></form>
            </div>
        </section>
    @endif

    <nav class="execution-detail-footer" aria-label="Navegação da execução">
        <a href="{{ route('backup-executions.index') }}" class="btn btn--secondary">Voltar para execuções</a>
    </nav>
</div>
@if($isActive)
    <script>
    (() => {
        const statusBadge = document.querySelector('[data-execution-status][data-status-url]');
        if (!statusBadge) return;
    const poll = async () => {
        try {
                const response = await fetch(statusBadge.dataset.statusUrl, {
                    cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Status indisponível');
            const current = await response.json();
            if (typeof current.status !== 'string') throw new Error('Resposta de status inválida');
            if (current.status !== statusBadge.dataset.executionStatus) {
                const activeStatuses = ['pending', 'queued', 'running', 'retry_wait'];
                if (!activeStatuses.includes(current.status)) {
                    window.location.reload();
                    return;
                }

                const labels = { pending: 'Pendente', queued: 'Na fila', running: 'Em andamento', retry_wait: 'Aguardando nova tentativa' };
                const variant = ['running', 'retry_wait'].includes(current.status) ? 'warning' : 'neutral';
                statusBadge.dataset.executionStatus = current.status;
                statusBadge.textContent = labels[current.status];
                statusBadge.classList.remove('badge--neutral', 'badge--warning', 'badge--success', 'badge--danger');
                statusBadge.classList.add(`badge--${variant}`);
            }
            } catch (_) {
                // Tenta novamente na próxima consulta sem interromper a tela.
            }
            window.setTimeout(poll, 5000);
        };
        window.setTimeout(poll, 5000);
    })();
    </script>
@endif
@endsection
