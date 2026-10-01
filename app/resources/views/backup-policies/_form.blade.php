@csrf
@isset($backupPolicy) @method('PUT') @endisset
@if($editModal ?? false)<input type="hidden" name="return_page" value="{{ request('page') }}">@endif
@if(($createModal ?? false) || ($editModal ?? false))
    <div class="modal__body form-create-modal__body">
@endif

<div class="form-create-grid">
    @unless(isset($backupPolicy))
    <div class="form-field form-span-2">
        <label class="form-label" for="policy_preset">Modelo pré-definido</label>
        <select class="form-control" id="policy_preset" aria-describedby="policy-preset-help">
            <option value="ssh_daily" @selected(old('name') === null)>Configuração via SSH · diário às 03:00 · 30 dias</option>
            <option value="ssh_weekly">Configuração via SSH · domingo às 03:00 · 90 dias</option>
            <option value="ftp_manual">Configuração via FTP · envio pelo equipamento · 90 dias</option>
            <option value="custom" @selected(old('name') !== null)>Personalizada</option>
        </select>
        <small id="policy-preset-help">Escolha um modelo para preencher os campos. Revise os valores antes de cadastrar.</small>
    </div>
    @endunless
    <div class="form-field form-span-2">
        <label class="form-label" for="name">Nome *</label>
        <input class="form-control" id="name" name="name" type="text" maxlength="255" value="{{ old('name', $backupPolicy->name ?? 'Configuração via SSH · diário') }}" required autofocus>
        @error('name') <span class="form-error">{{ $message }}</span> @enderror
    </div>
    <div class="form-field"><label class="form-label" for="method">Método *</label><select class="form-control" id="method" name="method" required>
        <option value="ssh_pull" @selected(old('method', $backupPolicy->method ?? 'ssh_pull') === 'ssh_pull')>Coleta via SSH</option>
        <option value="ftp_push" @selected(old('method', $backupPolicy->method ?? '') === 'ftp_push')>Envio via FTP</option>
    </select>@error('method') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field"><label class="form-label" for="artifact_mode">Artefato *</label><select class="form-control" id="artifact_mode" name="artifact_mode" required>
        <option value="config" @selected(old('artifact_mode', $backupPolicy->artifact_mode ?? 'config') === 'config')>Configuração</option>
        <option value="binary" @selected(old('artifact_mode', $backupPolicy->artifact_mode ?? '') === 'binary')>Binário</option>
        <option value="both" @selected(old('artifact_mode', $backupPolicy->artifact_mode ?? '') === 'both')>Ambos</option>
    </select>@error('artifact_mode') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field"><label class="form-label" for="schedule_type">Como o backup começa *</label><select class="form-control" id="schedule_type" name="schedule_type" required>
        <option value="manual" @selected(old('schedule_type', $backupPolicy->schedule_type ?? '') === 'manual')>{{ old('method', $backupPolicy->method ?? 'ssh_pull') === 'ftp_push' ? 'Envio pelo equipamento' : 'Manual' }}</option>
        <option value="daily" @selected(old('schedule_type', $backupPolicy->schedule_type ?? 'daily') === 'daily')>Diário</option>
        <option value="weekly" @selected(old('schedule_type', $backupPolicy->schedule_type ?? '') === 'weekly')>Semanal</option>
    </select>@error('schedule_type') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div id="ftp-manual-note" class="form-field form-span-2" @if(old('method', $backupPolicy->method ?? 'ssh_pull') !== 'ftp_push') hidden @endif><small>Para receber backup FTP todos os dias, configure o envio automático em cada equipamento Huawei. O Backup Manager recebe os arquivos; esta política não agenda o envio. O teste da OLT continua manual.</small></div>
    <div id="daily-schedule-note" class="form-field form-span-2" @if(old('schedule_type', $backupPolicy->schedule_type ?? 'daily') !== 'daily') hidden @endif><small>Diário: o backup é programado todos os dias, de domingo a sábado, no horário escolhido.</small></div>
    <div class="form-field"><label class="form-label" for="schedule_time">Horário (diário/semanal)</label><input class="form-control" id="schedule_time" name="schedule_time" type="time" value="{{ old('schedule_time', isset($backupPolicy) ? substr($backupPolicy->schedule_time ?? '', 0, 5) : '03:00') }}">@error('schedule_time') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field"><label class="form-label" for="schedule_weekday">Dia da semana (somente semanal)</label><select class="form-control" id="schedule_weekday" name="schedule_weekday">
        <option value="">Selecione...</option>
        @foreach(\App\Models\BackupPolicy::WEEKDAYS as $day => $label)
            <option value="{{ $day }}" @selected((string) old('schedule_weekday', $backupPolicy->schedule_weekday ?? '') === (string) $day)>{{ $label }}</option>
        @endforeach
    </select>@error('schedule_weekday') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field"><label class="form-label" for="retention_days">Retenção em dias</label><input class="form-control" id="retention_days" name="retention_days" type="number" min="1" value="{{ old('retention_days', $backupPolicy->retention_days ?? 30) }}"><small class="form-help">Sugestão para backup diário: 30 dias.</small>@error('retention_days') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field"><label class="form-label" for="retention_count">Retenção em quantidade</label><input class="form-control" id="retention_count" name="retention_count" type="number" min="1" placeholder="Opcional" value="{{ old('retention_count', $backupPolicy->retention_count ?? '') }}"><small class="form-help">Deixe vazio se usar apenas o prazo em dias.</small>@error('retention_count') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field form-span-2"><small class="form-help">Preencha ao menos um campo. A ativação da limpeza fica em Configurações → Retenção de backups.</small></div>
    <div class="form-field form-span-2"><label class="form-label" for="notes">Observações</label><textarea class="form-control" id="notes" name="notes" rows="4" maxlength="2000">{{ old('notes', $backupPolicy->notes ?? '') }}</textarea>@error('notes') <span class="form-error">{{ $message }}</span> @enderror</div>
    <div class="form-field form-span-2"><input type="hidden" name="is_active" value="0"><label class="switch-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $backupPolicy->is_active ?? true))><span><strong>Política ativa</strong><small>Permite usar esta política e executar seus backups agendados.</small></span></label></div>
</div>

@if(($createModal ?? false) || ($editModal ?? false))
    </div>
@endif

<div class="form-actions {{ (($createModal ?? false) || ($editModal ?? false)) ? 'modal__footer form-create-modal__footer' : '' }}">
    @if($createModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-policy-create>Cancelar</button>
    @elseif($editModal ?? false)
        <button type="button" class="btn btn--ghost" data-close-policy-edit>Cancelar</button>
    @else
        <a href="{{ route('backup-policies.index') }}" class="btn btn--ghost">Voltar</a>
    @endif
    <button type="submit" class="btn btn--primary">{{ isset($backupPolicy) ? 'Salvar alterações' : 'Cadastrar política' }}</button>
</div>
<script>
function syncPolicyScheduleFields() {
    const schedule = document.getElementById('schedule_type').value;
    const time = document.getElementById('schedule_time');
    const weekday = document.getElementById('schedule_weekday');
    time.closest('.form-field').hidden = schedule === 'manual';
    time.disabled = schedule === 'manual';
    weekday.closest('.form-field').hidden = schedule !== 'weekly';
    weekday.disabled = schedule !== 'weekly';
    document.getElementById('daily-schedule-note').hidden = schedule !== 'daily';
}
function syncPolicyMethodFields() {
    const ftp = document.getElementById('method').value === 'ftp_push';
    const schedule = document.getElementById('schedule_type');
    schedule.querySelector('[value="manual"]').textContent = ftp ? 'Envio pelo equipamento' : 'Manual';
    for (const value of ['daily', 'weekly']) {
        const option = schedule.querySelector(`[value="${value}"]`);
        option.hidden = ftp;
        option.disabled = ftp;
    }
    if (ftp) schedule.value = 'manual';
    document.getElementById('ftp-manual-note').hidden = !ftp;
    syncPolicyScheduleFields();
}
document.getElementById('method').addEventListener('change', syncPolicyMethodFields);
document.getElementById('schedule_type').addEventListener('change', syncPolicyScheduleFields);
syncPolicyMethodFields();
@unless(isset($backupPolicy))
document.getElementById('policy_preset').addEventListener('change', function () {
    const presets = {
        ssh_daily: { name: 'Configuração via SSH · diário', method: 'ssh_pull', artifact_mode: 'config', schedule_type: 'daily', schedule_time: '03:00', schedule_weekday: '', retention_days: '30', retention_count: '' },
        ssh_weekly: { name: 'Configuração via SSH · semanal', method: 'ssh_pull', artifact_mode: 'config', schedule_type: 'weekly', schedule_time: '03:00', schedule_weekday: '7', retention_days: '90', retention_count: '' },
        ftp_manual: { name: 'Configuração via FTP · envio pelo equipamento', method: 'ftp_push', artifact_mode: 'config', schedule_type: 'manual', schedule_time: '', schedule_weekday: '', retention_days: '90', retention_count: '' },
    };
    const preset = presets[this.value];
    if (!preset) return;
    for (const [field, value] of Object.entries(preset)) {
        document.getElementById(field).value = value;
    }
    syncPolicyMethodFields();
});
for (const field of ['name', 'method', 'artifact_mode', 'schedule_type', 'schedule_time', 'schedule_weekday', 'retention_days', 'retention_count']) {
    document.getElementById(field).addEventListener('input', () => {
        document.getElementById('policy_preset').value = 'custom';
    });
}
@endunless
</script>
