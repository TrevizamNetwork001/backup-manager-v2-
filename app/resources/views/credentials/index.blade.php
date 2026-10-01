@extends('layouts.app')

@section('title', 'Credenciais — Backup Manager')
@section('page-title', 'Credenciais')
@section('page-description', 'Gerencie o acesso aos equipamentos.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Credenciais</h1>
        <p class="page-header__description">Gerencie o acesso aos equipamentos.</p>
    </div>
    <div class="page-header__actions">
        @can('credentials.manage')
            @unless(isset($editingCredential))
                <button type="button" class="btn btn--primary" data-open-credential-create @disabled($devices->isEmpty())><x-icon name="add" size="sm" /> Nova Credencial</button>
            @endunless
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="credentials-list stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
    @endif
    @if($devices->isEmpty())
        <div class="alert alert--warning" role="status">
            Nenhum equipamento está disponível.
            @can('devices.manage') <a class="link" href="{{ route('devices.index') }}">Cadastre um equipamento</a> antes de adicionar credenciais. @else Cadastre um equipamento antes de adicionar credenciais. @endcan
        </div>
    @endif

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $credentials->total() }}</strong> {{ $credentials->total() === 1 ? 'credencial cadastrada' : 'credenciais cadastradas' }}</span>
        </div>
    </div>

    @if($credentials->isEmpty())
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="key" /></div>
            <h2 class="empty-state__title">Nenhuma credencial cadastrada</h2>
            <p class="empty-state__description">Cadastre uma credencial para um equipamento.</p>
            @can('credentials.manage')
                @unless(isset($editingCredential))
                    <div class="empty-state__actions"><button type="button" class="btn btn--secondary" data-open-credential-create @disabled($devices->isEmpty())>Cadastrar primeira credencial</button></div>
                @endunless
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de credenciais" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Equipamento</th>
                        <th>Tipo</th>
                        <th>Usuário</th>
                        <th>Porta</th>
                        <th>Status</th>
                        <th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($credentials as $credential)
                        <tr>
                            <td data-label="Nome"><span class="entity-cell__title">{{ $credential->name }}</span></td>
                            <td data-label="Equipamento">{{ $credential->device->name }}</td>
                            <td data-label="Tipo"><span class="tech-value">{{ strtoupper($credential->type) }}</span></td>
                            <td data-label="Usuário"><span class="tech-value">{{ $credential->username }}</span></td>
                            <td data-label="Porta">{{ $credential->port ?? '—' }}</td>
                            <td data-label="Status"><span class="badge badge--{{ $credential->is_active ? 'success' : 'neutral' }}">{{ $credential->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                            <td data-label="Ações">
                                @canany(['credentials.manage', 'credentials.disable'])
                                <details class="row-menu"><summary>Ações</summary><div class="table-actions">
                                    @can('credentials.manage')
                                        <a href="{{ route('credentials.edit', ['credential' => $credential, 'page' => request('page')]) }}" class="btn btn--ghost btn--sm">Editar</a>
                                        @if($credential->type === 'ssh')
                                            <button type="button" class="btn btn--ghost btn--sm" data-open-credential-ssh="{{ $credential->id }}">Segurança SSH</button>
                                        @endif
                                    @endcan
                                    @can('credentials.disable')
                                        <form method="POST" action="{{ route('credentials.destroy', $credential) }}" onsubmit="return confirm('Remover esta credencial? Só é possível sem vínculos ativos.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                        </form>
                                    @endcan
                                </div></details>
                                @endcanany
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($credentials->hasPages())
            <div class="pagination-container">{{ $credentials->links() }}</div>
        @endif
    @endif
</div>
@can('credentials.manage')
@php
    $securityCredentials = $credentials->getCollection();
    if (isset($editingCredential) && ! $securityCredentials->contains('id', $editingCredential->id)) {
        $securityCredentials = $securityCredentials->concat([$editingCredential]);
    }
@endphp
@foreach($securityCredentials as $credential)
    @if($credential->type === 'ssh')
        @php
            $device = $credential->device;
            $sshKeyChanged = $device->ssh_host_key_fingerprint && $device->ssh_observed_fingerprint &&
                ($device->ssh_host_key_algorithm !== $device->ssh_observed_algorithm || $device->ssh_host_key_fingerprint !== $device->ssh_observed_fingerprint);
        @endphp
        <dialog class="modal form-create-modal credential-ssh-dialog" id="credential-ssh-dialog-{{ $credential->id }}" aria-labelledby="credential-ssh-title-{{ $credential->id }}">
            <div class="modal__surface">
                <div class="modal__header">
                    <div><h2 class="modal__title" id="credential-ssh-title-{{ $credential->id }}">Segurança SSH</h2><p class="modal__description">{{ $device->name }} · {{ $credential->name }} · {{ $device->management_ip }}</p></div>
                    <button type="button" class="modal__close" data-close-credential-ssh aria-label="Fechar"><x-icon name="close" /></button>
                </div>
                <form method="POST" action="{{ route('devices.ssh-host-key.trust', $device) }}">
                    @csrf
                    <input type="hidden" name="return_to" value="credential_test">
                    <input type="hidden" name="credential_id" value="{{ $credential->id }}">
                    <div class="modal__body form-create-modal__body">
                        <div class="form-create-grid">
                            <div class="form-field form-span-2">
                                <span class="form-label">Situação da chave</span>
                                <div class="credential-ssh-dialog__status">
                                    <span class="badge badge--{{ $sshKeyChanged ? 'warning' : ($device->ssh_host_key_fingerprint ? 'success' : 'neutral') }}">{{ $sshKeyChanged ? 'Chave alterada' : ($device->ssh_host_key_fingerprint ? 'Chave confiada' : 'Não confiada') }}</span>
                                    <p>{{ $sshKeyChanged ? 'Confira a nova chave antes de confiar. O backup SSH fica bloqueado enquanto ela for diferente da chave confiada.' : ($device->ssh_host_key_fingerprint ? 'A identidade SSH deste equipamento já foi aprovada.' : 'Execute o teste SSH da credencial para observar a chave e confira o fingerprint antes de confiar.') }}</p>
                                </div>
                            </div>
                            <div class="form-field"><span class="form-label">Algoritmo confiado</span><div class="form-control credential-ssh-dialog__value tech-value">{{ $device->ssh_host_key_algorithm ?? '—' }}</div></div>
                            <div class="form-field"><span class="form-label">Algoritmo observado</span><div class="form-control credential-ssh-dialog__value tech-value">{{ $device->ssh_observed_algorithm ?? '—' }}</div></div>
                            <div class="form-field form-span-2"><span class="form-label">Fingerprint confiado</span><div class="form-control credential-ssh-dialog__value tech-value">{{ $device->ssh_host_key_fingerprint ?? '—' }}</div></div>
                            <div class="form-field form-span-2"><span class="form-label">Fingerprint observado</span><div class="form-control credential-ssh-dialog__value tech-value">{{ $device->ssh_observed_fingerprint ?? '—' }}</div></div>
                        </div>
                    </div>
                    <div class="modal__footer form-create-modal__footer">
                        <button type="button" class="btn btn--ghost" data-close-credential-ssh>Fechar</button>
                        @if($device->ssh_observed_fingerprint && ($sshKeyChanged || ! $device->ssh_host_key_fingerprint) && auth()->user()->can('devices.manage'))
                            <button type="submit" class="btn btn--primary">Confiar nesta chave observada</button>
                        @endif
                    </div>
                </form>
            </div>
        </dialog>
    @endif
@endforeach
<script>
(() => {
    const requestedSecurityCredential = @json((string) request('ssh_security', ''));
    const indexUrl = @json(route('credentials.index', ['page' => request('page')]));
    document.querySelectorAll('[data-open-credential-ssh]').forEach(button => {
        const dialog = document.getElementById(`credential-ssh-dialog-${button.dataset.openCredentialSsh}`);
        button.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-close-credential-ssh]').forEach(close => close.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    });
    if (/^\d+$/.test(requestedSecurityCredential)) {
        const dialog = document.getElementById(`credential-ssh-dialog-${requestedSecurityCredential}`);
        if (dialog) {
            dialog.addEventListener('close', () => window.location.assign(indexUrl));
            dialog.showModal();
        }
    }
})();
</script>
@unless(isset($editingCredential))
<dialog class="modal form-create-modal" id="credential-create-dialog" aria-labelledby="credential-create-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="credential-create-title">Nova Credencial</h2><p class="modal__description">Associe o acesso a um equipamento e informe os dados de autenticação.</p></div>
            <button type="button" class="modal__close" data-close-credential-create aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('credentials.store') }}">
            @include('credentials._form', ['createModal' => true, 'credential' => null])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('credential-create-dialog');
    document.querySelectorAll('[data-open-credential-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-credential-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endunless
@isset($editingCredential)
<dialog class="modal form-create-modal" id="credential-edit-dialog" aria-labelledby="credential-edit-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="credential-edit-title">Editar Credencial</h2><p class="modal__description">Atualize os dados de acesso ao equipamento.</p></div>
            <button type="button" class="modal__close" data-close-credential-edit aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('credentials.update', ['credential' => $editingCredential, 'page' => request('page')]) }}">
            @include('credentials._form', ['credential' => $editingCredential, 'editModal' => true])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('credential-edit-dialog');
    const indexUrl = @json(route('credentials.index', ['page' => request('page')]));
    dialog.querySelectorAll('[data-close-credential-edit]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => window.location.assign(indexUrl));
    @unless(request()->filled('ssh_security')) dialog.showModal(); @endunless
    @if(request()->boolean('ssh_test')) dialog.querySelector('[data-run-credential-ssh-test]')?.focus(); @endif
})();
</script>
@endisset
<script>
(() => {
    const testUrl = @json(route('credentials.ssh-test'));
    document.querySelectorAll('[data-credential-ssh-test]').forEach(section => {
        const form = section.closest('form');
        const button = section.querySelector('[data-run-credential-ssh-test]');
        const result = section.querySelector('[data-credential-ssh-result]');
        const type = form.elements.type;
        const sync = () => { section.hidden = type.value !== 'ssh'; result.hidden = true; };
        type.addEventListener('change', sync);
        button.addEventListener('click', async () => {
            const data = new FormData(form);
            data.delete('_method');
            button.disabled = true;
            result.hidden = false;
            result.className = 'alert alert--warning';
            result.textContent = 'Testando a conexão SSH...';
            try {
                const response = await fetch(testUrl, {
                    method: 'POST', body: data,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const body = await response.json();
                result.className = `alert alert--${body.success ? 'success' : 'warning'}`;
                result.textContent = body.message || 'Não foi possível concluir o teste SSH.';
                if (body.trust_url) {
                    const link = document.createElement('a');
                    link.href = body.trust_url;
                    link.className = 'link';
                    link.textContent = 'Clique para autorizar a chave SSH';
                    result.append(document.createTextNode(' '), link);
                }
            } catch (_) {
                result.className = 'alert alert--warning';
                result.textContent = 'Não foi possível iniciar o teste SSH.';
            } finally {
                button.disabled = false;
            }
        });
    });
    document.querySelectorAll('[data-toggle-credential-secret]').forEach(button => {
        const form = button.closest('form');
        const input = form.elements.secret;
        const type = form.elements.type;
        const label = form.querySelector('[data-credential-secret-label]');
        const hint = form.querySelector('[data-credential-secret-hint]');
        const error = form.querySelector('[data-credential-secret-error]');
        const usesPassword = () => type.value === 'ssh' || type.value === 'telnet';
        const fieldName = () => usesPassword() ? 'senha' : 'segredo';
        let revealedSaved = null;
        let revealRequest = 0;
        const updateButton = () => {
            const visible = input.type === 'text';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', `${visible ? 'Ocultar' : 'Mostrar'} ${fieldName()} ${revealedSaved !== null || (button.dataset.credentialRevealUrl && !input.value) ? 'atual' : 'digitado'}`);
        };
        const hide = () => {
            revealRequest++;
            if (revealedSaved !== null && input.value === revealedSaved) input.value = '';
            revealedSaved = null;
            input.type = 'password';
            error.hidden = true;
            updateButton();
        };
        label.textContent = usesPassword() ? 'Senha' : 'Segredo';
        type.addEventListener('change', () => {
            label.textContent = usesPassword() ? 'Senha' : 'Segredo';
            if (hint) hint.textContent = usesPassword() ? 'a senha atual' : 'o segredo atual';
            hide();
        });
        input.addEventListener('input', () => { revealRequest++; updateButton(); });
        form.addEventListener('submit', () => {
            revealRequest++;
            if (revealedSaved !== null && input.value === revealedSaved) input.value = '';
            revealedSaved = null;
            input.type = 'password';
        });
        form.closest('dialog')?.addEventListener('close', hide);
        button.addEventListener('click', async () => {
            error.hidden = true;
            if (input.type === 'text') { hide(); return; }
            if (button.dataset.credentialRevealUrl && !input.value) {
                const requestId = ++revealRequest;
                button.disabled = true;
                try {
                    const response = await fetch(button.dataset.credentialRevealUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        },
                    });
                    if (!response.ok) throw new Error('reveal failed');
                    const data = await response.json();
                    if (typeof data.secret !== 'string') throw new Error('invalid response');
                    if (requestId !== revealRequest || input.value || !form.closest('dialog')?.open) return;
                    revealedSaved = data.secret;
                    input.value = data.secret;
                    input.type = 'text';
                    updateButton();
                } catch (_) {
                    error.textContent = `Não foi possível mostrar ${usesPassword() ? 'a senha' : 'o segredo'} atual.`;
                    error.hidden = false;
                } finally {
                    button.disabled = false;
                }
                return;
            }
            input.type = 'text';
            updateButton();
        });
    });
})();
</script>
@endcan
@endsection
