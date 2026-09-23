@csrf
@isset($backupPolicy) @method('PUT') @endisset
<div class="form-grid">
    <div class="field form-span-2">
        <label for="name">Nome *</label>
        <input id="name" name="name" type="text" maxlength="255" value="{{ old('name', $backupPolicy->name ?? '') }}" required autofocus>
        @error('name') <span class="field-error">{{ $message }}</span> @enderror
    </div>
    <div class="field"><label for="method">Método *</label><select id="method" name="method" required>
        <option value="ssh_pull" @selected(old('method', $backupPolicy->method ?? 'ssh_pull') === 'ssh_pull')>SSH Pull</option>
        <option value="ftp_push" @selected(old('method', $backupPolicy->method ?? '') === 'ftp_push')>FTP Push</option>
    </select>@error('method') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field"><label for="artifact_mode">Artefato *</label><select id="artifact_mode" name="artifact_mode" required>
        <option value="config" @selected(old('artifact_mode', $backupPolicy->artifact_mode ?? 'config') === 'config')>Config</option>
        <option value="binary" @selected(old('artifact_mode', $backupPolicy->artifact_mode ?? '') === 'binary')>Binário</option>
        <option value="both" @selected(old('artifact_mode', $backupPolicy->artifact_mode ?? '') === 'both')>Ambos</option>
    </select>@error('artifact_mode') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field"><label for="schedule_type">Agendamento *</label><select id="schedule_type" name="schedule_type" required>
        <option value="manual" @selected(old('schedule_type', $backupPolicy->schedule_type ?? 'manual') === 'manual')>Manual</option>
        <option value="daily" @selected(old('schedule_type', $backupPolicy->schedule_type ?? '') === 'daily')>Diário</option>
        <option value="weekly" @selected(old('schedule_type', $backupPolicy->schedule_type ?? '') === 'weekly')>Semanal</option>
    </select>@error('schedule_type') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div id="ftp-manual-note" class="field form-span-2" @if(old('method', $backupPolicy->method ?? 'ssh_pull') !== 'ftp_push') hidden @endif><small>Nesta fase, Huawei OLT via FTP Push suporta somente execução manual.</small></div>
    <div class="field"><label for="schedule_time">Horário (diário/semanal)</label><input id="schedule_time" name="schedule_time" type="time" value="{{ old('schedule_time', isset($backupPolicy) ? substr($backupPolicy->schedule_time ?? '', 0, 5) : '') }}">@error('schedule_time') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field"><label for="schedule_weekday">Dia da semana (semanal)</label><select id="schedule_weekday" name="schedule_weekday">
        <option value="">Selecione...</option>
        @foreach(\App\Models\BackupPolicy::WEEKDAYS as $day => $label)
            <option value="{{ $day }}" @selected((string) old('schedule_weekday', $backupPolicy->schedule_weekday ?? '') === (string) $day)>{{ $label }}</option>
        @endforeach
    </select>@error('schedule_weekday') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field"><label for="retention_days">Retenção em dias</label><input id="retention_days" name="retention_days" type="number" min="1" value="{{ old('retention_days', $backupPolicy->retention_days ?? '') }}">@error('retention_days') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field"><label for="retention_count">Retenção em quantidade</label><input id="retention_count" name="retention_count" type="number" min="1" value="{{ old('retention_count', $backupPolicy->retention_count ?? '') }}">@error('retention_count') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field form-span-2"><small>Informe ao menos um critério. Artefatos fora do prazo ou da quantidade podem ser removidos; o último backup válido de cada associação é protegido. A limpeza automática depende da configuração da instância.</small></div>
    <div class="field form-span-2"><label for="notes">Observações</label><textarea id="notes" name="notes" rows="4" maxlength="2000">{{ old('notes', $backupPolicy->notes ?? '') }}</textarea>@error('notes') <span class="field-error">{{ $message }}</span> @enderror</div>
    <div class="field form-span-2"><input type="hidden" name="is_active" value="0"><label class="switch-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $backupPolicy->is_active ?? true))><span><strong>Política ativa</strong><small>Políticas inativas permanecem cadastradas.</small></span></label></div>
</div>
<div class="form-actions"><a href="{{ route('backup-policies.index') }}" class="secondary-button">Voltar</a><button type="submit" class="primary-button inline-button">{{ isset($backupPolicy) ? 'Salvar alterações' : 'Cadastrar política' }}</button></div>
<script>
document.getElementById('method').addEventListener('change', function () {
    document.getElementById('ftp-manual-note').hidden = this.value !== 'ftp_push';
});
</script>
