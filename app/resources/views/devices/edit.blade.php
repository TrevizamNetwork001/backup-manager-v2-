@extends('layouts.app')

@section('title', 'Editar Equipamento — Backup Manager')
@section('page-title', 'Editar Equipamento')
@section('page-description', 'Atualize as informações do equipamento.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content"><h1 class="page-header__title">Editar equipamento</h1><p class="page-header__description">Atualize as informações do equipamento.</p></div>
    <div class="page-header__actions"><a href="{{ route('devices.index') }}" class="btn btn--ghost">Voltar à lista</a></div>
</header>
@endsection
@section('content')

<div class="modern-form-page stack">
    @php
        $isHuaweiFtp = $device->isHuaweiFtpEligible();
        $isOlt = $device->platform === 'olt';
        $isVsol = mb_strtolower(trim($device->vendor)) === 'vsol';
        $deviceFormErrors = $errors->hasAny(['site_id', 'name', 'hostname', 'management_ip', 'vendor', 'platform', 'device_kind', 'device_function', 'model', 'os_version', 'notes', 'is_active']);
        $autoOpenDeviceEdit = $deviceFormErrors || (! $errors->any() && ! request()->boolean('olt_wizard') && ! session('success'));
    @endphp

    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    <article class="card">

        <div class="card__header">
            <div>
                <h2 class="card__title">{{ $device->name }}</h2>
                <p class="card__description">{{ $device->management_ip }}</p>
            </div>
        </div>

        <div class="card__body"><button type="button" class="btn btn--secondary" data-open-device-edit>Editar dados do equipamento</button></div>

    </article>

    <dialog class="modal form-create-modal" id="device-edit-dialog" aria-labelledby="device-edit-title">
        <div class="modal__surface">
            <div class="modal__header">
                <div><h2 class="modal__title" id="device-edit-title">Editar equipamento</h2><p class="modal__description">Atualize a identificação, o acesso e os dados operacionais.</p></div>
                <a href="{{ route('devices.index') }}" class="modal__close" data-close-device-edit aria-label="Fechar"><x-icon name="close" /></a>
            </div>
            <form method="POST" action="{{ route('devices.update', $device) }}">
                @include('devices._form', ['editModal' => true])
            </form>
        </div>
    </dialog>
    <script>
    (() => {
        const dialog = document.getElementById('device-edit-dialog');
        document.querySelectorAll('[data-open-device-edit]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
        const returnToList = () => location.replace(@json(route('devices.index')));
        dialog.querySelectorAll('[data-close-device-edit]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); returnToList(); }));
        dialog.addEventListener('click', event => { if (event.target === dialog) returnToList(); });
        dialog.addEventListener('cancel', event => { event.preventDefault(); returnToList(); });
        @if($autoOpenDeviceEdit) dialog.showModal(); @endif
    })();
    </script>

    @if ($isHuaweiFtp)
    @php
        $wizard = app(\App\Services\OltFtpWizard::class)->snapshot($device);
        $vendorLabel = $isVsol ? 'VSOL' : 'Huawei';
        // "a OLT" (feminine) vs. "o equipamento" (masculine) — same gender
        // agreement the pre-existing OLT-only strings already used.
        $equipmentName = $isOlt ? 'OLT' : 'equipamento';
        $aOrO = $isOlt ? 'a' : 'o';
        $naOrNo = $isOlt ? 'na' : 'no';
        $daOrDo = $isOlt ? 'da' : 'do';
    @endphp
    <article class="card">
        <div class="card__header"><div><h2 class="card__title">Integração {{ $vendorLabel }} {{ $isOlt ? 'OLT' : 'Rede' }} / FTP</h2><p class="card__description">Estado: {{ $wizard['operational'] ? 'Operacional' : 'Configuração pendente' }}</p></div></div>
        <div class="card__body"><button type="button" class="btn btn--secondary" id="open-olt-wizard">Abrir configuração guiada</button></div>
    </article>
    <dialog id="olt-wizard" class="olt-wizard device-ftp-wizard" aria-labelledby="olt-wizard-title" data-state="{{ $wizard['state'] }}" data-current-step="{{ $wizard['current_step'] }}" data-execution-id="{{ $wizard['execution']?->id }}" data-execution-status="{{ $wizard['execution']?->status }}">
        @php
            $wizardStep = $wizard['current_step'];
        @endphp
        <div class="olt-wizard-header">
            <div>
                <h2 id="olt-wizard-title">Configuração {{ $vendorLabel }} {{ $isOlt ? 'OLT' : 'Rede' }} / FTP</h2>
                <p id="olt-wizard-count">Etapa {{ $wizardStep }} de 6</p>
            </div>
            <button type="button" id="close-olt-wizard" class="modal__close" aria-label="Fechar configuração"><x-icon name="close" /></button>
        </div>
        <div class="olt-wizard-body">
        <div class="olt-wizard-progress" role="progressbar" aria-label="Progresso da configuração" aria-valuemin="1" aria-valuemax="6" aria-valuenow="{{ $wizardStep }}">
            <span id="olt-wizard-progress-fill" style="width: {{ $wizardStep / 6 * 100 }}%"></span>
        </div>
        <ol class="olt-wizard-steps" aria-label="Etapas da configuração">
            @foreach (['Conta FTP', 'Sincronização PureDB', 'Servidor FTP', $isOlt ? 'Configuração da OLT' : 'Configuração do equipamento', 'Teste de integração', 'Integração validada'] as $number => $label)
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
                <p>Trocar usuário ou senha depois de configurar {{ $aOrO }} {{ $equipmentName }} exigirá atualizar os dados {{ $naOrNo }} {{ $equipmentName }}.</p>
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
                <div class="field"><label for="ftp_username">Usuário FTP</label><input id="ftp_username" name="username" value="{{ old('username') }}" minlength="3" maxlength="32" pattern="[a-z][a-z0-9_-]*" autocomplete="off" required>@error('username') <span class="field-error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="ftp_password">Senha FTP</label><input id="ftp_password" name="password" type="password" minlength="12" maxlength="40" autocomplete="new-password" required><button type="button" data-generate-olt-password>Gerar</button><button type="button" data-toggle-password="ftp_password">Mostrar</button>@error('password') <span class="field-error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="ftp_password_confirmation">Confirmar senha</label><input id="ftp_password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="40" autocomplete="new-password" required><button type="button" data-toggle-password="ftp_password_confirmation">Mostrar</button></div>
                <button type="submit">{{ $wizard['account'] ? 'Confirmar substituição' : 'Criar conta FTP' }}</button>
            </form>
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="2" aria-label="Sincronização PureDB" @if ($wizardStep !== 2) hidden @endif>
            <h3>Sincronização PureDB</h3>
            @if ($ftpSecret ?? false)
                <div class="alert-warning"><strong>Senha FTP.</strong> Copie agora e guarde em local seguro; ela não será exibida novamente.</div>
                <p><code>{{ $ftpSecret }}</code></p>
            @endif
            @if ($wizard['synced'])
                <p role="status">Concluído: conta sincronizada no PureDB.</p>
            @elseif ($wizard['state'] === 'sync_error')
                <div class="olt-wizard-error" role="alert">{{ $wizard['account']->sync_error }}</div>
                <form method="POST" action="{{ route('devices.ftp-account.retry', $device) }}">@csrf<button type="submit">Tentar novamente</button></form>
            @else
                <p role="status" class="wizard-syncing"><span class="wizard-spinner" aria-hidden="true"></span> Sincronizando conta no PureDB... Esta etapa será atualizada automaticamente.</p>
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
        <section class="olt-wizard-panel" data-wizard-panel="4" aria-label="{{ $isOlt ? 'Configuração da OLT' : 'Configuração do equipamento' }}" @if ($wizardStep !== 4) hidden @endif>
            @if ($wizard['synced'] && $wizard['server'])
            @if ($isVsol)
            <h3>Configuração manual da OLT VSOL</h3>
            <p><strong>Servidor:</strong> {{ $wizard['host'] }} · <strong>Porta:</strong> {{ $wizard['port'] }} · <strong>Usuário FTP:</strong> {{ $wizard['account']->username }}</p>
            <p>Acesse a OLT por um console SSH confiável usando as credenciais de gerência do equipamento (não a conta FTP acima). O comando exato de envio, já com o nome de arquivo correto, aparece no próximo passo.</p>
            <p><strong>Atenção:</strong> nesse equipamento a senha FTP fica embutida na própria linha de comando (formato <code>ftp://usuário:senha@host/arquivo</code>) — evite rodar isso com a tela compartilhada/gravada, e limpe o histórico do shell depois se possível.</p>
            @elseif ($isOlt)
            <h3>Configuração manual da OLT</h3>
            <p><strong>Servidor:</strong> {{ $wizard['host'] }} · <strong>Porta:</strong> {{ $wizard['port'] }} · <strong>Usuário:</strong> {{ $wizard['account']->username }}</p>
            <p><strong>Diretório remoto:</strong> <code>/</code></p>
            <ol><li>Acesse a OLT por um console confiável e execute <code>ftp set</code>.</li><li>Informe o usuário acima e a senha FTP guardada na criação da conta.</li><li>Execute <code>quit</code> e depois <code>save</code>.</li></ol>
            <p>Os prompts e a sequência exata podem variar conforme o firmware e o modelo. Confirme as respostas exibidas pela OLT.</p>
            @else
            <h3>Configuração manual do equipamento (VRP)</h3>
            <p><strong>Servidor:</strong> {{ $wizard['host'] }} · <strong>Porta:</strong> {{ $wizard['port'] }} · <strong>Usuário:</strong> {{ $wizard['account']->username }}</p>
            <p>Acesse o equipamento por um console confiável e execute:</p>
            <pre class="vrp-commands"><code>system-view
set save-configuration backup-to-server server {{ $wizard['host'] }} transport-type ftp user {{ $wizard['account']->username }} password &lt;senha FTP guardada na criação da conta&gt;
set save-configuration interval 30</code></pre>
            <p><strong>Não use a opção <code>path</code> desse comando.</strong> A conta FTP já é isolada na própria pasta do equipamento; se o comando enviar o arquivo para um subdiretório (via <code>path</code>), o sistema não vai enxergá-lo — só é observada a raiz da conta.</p>
            <p>No NE8000, <code>interval</code> aceita de <strong>30 a 43200 minutos</strong> (não dá pra usar um valor menor só pra testar mais rápido); o comando também aceita <code>delay &lt;minutos&gt;</code> junto do <code>interval</code>. Depois de configurar, pode ser necessário rodar <code>commit</code> para aplicar (equipamentos com configuração por candidato mostram <code>[*...]</code> até o commit e <code>[~...]</code> depois). A sintaxe exata varia conforme o modelo/firmware — confirme os comandos aceitos pelo seu equipamento antes de prosseguir.</p>
            @endif
            @if (! $wizard['confirmed'])
            <form method="POST" action="{{ route('devices.olt-ftp.confirm', $device) }}">
                @csrf
                <label><input type="checkbox" name="olt_configured" value="1" required> Já configurei estes dados {{ $naOrNo }} {{ $equipmentName }}</label>
                <button type="submit">Avançar para teste</button>
            </form>
            @else
                <p>Configuração {{ $daOrDo }} {{ $equipmentName }} confirmada.</p>
            @endif
            @endif
        </section>
        <section class="olt-wizard-panel" data-wizard-panel="5" aria-label="Teste de integração" @if ($wizardStep !== 5) hidden @endif>
            @if (! $wizard['confirmed'])
                <p>Confirme primeiro a configuração {{ $daOrDo }} {{ $equipmentName }}.</p>
            @elseif (! $isOlt && ! $wizard['execution'])
                <p role="status">Aguardando o próximo envio automático do equipamento (respeite o intervalo configurado no comando <code>set save-configuration interval</code>). Esta tela será atualizada automaticamente quando um arquivo for recebido e validado.</p>
            @elseif (! $wizard['execution'])
                <p>Inicie o teste para gerar um nome de arquivo único. O sistema aguardará o envio manual da OLT.</p>
                <form method="POST" action="{{ route('devices.olt-ftp.test', $device) }}">@csrf<button type="submit">Iniciar teste de integração</button></form>
            @elseif (! $isOlt && in_array($wizard['execution']->status, ['failed', 'cancelled'], true) && ! $wizard['operational'])
                <div class="olt-wizard-error" role="alert"><strong>O último envio recebido não pôde ser validado.</strong> {{ $wizard['execution']->error_message ?: 'O arquivo não pôde ser armazenado ou validado.' }} Aguardando o próximo envio automático.</div>
            @elseif ($isOlt && in_array($wizard['execution']->status, ['failed', 'cancelled', 'succeeded'], true) && ! $wizard['operational'])
                <div class="olt-wizard-error" role="alert"><strong>Teste não concluído.</strong> {{ $wizard['execution']->error_message ?: 'O arquivo não foi recebido e validado nesta tentativa.' }}</div>
                <form method="POST" action="{{ route('devices.olt-ftp.test', $device) }}">@csrf<button type="submit">Tentar novamente</button></form>
            @elseif (! $isOlt)
                <h3>Teste de integração</h3>
                <p><strong>Último envio recebido:</strong> execução #{{ $wizard['execution']->id }}, status <strong>{{ $wizard['execution']->status }}</strong>.</p>
                @unless ($wizard['operational'])
                    <p role="status">Aguardando a validação deste envio. Esta tela será atualizada automaticamente.</p>
                @endunless
            @elseif ($isVsol)
                @php
                    $vsolAuthority = $wizard['port'] == 21 ? $wizard['host'] : $wizard['host'].':'.$wizard['port'];
                    $vsolFilename = 'bm-exec-'.$wizard['execution']->id.'.cfg';
                    // Built as one string in PHP, not interpolated inline in the
                    // template: a literal "@" placed directly before "{{" is
                    // Blade's own escape syntax ("@{{ ... }}" means "print this
                    // literally, don't evaluate it") and would swallow the
                    // variable instead of rendering the host.
                    $vsolCommand = 'copy startup-config ftp://'.$wizard['account']->username.':<senha FTP>@'.$vsolAuthority.'/'.$vsolFilename;
                @endphp
                <h3>Teste de integração</h3>
                <p><strong>Execução de teste #{{ $wizard['execution']->id }}</strong></p>
                <p><strong>Arquivo esperado:</strong> <code>{{ $vsolFilename }}</code></p>
                <p>Execute manualmente na OLT (console SSH da gerência, já autenticado):</p>
                <pre class="vrp-commands"><code id="olt-test-command">write
{{ $vsolCommand }}</code></pre>
                <button type="button" id="copy-olt-test-command" class="secondary-button">Copiar comando</button>
                <p><strong>Troque <code>&lt;senha FTP&gt;</code> pela senha guardada na criação da conta antes de rodar.</strong> Se o equipamento recusar o nome do arquivo, tente com a extensão <code>.config</code> em vez de <code>.cfg</code> — alguns firmwares VSOL exigem essa extensão especificamente.</p>
                <p>Os comandos podem variar conforme o firmware e o modelo da OLT.</p>
                <p><strong>Cada nova tentativa gera um novo número de execução e um novo nome de arquivo. Use sempre o comando exibido nesta tentativa.</strong></p>
                @unless ($wizard['operational'])
                    <p role="status">Após executar o comando na OLT, aguarde. Esta tela será atualizada automaticamente quando o arquivo for recebido e validado.{{ in_array($wizard['execution']->status, ['pending', 'queued'], true) ? ' (execução na fila)' : '' }}</p>
                @endunless
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
        </div>
        <div class="olt-wizard-navigation modal__footer form-create-modal__footer">
            <button type="button" id="back-olt-wizard" class="btn btn--ghost secondary-button">Voltar</button>
            <button type="button" id="next-olt-wizard" class="btn btn--primary" @if ($wizardStep >= 4) hidden @endif>Avançar</button>
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
            const returnToDevices = () => location.replace(@json(route('devices.index')));
            document.getElementById('close-olt-wizard').addEventListener('click', returnToDevices);
            document.getElementById('finish-olt-wizard')?.addEventListener('click', returnToDevices);
            dialog.addEventListener('cancel', event => { event.preventDefault(); returnToDevices(); });
            showStep(selectedStep);
            if (new URLSearchParams(location.search).has('olt_wizard') || {{ ($ftpSecret ?? false) || $errors->has('wizard') || $errors->has('olt_configured') || $errors->has('ftp_host') || $errors->has('ftp_passive_address') || $errors->has('ftp_port') || $errors->has('username') || $errors->has('password') ? 'true' : 'false' }}) dialog.showModal();
            dialog.querySelector('[data-generate-olt-password]')?.addEventListener('click', () => {
                // 16 chars, mixed categories: several Huawei VRP models only
                // accept a *plain-text* password up to 16 characters on
                // `set save-configuration backup-to-server` — a longer
                // string is treated as an already-encrypted blob and
                // rejected ("Wrong encrypted password"), homologated
                // against real switches. A pure-hex 32-char password (the
                // old generator) both overshoots that limit and lacks
                // symbols some devices also require.
                const pools = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghjkmnpqrstuvwxyz', '23456789', '!@#$%^*-_=+'];
                const all = pools.join('');
                const length = 16;
                const randomBytes = (count) => { const b = new Uint8Array(count); crypto.getRandomValues(b); return b; };
                const chars = Array.from(randomBytes(length), (b) => all[b % all.length]);
                const picks = randomBytes(pools.length);
                pools.forEach((pool, i) => { chars[i] = pool[picks[i] % pool.length]; });
                const shuffleBytes = randomBytes(length);
                for (let i = chars.length - 1; i > 0; i--) {
                    const j = shuffleBytes[i] % (i + 1);
                    [chars[i], chars[j]] = [chars[j], chars[i]];
                }
                const value = chars.join('');
                dialog.querySelector('#ftp_password').value = value;
                dialog.querySelector('#ftp_password_confirmation').value = value;
            });
            dialog.querySelectorAll('[data-toggle-password]').forEach((toggle) => {
                toggle.addEventListener('click', () => {
                    const input = document.getElementById(toggle.dataset.togglePassword);
                    const hidden = input.type === 'password';
                    input.type = hidden ? 'text' : 'password';
                    toggle.textContent = hidden ? 'Ocultar' : 'Mostrar';
                });
            });
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
    <style>
        .wizard-syncing { display: flex; align-items: center; gap: 8px; }
        .wizard-spinner {
            width: 14px;
            height: 14px;
            flex: none;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 999px;
            animation: btn-spin .8s linear infinite;
        }
        @media (prefers-reduced-motion: reduce) { .wizard-spinner { animation: none; } }
        .vrp-commands {
            white-space: pre-wrap;
            word-break: break-word;
            overflow-wrap: anywhere;
            font-family: ui-monospace, "SF Mono", "Cascadia Code", Consolas, "Roboto Mono", monospace;
            font-size: 13px;
            line-height: 1.6;
        }
        #olt-wizard section[data-wizard-panel="4"] p,
        #olt-wizard section[data-wizard-panel="4"] .vrp-commands {
            margin: 0 0 10px;
        }
    </style>
    @endif

</div>

@endsection
