@extends('layouts.app')

@section('title', 'Configurações — Backup Manager')
@section('page-title', 'Configurações')
@section('page-description', 'Preferências operacionais da instância.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Configurações</h1>
        <p class="page-header__description">Preferências operacionais da instância.</p>
    </div>
</header>
@endsection

@section('content')
<div class="settings-page stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
    @endif
    @error('enabled')
        <div class="alert alert--warning" role="alert">{{ $message }}</div>
    @enderror

    <section class="card settings-card" aria-labelledby="timezone-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="timezone-title">Fuso horário</h2>
                <p class="card__description">Defina a região usada para exibir horários e agendar backups.</p>
            </div>
        </div>

        <div class="card__body">
            <p class="settings-current-time">Hora atual: <strong>{{ $currentTime }}</strong> <span>({{ $timezone }})</span></p>

            <form method="POST" action="{{ route('settings.update') }}" class="settings-form">
                @csrf
                @method('PUT')

                <div class="form-field">
                    <label class="form-label" for="timezone">Estado e fuso horário da instância</label>
                    <select class="form-control" id="timezone" name="timezone" required @error('timezone') aria-invalid="true" aria-describedby="timezone-error" @enderror>
                        @foreach($timezoneOptions as $identifier => $region)
                            <option value="{{ $identifier }}" @selected(old('timezone', $timezone) === $identifier)>{{ $region }}</option>
                        @endforeach
                    </select>
                    <small class="form-help">Escolha a região onde o servidor está instalado.</small>
                    @error('timezone') <span class="form-error" id="timezone-error">{{ $message }}</span> @enderror
                </div>

                @can('settings.manage')
                    <div class="settings-form__actions"><button class="btn btn--primary" type="submit">Salvar alterações</button></div>
                @endcan
            </form>
        </div>
    </section>

    <section class="card settings-card" aria-labelledby="settings-shortcuts-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="settings-shortcuts-title">Administração</h2>
                <p class="card__description">Acesse os controles operacionais da instância.</p>
            </div>
        </div>
        <div class="card__body settings-shortcuts">
            @can('backup_policies.view')
                <a href="{{ route('backup-policies.index') }}"><strong>Políticas de backup</strong><span>Método, agendamento e limites de retenção por política.</span></a>
            @endcan
            @can('ftp.view')
                <a href="{{ route('ftp.index') }}"><strong>FTP</strong><span>Contas de recebimento e status dos envios.</span></a>
            @endcan
            @can('users.view')
                <a href="{{ route('users.index') }}"><strong>Usuários</strong><span>Contas, papéis e acesso ao sistema.</span></a>
            @endcan
            @can('system_health.view')
                <a href="{{ route('system-health.index') }}"><strong>Saúde do sistema</strong><span>Estado dos serviços, armazenamento e motor de backup.</span></a>
            @endcan
            @can('audit.view')
                <a href="{{ route('audit.index') }}"><strong>Auditoria</strong><span>Histórico das alterações administrativas.</span></a>
            @endcan
        </div>
    </section>

    <section class="card settings-card" id="retention" aria-labelledby="retention-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="retention-title">Retenção de backups</h2>
                <p class="card__description">Controle a limpeza automática dos backups antigos.</p>
            </div>
            <span class="badge badge--{{ $retentionEnabled ? 'success' : 'neutral' }}">{{ $retentionEnabled ? 'Ligada' : 'Desligada' }}</span>
        </div>
        <div class="card__body settings-retention">
            <p>Os limites de <strong>dias</strong> e <strong>quantidade</strong> são definidos em cada política. Por exemplo, 30 dias mantém os backups dos últimos 30 dias. Se os dois limites forem preenchidos, basta ultrapassar um deles para que um backup entre na limpeza. O backup válido mais recente de cada vínculo é protegido.</p>
            <p>Quando ligada, a limpeza é verificada diariamente às <strong>{{ config('backup.retention_time') }}</strong>, no fuso <strong>{{ $timezone }}</strong>. O botão <strong>Política ativa</strong> controla os backups agendados; ele não liga esta limpeza.</p>

            @unless($retentionAvailable)
                <div class="alert alert--warning" role="status">A configuração da retenção aguarda a atualização do banco.</div>
            @else
                @can('settings.manage')
                    <form method="POST" action="{{ route('settings.retention.preview') }}">
                        @csrf
                        <button class="btn btn--secondary" type="submit">Ver prévia da limpeza</button>
                    </form>
                @endcan

                @if($retentionPreview)
                    @php($previewSummary = $retentionPreview['summary'])
                    @php($previewFresh = now()->timestamp - $retentionPreview['created_at'] <= 600)
                    <div class="settings-retention__preview" role="status">
                        <strong>Prévia da limpeza — nenhum arquivo foi apagado</strong>
                        <dl>
                            <div><dt>Backups que seriam removidos</dt><dd>{{ $previewSummary['candidates'] }}</dd></div>
                            <div><dt>Últimos backups protegidos</dt><dd>{{ $previewSummary['protected_latest'] }}</dd></div>
                            <div><dt>Arquivos ausentes</dt><dd>{{ $previewSummary['missing'] }}</dd></div>
                            <div><dt>Anomalias</dt><dd>{{ $previewSummary['anomalies'] }}</dd></div>
                        </dl>
                        @unless($previewFresh)<p>Esta prévia expirou. Gere outra antes de ligar a limpeza.</p>@endunless
                    </div>
                @endif

                @can('settings.manage')
                    @if($retentionEnabled)
                        <form method="POST" action="{{ route('settings.retention.update') }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="enabled" value="0">
                            <button class="btn btn--secondary" type="submit">Desligar limpeza automática</button>
                        </form>
                    @elseif($retentionPreview && $previewFresh)
                        <form method="POST" action="{{ route('settings.retention.update') }}" class="settings-retention__enable">
                            @csrf @method('PATCH')
                            <input type="hidden" name="enabled" value="1">
                            <label class="switch-row"><input type="checkbox" name="confirmed" value="1" required><span><strong>Conferi a prévia</strong><small>Entendo que, após ligar, a rotina diária poderá remover os backups que ultrapassarem os limites das políticas.</small></span></label>
                            <button class="btn btn--primary" type="submit">Ligar limpeza automática</button>
                        </form>
                    @endif
                @endcan
            @endunless
        </div>
    </section>
</div>
@endsection
