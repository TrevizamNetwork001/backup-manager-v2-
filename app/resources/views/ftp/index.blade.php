@extends('layouts.app')
@section('title', 'FTP — Backup Manager')
@section('page-title', 'FTP')
@section('page-description', 'Servidor e contas para recebimento de backups.')
@section('content')
<div class="ftp-page">
    <div class="ftp-toolbar">
        <div><h2>Contas e recebimentos</h2><p>Gerencie o acesso FTP de cada equipamento.</p></div>
        <button type="button" class="primary-button inline-button" id="ftp-create-open" @disabled($devices->isEmpty())>+ Nova conta FTP</button>
    </div>
    @if ($errors->any()) <div class="alert-warning" role="alert">{{ $errors->first() }}</div> @endif
    <section class="panel ftp-server" aria-labelledby="ftp-server-title">
        <div class="panel-header"><div><h2 id="ftp-server-title">Servidor FTP</h2></div></div>
        <div class="ftp-facts">
            <div><small>Porta</small><strong>21/TCP</strong></div>
            <div><small>Faixa passiva</small><strong>30000–30009/TCP</strong></div>
            <div><small>Endereço anunciado</small><strong>{{ $server['passive_address'] ?: 'Não configurado' }}</strong></div>
            <div><small>PureDB</small><strong>{{ $accounts->whereNotNull('provisioned_at')->whereNull('sync_error')->count() }} contas sincronizadas</strong></div>
            <div><small>Serviço</small><span class="badge warning">Não monitorado</span></div>
        </div>
        <p class="ftp-note">O processo FTP não é monitorado pelo painel. A sincronização das contas é confirmada pelo ftp-admin.</p>
    </section>
    <section class="panel ftp-accounts" aria-labelledby="ftp-accounts-title">
        <div class="panel-header"><div><h2 id="ftp-accounts-title">Contas FTP</h2><p>{{ $accounts->count() }} {{ $accounts->count() === 1 ? 'conta cadastrada' : 'contas cadastradas' }}</p></div></div>
        @if ($accounts->isEmpty())
            <div class="empty-state ftp-empty"><div class="ftp-empty-icon">⇅</div><h3>Nenhuma conta FTP ainda</h3><p>Crie uma conta para permitir o recebimento de backups de um equipamento.</p>@if ($devices->isNotEmpty())<button type="button" class="secondary-button" data-open-ftp-create>Criar primeira conta</button>@endif</div>
        @else
            <div class="ftp-table-wrap"><table class="ftp-table"><thead><tr><th>Equipamento</th><th>Usuário</th><th>Status</th><th>Diretório</th><th>PureDB</th><th>Último recebimento</th><th>Ações</th></tr></thead><tbody>
            @foreach ($accounts as $account)
                <tr>
                    <td><span class="ftp-device">{{ $account->device?->name ?? 'Equipamento removido' }}</span><small>{{ $account->device?->management_ip }}</small></td>
                    <td><code class="ftp-username">{{ $account->username }}</code></td>
                    <td><span class="badge {{ $account->is_active ? 'success' : 'neutral' }}">{{ $account->is_active ? 'Ativa' : 'Desativada' }}</span></td>
                    <td><code class="ftp-directory" title="/data/ftp/{{ $account->device_id }}/incoming">/data/ftp/{{ $account->device_id }}/incoming</code></td>
                    <td><span class="badge {{ $account->sync_error ? 'danger' : ($account->provisioned_at && $account->is_active ? 'success' : 'neutral') }}">{{ $account->sync_error ? 'Erro de sync' : ($account->provisioned_at && $account->is_active ? 'Sincronizada' : 'Pendente') }}</span></td>
                    <td class="{{ isset($receipts[$account->id]) ? '' : 'ftp-muted' }}">{{ $receipts[$account->id] ?? 'Nunca' }}</td>
                    <td><a class="secondary-button ftp-open" href="{{ route('ftp.show', $account) }}">Abrir <span aria-hidden="true">→</span></a></td>
                </tr>
            @endforeach
            </tbody></table></div>
        @endif
    </section>
</div>
<dialog class="ftp-dialog" id="ftp-create-dialog" aria-labelledby="ftp-create-title" aria-describedby="ftp-create-description">
    <div class="ftp-dialog-header">
        <div><h2 id="ftp-create-title">Nova conta FTP</h2><p id="ftp-create-description">Configure uma conta exclusiva para o equipamento.</p></div>
        <button type="button" class="ftp-dialog-close" data-close-dialog aria-label="Fechar"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
    </div>
    <form method="POST" action="{{ route('ftp.store') }}" class="ftp-form" id="ftp-create-form">@csrf
        <div class="ftp-dialog-body">
            <div class="ftp-device-field field"><label for="device_id">Equipamento</label><select id="device_id" name="device_id" required><option value="">Selecione um equipamento</option>@foreach ($devices as $device)<option value="{{ $device->id }}" data-device-name="{{ $device->name }}" @selected(old('device_id') == $device->id)>{{ $device->name }} · {{ $device->management_ip }}</option>@endforeach</select><p class="ftp-device-hint" id="ftp-device-hint" hidden>Conta será vinculada exclusivamente a este equipamento.</p></div>
            <div class="ftp-credentials-grid">
                <section class="ftp-credential-card" aria-labelledby="ftp-user-label">
                    <div class="ftp-option-group" role="group" aria-labelledby="ftp-user-label"><h3 id="ftp-user-label">Usuário FTP</h3><div class="ftp-options"><label><input type="radio" name="mode" value="automatic" @checked(old('mode', 'automatic') === 'automatic')><span>Gerar automaticamente</span></label><label><input type="radio" name="mode" value="manual" @checked(old('mode') === 'manual')><span>Definir manualmente</span></label></div></div>
                    <p class="ftp-hint ftp-user-preview" id="ftp-user-preview" hidden><span>Usuário sugerido</span><strong id="ftp-suggested-name"></strong></p>
                    <div class="field" id="ftp-manual-user" hidden><label for="username">Usuário FTP</label><input id="username" name="username" value="{{ old('username') }}" minlength="3" maxlength="32" pattern="[a-z][a-z0-9_-]*" autocomplete="off"></div>
                </section>
                <section class="ftp-credential-card" aria-labelledby="ftp-password-label">
                    <div class="ftp-option-group" role="group" aria-labelledby="ftp-password-label"><h3 id="ftp-password-label">Senha</h3><div class="ftp-options"><label><input type="radio" name="password_mode" value="automatic" @checked(old('password_mode', 'automatic') === 'automatic')><span>Gerar automaticamente</span></label><label><input type="radio" name="password_mode" value="manual" @checked(old('password_mode') === 'manual')><span>Definir manualmente</span></label></div></div>
                    <p class="ftp-hint" id="ftp-auto-password">Será gerada uma senha segura de 32 caracteres.</p>
                    <div class="ftp-password-fields" id="ftp-manual-password" hidden><div class="field"><label for="password">Nova senha</label><input id="password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password"></div><div class="field"><label for="password_confirmation">Confirmar senha</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password"></div></div>
                </section>
            </div>
            <div class="ftp-creation-summary"><h3>Resumo</h3><dl><div><dt>Equipamento</dt><dd id="ftp-summary-device">Selecione um equipamento</dd></div><div><dt>Usuário</dt><dd id="ftp-summary-user">Automático</dd></div><div><dt>Diretório</dt><dd>/</dd></div><div><dt>Senha</dt><dd id="ftp-summary-password">Gerada automaticamente</dd></div></dl><p>O diretório interno e o chroot serão provisionados automaticamente.</p></div>
        </div>
        <div class="ftp-dialog-footer"><div class="ftp-dialog-actions"><button type="button" class="secondary-button" data-close-dialog>Cancelar</button><button type="submit" class="primary-button inline-button" @disabled($devices->isEmpty())><span aria-hidden="true">+</span> Criar conta FTP</button></div></div>
    </form>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('ftp-create-dialog');
    const form = document.getElementById('ftp-create-form');
    const device = form.elements.device_id;
    const username = form.elements.username;
    const password = form.elements.password;
    const confirmation = form.elements.password_confirmation;
    const sync = () => {
        const automaticUser = form.querySelector('[name="mode"]:checked').value === 'automatic';
        const automaticPassword = form.querySelector('[name="password_mode"]:checked').value === 'automatic';
        const selected = device.selectedOptions[0];
        document.getElementById('ftp-device-hint').hidden = !device.value;
        document.getElementById('ftp-manual-user').hidden = automaticUser;
        document.getElementById('ftp-user-preview').hidden = !automaticUser || !device.value;
        document.getElementById('ftp-suggested-name').textContent = device.value ? `bmdev${device.value}` : '';
        username.disabled = automaticUser;
        username.required = !automaticUser;
        document.getElementById('ftp-manual-password').hidden = automaticPassword;
        document.getElementById('ftp-auto-password').hidden = !automaticPassword;
        password.disabled = confirmation.disabled = automaticPassword;
        password.required = confirmation.required = !automaticPassword;
        document.getElementById('ftp-summary-device').textContent = device.value ? selected.dataset.deviceName : 'Selecione um equipamento';
        document.getElementById('ftp-summary-user').textContent = automaticUser ? (device.value ? `bmdev${device.value}` : 'Automático') : (username.value || 'A definir');
        document.getElementById('ftp-summary-password').textContent = automaticPassword ? 'Gerada automaticamente' : 'Definida manualmente';
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    document.getElementById('ftp-create-open').addEventListener('click', () => dialog.showModal());
    document.querySelectorAll('[data-open-ftp-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    sync();
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endsection
