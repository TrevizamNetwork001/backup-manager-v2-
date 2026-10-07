# FIELD-HOMOLOG-1 — homologação funcional e ajustes operacionais

Data: 2026-09-28 (America/Sao_Paulo). Repositório: `/opt/backup-manager-v2`.

**A10 Networks / ACOS CGNAT, 02/10/2026:** o TH1040 com ACOS 4.1.4-GR1-P14 build 42 foi **HOMOLOGADO REALMENTE PARA EXECUÇÃO MANUAL SSH → SFTP**. A execução 210, iniciada pelo Backup Manager, concluiu com artifact 71 de 832.411 bytes; SHA-256 e tamanho foram validados por `ArtifactStorage::verify()`. O A10 conectou de `172.22.254.234` à conta SFTP restrita do próprio host, enviou o `.tar.gz` completo e o engine armazenou o arquivo no caminho oficial. A política A10 ID 8 e associação ID 16 estão ativas, somente em modo manual, com retenção de 30 dias/30 cópias; app, scheduler e engine têm `BACKUP_A10_ENABLED=true`. Não há agendamento automático nem teste de restore/download pela UI. A execução 208 falhou por não reconhecer o prompt inicial `>` e a 209 falhou por não responder às perguntas pós-senha da CLI; ambas terminaram sem artifact. O envio nativo de teste feito pela UI do A10 chegou ao host e foi preservado separadamente, mas não foi importado para o histórico. Ver [A10_BACKUP.md](A10_BACKUP.md).

**Confirmação posterior do operador:** “tudo ok validado” após a implantação do botão de execução A10 no painel. O fluxo do painel fica registrado como validado pelo operador; não foram fornecidos ID de uma nova execução, horário, hash de novo arquivo ou evidência específica de download/restauração nessa confirmação.

**Execução posterior pelo painel, 02/10/2026:** após essa confirmação, o operador informou que executou o backup. Consulta somente leitura ao histórico identificou a execução manual **211**, de **17:14:12 a 17:14:26 UTC**, `succeeded`, com artifact **72** `available`. O arquivo possui **832.408 bytes**, SHA-256 `693829aa0701ff3b16c86e97e87b8f41018c415880c2c79b07555a921ff56bdc`; `ArtifactStorage::verify()` retornou `valid` em nova conferência. Caminho: `Backup Manager/POP-BATISTINI/CGNAT-A10/02-10-2026/CGNAT-A10_20261002141412-exec-211.tar.gz`. A política segue manual; não houve validação de restore nem confirmação específica de download web. Procedimento operacional e cronologia em [A10_BACKUP.md](A10_BACKUP.md).

**Adendo de 30/09/2026:** desde a data original deste relatório, a V2 passou a suportar recebimento FTP espontâneo de Huawei VRP em `platform=network`. A antiga análise detalhada abaixo, iniciada antes dessa implementação, contém conclusões superadas sobre esse fluxo. Para operação atual, use [FTP e SSH: troca segura de método](FTP_SSH_METHOD_SWITCH.md), que documenta o comportamento e a correção da execução manual inválida `UNSUPPORTED_POLICY`.

**OLT-SANCA / VSOL V1600GT, 30/09/2026:** o equipamento real não aceitou FTP e foi mantido exclusivamente em SSH pull. Conta, integração e associação FTP foram removidas; cinco tentativas FTP falhadas sem artifacts foram removidas a pedido do operador e a limpeza foi auditada. A V2 agora impede novo fluxo FTP de backup para o modelo V1600GT. O driver VSOL/OLT/FTP permanece no registry para outros modelos que venham a ser homologados; sua existência não comprova suporte FTP da V1600GT. Consulte [as alterações operacionais](CHANGES_2026-09-30.md).

**Resultado atualizado: Huawei OLT via FTP, MikroTik via SSH e Huawei Router/Switch via SSH estão HOMOLOGADOS REALMENTE, conforme confirmação explícita do usuário. Esses três fluxos estão encerrados para esta homologação e não devem ser repetidos.**

A confirmação do usuário atualiza o status de campo e prevalece sobre as pendências de coleta registradas na primeira versão deste relatório. Não foram fornecidos novos IDs, datas de execução ou hashes dessa homologação; não se inventam esses dados. O download autenticado pela interface e um novo disparo controlado pelo scheduler continuam pendentes, independentemente do sucesso da coleta.

Na execução inicial desta tarefa não houve nova coleta remota nem disparo de agendamento real pelo agente. Não houve exclusão de dados operacionais, alteração de contas reais, push, tag ou marcação de v2.0.0. As alterações anteriores do checkout foram preservadas.

## Critérios de classificação

- **SUPORTADO:** existe implementação executável do fluxo descrito, com validação controlada nesta etapa. Isso não significa homologação de um modelo/firmware real.
- **PARCIAL:** apenas parte do fluxo solicitado existe ou foi demonstrada; o limite está indicado na tabela.
- **NÃO IMPLEMENTADO:** não há implementação operacional do fluxo. Cadastro, opção de policy, permissão e UI não bastam para comprovar suporte.
- **FUTURO:** proposta de evolução, sem implementação ou promessa de suporte atual.

Na coluna “homologado real?”, **HOMOLOGADO REAL** identifica os três fluxos de campo confirmados pelo usuário; **laboratório real** significa protocolo/daemon executado com contas sintéticas em loopback isolado; **histórico verificado** significa arquivo existente validado somente por leitura; **controlado** significa testes HTTP no kernel Laravel, banco SQLite em memória, fixtures, mocks de transporte e arquivos temporários. Os três últimos termos não equivalem a uma nova coleta em equipamento de campo. Status de suporte e status de homologação são dimensões separadas.

## Referência V1 consultada antes das decisões

- `../backup-manager-local/backup_manager/mikrotik_ftp_service.py`: integração específica de RouterOS 6/7, formatos `backup`, `rsc`, `both`, conta isolada e correlação de teste.
- `../backup-manager-local/backup_manager/mikrotik_ftp_scripts.py`: geração de scripts RouterOS e agendamento no equipamento.
- `../backup-manager-local/backup_manager/ftp_importer.py`: importação com correlação, validação, duplicidade e armazenamento dos artifacts MikroTik.
- `../backup-manager-local/backup_manager/equipment_page_views.py`: a V1 também mistura nome amigável/hostname na apresentação; seu formulário usa `hostname` como nome amigável e `ip_address` para conexão. Essa ambiguidade não deve virar contrato da V2.
- `../backup-manager-local/README.md`: comandos operacionais dos drivers SSH, incluindo Huawei VRP.

Na V2, o registry registra MikroTik/rede/SSH, Huawei/rede/SSH, Huawei/OLT/FTP, VSOL/OLT/SSH e VSOL/OLT/FTP. Huawei VRP/rede também recebe FTP espontâneo pelo scanner, com criação de execução `ftp_received` no Laravel; esse fluxo não é um job `ftp_push` despachado pelo registry. Não existe portabilidade automática da integração MikroTik FTP da V1.

## Padronização do Fabricante/Vendor no cadastro

Referência consultada antes da alteração: `../backup-manager-local/backup_manager/equipment_views.py` carrega o catálogo `vendors` e `equipment_page_views.py` usa select `vendor_id`; `backup_manager/db.py` semeia os fabricantes. Consulta somente leitura ao banco V1 confirmou exatamente as mesmas 12 opções: **C-DATA, Cisco, Datacom, FiberHome, Huawei, Intelbras, Juniper, MikroTik, Parks, Ubiquiti, VSOL e ZTE**. Esse catálogo foi preservado no V2 sem copiar tabelas, relações ou arquitetura do V1. As opções de cadastro não ampliam o suporte de coleta: os drivers V2 continuam os três descritos acima.

Criação dedicada, modal de criação e edição compartilham o select obrigatório de fabricante. Backend valida o catálogo e normaliza caixa/espaços para a grafia canônica, incluindo Huawei/HUAWEI/huawei e MikroTik/Mikrotik/mikrotik. Modelo continua texto livre, sem lista de modelos; Tipo mantém as opções atuais `network` e `olt`.

Compatibilidade: equipamento com fabricante fora do catálogo recebe uma opção adicional selecionada, identificada como “(legado)”, apenas na edição desse equipamento. É possível editar os demais campos mantendo esse fabricante ou substituí-lo por uma opção canônica. A exceção não permite criar novos equipamentos com fabricante desconhecido, nem introduzir outra marca arbitrária na edição. Erros de validação preservam a seleção legada. Fabricantes conhecidos antigos aparecem na grafia canônica na edição e na listagem; abrir essas páginas não regrava o banco. A normalização persistida acontece ao salvar pelo cadastro. Valores legados desconhecidos continuam visíveis na listagem e disponíveis na busca. Nenhuma migration, alteração de schema ou atualização em lote dos registros existentes foi necessária.

Validação controlada final: **86 testes / 995 assertions, OK**, abrangendo `DeviceTest`, `EngineJobTest`, `HuaweiOltFtpTest` e `RbacTest`, em contêiner sem rede, repositório somente leitura, banco SQLite em memória e storage/cache temporários. Cinco novos testes cobrem catálogo/formulários, criação/normalização/rejeição, edição de fabricante conhecido antigo, preservação/substituição de fabricante legado e listagem/metadados de busca sem vazamento de opções legadas para o modal. O teste existente de ciclo Huawei passou a esperar a grafia canônica na listagem e verifica que a consulta mantém o valor original no banco. Handler JavaScript real da busca executado em DOM simulado: **nove cenários aprovados**, incluindo caixa/espaços, Huawei, MikroTik, fabricante legado, IP, ausência de resultados e consulta vazia. Pint aplicado somente aos quatro PHP desta tarefa; `git diff --check` passou. Sem inspeção visual em navegador, nova coleta em equipamento, alteração de dados operacionais ou push.

## Tabela por fluxo

| Fluxo | Status atual | Homologado real? | Bug/limite encontrado | Correção nesta etapa | Ainda pendente |
| --- | --- | --- | --- | --- | --- |
| MikroTik router SSH — coleta | SUPORTADO: export de configuração `.rsc` | HOMOLOGADO REAL — confirmado pelo usuário | Nenhum novo bug de transporte; não amplia suporte para backup binário | Sem mudança no driver | Nenhuma repetição da coleta |
| MikroTik — artifact e download | SUPORTADO | Fluxo SSH HOMOLOGADO REAL; download web autenticado pendente | Nome baixado acrescentava extensão ao nome já com extensão | Nome usa stem sanitizado + extensão do arquivo armazenado, uma vez | Baixar artifact existente pela interface, sem nova coleta |
| MikroTik — retry/failure | SUPORTADO | Fluxo SSH HOMOLOGADO REAL; cenários de falha cobertos em testes controlados | Confirmação não detalha quais falhas foram provocadas em campo | Testes existentes de auth, timeout, host key, retry, recuperação e cancelamento passaram | Não repetir fluxo nem provocar falha em equipamento nesta fase |
| Huawei router/switch SSH — coleta | SUPORTADO: Huawei VRP, `.cfg` | HOMOLOGADO REAL — confirmado pelo usuário | Não amplia automaticamente suporte para outras famílias Huawei | Sem mudança no driver | Nenhuma repetição da coleta |
| Huawei VRP — artifact/download | SUPORTADO | Fluxo SSH HOMOLOGADO REAL; download web autenticado pendente | Mesmo defeito no nome do download | Correção compartilhada do nome | Baixar artifact existente pela interface, sem nova coleta |
| Huawei OLT FTP — conta e policy | SUPORTADO para OLT Huawei | HOMOLOGADO REAL como parte do fluxo OLT confirmado | Conta provisionada não basta: equipamento ativo e policy compatível são necessários | Prontidão preserva essas condições | Não repetir preparação funcional já homologada; lifecycle administrativo separado abaixo |
| Huawei OLT FTP — recebimento espontâneo | SUPORTADO | HOMOLOGADO REAL — confirmado pelo usuário | OLT envia; aplicação não abre sessão na OLT. Parser informativo não bloqueia conteúdo íntegro desconhecido | Sem mudança no fluxo | Nenhuma repetição de recebimento/coleta |
| Huawei OLT FTP — teste manual | SUPORTADO com `bm-exec-<ID>.cfg` | Fluxo OLT HOMOLOGADO REAL; variante manual coberta em testes controlados | Confirmação não individualiza a variante do wizard; operador executa comando na OLT | Sem mudança | Não repetir teste na OLT nesta fase |
| Huawei OLT FTP — artifact/download | SUPORTADO | Fluxo FTP HOMOLOGADO REAL; download web autenticado pendente | Mesmo defeito no nome do download | Correção compartilhada | Baixar artifact existente pela interface, sem novo upload |
| FTP backup MikroTik/router/switch | NÃO IMPLEMENTADO na V2 | Não | Cadastro aceita conta vinculada; tela podia declarar “Conta apta para receber backups” sem driver | Detalhe deixa de declarar prontidão; aviso no detalhe e criação explica limite | Evolução própria para V2.1; não habilitada nesta etapa |
| FTP backup Huawei router/switch | SUPORTADO como recebimento espontâneo | Uploads reais #101 e #103 concluíram com artifact; manual #102 falhou por caminho de UI incorreto | VRP inicia o upload segundo seu intervalo; Backup Manager não oferece “enviar agora” | Ver [documentação operacional](FTP_SSH_METHOD_SWITCH.md) | Melhorar orientação de migração SSH→FTP; não repetir uploads já homologados |
| FTP genérico `file_server` — recepção | PARCIAL no fluxo completo solicitado: recepção/recibo existem | Login/upload/chroot em laboratório real; armazenamento/scanner controlados | Não cria `BackupExecution`/`BackupArtifact`; não possui download web | Sem inventar driver | Integração ponta a ponta com daemon + scanner + recibo; download é evolução |
| Conta FTP — criar | SUPORTADO | Conta operacional integra o fluxo OLT HOMOLOGADO REAL; criação HTTP/provisionamento também testados em laboratório | Confirmação do fluxo não certifica cada variante administrativa de criação | Sem mudança | Não recriar conta para repetir OLT; revisar somente variante administrativa ainda não homologada |
| Conta FTP — rotacionar | SUPORTADO | Laboratório real: antiga rejeitada e nova aceita; HTTP/RBAC controlados | Operador precisa atualizar senha no emissor; aguardar sincronização | Sem mudança na rotação | Cadeia completa web → PureDB → equipamento, por caso |
| Conta FTP — desativar/reativar | SUPORTADO para novos logins | Laboratório real: login revogado, reativação e novo upload; HTTP/RBAC controlados | Não certifica encerramento de sessões já abertas | Teste PureDB ampliado para reativação e preservação dos arquivos | Validar cadeia completa da UI ao reconciliador |
| Conta FTP — excluir só conta | SUPORTADO, admin, confirmação e revogação antes da remoção | Controlado; revogação PureDB em laboratório real | Não se deve apagar arquivos ao excluir só conta | Sem mudança; testes preservam arquivos/recibos/backups | Integração completa em ambiente descartável; nenhuma conta real excluída |
| Conta FTP — excluir com dados | SUPORTADO: `ftp_data` e `all`, com escopos distintos | Controlado com dados temporários | Claims/execuções em retry, paths inválidos e arquivos sem atribuição segura bloqueiam/preservam conforme contrato | Sem mudança; testes dos três modos passaram | Cadeia web → revogação → remoção física privilegiada, em ambiente descartável |
| Conta FTP — histórico | SUPORTADO com limite | Controlado; há recibos operacionais existentes | Detalhe mostra apenas os 10 últimos; expansão visual não carrega histórico completo | Sem mudança | Histórico completo paginado/filtros como evolução |
| Download web de `ftp_received_files` | NÃO IMPLEMENTADO | Não | Não existe rota/controller/botão de download; export CSV é apenas relatório | Documentado, sem adicionar feature | V2.1: endpoint, RBAC e verificação de path/hash próprios |
| Download de backup artifacts — autorização | SUPORTADO | Kernel HTTP com quatro papéis; Nginx real sem login retorna 302 | Auditor pode visualizar metadados, mas não baixar arquivo | Matriz preservada | Sessão autenticada no navegador em homologação de campo |
| Download — ausente/tamper/path safety | SUPORTADO para artifacts | Controlado; arquivos existentes também verificados | Arquivo ausente/alterado, traversal e symlink devem retornar 404 | Nova cobertura HTTP direta para ausente, traversal e symlink | FTP genérico não possui endpoint a que aplicar estes testes |
| Cadastro — nome principal/hostname | SUPORTADO, UX ajustada | Criação/edição/legado/HTML e busca controlados; sem inspeção visual de navegador | Listagem repetia nome/hostname; modal podia herdar dados e método PUT do último equipamento | Nome em destaque; hostname diferente junto ao IP; grid de duas colunas; Segurança SSH recolhida; modal cria via POST vazio | Conferência visual no navegador; schema e valores existentes preservados |
| Policies/associações | SUPORTADO para métodos compatíveis | Integram os três fluxos HOMOLOGADOS REAIS; demais validações controladas | Policy/UI não garantem driver; FTP OLT exige configuração compatível | Sem mudança; validações passaram | Somente associação do caso controlado do scheduler, sem refazer os três fluxos |
| Scheduler SSH diário/semanal | SUPORTADO | Testes controlados; tick e histórico operacional observados somente por leitura | Tick saudável não comprova nova execução completa; guarda de ocupado é best-effort | Sem mudança | Autorizar equipamento/policy/horário para novo disparo real |
| Scheduler FTP OLT | NÃO IMPLEMENTADO deliberadamente | Não | Upload espontâneo é operacional; scheduler Laravel seleciona somente `ssh_pull` | Documentado | Automação no emissor/agendamento FTP é FUTURO |
| Retention de artifacts | SUPORTADO: dias/quantidade, dry-run/apply, protege último válido | Controlado com arquivos temporários | Instância informa que retenção ainda não executou; registro automático depende de configuração | Sem mudança; nenhuma aplicação sobre arquivos operacionais | Revisar dry-run operacional e política antes de qualquer apply |
| RBAC admin/operator/viewer/auditor | SUPORTADO, falha corrigida | HTTP controlado; sem novas contas na instância | `GET ftp.show?deletion_preview=1` emitia `ftp.physical.request` para não-admin | Solicitação privilegiada exige `ftp.delete`; regressão reproduziu falha antes e passou depois | Navegador com sessões de homologação dos quatro papéis |

## Análise inicial de FTP de roteadores e switches (histórica; conclusões superadas em 30/09)

> As tabelas e conclusões desta seção são o levantamento anterior à implementação do recebimento Huawei VRP/network. Para a situação vigente, consulte o adendo no início do documento e `FTP_SSH_METHOD_SWITCH.md`. As partes sobre MikroTik FTP e `file_server` continuam válidas.

### Conclusão e classificação por caso

**A V2 não implementa backup FTP Push para roteadores/switches (`platform=network`), incluindo MikroTik e Huawei VRP.** O único vendor/platform de backup FTP aceito hoje é **Huawei / OLT / `ftp_push` / `config` / `manual`**. Cadastrar uma conta FTP, conseguir autenticar no daemon ou receber bytes não habilita a cadeia de backup. Os fluxos SSH e Huawei OLT FTP já homologados permanecem aceitos e não foram repetidos.

A classificação abaixo usa os quatro estados solicitados nesta investigação. “SUPORTADO, NÃO HOMOLOGADO” não substitui “NÃO IMPLEMENTADO”: só se aplica quando existe implementação do caso descrito, mas falta homologação de campo desse caso.

| Caso na V2 | Classificação | Evidência/limite | Decisão |
| --- | --- | --- | --- |
| Huawei OLT enviando backup FTP | SUPORTADO E HOMOLOGADO | Única combinação FTP de backup aceita; homologação real confirmada pelo usuário | Preservar; não repetir |
| MikroTik router ou switch RouterOS, `network`, enviando `.rsc` por FTP como backup | NÃO IMPLEMENTADO | Não há combinação no registry; associação/elegibilidade/receiver/completion bloqueiam `network` | V2.1 |
| MikroTik router/switch enviando `.backup` binário ou par `.backup` + `.rsc` | NÃO IMPLEMENTADO | Além dos gates de plataforma, FTP só permite artifact `config`; não há lifecycle de múltiplos artifacts | V2.1, escopo próprio |
| Huawei VRP router/switch, `network`, enviando configuração por FTP | NÃO IMPLEMENTADO | Huawei `network` tem somente SSH; receiver exige Huawei `olt` | V2.1 |
| Router/switch de outros vendors via FTP como backup | NÃO IMPLEMENTADO | Não há driver/combinação, gate de recebimento nem conclusão habilitados | Levantar protocolo/formato por vendor antes de implementar |
| Cadastro/provisionamento de conta `backup` vinculada a router/switch | SUPORTADO, NÃO HOMOLOGADO como caso administrativo de campo específico | Cadastro geral permite vínculo; laboratório demonstra mecanismo FTP; isso não gera policy compatível/execução/artifact para `network` | Não apresentar a conta como backup suportado |
| Router/switch como cliente de conta genérica `file_server` | SUPORTADO, NÃO HOMOLOGADO nesse emissor real | Receiver armazena arquivo/recibo genérico, sem dispositivo/policy/execução/artifact; o cliente precisa ser compatível com o FTP oferecido | Pode receber arquivos, mas não homologa backup de equipamento |
| Cadeia `file_server` → device → policy → backup artifact | NÃO APLICÁVEL | `file_server` é independente de equipamento; essa cadeia não faz parte do seu contrato | Não usar como atalho para declarar suporte |
| Homologação real de receipt/artifact/download/hash/histórico de backup FTP router/switch no código atual | NÃO APLICÁVEL enquanto o fluxo não existir | Gate rejeita o dispositivo antes de criar execução/artifact; pode haver recibo de quarentena, sem backup | Não disparar upload de campo para tentar homologar suporte ausente |

### 1. Mapeamento da V2: do cadastro ao artifact

| Camada | Implementação inspecionada | Comportamento efetivo |
| --- | --- | --- |
| Conta FTP | `FtpAdminController::store()` e `FtpAccountManager::create()` | Permitem conta `backup` vinculada a dispositivo existente; só preparam automaticamente policy para Huawei OLT. `file_server` não pode ter `device_id`. |
| Policy isolada | `BackupPolicyController::validated()` e `BackupPolicy::METHODS` | `ftp_push` pode ser cadastrado como método de uma policy, mas exige `config` e `manual`. Isso não demonstra suporte a nenhum dispositivo. |
| Associação device/policy | `DeviceBackupPolicyController::store()` | Exige vendor Huawei, plataforma OLT, conta FTP ativa e modo config; credencial SSH não é usada. Rejeita router/switch. Alteração de policy com associações incompatíveis também é bloqueada por `BackupPolicyController::update()`. |
| Execução manual | `BackupExecution::createManual()` | Exige Huawei OLT/conta ativa para `ftp_push`, mesmo se uma associação tiver sido inserida por fora da UI. |
| Preparação operacional | `HuaweiFtpBackupPolicy::ensure()` | Exige OLT Huawei ativa; seleciona/cria associação compatível. `active()` escolhe a primeira associação ativa compatível por ID, não por nome do arquivo enviado. |
| Despacho manual Python | `engine/registry_setup.py` e `backup_engine.execute()` | Registry contém apenas `huawei/olt/ftp_push` para FTP de backup. MikroTik/Huawei `network` têm drivers `ssh_pull`. |
| Upload espontâneo | `engine/ftp_spontaneous.py` e `EngineJobService::receiveFtp()` | Scanner encontra arquivo no home da conta; PHP verifica conta/device/policy. Dispositivo diferente de Huawei OLT ativo retorna `unsupported_device` antes de criar execução. |
| Elegibilidade e conclusão | `EngineJobService::job()` e `complete()` | Revalidam a combinação Huawei/OLT/FTP, config e disponibilidade da conta; não permitem promover upload `network` a artifact por contornar a UI. |
| Receipt e histórico | `ftp:receipt` em `app/routes/console.php`, `FtpAdminController::show()` | Receipt guarda account ID, claim token, nome original, status, tamanho/hash/path/erro. Históricos de conta mostram recibos; `stored` de um arquivo genérico não significa backup artifact. |
| Download | `BackupArtifactController::download()` e `ArtifactStorage::verify()` | Existe para backup artifacts aceitos; valida path/tamanho/hash e RBAC. Não há download web para arquivo genérico `ftp_received_files`. |

**Correlação no caminho suportado:** home/chroot identificado pela conta → `device_id` da conta → conta única desse dispositivo no PHP → associação ativa com policy compatível → execução com `ftp_account_id`, `ftp_claim_token`, `device_id` e `backup_policy_id` → artifact ligado à execução/device/policy. O scanner mantém account ID e token no sidecar; o recibo usa esse account ID/token e o path publicado. Receipt e execução compartilham o token no recebimento espontâneo; o artifact se liga à execução, não há FK direta do receipt para o artifact. Reutilizar um token com dispositivo/nome incompatível é rejeitado. A origem vem da conta/home, não de um vendor inferido do conteúdo nem de identidade autenticada por IP.

No teste manual, `bm-exec-<ID>.cfg` é reservado para a execução daquele dispositivo/home; arquivos tardios/sem correlação vão para quarentena. Upload espontâneo aceita nomes seguros arbitrários, preserva `received_filename` como `original_filename` e publica path próprio da execução, evitando colisão. Para uma conta `backup` MikroTik provisionada, o caminho espontâneo retorna `unsupported_device`; o scanner coloca o arquivo em quarentena e pode registrar recibo `quarantined`, sem execução/artifact. Não se deve testar esse caminho em equipamento real como se fosse funcional.

**Analysis/storage:** `validate_received_file_integrity()` verifica arquivo regular, tamanho, inode, ausência de links e SHA-256 sem pressupor texto Huawei; `store(..., 'ftp')` publica bytes com limite FTP e segurança de path. Essa parte física pode ser reutilizada. Já `ftp_spontaneous.process()` e `ftp_incoming.receive()` chamam literalmente `analyze_content(data, 'huawei_olt')`; o driver FTP registrado é `HuaweiOltFtpReceivedDriver`. Hoje isso acompanha a restrição de entrada para Huawei OLT, portanto não transforma indevidamente um router aceito em OLT: routers não são aceitos. Ampliar apenas o gate deixaria a análise/log do Python incorretamente direcionados à OLT. O PHP já analisa por vendor/platform reais e usa análise informativa; não exige marcadores Huawei para guardar bytes íntegros. `relativePath()` também decide extensão por vendor (`huawei` → `.cfg`, demais → `.rsc`), não por um contrato geral de formato FTP; isso não cobre `.backup`/`both`.

### 2. Consulta à V1 e aprendizados a preservar

**RouterOS via FTP existia na V1:** `mikrotik_ftp_service.py` implementa integração RouterOS 6/7 com `backup`, `rsc`, `both`; `mikrotik_ftp_scripts.py` gera scripts/identidade/namespace; `mikrotik_ftp_uploads.py` correlaciona e valida os tipos; `ftp_importer.py` os promove a backups/artifacts. Isso comprova implementação na V1, sem atribuir nova homologação de campo à V2. Um switch com RouterOS segue esse contrato RouterOS; não implica suporte a qualquer switch/SwitchOS.

**A V1 também tinha importação FTP genérica vinculada a equipamento:** `ftp.create_account()` não impõe vendor ao criar conta de backup; sem integração MikroTik ativa, `begin_validation()` devolve `None` e o importer pode seguir para `ftp_storage.store_backup()` com o `equipment_id` da conta. O arquivo recebe hash, registro `source_method=ftp` e vínculo ao equipamento. Com integração MikroTik ativa, arquivos não gerenciados podem ficar preservados em incoming, sem virar backup. Esse caminho genérico não é prova de comandos, parser ou homologação específica de Huawei VRP/outras famílias via FTP. Não foi encontrado um fluxo específico de router/switch Huawei FTP nesses componentes consultados.

| Aprendizado da V1 | Evidência | Aplicação à V2/evolução |
| --- | --- | --- |
| Conta identifica equipamento; nome sozinho não basta | `ftp_importer.py`, `ftp_correlation.py`, `mikrotik_ftp_uploads.begin_validation()` | Manter vínculo por conta/home; validar device/vendor/platform reais antes de escolher policy/análise. |
| Filename carrega identidade de operação/formato quando há integração gerenciada | Regex de nomes reais/curtos/teste, namespace/run ID, tipos `.backup`/`.rsc` em `mikrotik_ftp_uploads.py` | Preservar nome original como evidência; não copiar o contrato OLT `bm-exec-*.cfg` para RouterOS sem definir correlação. |
| Estabilidade não pode depender só do tamanho | `ftp_pipeline.discover()`/`stabilization()` filtram temporários e acompanham tamanho/mtime | A V2 já ignora `.part/.tmp/.partial/.filepart/.upload` e exige identidade/mtime estáveis; preservar. |
| Retry da mesma operação difere de um novo backup com conteúdo igual | `artifact_duplicate_kind()` distingue `idempotent`, `conflict`, `new`; espera de par tem prazo | A V2 já tem token/sidecar duráveis e recuperação de publicação/conclusão, mas não modela par RouterOS. Definir idempotência por operação, conflito de hash, timeout e retry sem duplicar artifacts. |
| Formato/vendor devem ser explícitos | Validador RouterOSExport/RouterOSBinary, integração reconhecida por chaves de driver/vendor, versão 6/7 | Não reutilizar análise Huawei OLT para outro vendor; storage íntegro e análise informativa continuam separados. |

Preservam-se esses conceitos, sem portar tabelas, operações agregadas, scripts ou arquitetura da V1 nesta fase.

### 3. Verificação controlada específica desta investigação

Foram executados três testes existentes, em contêiner sem rede, SQLite em memória e arquivos temporários: **3 testes / 14 assertions, OK**.

- `HuaweiOltFtpTest::test_backup_account_on_mikrotik_is_not_processed_as_huawei`: conta provisionada + upload MikroTik retorna `unsupported_device`, account ID correto e zero execuções.
- `HuaweiOltFtpTest::test_ftp_requires_olt_account_and_no_ssh_credential`: associação exige conta; job perde elegibilidade quando plataforma muda para `network`.
- `FtpAdminTest::test_provisioned_router_account_does_not_claim_backup_support`: conta MikroTik cadastrada/provisionada não anuncia backup pronto nem gera execução/artifact.

São testes de gates/UX com dados sintéticos, não repetição de coletas já homologadas nem homologação de FTP router/switch. Nenhum upload de campo, alteração de policy/conta operacional ou novo driver foi executado.

### 4. O que falta e decisão V2 versus V2.1

Faltam, no mínimo: contrato explícito de vendor/platform/formato FTP; associação e elegibilidade `network/ftp_push` no Laravel; recebimento/conclusão com correlação account→device→policy→artifact para esse caso; driver/capability registrados e análise/log por vendor/platform; filename/path compatíveis com o formato; cobertura de retry, duplicidade, erro e download/RBAC. Para `.backup`/`both`, faltam também tipos de artifact, validação e lifecycle de múltiplos arquivos por execução.

Um MVP de export textual MikroTik poderia reutilizar daemon, chroot, estabilidade, storage, recibos e download de artifacts. Ainda assim, precisa mudar gates em vários pontos e o contrato Python/Laravel; **não é um ajuste pequeno de bug/UX nem apenas habilitar uma opção**. Decisão desta investigação: **manter FTP Push router/switch em V2.1**, inclusive Huawei VRP, sem implementar nesta fase. Não houve evidência de suporte existente que justificasse homologação real agora.

Quando o suporte existir, preparar um caso com device ID/vendor/modelo/firmware, conta e policy compatíveis, formato/filename/correlação, janela, arquivo esperado e comportamento de erro. Apresentar o caso concreto e aguardar confirmação explícita antes de qualquer comando/upload no equipamento. O aceite deverá conferir receipt/account/device/policy, execução/artifact, SHA-256 na origem/armazenamento/download autenticado, histórico e erro controlado. Hoje esse roteiro é um critério futuro, não uma execução preparada contra equipamento real.

## Correções e evidências

1. **Nome do download:** antes, `config.cfg` virava `config-cfg.cfg`; agora vira `config.cfg`. `original_filename` continua metadado, nunca caminho usado para leitura. Extensão vem do path verificado. Stem vazio usa `artifact-<ID>`.
2. **Prontidão FTP incorreta:** conta vinculada a router/switch provisionada já podia aparecer pronta para backup. Agora apenas OLT Huawei com condições satisfeitas ou `file_server` provisionado aparece pronto. Cadastro de contas existentes continua permitido; ele não cria suporte. Teste com conta MikroTik realmente marcada como provisionada não gera execução/artifact nem mensagem de prontidão.
3. **Dois nomes:** `name` é o nome principal obrigatório; `hostname` é referência técnica opcional e não participa da conexão. A listagem mostra nome em destaque e, na linha secundária, hostname somente quando diferente, junto ao IP. Comparação ignora caixa/espaços; busca continua aceitando o identificador técnico. Hostname igual ao nome não foi normalizado para NULL: isso perderia o identificador ao renomear o equipamento. Valores existentes/schema foram preservados.
4. **Inspeção FTP sem permissão:** teste antes da correção falhou porque um operator gerou evento `ftp.physical.request`. Depois, operator/viewer/auditor podem ler o detalhe sem acionar o trabalho privilegiado; admin pode solicitá-lo. GET ainda serve ao preview administrativo existente, sem redesenhar fluxo nesta etapa.

Arquivos alterados: controllers `BackupArtifactController` e `FtpAdminController`; views `devices/_form`, `devices/index`, `ftp/index`, `ftp/show`; testes `ArtifactDeletionTest`, `FtpAdminTest`, `RbacTest` e integração PureDB. Pint aplicado aos PHP alterados; `git diff --check` passou.

Atualização funcional/UX de equipamentos: formulário compartilhado de criação/edição reordenado em `Nome | IP`, `Site/POP | Tipo`, `Fabricante | Modelo`, `Hostname técnico opcional | Versão/OS`, observações em largura total; inputs/selects têm altura 44px e largura integral da coluna, com uma coluna em telas pequenas. `SSH Host Key` virou accordion nativo recolhido “Segurança SSH”, com resumo de confiança/alteração e detalhes técnicos apenas ao expandir. Corrigido o vazamento do último `$device` da listagem para o modal de criação, que podia preencher dados antigos e gerar PUT. Verificação final: **81 testes / 858 assertions, OK**, cobrindo DeviceTest, EngineJobTest, HuaweiOltFtpTest e RbacTest; handler JavaScript existente de busca passou em seis cenários com DOM simulado. Não houve inspeção visual em navegador nem conexão a equipamento.

### Execuções da validação inicial

Os resultados abaixo são evidências preservadas da execução inicial. A atualização do status de campo decorre da confirmação do usuário; não repetiu os três fluxos nem reexecutou suas suítes.

- **Laravel final:** 368 testes, 2260 assertions, sem falhas ou skips, PHP 8.4.25/Laravel 13.33.0/PHPUnit 12.5.35. Banco SQLite `:memory:`, rede desligada, repositório somente leitura, storage/cache em tmpfs. Cobertura inclui policies, scheduler, retention, retries, OLT, FTP, exclusões sintéticas e RBAC.
- **Engine:** 103 testes, OK (`PYTHONPATH=engine python3 -m unittest discover -s engine/tests`). Transportes SSH mockados; fixtures Huawei; arquivos temporários reais. Não comprova uma sessão SSH em equipamento.
- **FTP admin:** 16 testes, OK (`python3 -m unittest discover -s docker/ftp -p test_admin.py`). Reconciliação/comandos PureDB usam mocks nesses testes.
- **Pure-FTPd/PureDB real isolado:** login, upload, preservação no reenvio, chroot entre contas, limite de bytes, rotação com rejeição da senha antiga, desativação com rejeição de login e reativação com upload. Resultado: `synthetic_puredb_login_chroot_rotation_disable_reactivate_ok`. Daemon em loopback de contêiner `--network none`, porta 2121, passive range 31000–31009, homes/PureDB em `/tmp`; sem montagem de volumes operacionais.
- **HTTP do Nginx local:** `/login` retornou 200; download do artifact 25 sem autenticação retornou 302. Não foi feito login em contas reais ou inspeção visual automatizada.
- **Artifacts existentes:** últimos 20 IDs (25 até 6), todos `available` e resultado `valid` de `ArtifactStorage::verify()`, sob UID `nobody`, usado pelo PHP-FPM. Incluem MikroTik `network`, Huawei `network` e Huawei `olt`, origens manual/scheduler/ftp_received. Leitura verifica confinamento, inode, size e SHA-256; conteúdo/configuração não foi publicado. Histórico não prova modelo/firmware nem nova coleta nesta etapa.

Comando reproduzível da suíte Laravel isolada, sem usar banco ou volumes de produção:

```bash
docker run --rm --network none --read-only --tmpfs /tmp \
  --tmpfs /workspace/app/storage --tmpfs /workspace/app/bootstrap/cache \
  -v /opt/backup-manager-v2:/workspace:ro -w /workspace/app \
  -e VIEW_COMPILED_PATH=/tmp \
  -e ENGINE_TEST_PYTHON_BIN=/opt/engine-venv/bin/python \
  backup-manager-v2-app vendor/bin/phpunit --do-not-cache-result
```

Comando do laboratório FTP real isolado:

```bash
docker run --rm --network none --read-only --tmpfs /tmp \
  --cap-add SYS_NICE --cap-add DAC_READ_SEARCH \
  -v /opt/backup-manager-v2/docker/ftp:/tests:ro \
  -e PUREDB_TEST_PASSIVE_PORTS=31000:31009 \
  backup-manager-v2-ftp python3 /tests/test_purepw_integration.py
```

Ocorrências de ambiente resolvidas: execução inicial no contêiner `app` não montava o diretório irmão `engine`, causando quatro erros de fixture e um skip de integração Python; discovery Python sem `PYTHONPATH` não importava dois módulos; tmpfs sem `VIEW_COMPILED_PATH` não tinha diretório Blade; Pure-FTPd precisava das capacidades já usadas no compose. A execução final corrigiu os ambientes e passou. Não foram tratadas como bugs de produto.

### Observação operacional somente leitura

Em 2026-09-28 19:15 BRT, `engine:diagnose --json` informou banco, Redis, engine, três drivers, scheduler, storage e recepção FTP saudáveis; backlog/stale/retry em zero. Nas últimas 24h havia quatro execuções `succeeded`, zero `failed`, dois recibos FTP armazenados e zero em quarentena. Worker estava ocioso e retenção “never_ran”, portanto status agregado `unknown`; isso não prova falha de transporte ou homologação completa.

`schedule:list` no scheduler registrou `backups:schedule` e `engine:recover-stale` a cada minuto. Não havia evento de retention registrado nessa consulta. Não foram executados `schedule:run`, `backups:schedule` ou `backups:retention --apply` na instância operacional. O scheduler preexistente continuou funcionando; esta etapa não o habilitou nem reconfigurou.

## RBAC esperado e exercitado

| Ação | Admin | Operator | Viewer | Auditor |
| --- | --- | --- | --- | --- |
| Ler equipamentos/FTP/policies/execuções/artifacts | Sim | Sim | Sim | Sim |
| Cadastrar/editar equipamento, conta FTP e policy; executar backup | Sim | Sim | Não | Não |
| Rotacionar FTP; desativar/reativar FTP | Sim | Sim | Não | Não |
| Baixar backup artifact | Sim | Sim | Sim | Não |
| Solicitar inspeção privilegiada para excluir FTP | Sim | Não | Não | Não |
| Excluir conta FTP/artifact/equipamento/policy | Sim | Não | Não | Não |
| Consultar auditoria | Sim | Não | Não | Sim |
| Baixar arquivo genérico FTP pela web | Sem implementação | Sem implementação | Sem implementação | Sem implementação |

A autorização é no backend, independentemente de ocultar botões. Todos os papéis foram exercitados nas rotas de rotação/status FTP e inspeção; download permite admin/operator/viewer e bloqueia auditor. Suítes existentes complementam as demais ações. Não há escopo por site/tenant; não se promete isolamento entre equipamentos por usuário além da matriz atual.

## Pendências reais atualizadas antes do release

As três coletas homologadas saem da lista de pendências. Não se solicita nova coleta, upload OLT ou teste de falha nesses equipamentos para repetir evidência já aceita. O caso de scheduler é uma validação própria, limitada ao disparo autorizado.

1. **Disparo real controlado pelo scheduler:** definir equipamento SSH, policy/associação e janela, com autorização por caso. Confirmar uma ocorrência pelo scheduler, origem `scheduler`, conclusão pelo engine e artifact, sem disparar todas as policies nem executar `schedule:run` indiscriminadamente. Tick saudável/histórico existente não substituem esse caso. O scheduler FTP OLT continua não implementado.
2. **Download autenticado de artifact pela interface web:** usar artifact já existente de um fluxo homologado, abrir detalhe e acionar o botão em sessão autorizada; conferir nome, bytes e SHA-256. Não depende de nova coleta. Backend e casos de arquivo ausente/path safety já têm testes controlados; download no navegador continua pendente. Matriz atual: admin/operator/viewer podem baixar; auditor não.
3. **UX de cadastro e listagem de equipamento:** ajustes funcionais e regressões concluídos: nome principal, hostname distinto junto ao IP, grid solicitado, Segurança SSH recolhida e modal de criação independente do último registro listado. Criação/edição/legado/busca validados em ambiente controlado. Resta conferência visual no navegador; valores existentes e schema preservados.
4. **Lifecycle FTP somente nos pontos ainda não homologados:** preservar a conta/policy e o recebimento OLT já homologados. Revisar as lacunas administrativas identificadas abaixo; nenhuma rotação/desativação/exclusão de conta operacional está autorizada por esta atualização. Usar contas descartáveis e autorização específica para ações operacionais. Não repetir login/upload da OLT para revalidar a coleta.
5. **Confirmar limites de suporte:** Huawei VRP/network FTP espontâneo está implementado; MikroTik FTP continua futuro. Download web de arquivos FTP genéricos também não existe; `file_server` recebe arquivos/recibos, sem gerar backup artifact.

Retention e RBAC conservam as evidências controladas e limites registrados na tabela; não são justificativa para reabrir as três coletas ou ampliar o escopo operacional desta atualização. Retention operacional não foi aplicada.

### Revisão do lifecycle FTP restante

| Ponto | Evidência já aceita | Lacuna restante | Limite da ação |
| --- | --- | --- | --- |
| Conta/policy aptas e recebimento OLT | Fluxo Huawei OLT FTP HOMOLOGADO REAL | Nenhuma nova validação de coleta | Não recriar conta/policy nem reenviar backup |
| Criação administrativa de conta | HTTP controlado e PureDB em laboratório; conta operacional existente no fluxo OLT | Variante web → reconciliador ainda não individualizada pela confirmação | Só revisar/testar variante realmente pendente com conta descartável |
| Rotação de senha | HTTP/RBAC controlados; senha antiga rejeitada e nova aceita no laboratório PureDB | Integração web → reconciliador ainda não registrada como homologada real | Não rotacionar senha da OLT homologada; escolher conta de teste |
| Desativação/reativação | HTTP/RBAC e laboratório com novo login/upload após reativação | Integração da UI ao reconciliador; sessões previamente abertas não certificadas | Não interromper emissor homologado; conta de teste |
| Excluir só conta | Testes preservam arquivos/recibos/backups; revogação PureDB exercitada no laboratório | Cadeia completa de exclusão administrativa/privilegiada | Não excluir conta real; pendente ambiente descartável autorizado |
| Excluir com dados (`ftp_data`/`all`) | Escopos, confirmação, bloqueios e path safety testados com arquivos temporários | Cadeia web → revogação → remoção física privilegiada | Não apagar dados operacionais; sem execução nesta atualização |
| Histórico de arquivos | Recibos existentes e testes de apresentação | Conferência visual do histórico; detalhe limitado aos últimos 10 | Paginação/histórico completo é V2.1, sem implementação agora |

Nesta atualização foram relidos `FtpAccountManager`, `FtpAccountDeletionService`, `EngineJobService`, registry, rotas e templates de equipamento. Rotação/status invalidam o provisionamento e aguardam reconciliador; exclusão exige confirmação, bloqueios e revogação; hostname não é usado para conexão. Não foi identificado novo bug funcional nesses pontos na revisão somente leitura. Isso não marca as integrações administrativas restantes como homologadas reais.

Para concluir cada pendência operacional, registrar data, autorização, IDs relevantes, resultado e tamanho/hash quando aplicável. A confirmação dos três fluxos já é evidência aceita; não exigir sua repetição para obter metadados ausentes. Não armazenar senhas ou configuração sensível no relatório.

## Escopo proposto para V2.1 — FUTURO

- Integração FTP Push própria para MikroTik router/switch, considerando as lições da V1: scripts, formato, correlação e lifecycle; demais vendors exigem levantamento específico. Não basta adicionar opção de método.
- Download web de arquivos genéricos FTP com autorização definida, vinculação de recibo/conta, confinamento, verificação de size/hash e respostas para ausente/arquivo alterado.
- Histórico FTP completo com paginação/filtros; política explícita de retenção de arquivos genéricos, incoming e quarentena.
- Avaliar FTPS, automação no emissor e credenciais/sessões abertas conforme necessidade operacional. Nenhum desses recursos foi habilitado ou homologado aqui.

**HOMOLOGADO REAL:** Huawei OLT via FTP, Huawei VRP/router-switch via SSH e recebimentos Huawei VRP FTP #101 e #103 confirmados pelo painel/execuções em 30/09. MikroTik SSH também permanece homologado. **Ainda falta:** disparo real controlado pelo scheduler, download autenticado pela interface e conferência visual da UX. MikroTik FTP continua futuro. **v2.0.0 permanece sem marcação; sem push.**
