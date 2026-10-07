@extends('layouts.app')

@section('title', 'Notificações — Backup Manager')
@section('page-title', 'Notificações')
@section('page-description', 'Avisos operacionais pelo Telegram.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Notificações</h1>
        <p class="page-header__description">Avisos operacionais pelo Telegram: falhas, atrasos e serviços parados, com aviso de normalização.</p>
    </div>
    <div class="page-header__actions"><a class="btn btn--secondary" href="{{ route('settings.edit') }}">Voltar às configurações</a></div>
</header>
@endsection

@section('content')
@php
    $tz = app(\App\Services\InstanceTimezone::class);
    $fmt = fn ($v) => $v ? $tz->format(\Illuminate\Support\Carbon::parse($v, 'UTC'), 'd/m/Y H:i') : '—';
    $statusLabel = ['pending' => 'Aguardando', 'sent' => 'Enviado', 'failed' => 'Falhou'];
    $kindLabel = ['alert' => 'Alerta', 'recovery' => 'Normalizado', 'summary' => 'Resumo', 'test' => 'Teste'];
@endphp
<div class="settings-page stack">
    @if(session('success'))<div class="alert alert--success" role="status">{{ session('success') }}</div>@endif
    @error('enabled')<div class="alert alert--warning" role="alert">{{ $message }}</div>@enderror

    <section class="card settings-card" aria-labelledby="tg-title">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="tg-title">Telegram</h2>
                <p class="card__description">Pendentes: {{ $pending }} · Falhas nas últimas 24 h: {{ $failed24h }} · Último envio: {{ $fmt($lastSent) }}</p>
            </div>
            <span class="badge badge--{{ $settings->enabled ? 'success' : 'neutral' }}">{{ $settings->enabled ? 'Canal habilitado' : 'Canal desabilitado' }}</span>
        </div>
        <div class="card__body">
            <form method="POST" action="{{ route('settings.notifications.update') }}" class="settings-form">
                @csrf @method('PUT')
                <label class="form-check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled))> Enviar notificações automáticas</label>

                <div class="form-field">
                    <label class="form-label" for="bot_token">Token do bot</label>
                    <div style="display:flex;gap:.5rem">
                        <input class="form-control" id="bot_token" name="bot_token" type="password" autocomplete="off" placeholder="{{ $settings->bot_token ? 'Configurado — deixe vazio para manter' : '123456:ABC…' }}" style="flex:1">
                        @can('settings.manage')
                            <button type="button" class="btn btn--secondary" id="toggle-bot-token" aria-pressed="false" aria-label="Mostrar token" title="Mostrar/ocultar token"><x-icon name="eye" size="sm" /></button>
                        @endcan
                    </div>
                    <small class="form-help">Crie o bot no @BotFather. O token é guardado cifrado e nunca é exibido novamente.</small>
                    @error('bot_token')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label class="form-label" for="chat_id">Chat ID</label>
                    <input class="form-control" id="chat_id" name="chat_id" value="{{ old('chat_id', $settings->chat_id) }}" placeholder="-1001234567890">
                    <small class="form-help">Adicione o bot ao grupo/canal. Em supergrupos o ID começa com -100.</small>
                    @error('chat_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label class="form-label" for="thread_id">ID do tópico (opcional)</label>
                    <input class="form-control" id="thread_id" name="thread_id" type="number" min="1" value="{{ old('thread_id', $settings->thread_id) }}" placeholder="Ex.: 1411">
                    <small class="form-help">Só para supergrupos com tópicos. Deixe vazio para enviar no chat geral. O ID é o número no final do link de uma mensagem do tópico (t.me/c/…/<b>1411</b>/…).</small>
                    @error('thread_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label class="form-label" for="cooldown_minutes">Repetir alerta persistente a cada (minutos)</label>
                    <input class="form-control" id="cooldown_minutes" name="cooldown_minutes" type="number" min="5" max="1440" value="{{ old('cooldown_minutes', $settings->cooldown_minutes) }}" required>
                    @error('cooldown_minutes')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <fieldset class="form-field">
                    <legend class="form-label">Janela de manutenção ({{ $timezone }})</legend>
                    <label class="form-check"><input type="checkbox" name="maintenance_enabled" value="1" @checked(old('maintenance_enabled', $settings->maintenance_enabled))> Silenciar alertas não críticos neste horário</label>
                    <input class="form-control" type="time" name="maintenance_start" value="{{ old('maintenance_start', $settings->maintenance_start) }}" aria-label="Início" required>
                    <input class="form-control" type="time" name="maintenance_end" value="{{ old('maintenance_end', $settings->maintenance_end) }}" aria-label="Fim" required>
                    <small class="form-help">Alertas críticos continuam sendo enviados; os demais saem após a janela se ainda estiverem ativos.</small>
                </fieldset>
                <fieldset class="form-field">
                    <legend class="form-label">Resumos automáticos ({{ $timezone }})</legend>
                    <label class="form-check"><input type="checkbox" name="daily_enabled" value="1" @checked(old('daily_enabled', $settings->daily_enabled))> Resumo diário (dia anterior completo) às</label>
                    <input class="form-control" type="time" name="daily_time" value="{{ old('daily_time', $settings->daily_time) }}" aria-label="Horário do resumo diário">
                    <label class="form-check"><input type="checkbox" name="weekly_enabled" value="1" @checked(old('weekly_enabled', $settings->weekly_enabled))> Resumo semanal (7 dias anteriores) toda</label>
                    <select class="form-control" name="weekly_day" aria-label="Dia do resumo semanal">
                        @foreach(['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'] as $i => $dayName)
                            <option value="{{ $i }}" @selected((int) old('weekly_day', $settings->weekly_day) === $i)>{{ $dayName }}</option>
                        @endforeach
                    </select>
                    <input class="form-control" type="time" name="weekly_time" value="{{ old('weekly_time', $settings->weekly_time) }}" aria-label="Horário do resumo semanal">
                    <small class="form-help">Cada período é enviado uma única vez, mesmo após reinício. O resumo inclui concluídos, falhas, rejeições FTP, remoções por retenção e equipamentos com atenção.</small>
                </fieldset>
                @can('settings.manage')
                    <div class="settings-form__actions"><button class="btn btn--primary" type="submit">Salvar alterações</button></div>
                @endcan
            </form>
            @can('settings.manage')
                <form method="POST" action="{{ route('settings.notifications.test') }}" class="settings-form">
                    @csrf
                    <button class="btn btn--secondary" type="submit">Enviar mensagem de teste</button>
                </form>
                <div class="settings-form__actions">
                    @foreach(['daily' => 'Prévia do resumo diário', 'weekly' => 'Prévia do resumo semanal'] as $kind => $label)
                        <form method="POST" action="{{ route('settings.notifications.summary-test', $kind) }}" style="display:inline">@csrf<button class="btn btn--secondary" type="submit">{{ $label }}</button></form>
                    @endforeach
                </div>
            @endcan
        </div>
    </section>

    <section class="card settings-card" aria-labelledby="tg-history">
        <div class="card__header"><div><h2 class="card__title" id="tg-history">Histórico recente</h2><p class="card__description">Últimas 30 mensagens. Erros mostram apenas códigos seguros.</p></div></div>
        <div class="card__body">
            @forelse($history as $item)
                <p><strong>{{ $kindLabel[$item->kind] ?? $item->kind }}</strong> · {{ $item->title }}
                    <br><small>{{ $fmt($item->created_at) }} · {{ $statusLabel[$item->status] ?? $item->status }}@if($item->attempts) · tentativa {{ $item->attempts }}@endif @if($item->last_error) · {{ $errorLabels[$item->last_error] ?? 'Falha no envio' }}@endif</small></p>
            @empty
                <p>Nenhuma notificação registrada ainda.</p>
            @endforelse
        </div>
    </section>
</div>
<script>
(() => {
    const input = document.getElementById('bot_token');
    const button = document.getElementById('toggle-bot-token');
    if (!input || !button) return;
    const hasStored = @json((bool) $settings->bot_token);
    let revealedStored = false;
    button.addEventListener('click', async () => {
        if (input.type === 'text') {
            input.type = 'password';
            if (revealedStored) { input.value = ''; revealedStored = false; }
            button.setAttribute('aria-pressed', 'false');
            return;
        }
        if (input.value === '' && hasStored) {
            try {
                const response = await fetch(@json(route('settings.notifications.token.reveal')), {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json'},
                });
                if (!response.ok) throw new Error();
                input.value = (await response.json()).token;
                revealedStored = true;
            } catch (e) { alert('Não foi possível exibir o token agora.'); return; }
        }
        input.type = 'text';
        button.setAttribute('aria-pressed', 'true');
    });
})();
</script>
@endsection
