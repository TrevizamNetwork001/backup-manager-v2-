@extends('layouts.app')

@section('title', 'Editar Equipamento — Backup Manager')
@section('page-title', 'Editar Equipamento')
@section('page-description', 'Atualize as informações do equipamento.')

@section('content')

<div class="page-width">
    @php
        $isHuaweiOltFtp = mb_strtolower(trim($device->vendor)) === 'huawei' && $device->platform === 'olt';
    @endphp

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

    @if ($isHuaweiOltFtp)
    @php
        $ftpAccount = $device->ftpAccount;
        $ftpHost = trim((string) config('backup.ftp_host'));
        $ftpProvisioned = $ftpAccount?->is_active && $ftpAccount->provisioned_at && $ftpAccount->provisioned_at >= $ftpAccount->updated_at;
        $ftpReady = $ftpProvisioned && $ftpHost !== '';
    @endphp
    <article class="panel form-panel">
        <div class="panel-header"><h2>O que fazer agora</h2></div>
        @if ($ftpReady)
            <div class="alert-success"><strong>Pronto para configurar a OLT.</strong> A conta FTP está ativa, sincronizada e o endereço do servidor está disponível.</div>
            <p>Copie os dados abaixo e faça a configuração inicial diretamente na OLT. Depois, siga as instruções de execução para cada backup.</p>
        @else
            <div class="alert-warning"><strong>Não configure a OLT ainda.</strong> Falta preparar a conta FTP ou o endereço do servidor.</div>
            @if (! $ftpAccount)
                <p>Crie a conta FTP deste equipamento e guarde a senha exibida uma única vez.</p>
            @elseif (! $ftpAccount->is_active)
                <p>Ative a conta FTP deste equipamento.</p>
            @endif
            @if ($ftpHost === '')
                <p>O endereço do servidor FTP desta instalação ainda não foi configurado.</p>
            @endif
            <p><strong>Próximo passo no servidor:</strong></p>
            <ol>
                @if (! $ftpProvisioned)
                    <li>Sincronizar a conta no PureDB.</li>
                @endif
                @if ($ftpHost === '')
                    <li>Configurar o endereço do servidor FTP.</li>
                @endif
            </ol>
        @endif
    </article>

    <article class="panel form-panel">
        <div class="panel-header"><h2>Configuração inicial da OLT — feita uma única vez</h2></div>
        @if ($ftpSecret ?? false)
            <div class="alert-warning"><strong>Senha FTP gerada.</strong> Copie agora e guarde em local seguro; ela não será exibida novamente.</div>
            <p><code>{{ $ftpSecret }}</code></p>
        @endif
        @if ($ftpAccount)
            <p><strong>Estado da conta:</strong> {{ ! $ftpAccount->is_active ? 'Inativa' : ($ftpProvisioned ? 'Pronta no servidor FTP' : 'Aguardando sincronização com o servidor FTP') }}</p>
        @endif
        @if ($ftpReady)
            <p><strong>Servidor:</strong> {{ $ftpHost }}</p>
            <p><strong>Porta:</strong> 21</p>
            <p><strong>Usuário:</strong> {{ $ftpAccount->username }}</p>
            <p><strong>Diretório remoto:</strong> <code>/</code> (diretório exclusivo deste equipamento)</p>
            <p>Na OLT, em uma sessão confiável, execute <code>ftp set</code>, informe o usuário e a senha FTP, depois execute <code>quit</code> e <code>save</code>. Confira os prompts do modelo antes de confirmar. Se perdeu a senha, gere uma nova abaixo e aguarde a sincronização antes de configurar a OLT.</p>
        @else
            <p>Os dados de conexão estarão disponíveis aqui quando a conta e o servidor estiverem prontos.</p>
        @endif
        <p>A V2 não executa <code>ftp set</code>, <code>save</code> nem qualquer comando na OLT.</p>
        @if ($ftpAccount)
            <form method="POST" action="{{ route('devices.ftp-account.update', $device) }}">@csrf @method('PATCH')
                <input type="hidden" name="is_active" value="{{ $ftpAccount->is_active ? 0 : 1 }}">
                <button type="submit">{{ $ftpAccount->is_active ? 'Desativar' : 'Ativar' }} conta</button>
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

    <article class="panel form-panel">
        <div class="panel-header"><h2>Execução de backup — feita a cada backup</h2></div>
        <p>Com a OLT já configurada, crie uma execução manual na <a href="{{ route('backup-policies.index') }}">política de backup FTP Push</a> e coloque-a na fila. Em seguida, execute manualmente na OLT o comando de backup mostrado na página da execução, usando o nome de arquivo indicado.</p>
        <p>A V2 aguarda o arquivo enviado pela OLT; ela não inicia o envio nem executa comandos na OLT.</p>
    </article>
    @endif

    @unless ($isHuaweiOltFtp)
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
    @endunless

</div>

@endsection
