# Troca de backup SSH para FTP em Huawei

Atualizado em 2026-09-30. Este documento descreve o contrato atual do Backup Manager V2 para SSH pull, FTP push de Huawei OLT e FTP push espontâneo de Huawei VRP em roteadores e switches.

## Resumo operacional

`ssh_pull` e `ftp_push` são caminhos de transporte diferentes. O equipamento e a tela determinam quem inicia a transferência:

| Equipamento e método | Quem inicia | Execução | Ação do operador |
| --- | --- | --- | --- |
| Huawei de rede (`platform=network`) por SSH | Backup Manager | Execução manual ou agendada; o engine abre SSH e coleta a configuração | Manter credencial SSH e política `ssh_pull` |
| Huawei de rede por FTP | Roteador/switch Huawei VRP | A execução é criada quando o scanner detecta o upload; `origin=ftp_received` | Configurar o endereço FTP e o intervalo no VRP; aguardar o próximo envio |
| Huawei OLT por FTP | O operador inicia no console da OLT | O wizard cria uma execução manual antes do upload; o engine aguarda o nome `bm-exec-<ID>.cfg` | Executar o comando exato mostrado pelo wizard enquanto a execução aguarda |
| VSOL OLT por SSH | Backup Manager | Execução manual ou agendada; o engine coleta `show running-config` | Método validado para a OLT-SANCA/V1600GT |
| VSOL OLT por FTP | O operador inicia no console da OLT | Fluxo manual do wizard, se o modelo realmente suportar `ftp://` | Depende de homologação por modelo; não usar na V1600GT |
| MikroTik por FTP | — | Não implementado na V2 | Usar SSH pull ou planejar integração própria |

Para roteadores e switches Huawei, FTP não possui o botão “enviar agora” no Backup Manager. A linha VRP `set save-configuration backup-to-server` programa envios periódicos. A tela não deve oferecer uma execução manual com nome arbitrário para esse caso.

## Procedimento seguro para trocar um equipamento de SSH para FTP

Uma policy SSH associada a credenciais não deve ser convertida em `ftp_push` no lugar. Execuções antigas referenciam essa policy; alterar seu método mudaria a interpretação do histórico. O método atual é bloqueado pelo controlador por esse motivo.

Use duas associações durante a transição:

1. No equipamento Huawei de rede, crie e sincronize a conta FTP pela tela do equipamento. A criação da conta prepara uma policy FTP e sua associação compatível.
2. Configure no roteador/switch o endereço do servidor, o usuário e a senha FTP guardada na criação da conta. Não configure `path`: cada conta já está isolada na raiz observada pelo scanner.
3. Configure o intervalo de envio suportado pelo modelo/firmware. Para o NE8000 documentado no wizard, o mínimo é 30 minutos. Confirme a sintaxe no equipamento; alguns modelos exigem `commit`.
4. Confirme a configuração no wizard do Backup Manager. Para `platform=network`, o wizard aguarda o próximo recebimento espontâneo; ele não cria uma execução manual.
5. Aguarde uma execução `ftp_received` com status `succeeded` e artefato validado.
6. Depois de confirmar o primeiro backup FTP, abra a policy SSH antiga e desative a associação desse equipamento. A associação e as execuções históricas permanecem para auditoria, mas o scheduler deixa de iniciar novas coletas SSH por ela.

Durante a transição podem existir duas associações ativas. Isso pode manter a coleta SSH agendada enquanto os arquivos FTP também chegam. O sistema não desativa automaticamente SSH quando recebe o primeiro FTP: essa troca precisa ser confirmada pelo operador. Ainda falta uma ação única e guiada de migração por equipamento.

Para voltar de FTP para SSH, prepare/valide uma associação SSH com credencial ativa e só então desative a associação FTP. Não altere o método da policy FTP se ela já tiver associações ou histórico.

Na OLT-SANCA/V1600GT, o hardware aceitou SSH e não suportou o destino `ftp://`. Em 30/09/2026 a associação SSH já estava ativa; a associação, a conta e o registro residual do wizard FTP foram removidos. As cinco tentativas FTP antigas eram falhas sem artifact e foram removidas mediante pedido explícito do operador, com auditoria. O modelo V1600GT agora é bloqueado nas rotas de criação de FTP e não exibe o wizard. A remoção normal de uma associação que tem execuções continua bloqueada. Veja `docs/CHANGES_2026-09-30.md` para IDs, critérios de segurança e inventário final.

## Conta, policy e execução

Uma conta FTP criada não é, por si só, uma execução. No Huawei de rede, a cadeia é:

`conta FTP backup ativa e provisionada → device Huawei/network ativo → associação ftp_push/config/manual ativa → equipamento faz upload → scanner estável identifica a conta/home → Laravel cria execução ftp_received → engine valida e grava artifact → execução succeeded`.

A criação da conta de backup por `FtpAccountManager` chama `HuaweiFtpBackupPolicy::ensure()` para equipamentos elegíveis e cria ou reaproveita uma associação. O recebimento espontâneo usa o home da conta para encontrar o dispositivo. O nome original do arquivo é preservado como metadado; o path final do artifact é gerado pelo sistema.

`ftp_push` usa `schedule_type=manual` como indicação de que o scheduler Laravel não inicia o transporte. No roteador, o período real é configurado no VRP. Na OLT, o operador dispara cada teste pelo console. O rótulo “Manual” da policy não significa que o Backup Manager possa enviar um Huawei VRP de rede imediatamente.

O engine registry contém `huawei/network/ssh_pull`, `huawei/olt/ftp_push`, `vsol/olt/ftp_push` e `vsol/olt/ssh_pull`, entre outros drivers listados em `docs/ENGINE_DRIVERS.md`. O backup FTP espontâneo de Huawei/network passa por `engine/ftp_spontaneous.py` e pelo comando Laravel `EngineJobService::receiveFtp()`; ele não é despachado como job `ftp_push` pelo registry. `ftp_received` não entra na fila normal do engine: o scanner recebe e conclui o artifact diretamente.

## Execução manual inválida em roteador

O botão genérico de criar execução manual FTP apareceu para uma associação Huawei de rede. Isso criou uma execução `origin=manual`, que seguiu para o dispatcher como método `ftp_push`. O registry não tem driver de despacho manual `huawei/network/ftp_push`, então a execução falhou com `UNSUPPORTED_POLICY`. Essa falha não representa tentativa de conexão FTP nem falha do envio automático do roteador.

O código agora impede esse caminho em três pontos:

- A tela de policy mostra “Recebimento automático” para associação FTP em `platform=network` e reserva “Criar execução” manual para FTP de OLT.
- `BackupExecution::createManual()` rejeita `ftp_push` manual fora de OLT, incluindo chamadas que contornem a tela.
- `EngineJobService::job()` considera elegível como job `ftp_push` somente a execução manual de OLT. Recebimentos de roteador continuam entrando pelo scanner como `ftp_received`.

A página de detalhes diferencia a orientação manual de OLT da orientação de recebimento espontâneo de roteador, inclusive para registros antigos.

## Caso observado em 30/09/2026

Os horários abaixo são locais de `America/Sao_Paulo`:

| Execução | Equipamento | Origem | Resultado | Interpretação |
| --- | --- | --- | --- | --- |
| #101 | `SWITCH-POP-IPE` | `ftp_received` | `succeeded`; artifact validado | Backup FTP espontâneo real. Recebido às 16:38:56 e processado às 16:39:03. |
| #102 | `VS-01-BGP1` | `manual` | `failed`, `UNSUPPORTED_POLICY` | Execução manual criada pela tela antiga. Não foi o upload do roteador. A página apresentava instruções de OLT por engano. |
| #103 | `VS-01-BGP1` | `ftp_received` | `succeeded`; artifact validado | Upload automático real. Recebido às 16:51:16 e concluído às 16:51:24. |

Origem e método são campos diferentes: `origin=ftp_received` identifica que o arquivo chegou pelo scanner; `backupPolicy.method=ftp_push` identifica a policy usada para associar e registrar o backup.

## Troca de método e histórico

`BackupPolicyController::update()` bloqueia a alteração SSH→FTP quando as associações têm `credential_id` de SSH. As execuções guardam `backup_policy_id`, `device_backup_policy_id` e `credential_id`; manter a policy SSH imutável preserva o significado dos registros antigos. A interface orienta criar a associação FTP pelo assistente e desativar a associação SSH antiga.

Essa proteção preserva o histórico, mas a operação ainda exige navegação entre o assistente FTP e a policy SSH. Não há migração atômica, resumo prévio de consequências ou rollback automático se o primeiro FTP não chegar. Uma futura ação “Trocar para FTP” deve ser por dispositivo, preparar a associação nova, aguardar um recebimento validado, permitir reversão e só então desativar a associação antiga. Não deve editar a policy compartilhada nem apagar associações com histórico.

## Método exibido no resumo de equipamento

Um equipamento pode ter mais de uma associação ativa. Antes da correção, `DeviceBackupHealth::rows()` escolhia a primeira associação ativa para método/policy, mas obtinha o último backup olhando todas as políticas do device. Isso podia mostrar “Coleta via SSH” ao lado de um recebimento FTP mais recente.

O resumo agora obtém método e nome da policy pela execução mais recente do equipamento, com fallback para a primeira associação se o equipamento ainda não tiver execuções. O status de saúde ainda resume as políticas ativas do equipamento; se métodos distintos permanecerem ativos durante a transição, verifique a lista de execuções por policy para distinguir falha SSH de recebimento FTP. A UI não apresenta hoje saúde separada por método.

## Códigos e pontos de código

| Responsabilidade | Arquivo/classe |
| --- | --- |
| Elegibilidade Huawei OLT/rede e VSOL OLT | `app/app/Models/Device.php::isHuaweiFtpEligible()` |
| Criar conta e preparar policy FTP | `app/app/Services/FtpAccountManager.php` e `app/app/Services/HuaweiFtpBackupPolicy.php` |
| Receber espontaneamente e criar `ftp_received` | `engine/ftp_spontaneous.py`, `app/app/Services/EngineJobService.php::receiveFtp()` |
| Criar execução manual e impedir FTP manual de rede | `app/app/Models/BackupExecution.php::createManual()` |
| Conferir elegibilidade de job normal | `app/app/Services/EngineJobService.php::job()` |
| Wizard OLT e roteador com etapas de confirmação distintas | `app/app/Services/OltFtpWizard.php` e `app/resources/views/devices/edit.blade.php` |
| Apresentar método da execução | `app/resources/views/backup-executions/index.blade.php` |
| Método/policy do resumo por equipamento | `app/app/Services/DeviceBackupHealth.php` e `app/resources/views/devices/index.blade.php` |
| Configuração das policies e associações | `BackupPolicyController`, `DeviceBackupPolicyController`, `app/resources/views/backup-policies/` |

`OltFtpWizard` e `olt_ftp_integrations` são nomes históricos compartilhados pelo wizard de OLT e Huawei/network. O comportamento tem ramificações por plataforma, mas a nomenclatura compartilhada é uma fonte de confusão e deve ser considerada em uma refatoração que separe os fluxos sem alterar os contratos operacionais.

## Verificação desta correção

O banco de produção foi consultado em modo de leitura para verificar contas, policies, recibos e execuções #101–#103. Nenhum registro operacional foi alterado. `git diff --check` passou e os arquivos PHP afetados passaram em `php -l` dentro do container da aplicação. A suíte automatizada não foi executada nesta correção.
