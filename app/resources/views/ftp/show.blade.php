@extends('layouts.app')
@section('title', 'Conta FTP — Backup Manager')
@section('page-title', 'Conta FTP')
@section('page-description', 'Acesso, sincronização e recebimentos do equipamento.')
@section('content')
@php($server = app(\App\Services\FtpServerSettings::class)->get())
<div class="ftp-page ftp-detail-page">
    <div class="ftp-detail-toolbar"><a class="ftp-back" href="{{ route('ftp.index') }}">← Voltar ao FTP</a><button type="button" class="primary-button inline-button" id="ftp-rotate-open">Rotacionar senha</button></div>
    @if ($errors->any()) <div class="alert-warning" role="alert">{{ $errors->first() }}</div> @endif
    <div class="ftp-detail-grid">
        <section class="panel ftp-detail-card"><div class="panel-header"><div><h2>Identificação</h2></div></div><dl class="ftp-detail-list"><div><dt>Equipamento</dt><dd><strong>{{ $ftpAccount->device?->name ?? 'Equipamento removido' }}</strong><small>{{ $ftpAccount->device?->management_ip }}</small></dd></div><div><dt>Usuário</dt><dd><code>{{ $ftpAccount->username }}</code></dd></div><div><dt>Status</dt><dd><span class="badge {{ $ftpAccount->is_active ? 'success' : 'neutral' }}">{{ $ftpAccount->is_active ? 'Ativa' : 'Desativada' }}</span></dd></div></dl></section>
        <section class="panel ftp-detail-card"><div class="panel-header"><div><h2>Acesso FTP</h2></div></div><dl class="ftp-detail-list"><div><dt>Servidor</dt><dd>{{ $server['host'] ?: 'Não configurado' }}</dd></div><div><dt>Porta</dt><dd>21/TCP</dd></div><div><dt>Path visível ao equipamento</dt><dd><code>/</code></dd></div></dl><details class="ftp-technical"><summary>Detalhes técnicos</summary><dl class="ftp-detail-list"><div><dt>Chroot interno</dt><dd><code>/data/ftp/{{ $ftpAccount->device_id }}/incoming</code></dd></div><div><dt>Permissão</dt><dd>Envio no próprio chroot</dd></div></dl></details></section>
        <section class="panel ftp-detail-card"><div class="panel-header"><div><h2>Sincronização</h2></div></div><dl class="ftp-detail-list"><div><dt>PureDB</dt><dd><span class="badge {{ $ftpAccount->sync_error ? 'danger' : ($ftpAccount->provisioned_at && $ftpAccount->is_active ? 'success' : 'neutral') }}">{{ $ftpAccount->sync_error ? 'Erro de sync' : ($ftpAccount->provisioned_at && $ftpAccount->is_active ? 'Sincronizada' : 'Pendente') }}</span></dd></div><div><dt>Provisionada em</dt><dd>{{ $ftpAccount->provisioned_at?->format('d/m/Y H:i:s') ?? 'Pendente' }}</dd></div><div><dt>Erro de sincronização</dt><dd class="{{ $ftpAccount->sync_error ? 'ftp-error-text' : 'ftp-muted' }}">{{ $ftpAccount->sync_error ?: 'Nenhum' }}</dd></div></dl></section>
        <section class="panel ftp-detail-card"><div class="panel-header"><div><h2>Recebimento</h2></div></div><dl class="ftp-detail-list"><div><dt>Último recebimento</dt><dd class="{{ $lastReceipt ? '' : 'ftp-muted' }}">{{ $lastReceipt?->received_at ?? 'Nunca' }}</dd></div><div><dt>Último arquivo</dt><dd class="{{ $lastReceipt ? '' : 'ftp-muted' }}">{{ $lastReceipt?->received_filename ?? 'Nenhum' }}</dd></div></dl>@if ($ftpAccount->credential_changed_at && (!$lastReceipt || $lastReceipt->received_at <= $ftpAccount->credential_changed_at->toDateTimeString()))<p class="alert-warning">Credencial alterada; aguardando novo recebimento. Atualize a senha no equipamento.</p>@endif</section>
    </div>
    <section class="panel ftp-account-actions"><div><h2>Estado da conta</h2><p>{{ $ftpAccount->is_active ? 'Desativar impede novos acessos FTP.' : 'Ativar permite novamente o acesso FTP.' }}</p></div><form method="POST" action="{{ route('ftp.status', $ftpAccount) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $ftpAccount->is_active ? 0 : 1 }}"><button type="submit" class="secondary-button {{ $ftpAccount->is_active ? 'ftp-danger-action' : '' }}">{{ $ftpAccount->is_active ? 'Desativar conta' : 'Ativar conta' }}</button></form></section>
</div>
<dialog class="ftp-dialog ftp-rotate-dialog" id="ftp-rotate-dialog" aria-labelledby="ftp-rotate-title"><div class="ftp-dialog-header"><div><h2 id="ftp-rotate-title">Rotacionar senha</h2><p>A nova senha aparecerá uma única vez após a alteração.</p></div><button type="button" class="ftp-dialog-close" data-close-rotate aria-label="Fechar">×</button></div><form method="POST" action="{{ route('ftp.rotate', $ftpAccount) }}" class="ftp-form" id="ftp-rotate-form">@csrf<div class="ftp-dialog-body"><div class="ftp-option-group" role="group" aria-labelledby="ftp-rotate-mode-label"><strong id="ftp-rotate-mode-label">Nova senha</strong><div class="ftp-options"><label><input type="radio" name="mode" value="automatic" @checked(old('mode', 'automatic') === 'automatic')><span>Gerar nova senha</span></label><label><input type="radio" name="mode" value="manual" @checked(old('mode') === 'manual')><span>Definir manualmente</span></label></div></div><p class="ftp-hint" id="ftp-rotate-auto">Será gerada uma senha segura de 32 caracteres.</p><div class="ftp-password-fields" id="ftp-rotate-manual" hidden><div class="field"><label for="new_password">Nova senha</label><input id="new_password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password"></div><div class="field"><label for="new_password_confirmation">Confirmar senha</label><input id="new_password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password"></div></div></div><div class="ftp-dialog-footer"><div class="ftp-dialog-actions"><button type="button" class="secondary-button" data-close-rotate>Cancelar</button><button type="submit" class="primary-button inline-button">Rotacionar senha</button></div></div></form></dialog>
<script>
(() => {
    const dialog = document.getElementById('ftp-rotate-dialog');
    const form = document.getElementById('ftp-rotate-form');
    const sync = () => {
        const manual = form.querySelector('[name="mode"]:checked').value === 'manual';
        document.getElementById('ftp-rotate-manual').hidden = !manual;
        document.getElementById('ftp-rotate-auto').hidden = manual;
        for (const input of [form.elements.password, form.elements.password_confirmation]) { input.disabled = !manual; input.required = manual; }
    };
    form.addEventListener('change', sync);
    document.getElementById('ftp-rotate-open').addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-close-rotate]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    sync();
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endsection
