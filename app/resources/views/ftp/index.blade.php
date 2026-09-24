@extends('layouts.app')
@section('title', 'FTP — Backup Manager')
@section('page-header')
<header class="page-header"><div class="page-header__content"><h1 class="page-header__title">FTP</h1><p class="page-header__description">Serviço de transferência de arquivos da infraestrutura.</p></div><div class="page-header__actions"><button type="button" class="btn btn--primary" data-open-ftp-create @disabled(! $ftpCoreReady)>Nova conta FTP</button></div></header>
@endsection
@section('content')
<div class="ftp-page stack">
    @if ($errors->any()) <div class="alert alert--warning" role="alert">{{ $errors->first() }}</div> @endif
    @unless($ftpCoreReady)<div class="alert alert--warning" role="status">As contas atuais continuam disponíveis. A criação de novas contas aguarda a migration FTP-CORE-1.</div>@endunless
    <section class="ftp-accounts" aria-labelledby="ftp-accounts-title">
        <div class="toolbar ftp-list-toolbar"><div class="toolbar__primary"><h2 id="ftp-accounts-title" class="ftp-section-title">Contas</h2><span class="toolbar__count">{{ $accounts->count() }}</span></div></div>
        @if ($accounts->isEmpty())<div class="empty-state"><h3 class="empty-state__title">Nenhuma conta FTP</h3><button type="button" class="btn btn--secondary" data-open-ftp-create @disabled(! $ftpCoreReady)>Nova conta FTP</button></div>
        @else <div class="table-shell" role="region" aria-label="Contas FTP" tabindex="0"><table class="data-table ftp-table"><thead><tr><th>Conta / usuário</th><th>Finalidade</th><th>Equipamento</th><th>Status</th><th>Último recebimento</th><th>Ações</th></tr></thead><tbody>
            @foreach ($accounts as $account)<tr><td><code class="tech-value">{{ $account->username }}</code></td><td>{{ ($account->purpose ?? 'backup') === 'backup' ? 'Backup' : 'Servidor de arquivos' }}</td><td>{{ $account->device?->name ?? 'Nenhum' }}</td><td><span class="badge badge--{{ $account->is_active ? 'success' : 'neutral' }}">{{ $account->is_active ? 'Ativa' : 'Desativada' }}</span></td><td>{{ $receipts[$account->id] ?? 'Nunca recebeu' }}</td><td><a class="btn btn--ghost btn--sm" href="{{ route('ftp.show', $account) }}">Abrir →</a></td></tr>@endforeach
        </tbody></table></div>@endif
    </section>
    <p class="ftp-note">O servidor aplica um único perfil global no próprio chroot. Distribuição de firmware ainda não está disponível.</p>
</div>
<dialog class="modal ftp-create-modal" id="ftp-create-dialog" aria-labelledby="ftp-create-title"><div class="modal__surface"><div class="modal__header"><h2 class="modal__title" id="ftp-create-title">Nova conta FTP</h2><button type="button" class="modal__close" data-close-dialog aria-label="Fechar"><x-icon name="close" size="sm" /></button></div>
<form method="POST" action="{{ route('ftp.store') }}" id="ftp-create-form">@csrf<div class="modal__body ftp-modal-body">
    <div class="form-field"><label class="form-label" for="purpose">Finalidade</label><select class="form-control" id="purpose" name="purpose"><option value="backup" @selected(old('purpose', 'backup') === 'backup')>Backup</option><option value="file_server" @selected(old('purpose') === 'file_server')>Servidor de arquivos</option></select></div>
    <div class="form-field" id="ftp-device-field"><label class="form-label" for="device_id">Equipamento</label><select class="form-control" id="device_id" name="device_id"><option value="">Selecione um equipamento</option>@foreach ($devices as $device)<option value="{{ $device->id }}" @selected(old('device_id') == $device->id)>{{ $device->name }} · {{ $device->management_ip }}</option>@endforeach</select></div>
    <div class="form-field"><label class="form-label" for="username">Usuário FTP</label><input class="form-control" id="username" name="username" value="{{ old('username') }}" minlength="3" maxlength="32" pattern="[a-z][a-z0-9_-]*" autocomplete="off" required></div>
    <div class="form-field"><label class="form-label" for="password">Senha</label><div style="display:flex;gap:.5rem"><input class="form-control" id="password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password" required><button class="btn btn--secondary" type="button" data-generate-password>Gerar</button></div></div>
    <div class="form-field"><label class="form-label" for="password_confirmation">Confirmar senha</label><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password" required></div>
    <p class="form-help">Permissão: perfil global do servidor FTP, sem ajuste por conta.</p>
</div><div class="modal__footer ftp-modal-footer"><button type="button" class="btn btn--ghost" data-close-dialog>Cancelar</button><button type="submit" class="btn btn--primary">Criar conta FTP</button></div></form></div></dialog>
<script>
(() => {
 const dialog = document.getElementById('ftp-create-dialog'); const form = document.getElementById('ftp-create-form'); const purpose = form.elements.purpose; const device = form.elements.device_id;
 const sync = () => { const backup = purpose.value === 'backup'; document.getElementById('ftp-device-field').hidden = !backup; device.disabled = !backup; device.required = backup; if (!backup) device.value = ''; };
 purpose.addEventListener('change', sync); sync();
 document.querySelectorAll('[data-open-ftp-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
 dialog.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => dialog.close()));
 dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
 form.querySelector('[data-generate-password]').addEventListener('click', () => { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); const value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join(''); form.elements.password.value = value; form.elements.password_confirmation.value = value; });
 @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endsection
