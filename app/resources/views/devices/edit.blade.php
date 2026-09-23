@extends('layouts.app')

@section('title', 'Editar Equipamento — Backup Manager')
@section('page-title', 'Editar Equipamento')
@section('page-description', 'Atualize as informações do equipamento.')

@section('content')

<div class="page-width">

    <article class="panel form-panel">

        <div class="panel-header">
            <div>
                <h2>{{ $device->name }}</h2>
                <p>{{ $device->management_ip }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('devices.update', $device) }}">
            @include('devices._form')
        </form>

    </article>

    @if (mb_strtolower(trim($device->vendor)) === 'huawei' && $device->platform === 'olt')
    <article class="panel form-panel">
        <div class="panel-header"><h2>FTP Push</h2></div>
        @if ($ftpSecret ?? false)
            <p>Senha FTP gerada. Copie agora; ela não será exibida novamente.</p>
            <p><code>{{ $ftpSecret }}</code></p>
            <p>Preparação manual da Huawei OLT, baseada no V1: no modo de configuração, execute <code>ftp set</code>, informe o usuário e a senha FTP exibidos aqui, depois <code>quit</code> e <code>save</code>. Verifique os prompts no modelo real. A V2 não envia esses comandos.</p>
        @endif
        @if ($account = $device->ftpAccount)
            <p>Conta: {{ $account->username }} · {{ ! $account->is_active ? 'Inativa' : ($account->provisioned_at && $account->provisioned_at >= $account->updated_at ? 'Pronta no PureDB' : 'Aguardando provisionamento local') }}</p>
            <p>Servidor FTP: {{ config('backup.ftp_host') ?: 'Configure BACKUP_FTP_HOST' }} · Porta 21 · Diretório remoto: /</p>
            <p>Diretório isolado: equipamento #{{ $device->id }}. Provisionamento: sincronização local pelo serviço ftp-admin.</p>
            <form method="POST" action="{{ route('devices.ftp-account.update', $device) }}">@csrf @method('PATCH')
                <input type="hidden" name="is_active" value="{{ $account->is_active ? 0 : 1 }}">
                <button type="submit">{{ $account->is_active ? 'Desativar' : 'Ativar' }} conta</button>
            </form>
            <form method="POST" action="{{ route('devices.ftp-account.rotate', $device) }}">@csrf
                <button type="submit">Gerar nova senha FTP</button>
            </form>
        @else
            <form method="POST" action="{{ route('devices.ftp-account.store', $device) }}">@csrf
                <button type="submit">Criar conta FTP</button>
            </form>
        @endif
    </article>
    @endif

    <article class="panel form-panel">
        <div class="panel-header"><h2>SSH Host Key</h2></div>
        @php
            $mismatch = $device->ssh_host_key_fingerprint && $device->ssh_observed_fingerprint &&
                ($device->ssh_host_key_algorithm !== $device->ssh_observed_algorithm ||
                 $device->ssh_host_key_fingerprint !== $device->ssh_observed_fingerprint);
        @endphp
        <p>Status: {{ $mismatch ? 'Chave alterada — backups bloqueados até aprovação' : ($device->ssh_host_key_fingerprint ? 'Confiada' : 'Não confiada') }}</p>
        <p>Algoritmo confiado: {{ $device->ssh_host_key_algorithm ?? '—' }}</p>
        <p>Fingerprint confiado: {{ $device->ssh_host_key_fingerprint ?? '—' }}</p>
        <p>Algoritmo observado: {{ $device->ssh_observed_algorithm ?? '—' }}</p>
        <p>Fingerprint observado: {{ $device->ssh_observed_fingerprint ?? '—' }}</p>
        @if ($device->ssh_observed_at)
            <p>Observada em: {{ app(\App\Services\InstanceTimezone::class)->format($device->ssh_observed_at, 'd/m/Y H:i') }}</p>
        @endif
        @if ($device->ssh_host_key_trusted_at)
            <p>Confiada em: {{ app(\App\Services\InstanceTimezone::class)->format($device->ssh_host_key_trusted_at, 'd/m/Y H:i') }}</p>
        @endif
        @error('ssh_host_key') <p>{{ $message }}</p> @enderror
        @if ($device->ssh_observed_fingerprint && ($mismatch || ! $device->ssh_host_key_fingerprint))
            <form method="POST" action="{{ route('devices.ssh-host-key.trust', $device) }}">
                @csrf
                <button type="submit">Confiar nesta chave observada</button>
            </form>
        @endif
    </article>

</div>

@endsection
