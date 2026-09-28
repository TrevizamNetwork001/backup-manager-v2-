@extends('layouts.app')
@section('title', 'Gerenciar Política — Backup Manager')
@section('page-title', 'Gerenciar Política')
@section('page-description', 'Atualize a política e seus equipamentos associados.')
@section('page-header')
<header class="page-header">
    <div class="page-header__content"><h1 class="page-header__title">Editar política</h1><p class="page-header__description">Atualize a política e seus equipamentos associados.</p></div>
    <div class="page-header__actions"><a href="{{ route('backup-policies.index') }}" class="btn btn--ghost">Voltar à lista</a></div>
</header>
@endsection
@section('content')
<div class="modern-form-page stack">
@if(session('success')) <div class="alert alert--success" role="status">{{ session('success') }}</div> @endif
@if(session('warning')) <div class="alert alert--warning" role="alert">{{ session('warning') }}</div> @endif
@error('association') <div class="alert alert--warning" role="alert">{{ $message }}</div> @enderror
<article class="card">
    <div class="card__header"><div><h2 class="card__title">{{ $backupPolicy->name }}</h2></div></div>
    <form class="card__body" method="POST" action="{{ route('backup-policies.update', $backupPolicy) }}">@include('backup-policies._form')</form>
</article>

<article class="card policy-associations">
    <div class="card__header"><div><h2 class="card__title">Equipamentos associados</h2><p class="card__description">{{ $backupPolicy->method === 'ftp_push' ? 'Escolha uma Huawei OLT com conta FTP ativa.' : 'Escolha uma credencial SSH do próprio equipamento.' }}</p></div></div>
    <div class="card__body">
    @if($backupPolicy->deviceBackupPolicies->isEmpty())
        <p class="muted-text">Nenhum equipamento associado.</p>
    @else
        <div class="table-shell" role="region" aria-label="Equipamentos associados à política" tabindex="0"><table class="data-table">
            <thead><tr><th>Equipamento</th><th>Credencial</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>@foreach($backupPolicy->deviceBackupPolicies as $association)
                <tr>
                    <td data-label="Equipamento"><strong>{{ $association->device->name }}</strong></td>
                    <td data-label="Credencial">{{ $association->credential ? $association->credential->name.' · SSH · '.$association->credential->username : 'Conta FTP do equipamento' }}</td>
                    <td data-label="Status"><span class="badge badge--{{ $association->is_active ? 'success' : 'neutral' }}">{{ $association->is_active ? 'Ativa' : 'Inativa' }}</span></td>
                    <td data-label="Ações" class="table-actions">
                        @if($association->is_active && $backupPolicy->is_active && $association->device->is_active && ($association->credential?->is_active || ($backupPolicy->method === 'ftp_push' && $backupPolicy->schedule_type === 'manual')))
                            <form method="POST" action="{{ route('backup-policies.associations.executions.store', [$backupPolicy, $association]) }}">@csrf<button class="btn btn--ghost btn--sm" type="submit">Criar execução</button></form>
                        @endif
                        <form method="POST" action="{{ route('backup-policies.associations.update', [$backupPolicy, $association]) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $association->is_active ? 0 : 1 }}"><button class="btn btn--ghost btn--sm" type="submit">{{ $association->is_active ? 'Desativar' : 'Ativar' }}</button></form>
                        <form method="POST" action="{{ route('backup-policies.associations.destroy', [$backupPolicy, $association]) }}" onsubmit="return confirm('Remover esta associação?');">@csrf @method('DELETE')<button class="btn btn--ghost btn--sm table-actions__danger" type="submit">Remover</button></form>
                    </td>
                </tr>
            @endforeach</tbody>
        </table></div>
    @endif

    <form method="POST" action="{{ route('backup-policies.associations.store', $backupPolicy) }}">
        @csrf
        <div class="form-create-grid">
            <div class="form-field"><label class="form-label" for="device_id">Equipamento *</label><select class="form-control" id="device_id" name="device_id" required><option value="">Selecione...</option>@foreach($devices as $device)<option value="{{ $device->id }}" @selected((string) old('device_id') === (string) $device->id)>{{ $device->name }}</option>@endforeach</select>@error('device_id') <span class="form-error">{{ $message }}</span> @enderror</div>
            @if($backupPolicy->method === 'ssh_pull')<div class="form-field"><label class="form-label" for="credential_id">Credencial SSH *</label><select class="form-control" id="credential_id" name="credential_id" required><option value="">Selecione...</option>@foreach($credentials as $credential)<option value="{{ $credential->id }}" data-device-id="{{ $credential->device_id }}" @selected((string) old('credential_id') === (string) $credential->id)>{{ $credential->name }} · {{ $credential->username }}</option>@endforeach</select>@error('credential_id') <span class="form-error">{{ $message }}</span> @enderror</div>@endif
            <div class="form-field form-span-2"><input type="hidden" name="is_active" value="0"><label class="switch-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))><span><strong>Associação ativa</strong></span></label></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary">Associar equipamento</button></div>
    </form>
    </div>
</article>
</div>
<script>
    const deviceSelect = document.getElementById('device_id');
    const credentialSelect = document.getElementById('credential_id');
    function filterCredentials() {
        if (!credentialSelect) return;
        for (const option of credentialSelect.options) {
            if (!option.value) continue;
            option.hidden = option.dataset.deviceId !== deviceSelect.value;
            option.disabled = option.hidden;
        }
        if (credentialSelect.selectedOptions[0]?.disabled) credentialSelect.value = '';
    }
    deviceSelect.addEventListener('change', filterCredentials);
    filterCredentials();
</script>
@endsection
