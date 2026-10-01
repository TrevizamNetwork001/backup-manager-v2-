@extends('layouts.app')

@section('title', 'Equipamentos — Backup Manager')
@section('page-title', 'Equipamentos')
@section('page-description', 'Gerencie os equipamentos protegidos pelo Backup Manager.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Equipamentos</h1>
        <p class="page-header__description">Gerencie os equipamentos protegidos pelo Backup Manager.</p>
    </div>
    <div class="page-header__actions">
        @can('devices.manage')
            <button type="button" class="btn btn--primary" data-open-device-create @disabled($sites->isEmpty())><x-icon name="add" size="sm" /> Novo equipamento</button>
        @endcan
    </div>
</header>
@endsection

@section('content')
<div class="devices-list stack">
    @if(session('success') && ! session('policy_device_id'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning') && ! session('policy_device_id'))
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
    @endif
    @if($sites->isEmpty())
        <div class="alert alert--warning" role="status">
            Nenhum Site / POP ativo está disponível.
            @can('sites.manage') <a class="link" href="{{ route('sites.create') }}">Cadastre ou ative um Site / POP</a> antes de adicionar equipamentos. @else Cadastre ou ative um Site / POP antes de adicionar equipamentos. @endcan
        </div>
    @endif

    <div class="toolbar">
        <div class="toolbar__primary">
            <span class="toolbar__count"><strong>{{ $devices->total() }}</strong> {{ $devices->total() === 1 ? 'equipamento cadastrado' : 'equipamentos cadastrados' }}</span>
        </div>
        @unless($devices->isEmpty())
            <label class="list-search">Buscar nesta página <input class="form-control" type="search" data-list-search="devices-rows" placeholder="Nome, hostname, IP, fabricante ou local"></label>
        @endunless
    </div>

    @if($devices->isEmpty())
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="device" /></div>
            <h2 class="empty-state__title">Nenhum equipamento cadastrado</h2>
            <p class="empty-state__description">Cadastre o primeiro equipamento para começar a estruturar a operação de backup.</p>
            @can('devices.manage')
                <div class="empty-state__actions"><button type="button" class="btn btn--secondary" data-open-device-create @disabled($sites->isEmpty())>Cadastrar primeiro equipamento</button></div>
            @endcan
        </div>
    @else
        <div class="table-shell" role="region" aria-label="Lista de equipamentos" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>Site / POP</th>
                        <th>Fabricante / Modelo</th>
                        <th>Método</th>
                        <th>Último backup</th>
                        <th>Saúde</th>
                        <th>Status</th>
                        <th class="table-actions-column">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $device)
                        @php
                            $health = $healthByDevice->get($device->id);
                        @endphp
                        <tr data-list-row="devices-rows" data-search="{{ mb_strtolower($device->name.' '.$device->hostname.' '.$device->management_ip.' '.$device->vendor.' '.$device->model.' '.$device->site->name) }}">
                            <td data-label="Equipamento">
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ $device->name }}</span>
                                    <span class="entity-cell__meta tech-value">@if($device->technicalHostnameForDisplay()){{ $device->technicalHostnameForDisplay() }} · @endif{{ $device->management_ip }}</span>
                                </div>
                            </td>
                            <td data-label="Site / POP">
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ $device->site->name }}</span>
                                    @if($device->site->code)<span class="entity-cell__meta">{{ $device->site->code }}</span>@endif
                                </div>
                            </td>
                            <td data-label="Fabricante / Modelo">
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ \App\Models\Device::normalizeVendor($device->vendor) }}</span>
                                    @if($device->model)<span class="entity-cell__meta">{{ $device->model }}</span>@endif
                                </div>
                            </td>
                            <td data-label="Método">
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ ($health['method'] ?? null) === 'ftp_push' ? 'Envio via FTP' : (($health['method'] ?? null) === 'ssh_pull' ? 'Coleta via SSH' : '—') }}</span>
                                </div>
                            </td>
                            <td data-label="Último backup" class="tech-value">{{ $health && $health['last_backup_at'] ? app(\App\Services\InstanceTimezone::class)->format(\Illuminate\Support\Carbon::parse($health['last_backup_at']), 'd/m/Y H:i') : '—' }}</td>
                            <td data-label="Saúde">
                                @if($health)
                                    <span class="badge badge--{{ \App\Support\HealthStatus::from($health['status'])->badgeVariant() }}">{{ \App\Support\HealthStatus::from($health['status'])->label() }}</span>
                                @else
                                    <span class="badge badge--neutral">Não avaliado</span>
                                @endif
                            </td>
                            <td data-label="Status"><span class="badge badge--{{ $device->is_active ? 'success' : 'neutral' }}">{{ $device->is_active ? 'Ativo' : 'Inativo' }}</span></td>
                            <td data-label="Ações">
                                <details class="row-menu"><summary>Ações</summary><div class="table-actions">
                                    @can('devices.manage')
                                        <a href="{{ route('devices.edit', $device) }}" class="btn btn--ghost btn--sm">Editar</a>
                                        @if($device->isHuaweiFtpEligible())
                                            <button type="button" class="btn btn--ghost btn--sm" data-open-device-access="{{ $device->id }}">FTP</button>
                                        @endif
                                    @endcan
                                    @can('backup_policies.manage')
                                        <button type="button" class="btn btn--ghost btn--sm" data-open-device-policy="{{ $device->id }}">Política de backup</button>
                                    @endcan
                                    @can('devices.delete')
                                        <form method="POST" action="{{ route('devices.destroy', $device) }}" onsubmit="return confirm('Remover este equipamento? Só é possível sem histórico ou vínculos.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                        </form>
                                    @endcan
                                </div></details>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-list-empty="devices-rows" hidden><td colspan="8" class="empty-table">Nenhum equipamento nesta página corresponde à busca.</td></tr>
                </tbody>
            </table>
        </div>
        @if($devices->hasPages())
            <div class="pagination-container">{{ $devices->links() }}</div>
        @endif
    @endif
</div>
@can('devices.manage')
@foreach($devices as $device)
    @if($device->isHuaweiFtpEligible())
    <dialog class="modal form-create-modal device-access-dialog" id="device-access-dialog-{{ $device->id }}" aria-labelledby="device-access-title-{{ $device->id }}">
        <div class="modal__surface">
            <div class="modal__header">
                <div><h2 class="modal__title" id="device-access-title-{{ $device->id }}">FTP</h2><p class="modal__description">{{ $device->name }} · {{ $device->management_ip }}</p></div>
                <button type="button" class="modal__close" data-close-device-access aria-label="Fechar"><x-icon name="close" /></button>
            </div>
            <div class="modal__body form-create-modal__body device-access-dialog__body">
                <section class="device-access-dialog__section">
                    <h3>Backup via FTP</h3>
                    <p>Configure o envio de backup deste equipamento no assistente FTP.</p>
                </section>
            </div>
            <div class="modal__footer form-create-modal__footer">
                <button type="button" class="btn btn--ghost" data-close-device-access>Cancelar</button>
                <a href="{{ route('devices.edit', [$device, 'olt_wizard' => 1]) }}" class="btn btn--primary">Abrir assistente FTP</a>
            </div>
        </div>
    </dialog>
    @endif
@endforeach
<script>
(() => {
    document.querySelectorAll('[data-open-device-access]').forEach(button => {
        const dialog = document.getElementById(`device-access-dialog-${button.dataset.openDeviceAccess}`);
        button.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-close-device-access]').forEach(close => close.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    });
})();
</script>
<dialog class="modal form-create-modal" id="device-create-dialog" aria-labelledby="device-create-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="device-create-title">Novo equipamento</h2><p class="modal__description">Informe a identificação, o acesso e os dados operacionais.</p></div>
            <button type="button" class="modal__close" data-close-device-create aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('devices.store') }}">
            @include('devices._form', ['createModal' => true, 'device' => null])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('device-create-dialog');
    document.querySelectorAll('[data-open-device-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-device-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any() && ! old('policy_device_id')) dialog.showModal(); @endif
})();
</script>
@endcan
@can('backup_policies.manage')
    @foreach($devices as $device)
        @include('devices._policy_modal', [
            'device' => $device,
            'availablePolicies' => $policies->filter(fn ($policy) => ! $device->deviceBackupPolicies->contains('backup_policy_id', $policy->id)
                && ! $device->deviceBackupPolicies->contains(fn ($association) => $association->is_active && $association->backupPolicy?->is_active && $association->backupPolicy->method === $policy->method)
                && ($policy->method === 'ssh_pull'
                    ? ($device->platform !== 'olt' || mb_strtolower(trim($device->vendor)) === 'vsol') && $sshCredentialsByDevice->has($device->id)
                    : $policy->method === 'ftp_push' && $policy->schedule_type === 'manual' && $device->isHuaweiFtpEligible() && $device->ftpAccount?->is_active)),
            'sshCredentials' => $sshCredentialsByDevice->get($device->id, collect()),
        ])
    @endforeach
<script>
(() => {
    document.querySelectorAll('[data-open-device-policy]').forEach(button => {
        const dialog = document.getElementById(`device-policy-${button.dataset.openDevicePolicy}`);
        button.addEventListener('click', () => dialog.showModal());
    });
    document.querySelectorAll('.device-policy-dialog').forEach(dialog => {
        dialog.querySelectorAll('[data-close-device-policy]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', () => {
            if (location.hash === `#${dialog.id}`) history.replaceState(null, '', location.pathname + location.search);
        });
        const form = dialog.querySelector('[data-device-policy-form]');
        if (!form) return;
        const policy = form.querySelector('[data-device-policy-select]');
        const credentialField = form.querySelector('[data-device-policy-credential-field]');
        const credential = form.querySelector('[data-device-policy-credential]');
        const addSection = dialog.querySelector('[data-device-policy-add]');
        const submit = dialog.querySelector('[data-device-policy-submit]');
        const sync = () => {
            const selected = policy.selectedOptions[0];
            const isSsh = selected?.dataset.method === 'ssh_pull';
            form.action = selected?.dataset.storeUrl || '';
            credentialField.hidden = !isSsh;
            credential.disabled = !isSsh;
            credential.required = isSsh;
            if (!isSsh) credential.value = '';
            submit.hidden = !addSection.open;
            submit.disabled = !addSection.open || !selected?.dataset.storeUrl;
        };
        addSection.addEventListener('toggle', sync);
        policy.addEventListener('change', sync);
        sync();
    });
    const requestedDevice = @json(old('policy_device_id')) || location.hash.match(/^#device-policy-(\d+)$/)?.[1];
    if (requestedDevice) document.getElementById(`device-policy-${requestedDevice}`)?.showModal();
})();
</script>
@endcan
@endsection
