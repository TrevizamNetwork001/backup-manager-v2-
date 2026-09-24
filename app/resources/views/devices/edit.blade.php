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
        $wizard = app(\App\Services\OltFtpWizard::class)->snapshot($device);
    @endphp
    <article class="panel form-panel">
        <div class="panel-header"><div><h2>Integração Huawei OLT / FTP</h2><p>Estado: {{ $wizard['operational'] ? 'Operacional' : 'Configuração pendente' }}</p></div></div>
        <button type="button" class="secondary-button" id="open-olt-wizard">Abrir configuração guiada</button>
    </article>
    <dialog id="olt-wizard" class="olt-wizard" aria-labelledby="olt-wizard-title" data-state="{{ $wizard['state'] }}" data-current-step="{{ $wizard['current_step'] }}" data-execution-id="{{ $wizard['execution']?->id }}" data-execution-status="{{ $wizard['execution']?->status }}">
        @php
            $wizardStep = $wizard['current_step'];
        @endphp
        <div class="olt-wizard-header">
            <div>
                <h2 id="olt-wizard-title">Configuração Huawei OLT / FTP</h2>
                <p id="olt-wizard-count">Etapa {{ $wizardStep }} de 6</p>
            </div>
            <button type="button" id="close-olt-wizard" class="secondary-button" aria-label="Fechar configuração">Fechar</button>
        </div>
        <div class="olt-wizard-progress" role="progressbar" aria-label="Progresso da configuração" aria-valuemin="1" aria-valuemax="6" aria-valuenow="{{ $wizardStep }}">
            <span id="olt-wizard-progress-fill" style="width: {{ $wizardStep / 6 * 100 }}%"></span>
        </div>
        <ol class="olt-wizard-steps" aria-label="Etapas da configuração">
            @foreach (['Conta FTP', 'Sincronização PureDB', 'Servidor FTP', 'Configuração da OLT', 'Teste de integração', 'Integração validada'] as $number => $label)
                <li data-wizard-step-item="{{ $number + 1 }}" @class(['is-current' => $number + 1 === $wizardStep, 'is-complete' => $number + 1 < $wizardStep, 'is-pending' => $number + 1 > $wizardStep, 'is-error' => $number + 1 === 5 && $wizard['state'] === 'failed'])>
                    <button type="button" data-wizard-step="{{ $number + 1 }}" @if ($number + 1 === $wizardStep) aria-current="step" @endif @if ($number + 1 > $wizardStep) disabled @endif>
                        <span class="olt-wizard-step-marker" aria-hidden="true">{{ $number + 1 < $wizardStep ? '✓' : $number + 1 }}</span>
                        <span class="olt-wizard-step-label">{{ $label }}<small>{{ $number + 1 < $wizardStep ? 'Concluído' : ($number + 1 === $wizardStep ? 'Etapa atual' : 'Pendente') }}</small></span>
                    </button>
                </li>
            @endforeach
        </ol>
        <section class="olt-wizard-panel" data-wizard-panel="1" aria-label="Conta FTP" @if ($wizardStep !== 1) hidden @endif>
            <h3>Conta FTP</h3>
            @if ($wizard['account'])
                <p>Usuário atual: <strong>{{ $wizard['account']->username }}</strong>.</p>
                <p>Trocar usuário ou senha depois de configurar a OLT exigirá atualizar os dados na OLT.</p>
                @if (! $wizard['created'])
                <form method="POST" action="{{ route('devices.ftp-account.update', $device) }}">@csrf @method('PATCH')
                    <input type="hidden" name="is_active" value="1">
                    <button type="submit">Manter conta atual</button>
                </form>
                @else
                    <button type="button" id="keep-ftp-account">Manter conta atual</button>
                @endif
                <button type="button" id="replace-ftp-account">Substituir conta</button>
            @endif
            <form method="POST" action="{{ $wizard['account'] ? route('devices.ftp-account.replace', $device) : route('devices.ftp-account.store', $device) }}" data-account-form @if ($wizard['account'] && ! $errors->has('mode') && ! $errors->has('username') && ! $errors->has('password')) hidden @endif>
                @csrf
                <fieldset class="olt-account-mode"><legend>Como deseja criar a conta FTP?</legend>
                    <label><input type="radio" name="mode" value="automatic" @checked(old('mode', 'automatic') === 'automatic')> Gerar automaticamente</label>
                    <label><input type="radio" name="mode" value="manual" @checked(old('mode') === 'manual')> Definir manualmente</label>
                </fieldset>
                <p data-automatic-account>Usuário sugerido: <code>bmdev{{ $device->id }}</code>. Uma senha forte será gerada.</p>
                <div data-manual-account>
                    <div class="field"><label for="ftp_username">Usuário FTP</label><input id="ftp_username" name="username" value="{{ old('username') }}" minlength="3" maxlength="32" pattern="[a-z][a-z0-9_-]*" autocomplete="off">@error('username') <span class="field-error">{{ $message }}</span> @enderror</div>
                    <div class="field"><label for="ftp_password">Senha FTP</label><input id="ftp_password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password">@error('password') <span class="field-error">{{ $message }}</span> @enderror</div>
                    <div class="field"><label for="ftp_password_confirmation">Confirmar senha</label><input id="ftp_password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password"></div>
                </div>
                <button type="submit">{{ $wizard['account'] ? 'Confirmar substituição' : 'Criar conta FTP' }}</button>
            </form>
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="2" aria-label="Sincronização PureDB" @if ($wizardStep !== 2) hidden @endif>
            <h3>Sincronização PureDB</h3>
            @if ($ftpSecret ?? false)
                <div class="alert-warning"><strong>Senha FTP gerada.</strong> Copie agora e guarde em local seguro; ela não será exibida novamente.</div>
                <p><code>{{ $ftpSecret }}</code></p>
            @endif
            @if ($wizard['synced'])
                <p role="status">Concluído: conta sincronizada no PureDB.</p>
            @elseif ($wizard['state'] === 'sync_error')
                <div class="olt-wizard-error" role="alert">{{ $wizard['account']->sync_error }}</div>
                <form method="POST" action="{{ route('devices.ftp-account.retry', $device) }}">@csrf<button type="submit">Tentar novamente</button></form>
            @else
                <p role="status">Sincronizando conta no PureDB... Esta etapa será atualizada automaticamente.</p>
            @endif
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="3" aria-label="Servidor FTP" @if ($wizardStep !== 3) hidden @endif>
            <h3>Servidor FTP</h3>
            <p>IP ou hostname do servidor FTP que receberá os backups da OLT.</p>
            <form method="POST" action="{{ route('devices.olt-ftp.server', $device) }}">
                @csrf
                <div class="field"><label for="ftp_host">Host do servidor FTP</label>
                    <input id="ftp_host" name="ftp_host" type="text" value="{{ old('ftp_host', $wizard['host']) }}" required>
                    @error('ftp_host') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="field"><label for="ftp_passive_address">Endereço passivo do FTP</label>
                    <input id="ftp_passive_address" name="ftp_passive_address" type="text" value="{{ $wizard['passive_address'] }}" readonly>
                    <small>Definido por BACKUP_FTP_PASSIVE_ADDRESS no .env do Compose. Após alterar, recrie app e ftp.</small>
                    @error('ftp_passive_address') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="field"><label for="ftp_port">Porta</label>
                    <input id="ftp_port" name="ftp_port" type="number" value="21" readonly required>
                    @error('ftp_port') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <button type="submit">Salvar e validar</button>
            </form>
            @if ($wizard['server'])
                <p>Valores salvos: <strong>{{ $wizard['host'] }}</strong> · Endereço passivo: <strong>{{ $wizard['passive_address'] ?: 'Padrão do servidor' }}</strong> · Porta: <strong>{{ $wizard['port'] }}</strong>.</p>
            @endif
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="4" aria-label="Configuração da OLT" @if ($wizardStep !== 4) hidden @endif>
            @if ($wizard['synced'] && $wizard['server'])
            <h3>Configuração manual da OLT</h3>
            <p><strong>Servidor:</strong> {{ $wizard['host'] }} · <strong>Porta:</strong> {{ $wizard['port'] }} · <strong>Usuário:</strong> {{ $wizard['account']->username }}</p>
            <p><strong>Diretório remoto:</strong> <code>/</code></p>
            <ol><li>Acesse a OLT por um console confiável e execute <code>ftp set</code>.</li><li>Informe o usuário acima e a senha FTP guardada na criação da conta.</li><li>Execute <code>quit</code> e depois <code>save</code>.</li></ol>
            <p>Os prompts e a sequência exata podem variar conforme o firmware e o modelo. Confirme as respostas exibidas pela OLT.</p>
            @if (! $wizard['confirmed'])
            <form method="POST" action="{{ route('devices.olt-ftp.confirm', $device) }}">
                @csrf
                <label><input type="checkbox" name="olt_configured" value="1" required> Já configurei estes dados na OLT</label>
                <button type="submit">Avançar para teste</button>
            </form>
            @else
                <p>Configuração da OLT confirmada.</p>
            @endif
            @endif
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="5" aria-label="Teste de integração" @if ($wizardStep !== 5) hidden @endif>
            @if (! $wizard['confirmed'])
                <p>Confirme primeiro a configuração da OLT.</p>
            @elseif (! $wizard['execution'])
                <p>Inicie o teste para gerar um nome de arquivo único. O sistema aguardará o envio manual da OLT.</p>
                <form method="POST" action="{{ route('devices.olt-ftp.test', $device) }}">@csrf<button type="submit">Iniciar teste de integração</button></form>
            @elseif (in_array($wizard['execution']->status, ['failed', 'cancelled', 'succeeded'], true) && ! $wizard['operational'])
                <div class="olt-wizard-error" role="alert"><strong>Teste não concluído.</strong> {{ $wizard['execution']->error_message ?: 'O arquivo não foi recebido e validado nesta tentativa.' }}</div>
                <form method="POST" action="{{ route('devices.olt-ftp.test', $device) }}">@csrf<button type="submit">Tentar novamente</button></form>
            @else
                <h3>Teste de integração</h3>
                <p><strong>Execução de teste #{{ $wizard['execution']->id }}</strong></p>
                <p><strong>Arquivo esperado:</strong> <code>bm-exec-{{ $wizard['execution']->id }}.cfg</code></p>
                <p>Execute manualmente na OLT: <code id="olt-test-command">backup configuration ftp {{ $wizard['host'] }} bm-exec-{{ $wizard['execution']->id }}.cfg</code></p>
                <button type="button" id="copy-olt-test-command" class="secondary-button">Copiar comando</button>
                <p>O usuário e a senha FTP já foram configurados anteriormente com <code>ftp set</code>.</p>
                <p>Os comandos podem variar conforme o firmware e o modelo da OLT.</p>
                <p><strong>Cada nova tentativa gera um novo número de execução e um novo nome de arquivo. Use sempre o comando exibido nesta tentativa.</strong></p>
                @unless ($wizard['operational'])
                    <p role="status">Após executar o comando na OLT, aguarde. Esta tela será atualizada automaticamente quando o arquivo for recebido e validado.{{ in_array($wizard['execution']->status, ['pending', 'queued'], true) ? ' (execução na fila)' : '' }}</p>
                @endunless
            @endif
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="6" aria-label="Integração validada" @if ($wizardStep !== 6) hidden @endif>
            @if ($wizard['operational'])
                <h3>Integração validada</h3>
                <div class="alert-success"><strong>Integração operacional.</strong> O arquivo foi recebido e validado.</div>
                <p><strong>Última validação:</strong> {{ app(\App\Services\InstanceTimezone::class)->format($wizard['execution']->artifact->validated_at, 'd/m/Y H:i') }}. Teste #{{ $wizard['execution']->id }} concluído. <a href="{{ route('backup-executions.show', $wizard['execution']) }}">Ver execução</a></p>
                <button type="button" id="finish-olt-wizard">Concluir</button>
            @endif
        </section>
        @error('wizard') <p class="olt-wizard-error" role="alert">{{ $message }}</p> @enderror
        @error('olt_configured') <p class="olt-wizard-error" role="alert">Marque a confirmação após configurar a OLT.</p> @enderror
        <div class="olt-wizard-navigation">
            <button type="button" id="back-olt-wizard" class="secondary-button">Voltar</button>
            <button type="button" id="next-olt-wizard" @if ($wizardStep >= 4) hidden @endif>Avançar</button>
        </div>
    </dialog>
    <script>
        (() => {
            const dialog = document.getElementById('olt-wizard');
            const currentStep = Number(dialog.dataset.currentStep);
            let selectedStep = {{ $errors->has('mode') || $errors->has('username') || $errors->has('password') ? '1' : 'currentStep' }};
            const steps = [...dialog.querySelectorAll('[data-wizard-step]')];
            const panels = [...dialog.querySelectorAll('[data-wizard-panel]')];
            const back = document.getElementById('back-olt-wizard');
            const next = document.getElementById('next-olt-wizard');
            const count = document.getElementById('olt-wizard-count');
            const progress = dialog.querySelector('.olt-wizard-progress');
            const progressFill = document.getElementById('olt-wizard-progress-fill');
            const showStep = (step) => {
                if (step < 1 || step > currentStep) return;
                selectedStep = step;
                count.textContent = `Etapa ${currentStep} de 6`;
                progress.setAttribute('aria-valuenow', currentStep);
                progressFill.style.width = `${currentStep / 6 * 100}%`;
                steps.forEach((button) => {
                    const number = Number(button.dataset.wizardStep);
                    const active = number === currentStep;
                    button.parentElement.classList.toggle('is-current', active);
                    button.parentElement.classList.toggle('is-complete', number < currentStep);
                    button.parentElement.classList.toggle('is-pending', number > currentStep);
                    button.querySelector('.olt-wizard-step-marker').textContent = number < currentStep ? '✓' : number;
                    button.querySelector('small').textContent = active ? 'Etapa atual' : (number < currentStep ? 'Concluído' : 'Pendente');
                    if (active) button.setAttribute('aria-current', 'step');
                    else button.removeAttribute('aria-current');
                });
                panels.forEach((panel) => panel.hidden = Number(panel.dataset.wizardPanel) !== step);
                back.disabled = step === 1;
                next.hidden = step >= currentStep || step >= 5;
            };
            steps.forEach((button) => button.addEventListener('click', () => showStep(Number(button.dataset.wizardStep))));
            back.addEventListener('click', () => showStep(selectedStep - 1));
            next.addEventListener('click', () => showStep(selectedStep + 1));
            document.getElementById('open-olt-wizard').addEventListener('click', () => { showStep(currentStep); dialog.showModal(); poll(); });
            document.getElementById('copy-olt-test-command')?.addEventListener('click', async () => {
                const command = document.getElementById('olt-test-command').textContent.trim();
                try { await navigator.clipboard.writeText(command); } catch (_) { return; }
            });
            document.getElementById('close-olt-wizard').addEventListener('click', () => dialog.close());
            document.getElementById('finish-olt-wizard')?.addEventListener('click', () => dialog.close());
            showStep(selectedStep);
            if (new URLSearchParams(location.search).has('olt_wizard') || {{ ($ftpSecret ?? false) || $errors->has('wizard') || $errors->has('olt_configured') || $errors->has('ftp_host') || $errors->has('ftp_passive_address') || $errors->has('ftp_port') || $errors->has('username') || $errors->has('password') ? 'true' : 'false' }}) dialog.showModal();
            const modeInputs = [...dialog.querySelectorAll('input[name="mode"]')];
            const manual = dialog.querySelector('[data-manual-account]');
            const automatic = dialog.querySelector('[data-automatic-account]');
            const updateMode = () => {
                if (! manual) return;
                const isManual = modeInputs.find((input) => input.checked)?.value === 'manual';
                manual.hidden = ! isManual;
                automatic.hidden = isManual;
                manual.querySelectorAll('input').forEach((input) => input.disabled = ! isManual);
            };
            modeInputs.forEach((input) => input.addEventListener('change', updateMode));
            updateMode();
            document.getElementById('keep-ftp-account')?.addEventListener('click', () => showStep(2));
            document.getElementById('replace-ftp-account')?.addEventListener('click', () => {
                dialog.querySelector('[data-account-form]').hidden = false;
            });
            const current = [dialog.dataset.state, dialog.dataset.currentStep, dialog.dataset.executionId, dialog.dataset.executionStatus].join(':');
            const poll = async () => {
                if (! dialog.open || {{ ($ftpSecret ?? false) ? 'true' : 'false' }}) return;
                try {
                    const response = await fetch(@json(route('devices.olt-ftp.status', $device)), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
                    if (! response.ok) return;
                    const status = await response.json();
                    if ([status.state, status.current_step, status.execution_id || '', status.execution_status || ''].join(':') !== current) {
                        location.replace(@json(route('devices.edit', [$device, 'olt_wizard' => 1])));
                    }
                } catch (_) { /* Keep waiting through temporary connection errors. */ }
            };
            setInterval(poll, 3000);
            if (dialog.open) poll();
        })();
    </script>
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
