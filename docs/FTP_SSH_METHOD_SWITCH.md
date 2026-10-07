# Troca de backup SSH para FTP em Huawei

Atualizado em 2026-10-02. Este documento descreve o contrato atual do Backup Manager V2 para SSH pull, FTP push de Huawei OLT e FTP push espontâneo de Huawei VRP em roteadores e switches.

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

Para roteadores e switches Huawei, FTP não possui o botão “enviar agora” no Backup Manager. A linha VRP `set save-configuration backup-to-server` define somente o **destino**; `set save-configuration interval` configura separadamente a **periodicidade**. A tela não deve oferecer uma execução manual com nome arbitrário para esse caso.

A origem de rede do upload também precisa constar em **Configurações → Origens permitidas no FTP**. Use o endereço visto pelo servidor após NAT. O procedimento de cadastro e conferência do firewall está em [FTP_ACCESS_CONTROL.md](FTP_ACCESS_CONTROL.md); a lista não configura destino nem agendamento no VRP.

## Configuração periódica no Huawei VRP de rede

Use este procedimento para roteadores e switches Huawei com conta FTP `backup` ativa no Backup Manager. Não transplante estes comandos para OLT: o agendamento da OLT depende do modelo e de seu fluxo próprio.

1. No painel, confirme que a conta FTP pertence ao equipamento correto, está ativa e provisionada, e que existe associação ativa à política Huawei FTP (`ftp_push/config/manual`). Copie o usuário e a senha da conta pelo fluxo autorizado do painel. A política permanece **manual** porque o scheduler do Backup Manager não inicia o envio FTP.
2. No VRP, configure **destino e intervalo**, ambos necessários para o fluxo periódico. Substitua os marcadores antes de executar; não cole a senha em tickets, chat ou documentação:

   ```text
   system-view
   set save-configuration backup-to-server server <IP_DO_BACKUP_MANAGER> transport-type ftp user <USUARIO_FTP_DO_DEVICE> password <SENHA_DA_CONTA>
   set save-configuration interval 1440
   commit
   ```

   Nesta instância o destino é `45.239.157.250`. A conta de cada equipamento é isolada; não acrescente `path` sem necessidade. No VRP que foi conferido em campo, a ajuda do comando informa intervalo de **30 a 43.200 minutos**. `1440` significa 24 horas; `14400`, 10 dias; `43200`, 30 dias. `interval` determina cadência, não um horário fixo da madrugada.
3. Confira no equipamento se **as duas linhas** aparecem na configuração aplicada e se o `commit` foi concluído. A consulta à ajuda com `?` mostra faixa e valor padrão, mas **não comprova** o intervalo efetivamente configurado. Ao compartilhar a saída, oculte a linha de senha ou envie apenas a linha `set save-configuration interval ...`.
4. Aguarde a próxima tentativa e confira o log do equipamento. No painel, procure uma execução com origem **FTP recebido** (`ftp_received`), status `succeeded` e artifact disponível. Conta FTP autenticada ou destino configurado, isoladamente, não comprovam que o arquivo foi recebido. Caso não apareça recibo, examine o horário e o erro no VRP, além de conectividade, autenticação e destino FTP.

O [guia da Huawei para salvar configurações](https://info.support.huawei.com/enterprise/en/doc/EDOC1100419273/7cea5c40/managing-configuration-files) apresenta `set save-configuration` e `backup-to-server` em etapas separadas e explica que o salvamento automático pode depender de diferença entre a configuração atual e a já salva; por isso, um intervalo diário não garante um arquivo novo todos os dias. Confirme comportamento e sintaxe no firmware do equipamento antes de aplicar a outro modelo.

### Intervalo, `delay` e mudanças de configuração

A ajuda da CLI do `SW-CORE-IPE` mostrou `set save-configuration interval 1440 ?` com `delay` descrito como **tempo do backup automático após uma mudança na configuração**. Portanto, `set save-configuration interval 1440 delay 30` combina a verificação periódica de 24 horas com uma espera de 30 minutos após mudança para o salvamento automático; o `delay` não é apenas uma tolerância acrescida ao horário periódico. No equipamento consultado, a ajuda de `delay ?` aceitou **1 a 60 minutos** e informou **5 minutos como padrão**. Sem `delay` explícito, o parâmetro pode ser omitido com `<cr>`.

Depois de alterar e aplicar a configuração, pode ocorrer um novo salvamento após o `delay` mesmo antes de vencer o `interval`; a publicação efetiva no servidor deve ser confirmada pelo log do equipamento e por um novo recibo `ftp_received` no Backup Manager. A explicação de que **cada alteração reinicia uma contagem de 30 minutos de inatividade** não está comprovada pela ajuda apresentada nem pelo [guia oficial](https://info.support.huawei.com/enterprise/en/doc/EDOC1100419273/7cea5c40/managing-configuration-files); não usar essa hipótese como garantia operacional para este firmware. Também não foi confirmada para este equipamento a disponibilidade do comando `display save-configuration argument` citado em uma resposta externa. Use a configuração aplicada e os logs disponíveis na CLI real para verificar o comportamento.

**Caso VS-01-BGP1 em 02/10/2026:** o operador encontrou `interval 43200` (30 dias) e corrigiu para `interval 1440`, mantendo o destino FTP e salvando a configuração. Na consulta feita às 14h33 (America/Sao_Paulo), ainda não havia novo recebimento; o último recibo continuava sendo o #48, de 30/09 às 16h51, associado à execução #103 e ao artifact #37. A correção da cadência foi confirmada pelo operador, mas o primeiro envio após ela ainda precisa ser observado. Nenhum valor de senha ou sua representação criptografada foi registrado aqui.

**Acompanhamento dos outros Huawei FTP de rede em 02/10/2026:** o operador informou que também ajustou o `BGP-VS-ADMIN`; a linha efetiva do intervalo desse equipamento não foi apresentada, portanto o valor não foi registrado aqui. O único ajuste ainda pendente informado pelo operador é o `SWITCH-POP-IPE`. Em consulta posterior aos três equipamentos, não havia recibo novo após os ajustes: último recebimento de `BGP-VS-ADMIN` em 01/10 às 10h47 (recibo #49, execução #153), de `SWITCH-POP-IPE` em 30/09 às 16h38 (recibo #47, execução #101) e de `VS-01-BGP1` em 30/09 às 16h51 (recibo #48, execução #103), horários de America/Sao_Paulo. O primeiro envio com a cadência corrigida continua pendente de confirmação para os dois equipamentos já ajustados.

**Atualização posterior em 02/10/2026:** o operador confirmou também o ajuste do `SWITCH-POP-IPE`. Assim, os três ajustes de configuração foram informados como concluídos, embora a linha efetiva do intervalo do switch não tenha sido apresentada. O `VS-01-BGP1` enviou um novo arquivo às **14h40** (America/Sao_Paulo): recibo FTP **#63** `stored`, execução **#212** `succeeded`, artifact **#73** com 8.751 bytes e SHA-256 `dbe06df4042d540e2f07e01db61c90090018ad4c329efee4605ea2d60773cb17`. `ArtifactStorage::verify()` retornou `valid`. Na mesma consulta, `BGP-VS-ADMIN` e `SWITCH-POP-IPE` ainda não tinham novo recibo; o próximo envio de cada um segue pendente de confirmação. Os parágrafos anteriores registram o estado nas consultas feitas antes desta atualização.

**Configuração informada depois pelo operador:** o `SWITCH-POP-IPE` recebeu `set save-configuration interval 1440 delay 60` e destino FTP `45.239.157.250` com sua conta própria. A senha codificada mostrada pela CLI não foi reproduzida. O intervalo é de 24 horas; o `delay` de 60 minutos aguarda após mudança na configuração, conforme a ajuda observada no switch. A confirmação de um novo envio do switch após essa alteração permanece pendente.

**Confirmação de recebimento posterior, 02/10/2026:** os três Huawei de rede enviaram novos arquivos FTP. `VS-01-BGP1`: recibo #63, execução #212, artifact #73 de 8.751 bytes. `BGP-VS-ADMIN`: recibo #64, execução #213, artifact #74 de 6.457 bytes. `SWITCH-POP-IPE`: recibo #65, execução #214, artifact #75 de 2.502 bytes. As três execuções terminaram `succeeded` e `ArtifactStorage::verify()` retornou `valid` para os três artifacts. Isso confirma recebimento e integridade após os ajustes informados, mas um ciclo isolado ainda não comprova a cadência de 24 horas nem se novas alterações reiniciam o contador `delay` no firmware.

**Informação operacional do operador:** `delay 60` foi aplicado nos switches e roteadores Huawei sob sua configuração. Essa confirmação é do operador; não foi feita leitura da configuração efetiva de cada equipamento pelo Backup Manager. O resultado esperado é aguardar até 60 minutos após mudança antes do salvamento automático, mas a quantidade de envios durante uma sequência de commits ainda depende do comportamento observado em cada firmware.

**BNG-NE8000 em 02/10/2026:** após o operador configurar FTP no equipamento, a associação FTP #8 ficou ativa e a associação SSH diária #3 foi desativada. O intervalo esperado de chegada no painel foi definido como 24 horas. O envio real chegou às 19:38:21 UTC: recibo #70 `stored`, execução #220 `ftp_received/succeeded` e artefato #81 com 6.714 bytes. `ArtifactStorage::verify()` retornou `valid`. Assim, o transporte FTP do BNG foi confirmado; o próximo ciclo ainda é necessário para observar a regularidade diária. As informações anteriores deste documento que diziam não haver novo arquivo do BNG pertencem à consulta anterior a esse recebimento.

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

Um equipamento pode ter mais de uma associação ativa. A coluna **Método** da lista de equipamentos mostra os métodos das associações e políticas ativas, sem usar uma execução histórica para definir a configuração atual. Quando SSH e FTP estão ativos no mesmo equipamento, a lista mostra ambos. O relatório de equipamentos usa a primeira política ativa para seus campos singulares de método e política. A saúde ainda é resumida por equipamento; para distinguir resultados por método, consulte as execuções de cada política.

Na OLT-huawei-base, a única associação operacional ativa é **Huawei FTP**, com método `ftp_push`. A execução FTP recebida #171 também pertence a essa política. A configuração não deve ser alterada para SSH para corrigir um rótulo antigo na tela.

### Correção do histórico de execuções em 01/10/2026

Na lista **Execuções**, a linha da OLT-huawei-base mostrava `Huawei FTP` e `Coleta via SSH` ao mesmo tempo. A política no banco já era `ftp_push`: o controlador carregava a relação `backupPolicy` somente com `id` e `name`, sem `method`. A view recebia `method = null` e seu texto alternativo era SSH. A consulta agora carrega `id,name,method`; a view usa os rótulos de `OperationalLabels` e mostra `—` se o método estiver ausente, sem atribuir SSH por engano.

Foi renderizada a lista com dados operacionais após a correção: a linha da OLT-huawei-base mostrou `Huawei FTP` e **Envio via FTP**. `BackupExecutionTest` passou com 14 testes e 101 asserções, incluindo regressão para esse caso. Nenhuma política, conta FTP ou execução foi alterada para corrigir o rótulo.

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
