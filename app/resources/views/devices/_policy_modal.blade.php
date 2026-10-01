<dialog class="modal form-create-modal device-policy-dialog" id="device-policy-{{ $device->id }}" aria-labelledby="device-policy-title-{{ $device->id }}">
    @php($visibleAssociations = $device->deviceBackupPolicies->filter(fn ($association) => $association->archived_at === null))
    @php($activePolicyCount = $visibleAssociations->filter(fn ($association) => $association->is_active && $association->backupPolicy?->is_active)->count())
    @php($hasActivePolicy = $activePolicyCount > 0)
    <div class="modal__surface">
        <div class="modal__header">
            <div>
                <h2 class="modal__title" id="device-policy-title-{{ $device->id }}">Política de backup</h2>
                <p class="modal__description">Gerencie as políticas de {{ $device->name }}.</p>
            </div>
            <button type="button" class="modal__close" data-close-device-policy aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <div class="modal__body form-create-modal__body device-policy-dialog__body">
            @if(session('policy_device_id') == $device->id && session('success'))
                <div class="alert alert--success" role="status">{{ session('success') }}</div>
            @endif
            @if(session('policy_device_id') == $device->id && session('warning'))
                <div class="alert alert--warning" role="alert">{{ session('warning') }}</div>
            @endif
            @if(old('policy_device_id') == $device->id && $errors->any())
                <div class="alert alert--warning" role="alert">{{ $errors->first() }}</div>
            @endif
            <div class="form-create-grid device-policy-dialog__device">
                <div class="form-field"><span class="form-label">Equipamento</span><div class="device-policy-dialog__readonly">{{ $device->name }}</div></div>
                <div class="form-field"><span class="form-label">IP de gerenciamento</span><div class="device-policy-dialog__readonly">{{ $device->management_ip }}</div></div>
            </div>
            <section class="device-policy-dialog__section" aria-label="Políticas vinculadas">
                <h3 class="device-policy-dialog__section-title">Políticas vinculadas</h3>
                @forelse($visibleAssociations as $association)
                    <div class="device-policy-dialog__association">
                        <div class="device-policy-dialog__association-main">
                            <div class="device-policy-dialog__association-info">
                                <strong>{{ $association->backupPolicy->name }}</strong>
                                <small>{{ $association->backupPolicy->method === 'ftp_push' ? 'Envio via FTP' : 'Coleta via SSH' }} · {{ $association->backupPolicy->method === 'ftp_push' ? 'Conta FTP do equipamento' : ($association->credential?->name ?? 'Credencial não definida') }}</small>
                            </div>
                            <span class="badge badge--{{ $association->is_active && $association->backupPolicy->is_active ? 'success' : 'neutral' }}">{{ ! $association->backupPolicy->is_active ? 'Política inativa' : ($association->is_active ? 'Ativa' : 'Inativa') }}</span>
                        </div>
                        @if($association->backup_executions_count > 0)
                            <small class="device-policy-dialog__history">{{ $association->backup_executions_count }} {{ $association->backup_executions_count === 1 ? 'execução registrada' : 'execuções registradas' }}</small>
                        @endif
                        <div class="device-policy-dialog__actions">
                            <form method="POST" action="{{ route('backup-policies.associations.update', [$association->backupPolicy, $association]) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="return_to" value="devices"><input type="hidden" name="page" value="{{ $devices->currentPage() }}"><input type="hidden" name="policy_device_id" value="{{ $device->id }}">
                                <input type="hidden" name="is_active" value="{{ $association->is_active ? 0 : 1 }}">
                                <button type="submit" class="btn btn--ghost btn--sm">{{ $association->is_active ? 'Desativar' : 'Ativar' }}</button>
                            </form>
                            <form method="POST" action="{{ route('backup-policies.associations.destroy', [$association->backupPolicy, $association]) }}" onsubmit="return confirm('Remover este vínculo de política? O histórico de backups será preservado.');">
                                @csrf @method('DELETE')
                                <input type="hidden" name="return_to" value="devices"><input type="hidden" name="page" value="{{ $devices->currentPage() }}"><input type="hidden" name="policy_device_id" value="{{ $device->id }}">
                                <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="muted-text">Nenhuma política vinculada.</p>
                @endforelse
                @if($visibleAssociations->contains(fn ($association) => $association->backup_executions_count > 0))
                    <p class="device-policy-dialog__note">Ao remover um vínculo com histórico, ele sai desta lista e as execuções antigas são preservadas.</p>
                @endif
            </section>
            @if($device->is_active && $availablePolicies->isNotEmpty())
                <details class="device-policy-dialog__add" data-device-policy-add @if(! $hasActivePolicy || (old('policy_device_id') == $device->id && old('backup_policy_id'))) open @endif>
                    <summary>{{ $hasActivePolicy ? 'Adicionar outra política' : 'Associar política' }}</summary>
                    <section class="device-policy-dialog__section" aria-label="Associar política">
                    @if($hasActivePolicy)
                        <p class="device-policy-dialog__note">Os vínculos ativos atuais continuam ativos até serem desativados acima.</p>
                    @endif
                    <form method="POST" id="device-policy-form-{{ $device->id }}" action="{{ route('backup-policies.associations.store', $availablePolicies->first()) }}" data-device-policy-form>
                        @csrf
                        <input type="hidden" name="return_to" value="devices"><input type="hidden" name="page" value="{{ $devices->currentPage() }}">
                        <input type="hidden" name="policy_device_id" value="{{ $device->id }}"><input type="hidden" name="device_id" value="{{ $device->id }}"><input type="hidden" name="is_active" value="1">
                        <div class="form-create-grid">
                            <div class="form-field"><label class="form-label" for="device-policy-select-{{ $device->id }}">Política *</label><select class="form-control" id="device-policy-select-{{ $device->id }}" name="backup_policy_id" data-device-policy-select required>
                                <option value="">Selecione uma política</option>
                                @foreach($availablePolicies as $policy)
                                    <option value="{{ $policy->id }}" data-method="{{ $policy->method }}" data-store-url="{{ route('backup-policies.associations.store', $policy) }}" @selected(old('policy_device_id') == $device->id && old('backup_policy_id') == $policy->id)>{{ $policy->name }} · {{ $policy->method === 'ftp_push' ? 'FTP' : 'SSH' }}</option>
                                @endforeach
                            </select></div>
                            <div class="form-field" data-device-policy-credential-field hidden><label class="form-label" for="device-policy-credential-{{ $device->id }}">Credencial SSH *</label><select class="form-control" id="device-policy-credential-{{ $device->id }}" name="credential_id" data-device-policy-credential disabled>
                                <option value="">Selecione uma credencial</option>
                                @foreach($sshCredentials as $credential)
                                    <option value="{{ $credential->id }}" @selected(old('policy_device_id') == $device->id && old('credential_id') == $credential->id)>{{ $credential->name }} · {{ $credential->username }}</option>
                                @endforeach
                            </select></div>
                        </div>
                    </form>
                    </section>
                </details>
            @elseif(! $hasActivePolicy)
                <section class="device-policy-dialog__section" aria-label="Associar política">
                    <h3 class="device-policy-dialog__section-title">Associar política</h3>
                    <p class="muted-text">{{ $device->is_active ? 'Nenhuma política compatível disponível. Confira as políticas ativas e o acesso SSH ou FTP deste equipamento.' : 'Ative o equipamento para associar uma política.' }}</p>
                </section>
            @endif
        </div>
        <div class="modal__footer form-create-modal__footer">
            <button type="button" class="btn btn--ghost" data-close-device-policy>Fechar</button>
            @if($device->is_active && $availablePolicies->isNotEmpty())
                <button type="submit" form="device-policy-form-{{ $device->id }}" class="btn btn--primary" data-device-policy-submit @if($hasActivePolicy && ! old('backup_policy_id')) hidden @endif disabled>Associar política</button>
            @endif
        </div>
    </div>
</dialog>
