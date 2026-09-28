@extends('layouts.app')
@section('title', 'FTP — Backup Manager')
@section('page-header')
<header class="page-header reference-page-header ftp-index-header"><div class="page-header__content"><h1 class="page-header__title">FTP</h1><p class="page-header__description">Serviço de transferência de arquivos da infraestrutura.</p></div>@can('ftp.manage')<button type="button" class="btn btn--primary" data-open-ftp-create @disabled(! $ftpCoreReady)><x-icon name="add" /> Nova conta FTP</button>@endcan</header>
@endsection
@section('content')
<div class="ftp-page ftp-reference">
    @if ($errors->any()) <div class="alert alert--warning" role="alert">{{ $errors->first() }}</div> @endif
    @unless($ftpCoreReady)<div class="alert alert--warning" role="status">As contas atuais continuam disponíveis. A criação de novas contas aguarda a migration FTP-CORE-1.</div>@endunless
    <div class="ftp-stats">
        @foreach ([
            ['Total de contas', $accounts->count(), 'database', 'blue'],
            ['Backup (equipamentos)', $accounts->filter(fn ($account) => ($account->purpose ?? 'backup') === 'backup')->count(), 'server', 'slate'],
            ['Servidores de arquivos', $accounts->where('purpose', 'file_server')->count(), 'folder', 'amber'],
            ['Contas ativas', $accounts->where('is_active', true)->count(), 'check-circle', 'green']
        ] as [$label, $value, $icon, $color])
            <div class="ftp-stat"><span class="reference-stat__icon {{ $color }}"><x-icon :name="$icon" /></span><small>{{ $label }}</small><strong>{{ $value }}</strong></div>
        @endforeach
    </div>
    <div class="ftp-filters">
        <label class="ftp-search"><x-icon name="search" /><input type="search" id="ftp-search" placeholder="Buscar por usuário, finalidade ou equipamento..." aria-label="Buscar contas FTP"></label>
        <select id="ftp-purpose-filter" class="form-control" aria-label="Filtrar por finalidade"><option value="">Todas as finalidades</option><option value="backup">Backup</option><option value="file_server">Servidor de arquivos</option></select>
        <select id="ftp-status-filter" class="form-control" aria-label="Filtrar por status"><option value="">Todos os status</option><option value="active">Ativa</option><option value="inactive">Desativada</option></select>
    </div>
    <section class="ftp-list-card" aria-label="Contas FTP">
        <div class="table-shell" role="region" aria-label="Lista de contas FTP" tabindex="0"><table class="data-table ftp-table"><thead><tr><th>Conta / usuário</th><th>Finalidade</th><th>Equipamento</th><th>Status</th><th>Último recebimento</th><th>Ações</th></tr></thead><tbody>
        @foreach ($accounts as $account)
            @php $purpose = $account->purpose ?? 'backup'; @endphp
            <tr class="ftp-account-row" data-search="{{ mb_strtolower($account->username . ' ' . ($account->device?->name ?? '') . ' ' . ($purpose === 'backup' ? 'backup' : 'servidor de arquivos')) }}" data-purpose="{{ $purpose }}" data-status="{{ $account->is_active ? 'active' : 'inactive' }}">
                <td data-label="Conta / usuário"><div class="ftp-account-cell"><span class="ftp-account-icon {{ $purpose === 'backup' ? 'slate' : 'blue' }}"><x-icon :name="$purpose === 'backup' ? 'server' : 'folder'" /></span><span><strong>{{ $account->username }}</strong><small>{{ $account->username }}</small></span></div></td>
                <td data-label="Finalidade"><span class="purpose-pill {{ $purpose === 'backup' ? 'backup' : 'file-server' }}">{{ $purpose === 'backup' ? 'Backup' : 'Servidor de arquivos' }}</span></td>
                <td data-label="Equipamento"><strong>{{ $account->device?->name ?? 'Nenhum' }}</strong><small>{{ $account->device?->vendor ?? '—' }}</small></td>
                <td data-label="Status"><span class="badge badge--{{ $account->is_active ? 'success' : 'neutral' }}">{{ $account->is_active ? '● Ativa' : '○ Desativada' }}</span></td>
                <td data-label="Último recebimento"><span>{{ $receipts->get($account->id) ? \Illuminate\Support\Carbon::parse($receipts->get($account->id))->format('d/m/Y H:i') : 'Nunca recebeu' }}</span></td>
                <td data-label="Ações"><details class="row-menu"><summary>Ações</summary><div class="table-actions"><a class="ftp-open-btn" href="{{ route('ftp.show', $account) }}">Abrir <span aria-hidden="true">→</span></a></div></details></td>
            </tr>
        @endforeach
        <tr id="ftp-empty-row" @unless($accounts->isEmpty()) hidden @endunless><td colspan="6">Nenhuma conta FTP encontrada.</td></tr>
        </tbody></table></div>
        <details class="ftp-note"><summary>Detalhes do serviço FTP</summary><p>O servidor aplica um único perfil global no próprio chroot. Distribuição de firmware ainda não está disponível.</p></details>
    </section>
</div>
@can('ftp.manage')
<dialog class="modal ftp-create-modal" id="ftp-create-dialog" aria-labelledby="ftp-create-title"><div class="modal__surface"><div class="modal__header"><div><h2 class="modal__title" id="ftp-create-title">Nova conta FTP</h2><p class="modal__description">Crie uma conta para backup ou servidor de arquivos.</p></div><button type="button" class="modal__close" data-close-dialog aria-label="Fechar"><x-icon name="close" /></button></div>
<form method="POST" action="{{ route('ftp.store') }}" id="ftp-create-form">@csrf<div class="modal__body ftp-modal-body">
    <div class="ftp-modal-top">
        <div class="form-field"><label class="form-label" for="purpose">Finalidade</label><div class="ftp-select-wrap"><select class="form-control" id="purpose" name="purpose"><option value="backup" @selected(old('purpose', 'backup') === 'backup')>Backup</option><option value="file_server" @selected(old('purpose') === 'file_server')>Servidor de arquivos</option></select><x-icon name="chevron-down" /></div></div>
        <div class="form-field"><span class="form-label">Vinculação ao equipamento</span><div class="ftp-link-state"><span class="ftp-link-state__switch" id="ftp-link-indicator" aria-hidden="true"></span><span id="ftp-link-description">Obrigatória para contas de backup</span></div></div>
    </div>
    <div class="form-field" id="ftp-device-field"><label class="form-label" for="device_id">Equipamento</label><div class="ftp-select-wrap"><select class="form-control" id="device_id" name="device_id"><option value="">Selecione um equipamento</option>@foreach ($devices as $device)<option value="{{ $device->id }}" @selected(old('device_id') == $device->id)>{{ $device->name }} · {{ $device->management_ip }}</option>@endforeach</select><x-icon name="chevron-down" /></div></div>
    <div class="form-field"><label class="form-label" for="username">Usuário FTP</label><input class="form-control" id="username" name="username" value="{{ old('username') }}" minlength="3" maxlength="32" pattern="[a-z][a-z0-9_-]*" autocomplete="off" required></div>
    <div class="form-field"><label class="form-label" for="password">Senha</label><div class="ftp-password-control"><input class="form-control" id="password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password" aria-describedby="ftp-password-strength-label ftp-password-strength-tip" required><button type="button" class="ftp-show-password" data-toggle-password="password" aria-label="Mostrar senha"><x-icon name="eye" /></button><button class="btn btn--secondary" type="button" data-generate-password>Gerar</button></div><div class="ftp-password-strength" id="ftp-password-strength" data-strength="empty"><div class="ftp-password-strength__track" role="progressbar" aria-label="Força da senha" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-password-strength-fill></span></div><strong id="ftp-password-strength-label" data-password-strength-label>Sem senha</strong><small id="ftp-password-strength-tip" data-password-strength-tip>Use ao menos 12 caracteres; uma frase longa e exclusiva é uma boa opção.</small></div></div>
    <div class="form-field"><label class="form-label" for="password_confirmation">Confirmar senha</label><div class="ftp-password-control"><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password" required><button type="button" class="ftp-show-password" data-toggle-password="password_confirmation" aria-label="Mostrar confirmação de senha"><x-icon name="eye" /></button></div></div>
    <p class="ftp-modal-note"><x-icon name="info" /> O servidor usa um perfil global. O path remoto visível para o cliente é /.</p>
</div><div class="modal__footer ftp-modal-footer"><button type="button" class="btn btn--ghost" data-close-dialog>Cancelar</button><button type="submit" class="btn btn--primary">Criar conta</button></div></form></div></dialog>
<script>
(() => {
 const dialog = document.getElementById('ftp-create-dialog'), form = document.getElementById('ftp-create-form'), purpose = form.elements.purpose, device = form.elements.device_id;
 const password = form.elements.password, strength = document.getElementById('ftp-password-strength'), strengthLabel = document.querySelector('[data-password-strength-label]'), strengthTip = document.querySelector('[data-password-strength-tip]'), strengthBar = strength.querySelector('[role="progressbar"]'), strengthFill = strength.querySelector('[data-password-strength-fill]');
 const sync = () => { const backup = purpose.value === 'backup'; device.disabled = !backup; device.required = backup; document.getElementById('ftp-link-indicator').classList.toggle('is-on', backup); document.getElementById('ftp-link-description').textContent = backup ? 'Obrigatória para contas de backup' : 'Sem equipamento para servidor de arquivos'; if (!backup) device.value = ''; };
 const updatePasswordStrength = () => {
  const value = password.value, length = [...value].length;
  const types = [/[a-z]/.test(value), /[A-Z]/.test(value), /[0-9]/.test(value), /[^A-Za-z0-9]/.test(value)].filter(Boolean).length;
  const diverse = new Set([...value]).size >= 8;
  let level = 'weak', score = 0, label = 'Fraca', tip = 'Use no mínimo 12 caracteres e evite nomes, datas ou sequências previsíveis.', progress = 20;
  if (!length) { level = 'empty'; label = 'Sem senha'; tip = 'Use ao menos 12 caracteres; uma frase longa e exclusiva é uma boa opção.'; progress = 0; }
  else if (length >= 12) {
   score = (length >= 16 ? 2 : 1) + (types >= 3 ? 1 : 0) + (diverse ? 1 : 0);
   if (/^(.)\1+$/.test(value)) score = 0;
   if (score >= 3) { level = 'strong'; label = 'Forte'; tip = 'Boa senha. Use-a somente nesta conta.'; progress = 100; }
   else if (score >= 2) { level = 'medium'; label = 'Razoável'; tip = 'Para fortalecer, use 16 ou mais caracteres e combine palavras ou tipos de caracteres.'; progress = 60; }
   else { label = 'Fraca'; tip = 'Use no mínimo 12 caracteres; prefira 16 ou mais, sem nomes ou datas.'; progress = 25; }
  }
  strength.dataset.strength = level;
  strengthLabel.textContent = label;
  strengthTip.textContent = tip;
  strengthBar.setAttribute('aria-valuenow', progress);
  strengthFill.style.width = `${progress}%`;
 };
 password.addEventListener('input', updatePasswordStrength);
 updatePasswordStrength();
 purpose.addEventListener('change', sync); sync();
 document.querySelectorAll('[data-open-ftp-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
 dialog.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => dialog.close()));
 dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
 form.querySelector('[data-generate-password]').addEventListener('click', () => { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); const value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join(''); form.elements.password.value = value; form.elements.password_confirmation.value = value; password.dispatchEvent(new Event('input', {bubbles: true})); });
 form.querySelectorAll('[data-toggle-password]').forEach(button => button.addEventListener('click', () => { const input = form.elements[button.dataset.togglePassword]; input.type = input.type === 'password' ? 'text' : 'password'; button.setAttribute('aria-label', input.type === 'password' ? 'Mostrar senha' : 'Ocultar senha'); }));
 @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endcan
<script>
(() => {
 const search = document.getElementById('ftp-search'), purpose = document.getElementById('ftp-purpose-filter'), status = document.getElementById('ftp-status-filter'), rows = [...document.querySelectorAll('.ftp-account-row')], empty = document.getElementById('ftp-empty-row');
 const filter = () => { let shown = 0; rows.forEach(row => { const visible = row.dataset.search.includes(search.value.trim().toLocaleLowerCase('pt-BR')) && (!purpose.value || row.dataset.purpose === purpose.value) && (!status.value || row.dataset.status === status.value); row.hidden = !visible; if (visible) shown++; }); empty.hidden = shown > 0; };
 [search, purpose, status].forEach(control => control.addEventListener(control === search ? 'input' : 'change', filter));
})();
</script>
@endsection
