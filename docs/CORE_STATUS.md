# Estado do core — Backup Manager V2

## RELEASE-1.1 — 28/09/2026

**NOT READY para `v2.0.0` enquanto a aplicação real e a custódia externa
obrigatória não tiverem confirmação.** Correções de configuração e testes
estão preparados em [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).

| Blocker | Estado |
| --- | --- |
| Índice ftp_account_id | PENDENTE MANUAL: migration esperada, up/down e --path homologados em PG isolado; real não aplicada |
| Storage PHP-FPM | PENDENTE MANUAL: pool UID 65534/grupo www-data preparado e probe controlado passou, sem mudar artifacts 0700/0600; falta recriação autorizada |
| 8081 público | PENDENTE MANUAL: Compose corrigido para 127.0.0.1; falta recriação do nginx |
| Redirect V1 | PENDENTE MANUAL: candidato para V2 :8443 passou, V1 :443 preservado; confirmar URL e reload do host |
| Headers/cookies/proxy | RESOLVIDO em código/testes; ativação em produção PENDENTE MANUAL |
| Backup pré-release | PENDENTE MANUAL: script preservado e revalidado sinteticamente; gerar/validar dump real só após autorização |
| Custódia APP_KEY/config/volumes | PENDENTE EXTERNO: APP_KEY presente, fingerprint 8f5f636f698c8ac6, cofre/cópias fora do host ainda não comprovados |

Laravel completo: 364 testes em SQLite e 364 em PostgreSQL isolado; Python
103, ftp-admin 16, PureDB, lint/compile/Pint, diff e secret scan passaram.
Testes novos cobrem proxy confiável/não confiável, HTTPS :8443, cookies,
login/logout/CSRF e reversibilidade do índice sem perder dados sintéticos.
Health atual continua UNKNOWN (worker ocioso/retention nunca executada),
scheduler HEALTHY, recovery-check HEALTHY sob root. Não apagar essa ausência
de evidência nem confundir leitura CLI com pool web ainda não ativado.

READY exige aplicação técnica e recheck, backup real validado e confirmação
externa. Nenhum tuning, feature, tag ou push. PÓS-RELEASE/V2.1 mantidos abaixo;
o relatório de RELEASE-1 seguinte é histórico.

## RELEASE-1 — histórico de 28/09/2026

**NOT READY para `v2.0.0` neste deployment.** Core funcional e performance
PERF-1/2/3 fechados; a homologação sintética passou. A liberação depende dos
gates operacionais detalhados em [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).
As seções de STABILIZATION-1 abaixo são histórico; "nenhum bloqueante" nessa
fase não significa produção aprovada hoje.

### READY

- Suítes completas Laravel SQLite/PostgreSQL isolado, Python e ftp-admin;
  RBAC, login/logout, telas/CSV, engine, FTP/PureDB e retention sintéticos.
- RELEASE-1 corrigiu dois bugs reais com regressões: download retornava
  HTTP 500 por tipo incompatível com BinaryFileResponse; scheduler era
  UNKNOWN mesmo com tick Redis recente porque o timestamp vinha como string.
- Produção: APP_ENV=production, APP_DEBUG=false, APP_KEY presente sem
  impressão; DB/Redis/engine/scheduler conectados/ativos. Recovery-check
  HEALTHY sob CLI root. Nenhum novo tuning ou feature.

### WARNINGS / GATES

- Migration legítima de índice ftp_account_id pendente; não aplicada.
- PHP-FPM www-data não consegue acessar backups 0700 de nobody: download
  real permanece bloqueado por permissões, apesar da correção PHP.
- Backend HTTP 8081 público, cookies Secure dependentes do esquema do proxy,
  headers básicos incompletos e redirect de HTTP para V1 (:443), não V2 (:8443).
  URL oficial e adequação da topologia precisam de confirmação operacional.
- Backup pré-release real e custódia externa da APP_KEY não comprovados.
  Script validado somente com dump sintético, manifesto e checksum.
- Health final UNKNOWN: 13 HEALTHY, worker ocioso e retention nunca executada
  UNKNOWN; zero WARNING/CRITICAL nativos. CLI root não comprova acesso web.
- FTP sem TLS, seis containers sem healthcheck Docker, firewall passivo
  mais amplo que o V2 e commit desconhecido no diagnose são warnings.
- Alterações preexistentes preservadas e fora dos commits desta fase;
  worktree sujo. Sem tag e sem push.

### PÓS-RELEASE

Monitoramento operacional, ensaio autorizado de DR em destino independente,
retention somente após revisão de políticas/janela, endurecimento de runtime
e refinamentos visuais. Não criar jobs reais para forçar health HEALTHY.

### V2.1 / FUTURO

Manter a dívida P2 e extensões já descritas abaixo, sem ampliar este release.
Novo tuning somente quando houver gargalo medido em produção.

Referência de "o que está pronto" antes do polimento geral de UI. Produzido
pelo STABILIZATION-1, uma fase de fechamento de core (não de features novas):
mapeamento e correção de bugs, dívida técnica, gaps de segurança/recovery, e
comportamentos do V1 ainda pendentes. Ver o relatório completo da fase para
a auditoria linha a linha; este documento é a síntese que deve ser mantida
atualizada por fases futuras.

## CONCLUÍDO

- **RBAC** (admin/operador/viewer/auditor), permissões centralizadas em
  `Rbac::PERMISSIONS`, Gates gerados automaticamente. Auditoria de rotas
  desta fase confirmou: toda rota mutável (POST/PUT/PATCH/DELETE) está atrás
  de `['auth','active']` **e** de `$this->authorize()` explícito — nenhuma
  brecha encontrada.
- **Ações destrutivas padronizadas** (frase de confirmação, preview de
  impacto, auditoria) para exclusão de artifacts, sites, devices, políticas,
  contas FTP.
- **Ciclo de vida do engine** (ENGINE-1/2): contrato único de drivers, claim
  exclusivo por device (inclusive índice parcial único no Postgres real),
  heartbeat, retry com backoff, timeout geral distinto de heartbeat,
  cancelamento cooperativo, stale recovery. Homologado no PostgreSQL real.
- **Health e diagnóstico** (ENGINE-3): 15 checks uniformes, fail-soft,
  `/system/health`, `engine:health`/`engine:diagnose` (`--json`, exit codes).
  Corrigido nesta fase: bug de sinal em `oldestAgeSeconds()`; falta de
  `up -d` vs `restart` para o engine carregar código novo (documentado em
  UPGRADE.md).
- **FTP**: claim atômico (rename + sidecar fsync), correlação por conta
  dedicada, estabilização por tamanho/mtime, quarentena, teto de tentativas
  de processamento. **Corrigido nesta fase**: faltava filtro de sufixo
  "em progresso" (`.part`/`.tmp`/`.partial`/`.filepart`/`.upload`) — a
  estabilização sozinha não protegia contra um upload que estagna.
- **Retenção**: hard-delete com reverificação de hash/tamanho/inode
  (TOCTOU-safe) antes de apagar, auditado, mais recente sempre protegido.
  **Corrigido nesta fase**: `ArtifactStorage::verify()` dependia do nome
  *atual* do device/site — renomear um equipamento travava a retenção para
  todos os artifacts históricos dele, silenciosamente, para sempre.
- **Auditoria global**: login/logout, central de segurança, trilha
  administrativa completa.
- **Credenciais**: nunca aparecem em `toArray()`/`toJson()`/logs/auditoria;
  travessia Laravel→Python via file descriptor herdado, nunca argv/env/stdout.
  Auditado nesta fase sem achados.
- **Login**: agora com rate limiting (5 tentativas/minuto por email+IP) —
  não existia antes desta fase.
- **`APP_ENV`/`APP_DEBUG` de produção**: corrigidos nesta fase (estavam
  `local`/`true` no ambiente real — qualquer usuário logado via qualquer
  exceção não tratada via stack trace completo, SQL com bindings e config).

## PENDENTE BLOQUEANTE

Nenhum item identificado nesta fase bloqueia chamar o core de "pronto" — os
bloqueantes reais encontrados (APP_DEBUG exposto, retenção quebrando em
rename, sem rate limit de login, upload truncado podendo ser aceito como
sucesso, race de last-admin) foram corrigidos durante esta própria fase.

## PENDENTE NÃO BLOQUEANTE

Ordenado por prioridade (P1 = deveria ser tratado logo; P2 = importante mas
não urgente):

- **P1 resolvido na fase de relatórios** — `claim()` agora exclui
  `origin=ftp_received`, inclusive em `retry_wait`; o receiver FTP é o único
  responsável por retomar esse fluxo. Coberto em `EngineRetryTest`.
- **P2** — Arquivo já gravado por `store()` fica órfão (sem `BackupArtifact`)
  se `engine:complete` falhar por timeout/erro depois do `store()` ter
  sucesso, tanto no fluxo manual quanto no espontâneo de FTP. Retention não
  limpa esses órfãos hoje.
- **P2 resolvido na fase de relatórios** — sidecar FTP inválido é
  quarentenado imediatamente, encerrando o retry sem fim.
- **P2 resolvido na fase de relatórios** — `assertIdle()` inclui `retry_wait`
  e bloqueia exclusão da conta enquanto a execução estiver viva.
- **P2** — Recepção manual de FTP pode colocar em quarentena um upload
  espontâneo legítimo que esteja no mesmo diretório de incoming no mesmo
  instante (leitura concorrente de diretório).
- **P2** — `ArtifactDeletionService` desfaz o `unlink()` antes de o registro
  ser salvo/commitado — uma falha no meio pode deixar o registro
  `available` sem arquivo físico.
- **P2** — Deleções da retenção vão só para `Log::info`, não para
  `audit_events` por artifact (o total agregado por execução já vai, desde
  esta fase). Não há caminho de recuperação (nem manual) para um artifact
  marcado `deleted`/`missing` que na verdade ainda existe.
- **P2 resolvido na fase de relatórios** — a rota/ação de download de
  artifact foi acrescentada e `InstanceTimezone::get()` usa cache por request.
- **P2** — Gráfico do dashboard roda uma query por dia (até 31) carregando
  linhas inteiras para contar status em PHP; uma única `GROUP BY` resolveria.
- **P2** — Reset de senha por admin não invalida sessões/`remember_token`
  existentes do usuário afetado.
- **P2 atualizado em PERF/RELEASE-1** — CHECK constraints (`ftp_accounts`,
  `ftp_received_files`) continuam específicas do PostgreSQL. A suíte completa
  agora também foi homologada em PostgreSQL isolado; o comando padrão
  `php artisan test` segue usando SQLite. Preservar ambas as validações nas
  próximas fases, sem executar testes destrutivos no PostgreSQL de produção.

## FUTURO (fora de escopo desta fase, não descartado)

- **MikroTik FTP Push** — o sistema antigo possui script e agendamento para
  enviar `.rsc` e/ou `.backup`; a v2 usa SSH Pull para MikroTik. A decisão,
  diferenças de formato e trabalho necessário estão em
  [IDEIAS_FUTURAS.md](IDEIAS_FUTURAS.md).
- **Relatórios/exportação** — cinco relatórios e CSV foram entregues nesta
  fase (ver `docs/REPORTS.md`); XLSX/PDF continuam como extensões futuras.
- **Lixeira com prazo de graça** na retenção (soft-delete + restauração
  self-service) — dívida documentada desde o ENGINE-1, reavaliada nesta fase
  e mantida como futura (retenção atual já é TOCTOU-safe e auditada; falta a
  UX de recuperação).
- **Metadata de versão RouterOS/VRP** — versão RouterOS do `/export` agora
  é extraída e auditada sem comando extra; VRP continua futuro.
- **Export/import de configuração da instância** (clonar ambiente, DR de
  config) — identificado na exploração do V1.
- **Notificações**: janela de manutenção e digest programado — identificado
  na exploração do V1; Telegram/e-mail em si continuam fora de escopo.
- **Probe TCP do Pure-FTPd** no health check (hoje só infere pelos
  recebimentos recentes).
- Explicitamente fora de escopo por instrução: restore em equipamento,
  S3/rclone, Telegram, multiempresa, SSO, Kubernetes, HA distribuída,
  operações em bloco, busca global, onboarding automático de equipamentos.

## DÍVIDA TÉCNICA (consciente, documentada, não urgente)

- 39 checagens `Schema::hasTable`/`hasColumn` espalhadas por 14 arquivos —
  guardas de migration que podem ser removidas quando a migration
  correspondente for garantidamente antiga o suficiente.
- Suporte a `home_layout=legacy` em contas FTP e ao formato antigo de path de
  artifact — mantidos por compatibilidade com dados reais existentes.
- Coluna `is_admin` mantida só como ponte de compatibilidade; autorização
  nunca a lê diretamente.
- Cancelamento do driver VRP não é cooperativo mid-comando (limitado pelo
  timeout geral da execução) — documentado, aceito.
- `analyze()` de MikroTik/VRP é heurístico, nunca validado contra hardware
  real de todas as versões.
- Sem tamanho mínimo plausível de saída SSH (V1 tinha `MIN_OUTPUT_BYTES`).
- Tema claro da UI não implementado.
- Mapa `BackupExecution::TRANSITIONS` documenta `failed→retry_wait`, mas o
  código real transiciona `running→retry_wait` diretamente — a documentação
  inline do mapa está desatualizada (comportamento em si está correto e
  protegido por lock+guarda de worker em cada escrita).
- `BackupArtifact::STATUSES` existe mas não é referenciado; strings cruas
  espalhadas em `BackupRetention`/`ArtifactDeletionService`/
  `FtpAccountDeletionService`.
- `ProbeContext`/`probe()` dos drivers SSH leem o segredo de
  `context.metadata['__secret']`, contradizendo o docstring de `contexts.py`
  ("nunca o segredo resolvido") — sem consumidor em produção hoje
  (`probe()` não é chamado por `backup_engine.py::execute()`), risco latente
  caso um `repr()`/log de contexto seja adicionado no futuro sem revisar isso.

## Achado operacional real (não é bug)

Durante a investigação do `engine:health` reportando `CRITICAL`: a taxa de
sucesso (66,7% nas últimas 24h à época da investigação) e o equipamento
`MK-BORDA-01` (10.255.0.253) marcado `critical` em `devices` refletem dados
reais — duas falhas consecutivas de `SSH_CONNECTION_REFUSED` e mais de 48h
sem um backup bem-sucedido. A fórmula de cálculo foi auditada e está correta;
nenhum dado sintético de homologação vazou para o cálculo. Ação recomendada:
verificar conectividade SSH desse equipamento.

## Decisão: FTP push como alternativa em roteadores/switches Huawei

**Contexto.** O método `ftp_push` era restrito a `platform === 'olt'` (Huawei
OLT) em ~12 pontos de código duplicando o mesmo predicado. Firmware VRP
(roteadores/switches Huawei) também suporta push automático de configuração
para um servidor FTP, via:

```
system-view
set save-configuration backup-to-server server <host> transport-type ftp user <user> password <senha>
set save-configuration interval <minutos>
```

(confirmado por dois materiais reais: um slide de treinamento e a
apresentação "Compartilhamento Huawei Múltiplos ISPs", GTER 51/NIC.br —
que mostra o mesmo comando em um NE8000, com o parâmetro extra `delay` no
`interval` e um `path` opcional no `backup-to-server`).

**Comparação com V1** (`V1 | decisão na V2 | justificativa`):

`huawei_vrp`/`huawei_router` eram drivers **SSH-only** no V1 (`ssh.py`
`SSH_DRIVERS`), igual a `mikrotik_routeros`/`cisco_ios`; FTP push só existia
para `huawei_olt_ssh_ftp` | **V2 passa a aceitar SSH pull OU FTP push, à
escolha do operador**, para qualquer equipamento Huawei (rede ou OLT) |
Firmware VRP já suporta push nativo; reaproveitar a infraestrutura de FTP
que já existe para OLT (`Device::isHuaweiFtpEligible()`,
`HuaweiFtpBackupPolicy`, `OltFtpWizard`) evita duplicar código e dá
flexibilidade operacional real sem exigir acesso SSH ao equipamento (útil
quando firewall/ACL bloqueia o Backup Manager, mas o equipamento consegue
falar para fora).

**Limitação real, documentada no próprio wizard**: o comando VRP não tem
equivalente a "enviar agora com este nome de arquivo" (ao contrário do fluxo
manual da OLT via console `ftp set` + `backup configuration`). Por isso, o
passo "Teste de integração" do wizard, para equipamentos de rede, não cria
uma execução aguardando um nome específico — ele observa a próxima execução
`ftp_received` espontânea que chegar após a confirmação
(`OltFtpWizard::snapshot()`), refletindo como o VRP realmente funciona
(push periódico, não sob demanda).

**Outra armadilha documentada na UI**: o `path` opcional do
`backup-to-server` não deve ser usado — a conta FTP já é isolada na própria
pasta do equipamento, e o scanner de recebimento espontâneo
(`engine/ftp_spontaneous.py`) só observa a raiz dessa pasta, não
subdiretórios.

Nenhuma mudança foi necessária no motor Python nem no schema do banco —
`analyze_content()`/`storage.py` já tratam conteúdo não reconhecido como
`status: unknown` (informativo, nunca bloqueia gravação), e não havia
nenhuma constraint de banco restringindo o fluxo a `platform = 'olt'`.

## Decisão: OLT VSOL no mesmo fluxo de FTP push

Mesmo padrão acima, estendido para um segundo vendor de OLT. V1 já suportava
VSOL por dois transportes (`backup_manager/vsol_olt.py`,
`backup_manager/olt_runtime.py`): **SSH** (`vsol_olt_ssh_ftp`, reaproveitando
o `connect_device()`/`run_ssh_plan()` genérico já usado para Huawei OLT) e
**Telnet puro via socket** (`vsol_olt_telnet_cli`, implementação própria sem
`telnetlib`, validada com testes em `tests/test_vsol_telnet.py`) para
modelos sem SSH.

`V1 | decisão na V2 | justificativa`: `vsol_olt_ssh_ftp` (V1, transporte
SSH) | `Device::isHuaweiFtpEligible()` passa a aceitar também
`vendor=vsol && platform=olt`, reaproveitando o wizard/infra de FTP já
existente | Equipamento real do usuário tem acesso SSH; o comando de push
(`write` + `copy startup-config ftp://usuário:senha@host/arquivo`) roda no
mesmo console manual que já usamos para Huawei OLT — nenhum driver Python
novo necessário. `vsol_olt_telnet_cli` (V1, transporte Telnet puro) |
**Não portado nesta fase** | Sem equipamento só-Telnet para homologar agora;
registrado em `docs/IDEIAS_FUTURAS.md` para quando aparecer essa necessidade
concreta — a implementação do V1 já existe e é reaproveitável quando isso
acontecer.

Diferença notável do Huawei: a senha FTP fica embutida na própria linha de
comando VSOL (`ftp://usuário:senha@host/...`), não digitada num prompt
separado como no `ftp set` da Huawei — o wizard avisa sobre isso
explicitamente. Também documentamos uma incerteza real (não confirmada
ainda com hardware): o V1 exigia extensão `.config` no nome do arquivo por
convenção própria do script; não está confirmado se é exigência do firmware
VSOL ou não — o wizard orienta tentar `.config` se `.cfg` for recusado.

## Antes do polimento de UI

Com os P0/P1 desta fase corrigidos, não há bloqueio técnico remanescente
para seguir para o polimento de UI. Os itens **P1 pendente não bloqueante**
acima (principalmente o guard de `ftp_received`/`retry_wait` no claim) valem
a pena fechar antes ou logo depois do polimento, mas não impedem começá-lo.
