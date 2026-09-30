@extends('layouts.app')
@section('title', 'Conta FTP — Backup Manager')
@section('page-header')
<nav class="ftp-detail-breadcrumb" aria-label="Localização"><a href="{{ route('ftp.index') }}">FTP</a><span aria-hidden="true">›</span><a href="{{ route('ftp.index') }}">Contas</a><span aria-hidden="true">›</span><span aria-current="page">{{ $ftpAccount->username }}</span></nav>
<header class="page-header ftp-detail-header">
    <div class="ftp-detail-heading"><span class="ftp-detail-heading__icon"><x-icon name="ftp" /></span><div class="page-header__content"><div class="ftp-detail-title-row"><h1 class="page-header__title">Conta FTP: {{ $ftpAccount->username }}</h1><span class="ftp-detail-status {{ $ftpAccount->is_active ? 'is-active' : 'is-inactive' }}"><span aria-hidden="true">●</span> {{ $ftpAccount->is_active ? 'Ativa' : 'Desativada' }}</span></div><p class="page-header__description">Acesso e recebimentos da conta FTP.</p></div></div>
    <div class="page-header__actions">@can('ftp.manage')<button type="button" class="btn ftp-detail-rotate" id="ftp-rotate-open" @disabled($impact['deletion_mode'])><x-icon name="sync" /> Rotacionar senha</button>@endcan
        <details class="row-menu ftp-detail-menu"><summary aria-label="Mais opções da conta"><x-icon name="more-horizontal" /></summary><div class="table-actions"><button type="button" data-ftp-section="ftp-account-details">Detalhes da conta</button>@canany(['ftp.manage', 'ftp.delete'])<button type="button" data-ftp-section="ftp-account-management">Gerenciar conta</button>@endcanany<a href="{{ route('ftp.index') }}">Voltar ao FTP</a></div></details>
    </div>
</header>
@endsection
@section('content')
@php
    $server = app(\App\Services\FtpServerSettings::class)->get();
    $selectedDeletionMode = session()->hasOldInput('mode') ? old('mode') : ($errors->has('mode') ? null : 'account');
    $readyToReceive = $isHuaweiBackup
        ? $accountReady && $pureDbReady && $policyReady
        : $ftpAccount->is_active && !$ftpAccount->deletion_mode && $ftpAccount->provisioned_at !== null && !$ftpAccount->sync_error
            && (($ftpAccount->purpose ?? 'backup') === 'file_server' || $ftpAccount->device?->is_active);
@endphp
<div class="ftp-page ftp-detail-page">
@if ($errors->any()) <div class="alert alert--warning" role="alert">{{ $errors->first() }}</div> @endif
@if (session('status')) <div class="alert" role="status">{{ session('status') }}</div> @endif
@if ($impact['deletion_mode']) <div class="alert alert--warning" role="status">Exclusão solicitada ({{ $impact['deletion_mode'] }}). Aguardando PureDB e conclusão segura. {{ $impact['deletion_error'] }}</div> @endif
@if ($impact['deletion_mode'] && $impact['safety_error']) <div class="alert alert--warning" role="alert">Preview incompleto: {{ $impact['safety_error'] }}. Exclusão bloqueada até corrigir o path.</div> @endif
@if ($impact['deletion_mode'] && $impact['unattributed_stored_files']) <div class="alert alert--warning" role="alert">{{ $impact['unattributed_stored_files'] }} arquivo(s) do servidor de arquivos sem vínculo seguro. Eles serão preservados.</div> @endif
@if ($impact['deletion_mode'] && $impact['blocker']) <div class="alert alert--warning" role="alert">{{ $impact['blocker'] }}</div> @endif
<div class="ftp-detail-stats">
    <article class="ftp-detail-stat"><span class="ftp-detail-stat__icon {{ $readyToReceive ? 'is-ready' : 'is-pending' }}"><x-icon :name="$readyToReceive ? 'check-circle' : 'alert'" /></span><div><p>Pronto para receber</p><strong>{{ $readyToReceive ? 'SIM' : 'NÃO' }}</strong><small>{{ $readyToReceive ? (($ftpAccount->purpose ?? 'backup') === 'backup' ? 'Conta apta para receber backups.' : 'Conta apta para receber arquivos.') : 'Confira o status e a configuração da conta.' }}</small></div></article>
    <article class="ftp-detail-stat"><span class="ftp-detail-stat__icon"><x-icon name="server" /></span><div><p>Equipamento</p><strong>{{ $ftpAccount->device?->name ?? 'Nenhum' }}</strong><small>{{ $ftpAccount->device ? 'Equipamento vinculado a esta conta.' : 'Conta sem equipamento vinculado.' }}</small></div></article>
    <article class="ftp-detail-stat"><span class="ftp-detail-stat__icon is-receipt"><x-icon name="clock" /></span><div><p>Último recebimento</p><strong>{{ $lastReceipt ? app(\App\Services\InstanceTimezone::class)->format(\Illuminate\Support\Carbon::parse($lastReceipt->received_at), 'd/m/Y H:i') : 'Nunca recebeu' }}</strong><small>Arquivo: {{ $lastReceipt?->original_filename ?? 'Nenhum' }}</small></div></article>
</div>
@if($ftpAccount->purpose === 'backup' && $ftpAccount->credential_changed_at && (!$lastReceipt || $lastReceipt->received_at <= $ftpAccount->credential_changed_at->toDateTimeString()))<p class="alert alert--warning">Credencial alterada; aguardando novo recebimento. Atualize a senha no equipamento.</p>@endif
<section class="ftp-detail-receipts" aria-labelledby="ftp-receipts-title"><div class="ftp-detail-receipts__heading"><h2 id="ftp-receipts-title"><x-icon name="file" /> Recebimentos recentes</h2>@if($history->count() > 5)<button type="button" class="ftp-detail-history-toggle" aria-expanded="false" aria-controls="ftp-receipts-body" hidden>Ver todos <span aria-hidden="true">→</span></button>@endif</div>@if($history->isEmpty())<p class="ftp-detail-empty">Nenhum arquivo recebido.</p>@else<div class="table-shell" role="region" aria-label="Recebimentos recentes da conta FTP" tabindex="0"><table class="data-table ftp-detail-table"><thead><tr><th>Data e hora</th><th>Arquivo</th><th>Tamanho</th><th>Status</th><th>Destino / erro</th></tr></thead><tbody id="ftp-receipts-body">@foreach($history as $item)@php($receiptTone = match ($item->status) { 'stored' => 'success', 'quarantined' => 'warning', 'failed', 'error' => 'danger', default => 'neutral' })<tr><td data-label="Data e hora">{{ app(\App\Services\InstanceTimezone::class)->format(\Illuminate\Support\Carbon::parse($item->received_at), 'd/m/Y H:i:s') }}</td><td data-label="Arquivo">{{ $item->original_filename }}</td><td data-label="Tamanho">{{ $item->size_bytes }} bytes</td><td data-label="Status"><span class="ftp-receipt-status ftp-receipt-status--{{ $receiptTone }}">{{ \App\Support\OperationalLabels::FTP_STATUSES[$item->status] ?? $item->status }}</span></td><td data-label="Destino / erro"><span class="ftp-detail-destination" title="{{ $item->relative_path ?? $item->error_code ?? '—' }}">{{ $item->relative_path ?? $item->error_code ?? '—' }}</span></td></tr>@endforeach</tbody></table></div>@endif</section>
<div class="ftp-detail-accordions">
<details class="ftp-detail-accordion" id="ftp-account-details"><summary><x-icon name="user" /><strong>Detalhes da conta</strong><span>Usuário, finalidade, status e informações gerais.</span><x-icon name="chevron-down" class="ftp-detail-chevron" /></summary><div class="ftp-detail-accordion__body"><section class="ftp-detail-section"><h2>Identificação</h2><dl class="ftp-detail-list"><div><dt>Usuário</dt><dd><code>{{ $ftpAccount->username }}</code></dd></div><div><dt>Finalidade</dt><dd>{{ ($ftpAccount->purpose ?? 'backup') === 'backup' ? 'Backup' : 'Servidor de arquivos' }}</dd></div><div><dt>Equipamento</dt><dd>{{ $ftpAccount->device?->name ?? 'Nenhum' }}</dd></div><div><dt>Status</dt><dd>{{ $ftpAccount->is_active ? 'Ativa' : 'Desativada' }}</dd></div></dl></section>
</div></details>
<details class="ftp-detail-accordion" id="ftp-account-configuration"><summary><x-icon name="settings" /><strong>Configuração FTP</strong><span>Servidor, porta, path remoto e permissões.</span><x-icon name="chevron-down" class="ftp-detail-chevron" /></summary><div class="ftp-detail-accordion__body"><section class="ftp-detail-section"><h2>Acesso</h2><dl class="ftp-detail-list"><div><dt>Servidor</dt><dd class="tech-value">{{ $server['host'] ?: 'Não configurado' }}</dd></div><div><dt>Porta</dt><dd>21/TCP</dd></div><div><dt>Path remoto</dt><dd>/</dd></div><div><dt>Permissão</dt><dd>Perfil global do Pure-FTPd, sem ajuste por conta</dd></div></dl></section>
@if($isHuaweiBackup)
<section class="ftp-detail-section">
    <h2>Integração de backup FTP</h2>
    <dl class="ftp-detail-list"><div><dt>Conta FTP</dt><dd>{{ $accountReady ? 'OK' : 'Pendente' }}</dd></div><div><dt>PureDB</dt><dd>{{ $pureDbReady ? 'OK' : 'Pendente' }}</dd></div><div><dt>Política ftp_push</dt><dd>{{ $policyReady ? 'OK' : 'Ausente' }}</dd></div><div><dt>Pronto para receber backup</dt><dd>{{ $accountReady && $pureDbReady && $policyReady ? 'SIM' : 'NÃO' }}</dd></div></dl>
    @if($ftpAccount->sync_error)<p class="alert alert--warning" role="alert">{{ $ftpAccount->sync_error }}</p>@endif
    @can('ftp.manage')
    @if(!$policyReady && !$ftpAccount->deletion_mode)
        <form method="POST" action="{{ route('ftp.prepare', $ftpAccount) }}" id="ftp-prepare-form">
            @csrf
            <button type="submit" class="btn btn--secondary">Preparar backup FTP</button>
        </form>
    @endif
    @endcan
</section>
@endif
</div></details>
@canany(['ftp.manage', 'ftp.delete'])
<details class="ftp-detail-accordion" id="ftp-account-management"><summary><x-icon name="shield" /><strong>Gerenciar conta</strong><span>Ações administrativas para esta conta.</span><x-icon name="chevron-down" class="ftp-detail-chevron" /></summary><div class="ftp-detail-accordion__body">@can('ftp.manage')
<section class="ftp-account-actions"><div><h2>Estado da conta</h2><p>{{ $ftpAccount->is_active ? 'Desativar impede novos acessos FTP.' : 'Ativar permite novamente o acesso FTP.' }}</p></div><form method="POST" action="{{ route('ftp.status', $ftpAccount) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $ftpAccount->is_active ? 0 : 1 }}"><button type="submit" class="btn btn--secondary" @if($impact['deletion_mode']) disabled @endif>{{ $ftpAccount->is_active ? 'Desativar conta' : 'Ativar conta' }}</button></form></section>
@endcan
@can('ftp.delete')
<section class="ftp-account-actions"><div><h2>Zona de risco</h2><p>Confira os dados vinculados antes de excluir. A revogação do PureDB será confirmada antes da remoção.</p></div><button type="button" class="btn btn--secondary" id="ftp-delete-open" @if($impact['deletion_mode']) disabled @endif>Excluir conta</button></section>
@endcan
</div></details>
@endcanany
</div>
</div>
<script>
(() => {
    const toggle = document.querySelector('.ftp-detail-history-toggle');
    if (toggle) {
        const extraRows = [...document.querySelectorAll('#ftp-receipts-body tr')].slice(5);
        extraRows.forEach(row => row.hidden = true);
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(expanded));
            toggle.innerHTML = expanded ? 'Ver menos <span aria-hidden="true">↑</span>' : 'Ver todos <span aria-hidden="true">→</span>';
            extraRows.forEach(row => row.hidden = !expanded);
        });
    }
    document.querySelectorAll('[data-ftp-section]').forEach(button => button.addEventListener('click', () => {
        const section = document.getElementById(button.dataset.ftpSection);
        if (!section) return;
        section.open = true;
        button.closest('details').open = false;
        section.scrollIntoView({ block: 'nearest' });
        section.querySelector('summary').focus();
    }));
})();
</script>
@can('ftp.delete')
<dialog class="modal" id="ftp-delete-dialog" aria-labelledby="ftp-delete-title"><div class="modal__surface"><div class="modal__header"><h2 class="modal__title" id="ftp-delete-title">Excluir conta FTP</h2><button type="button" class="modal__close" data-close-delete aria-label="Fechar"><x-icon name="close" size="sm" /></button></div><form method="POST" action="{{ route('ftp.delete', $ftpAccount) }}">@csrf @method('DELETE')<div class="modal__body ftp-modal-body">
@if($errors->has('mode') || $errors->has('confirmation'))<div class="alert alert--warning" role="alert" id="ftp-delete-error">@foreach($errors->get('mode') as $error)<p>{{ $error }}</p>@endforeach @foreach($errors->get('confirmation') as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($deletionPreview && $impact['blocker'])<div class="alert alert--warning" role="alert">{{ $impact['blocker'] }}</div>@endif
@if($deletionPreview && $impact['safety_error'])<div class="alert alert--warning" role="alert">{{ $impact['safety_error'] }}</div>@endif
<dl class="ftp-detail-list"><div><dt>Conta</dt><dd>{{ $impact['account'] }}</dd></div><div><dt>Finalidade</dt><dd>{{ $impact['purpose'] === 'backup' ? 'Backup' : 'Servidor de arquivos' }}</dd></div><div><dt>Equipamento</dt><dd>{{ $impact['device'] ?? 'Nenhum' }}</dd></div><div><dt>Organização dos arquivos</dt><dd>{{ \App\Support\OperationalLabels::FTP_LAYOUTS[$impact['home_layout'] ?? 'legacy'] ?? $impact['home_layout'] }}</dd></div><div><dt>Chroot</dt><dd><code>{{ $impact['chroot'] }}</code></dd></div><div><dt>PureDB</dt><dd>{{ $impact['puredb'] }}</dd></div><div><dt>Recebimentos FTP</dt><dd>{{ $impact['receipts'] }}</dd></div><div><dt>Arquivos recebidos / em processamento / em quarentena</dt><dd>{{ $impact['incoming'] ?? 'Indisponível' }} / {{ $impact['processing'] ?? 'Indisponível' }} / {{ $impact['quarantine'] ?? 'Indisponível' }}</dd></div><div><dt>Bytes recebidos / em processamento / em quarentena</dt><dd>{{ $impact['physical']['incoming']['bytes'] ?? '—' }} / {{ $impact['physical']['processing']['bytes'] ?? '—' }} / {{ $impact['physical']['quarantine']['bytes'] ?? '—' }}</dd></div><div><dt>Diretório FTP existe</dt><dd>{{ !isset($impact['physical']['home_exists']) ? 'Indisponível' : ($impact['physical']['home_exists'] ? 'SIM' : 'Não') }}</dd></div><div><dt>Execuções / artefatos vinculados</dt><dd>{{ $impact['executions'] }} / {{ $impact['artifacts'] }}</dd></div><div><dt>Tamanho dos arquivos finais</dt><dd>{{ number_format($impact['artifact_bytes'] / 1048576, 2, ',', '.') }} MB</dd></div><div><dt>Artefatos não atribuíveis com segurança</dt><dd>{{ $impact['unattributable_artifacts'] }}</dd></div><div><dt>Arquivos do servidor de arquivos</dt><dd>{{ $impact['stored_files'] }} ({{ number_format($impact['stored_bytes'] / 1048576, 2, ',', '.') }} MB)</dd></div><div><dt>Equipamento livre para nova conta</dt><dd>{{ $impact['device_released'] ? 'SIM' : 'Não se aplica' }}</dd></div>@if($impact['unattributed_executions'])<div><dt>Execuções antigas sem vínculo seguro</dt><dd>{{ $impact['unattributed_executions'] }} — preservadas</dd></div>@endif</dl>
@if($impact['stored_paths'])<details><summary>Caminhos do servidor de arquivos</summary><ul>@foreach($impact['stored_paths'] as $path)<li><code>{{ $path }}</code></li>@endforeach</ul></details>@endif
<fieldset @if($errors->has('mode')) aria-describedby="ftp-delete-error" @endif><legend>Modo de exclusão</legend><label><input type="radio" name="mode" value="account" required @checked($selectedDeletionMode === 'account')> Somente conta</label><p>Revoga o acesso FTP e libera o equipamento. Backups existentes permanecem.</p><label><input type="radio" name="mode" value="ftp_data" @checked($selectedDeletionMode === 'ftp_data')> Conta + dados FTP</label><p>Também remove arquivos e histórico próprios da conta FTP. Backups processados permanecem.</p><label><input type="radio" name="mode" value="all" @checked($selectedDeletionMode === 'all')> Conta + todos os dados associados</label><p>Também remove execuções, artefatos e arquivos finais relacionados. Esta ação não pode ser desfeita.</p></fieldset>
<div class="form-field"><label class="form-label" for="ftp-delete-confirmation">Frase de confirmação: <strong id="ftp-delete-phrase">{{ $confirmationPhrases[$selectedDeletionMode] ?? 'Selecione um modo de exclusão.' }}</strong></label><input class="form-control" id="ftp-delete-confirmation" name="confirmation" autocomplete="off" required @if($errors->has('confirmation')) aria-invalid="true" aria-describedby="ftp-delete-error" @endif></div>
</div><div class="modal__footer ftp-modal-footer"><button type="button" class="btn btn--ghost" data-close-delete>Cancelar</button><button type="submit" class="btn btn--danger" @if($impact['blocker'] || $impact['safety_error']) disabled @endif>Excluir definitivamente</button></div></form></div></dialog>
@endcan
@can('ftp.manage')
<dialog class="modal modal--sm" id="ftp-rotate-dialog" aria-labelledby="ftp-rotate-title"><div class="modal__surface"><div class="modal__header"><h2 class="modal__title" id="ftp-rotate-title">Rotacionar senha FTP</h2><button type="button" class="modal__close" data-close-rotate aria-label="Fechar"><x-icon name="close" size="sm" /></button></div><form method="POST" action="{{ route('ftp.rotate', $ftpAccount) }}" id="ftp-rotate-form">@csrf<div class="modal__body ftp-modal-body"><div class="form-field"><label class="form-label" for="new_password">Nova senha</label><div style="display:flex;gap:.5rem"><input class="form-control" id="new_password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password" required><button class="btn btn--secondary" type="button" data-generate-password>Gerar</button></div></div><div class="form-field"><label class="form-label" for="new_password_confirmation">Confirmar senha</label><input class="form-control" id="new_password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password" required></div></div><div class="modal__footer ftp-modal-footer"><button type="button" class="btn btn--ghost" data-close-rotate>Cancelar</button><button type="submit" class="btn btn--primary">Rotacionar senha</button></div></form></div></dialog>
<script>
(() => { const dialog = document.getElementById('ftp-rotate-dialog'); const form = document.getElementById('ftp-rotate-form'); document.getElementById('ftp-rotate-open').addEventListener('click', () => dialog.showModal()); dialog.querySelectorAll('[data-close-rotate]').forEach(button => button.addEventListener('click', () => dialog.close())); dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); }); form.querySelector('[data-generate-password]').addEventListener('click', () => { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); const value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join(''); form.elements.password.value = value; form.elements.password_confirmation.value = value; }); @if ($errors->has('password') || $errors->has('password_confirmation')) dialog.showModal(); @endif })();
</script>
@endcan
@can('ftp.delete')
<script>
(() => {
    const dialog = document.getElementById('ftp-delete-dialog');
    const phrase = document.getElementById('ftp-delete-phrase');
    const confirmation = document.getElementById('ftp-delete-confirmation');
    const phrases = @json($confirmationPhrases);
    const syncPhrase = () => {
        const mode = dialog.querySelector('input[name="mode"]:checked')?.value;
        phrase.textContent = phrases[mode] ?? 'Selecione um modo de exclusão.';
    };
    document.getElementById('ftp-delete-open').addEventListener('click', () => { @if (!$deletionPreview) location.href = @json(route('ftp.show', ['ftpAccount' => $ftpAccount, 'deletion_preview' => 1])); return; @endif syncPhrase(); dialog.showModal(); });
    dialog.querySelectorAll('[data-close-delete]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.querySelectorAll('input[name="mode"]').forEach(input => input.addEventListener('change', () => { syncPhrase(); confirmation.value = ''; }));
    syncPhrase();
    @if ($deletionPreview && $impact['physical'] === null && !$impact['deletion_mode']) setTimeout(() => location.reload(), 18000); @endif
    @if ($deletionPreview && !$impact['deletion_mode']) syncPhrase(); dialog.showModal(); @endif
})();
</script>
@endcan
@endsection
