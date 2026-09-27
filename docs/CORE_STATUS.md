# Estado do core — Backup Manager V2

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

- **P1** — Execução recebida via FTP (`origin=ftp_received`) que precisa de
  retry (engine caiu entre o recebimento e a conclusão) é reclamada por
  `claim()` sem filtro de origem, cai em `UNSUPPORTED_POLICY` no
  `backup_engine.py::execute()` (que não sabe lidar com esse fluxo fora do
  caminho de recebimento direto), e o upload originalmente válido acaba
  quarentenado. Precisa de um guard explícito para não reclamar execuções
  `ftp_received` pelo caminho de claim genérico.
- **P2** — Arquivo já gravado por `store()` fica órfão (sem `BackupArtifact`)
  se `engine:complete` falhar por timeout/erro depois do `store()` ter
  sucesso, tanto no fluxo manual quanto no espontâneo de FTP. Retention não
  limpa esses órfãos hoje.
- **P2** — Sidecar de metadata FTP corrompido/imparseável retry para sempre
  a cada ciclo de scan (5s) sem nunca contar para o teto de
  `MAX_PROCESSING_RETRIES` — o mesmo caso que o teto foi criado para evitar,
  só que pela porta dos fundos.
- **P2** — `FtpAccountDeletionService::assertIdle()` não considera
  `retry_wait` como "execução ainda viva" — uma conta FTP pode ser
  desativada/apagada enquanto uma execução dela ainda pode ser reclamada.
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
- **P2** — Permissão `backup_artifacts.download` existe e é concedida a três
  papéis, mas não existe rota/ação que efetivamente baixe um artifact.
- **P2** — `InstanceTimezone::get()` roda uma query a cada chamada, sem
  memoização por request — múltiplas dezenas de queries extras por página em
  listagens com datas formatadas por linha (execuções, auditoria, artifacts,
  usuários).
- **P2** — Gráfico do dashboard roda uma query por dia (até 31) carregando
  linhas inteiras para contar status em PHP; uma única `GROUP BY` resolveria.
- **P2** — Reset de senha por admin não invalida sessões/`remember_token`
  existentes do usuário afetado.
- **P2** — Índice CHECK constraints (`ftp_accounts`, `ftp_received_files`)
  só existem no PostgreSQL; a suíte de testes roda inteiramente em SQLite, que
  os ignora silenciosamente — uma regressão nessas regras não seria pega por
  `php artisan test`, só na homologação manual contra o Postgres real.

## FUTURO (fora de escopo desta fase, não descartado)

- **Relatórios/exportação** (equipamentos com falha/continuidade, storage,
  FTP, export CSV/XLSX/PDF) — identificado na exploração do V1 como a maior
  lacuna funcional real; o usuário confirmou interesse nesta prioridade.
- **Lixeira com prazo de graça** na retenção (soft-delete + restauração
  self-service) — dívida documentada desde o ENGINE-1, reavaliada nesta fase
  e mantida como futura (retenção atual já é TOCTOU-safe e auditada; falta a
  UX de recuperação).
- **Metadata de versão RouterOS/VRP** no `analyze()` — extraível do próprio
  `/export`/`display current-configuration` já coletado, sem comando SSH
  extra; não implementado ainda porque `analyze()` não é chamado no caminho
  SSH em produção hoje (só nos testes e no caminho FTP).
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

## Antes do polimento de UI

Com os P0/P1 desta fase corrigidos, não há bloqueio técnico remanescente
para seguir para o polimento de UI. Os itens **P1 pendente não bloqueante**
acima (principalmente o guard de `ftp_received`/`retry_wait` no claim) valem
a pena fechar antes ou logo depois do polimento, mas não impedem começá-lo.
