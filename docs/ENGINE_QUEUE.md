# ENGINE-2 — Fila robusta, retry, timeout, stale recovery e cancelamento

Este documento descreve o ciclo de vida operacional de uma `BackupExecution`
depois do ENGINE-2. Ele complementa `docs/ENGINE_DRIVERS.md` (que descreve o
contrato dos drivers Python) — aqui o foco é *quem decide o que roda, quando,
e o que acontece quando algo dá errado*.

## Fonte de verdade

**PostgreSQL é a única fonte de verdade para o ciclo de vida.** Status,
tentativas, heartbeat, claim, agendamento de retry e cancelamento vivem todos
em `backup_executions`. O Python engine não guarda estado próprio entre
execuções — a cada iteração do loop principal ele pergunta ao Laravel
(`engine:claim`) o que fazer.

**Redis não participa do ciclo de vida.** O mecanismo de claim exclusivo já é
resolvido inteiramente pelo Postgres (`SELECT ... FOR UPDATE SKIP LOCKED` +
`UPDATE ... WHERE status = ...`), que é atômico e não depende de um lock
externo. Introduzir Redis aqui adicionaria uma segunda fonte de estado a
manter consistente sem resolver nenhum problema que o Postgres já não resolva
sozinho nesta arquitetura (processo único de engine, sem múltiplos workers
concorrentes disputando um lock de alto throughput). Ver "Comparação com V1"
em `docs/ENGINE_DRIVERS.md` — o próprio V1 chegou à mesma conclusão para o seu
equivalente (lock table em SQLite), mesmo sem ter Redis disponível.

## Modelo de estados

```
pending -> queued -> running -> succeeded
                             -> failed
                             -> timed_out
                             -> cancelled (via cancelAck, cooperativo)
failed | timed_out -> retry_wait -> running (novo attempt)
pending | queued | retry_wait -> cancelled (imediato)
```

`retry_wait` faz o papel do "claimed" mencionado na especificação original: um
job nesse estado está pronto para ser reclamado assim que `next_attempt_at`
for alcançado, exatamente como `queued`. Não foi criado um estado "claimed"
separado porque `claim()` já move `queued`/`retry_wait` diretamente para
`running` numa única transação atômica (não há uma janela intermediária onde
um job está "reclamado mas não rodando").

Transições são validadas em dois lugares:
- `BackupExecution::TRANSITIONS` (o mapa de transições válidas) para o que um
  usuário pode pedir via `transitionTo()` (ex.: `pending -> queued`).
- `running`, `succeeded`, `failed`, `timed_out` e `retry_wait` são reservados
  ao engine — só `EngineJobService` escreve neles diretamente, nunca via
  `transitionTo()`.
- `cancelled` a partir de `running` também é bloqueada em `transitionTo()`: só
  o próprio worker, via `cancelAck()`, pode finalizar um job em execução como
  cancelado (ver "Cancelamento" abaixo).

## Claim exclusivo

`EngineJobService::claim()` (`app/Services/EngineJobService.php`) usa uma
transação com `SELECT ... FOR UPDATE SKIP LOCKED` sobre `queued` e
`retry_wait` (este último filtrado por `next_attempt_at <= now()`), com uma
subquery `whereNotExists` que rejeita qualquer device que já tenha uma
execução `running` — isso é o "lock por equipamento" (nenhum device roda dois
backups simultâneos, independente de origem manual/agendada). O claim grava
`worker_id`, `claimed_at`, `heartbeat_at` e `started_at` na mesma transação.
Um `worker_id` é um token aleatório de 32 hex chars gerado uma vez por
processo do engine Python (`WORKER_ID = secrets.token_hex(16)` em
`backup_engine.py`) — não é PII, apenas um identificador de sessão do worker.

## Heartbeat e lease

Enquanto um job está `running`, o engine Python atualiza `heartbeat_at` a cada
`BACKUP_ENGINE_HEARTBEAT_SECONDS` (padrão 30s) via `engine:heartbeat`. Não há
uma coluna `lease_expires_at` separada — o "lease" é o próprio
`heartbeat_at` mais o threshold `backup.engine_stale_seconds` (padrão 300s),
verificado por `recoverStale()`. Isso é deliberadamente mais simples que uma
coluna de lease dedicada e cobre o mesmo caso de uso (ver "Comparação com V1"
— o V1 nem tinha heartbeat real, apenas a idade de `started_at`).

## Timeout — três camadas, como no V1, mas a última realmente age

1. **Connect/command timeout** — já existiam antes do ENGINE-2, por driver
   (ex.: `paramiko` `timeout=`/`auth_timeout=`/`banner_timeout=` em
   `drivers/mikrotik_ssh.py`). Inalterados.
2. **Heartbeat stale** (`engine_stale_seconds`, padrão 300s) — detecta um
   worker morto/reiniciado: o heartbeat simplesmente parou.
3. **Overall execution timeout** (`engine_execution_timeout_seconds`, padrão
   1800s) — **novo no ENGINE-2**. Detecta um job que continua respondendo
   heartbeat mas está preso além do seu orçamento total (ex.: laço infinito
   num driver, device respondendo devagar demais por um bug específico).
   Diferente do V1 (onde o "timeout geral" só relabelava a linha no banco sem
   nunca de fato interromper o trabalho em andamento — ver "Comparação com
   V1"), aqui a detecção do timeout dispara o mesmo mecanismo de
   retry/terminalização que a detecção de stale, e o cancelamento cooperativo
   (abaixo) dá ao worker Python uma chance real de parar.

Ambas as camadas 2 e 3 rodam dentro de `EngineJobService::recoverStale()`,
chamado a cada minuto pelo scheduler Laravel
(`Schedule::command('engine:recover-stale')`).

## Retry e backoff

`EngineJobService::scheduleRetryOrFail()` é o ponto único usado tanto por
`fail()` (erro reportado pelo worker) quanto por `recoverStale()` (stale ou
timeout detectado externamente). A decisão:

- Se o código é retryable (`EngineJobService::RETRYABLE_CODES` — espelha
  `engine/errors.py::RETRYABLE_CODES`, mais `ENGINE_STALE`/`ENGINE_TIMEOUT`
  que só existem do lado Laravel) **e** `attempt < max_attempts`: o job vai
  para `retry_wait`, `attempt` é incrementado, e `next_attempt_at` recebe o
  backoff (`config('backup.engine_retry_backoff_seconds')`: +60s na 2ª
  tentativa, +300s na 3ª, +900s a partir da 4ª — `engine_max_attempts` padrão
  é 3, então na prática só a primeira janela é usada por padrão).
- Caso contrário, o job vai para o estado terminal (`failed` para
  erro-de-driver/stale exaurido, `timed_out` para timeout-geral exaurido).

Um job com `cancellation_requested_at` preenchido nunca é reagendado para
retry, mesmo que o código reportado seja retryable — ele sempre termina como
`cancelled` (ver abaixo). Isso é verificado tanto em `fail()` quanto em
`recoverStale()`.

**Nota de manutenção**: a lista `EngineJobService::RETRYABLE_CODES` (PHP) e
`errors.RETRYABLE_CODES` (Python) não têm uma fonte única compartilhada — são
mantidas manualmente em sincronia, como já documentado em
`engine/errors.py`. Não existe teste automatizado cross-linguagem que
detecte divergência; um revisor humano precisa notar isso ao adicionar um
novo código de erro em um dos dois lados.

## Cancelamento

Esta é a melhoria mais explícita sobre o V1, que nunca oferecia cancelamento
de um job em execução em nenhum dos seus subsistemas (jobs de backup,
cloud-sync, telegram) — ver "Comparação com V1" em `docs/ENGINE_DRIVERS.md`.

- **`pending` / `queued` / `retry_wait`**: `EngineJobService::requestCancel()`
  cancela na hora — nada está rodando, não há nada para sinalizar.
- **`running`**: só marca `cancellation_requested_at`. O status continua
  `running` até o worker confirmar.
- O worker Python aprende sobre o pedido no próximo `engine:heartbeat`
  (a resposta agora inclui `{"cancel_requested": true}`) e seta um
  `threading.Event` local (`cancelled`, em `backup_engine.py::execute()`).
- **Checkpoint cooperativo**: depois que `driver.backup()` retorna (sucesso ou
  falha), `execute()` verifica `cancelled.is_set()` antes de aceitar o
  resultado — se cancelado, o payload é descartado (mesmo que a coleta tenha
  tecnicamente funcionado) e o engine chama `engine:cancel-ack` em vez de
  `engine:complete`/`engine:fail`.
- **Checkpoint intra-driver (best-effort)**: o driver MikroTik consulta
  `cancel_check()` a cada iteração do seu loop de polling em
  `export_config()` (que já existia para o timeout de 30s) e levanta
  `BackupError('CANCELLED')` assim que percebe o pedido — interrompendo a
  sessão SSH antes mesmo dela terminar de ler o `/export`. O driver Huawei
  VRP recebe o mesmo parâmetro por uniformidade de contrato mas não o
  consulta: sua sessão interativa (`invoke_shell`) não tem um ponto seguro de
  interrupção sem risco de deixar o canal SSH num estado inconsistente —
  nesse caso, o cancelamento só terá efeito real no checkpoint pós-`backup()`
  (ou, se o job demorar demais, pelo overall execution timeout). Isso é uma
  limitação conhecida e documentada, não um bug: cancelamento cooperativo é
  "melhor esforço", nunca uma parada instantânea garantida.
- **`cancelAck(id, workerId)`**: só aceita a transição se o job ainda está
  `running`, pertence ao mesmo `worker_id` que fez o claim, e
  `cancellation_requested_at` está preenchido — mesmo padrão de guarda de
  posse usado por `fail()`/`complete()`.

RBAC: cancelar usa a mesma permissão `backup_executions.run` que já governava
criar/enfileirar uma execução manual — `admin` e `operator` podem, `viewer` e
`auditor` não.

## Stale recovery e recuperação após restart

`recoverStale()` cobre os dois cenários em uma única passada: heartbeat parado
(worker morto/reiniciado) e execução presa além do timeout geral (worker vivo
mas travado). Um job com heartbeat/timeout estourado, mas que também tem
`cancellation_requested_at`, é finalizado como `cancelled` em vez de
retry/fail — evita reviver um job que o operador já queria parar.

**Restart do host/containers**: não existe lógica especial de "boot" — o
scheduler do Laravel roda `engine:recover-stale` a cada minuto
independentemente de quem reiniciou o quê, então qualquer execução
`running` órfã (worker morto, container do engine reiniciado, host
reiniciado) é reclamada dentro de, no máximo, `engine_stale_seconds` +
1 minuto. Isso é intencional: um sweep único e consistente para todo tipo de
interrupção, em vez de lógica dedicada por tipo de reinício (ver
"Comparação com V1" — o V1 tinha esse comportamento consistente só para jobs
de backup, mas não para uploads FTP presos em `processing`; aqui a mesma
política agora cobre ambos, ver seção "FTP" abaixo).

Idempotência: `complete()` já rejeitava reprocessar uma execução `succeeded`
ou sem `status = running` (nada mudou aqui); a proteção contra artifact
duplicado quando um retry é bem-sucedido depois de uma tentativa anterior
falhar continua vindo do storage primitive (`store()`), que usa
`-exec-{id}.ext` para desambiguar tentativas com conteúdo diferente e rejeita
uma reescrita idêntica sob o mesmo `execution_id`.

## Concorrência por device e globalmente

- **Por device**: a subquery `whereNotExists` em `claim()` garante que nenhum
  device tenha duas execuções `running` simultâneas.
- **Duplicata na criação, não só no claim** (lição do V1 — ver
  `docs/ENGINE_DRIVERS.md`): `BackupExecution::createManual()` e
  `BackupScheduler::run()` agora rejeitam/pulam a criação de uma nova
  execução se o device já tem uma em `BackupExecution::LIVE_STATUSES`
  (`pending`, `queued`, `running`, `retry_wait`). Isso é best-effort (não há
  um índice único parcial por device no Postgres cobrindo isso) — a garantia
  forte contra corrida continua sendo o `whereNotExists` de `claim()`; este
  guard só evita acumular pedidos redundantes antes mesmo de chegar lá.
- **Global**: o engine Python processa até 4 jobs simultâneos
  (`ThreadPoolExecutor(max_workers=4)`, inalterado desde ENGINE-1). Não foi
  adicionado um `ENGINE_MAX_CONCURRENT_JOBS` configurável nesta fase — o
  valor fixo já é suficiente para o volume atual e pode virar variável de
  ambiente numa fase futura sem mudança estrutural.

## Scheduler

`BackupScheduler::run()` continua com o índice único
`(device_backup_policy_id, scheduled_for)` (ENGINE-1) para nunca duplicar a
mesma janela agendada, e agora também pula uma associação cujo device já está
"ocupado" (ver acima) antes de tentar o insert.

## FTP espontâneo (upload passivo)

O fluxo de estabilização/claim/correlação de `engine/ftp_spontaneous.py` já
era robusto antes do ENGINE-2 (identidade `(dev, ino, size, mtime, nlink,
mode)` para detectar "ainda subindo" vs. "estável", claim atômico via
metadata-then-rename, reprocessamento automático de qualquer arquivo deixado
em `processing/` a cada ciclo de scan — o que já cobre "recuperação após
restart" para esse fluxo sem precisar de lógica dedicada). A única lacuna
real identificada na comparação com o V1 (que nunca resolveu isso em nenhum
dos seus subsistemas de importação) era: **nada dava desistência** — um
arquivo que nunca consegue ser processado (Laravel fora do ar por dias,
sidecar permanentemente malformado) reagenda para sempre. `remember_failure()`
agora tem um teto (`MAX_PROCESSING_RETRIES = 20` ciclos de scan) e
quarentena com `processing_retry_exhausted` ao esgotar.

## Health

`App\Services\EngineHealth::snapshot()` (exposto via `php artisan engine:health`)
dá uma leitura pontual: contagem por status relevante, quantos `running` estão
com heartbeat velho, idade do pendente mais antigo, última execução
bem-sucedida. Sem UI nesta fase — é a base para um dashboard futuro
("ENGINE-3").

## Auditoria

Eventos gravados em `audit_events` (via `App\Services\AuditEvents`, já
existente desde ADMIN-1): `backup_execution.claimed`, `.retry_scheduled`,
`.recovered`, `.failed`, `.timed_out`, `.succeeded`, `.cancel_requested`,
`.cancelled`. Heartbeat **não** gera evento de auditoria (seria ruído puro —
um evento a cada 30s por job em execução).

## Comandos

Novos/alterados nesta fase:

- `engine:cancel-ack {id} {worker}` — novo; usado pelo worker Python para
  confirmar que parou após um pedido de cancelamento.
- `engine:health` — novo; imprime o snapshot do `EngineHealth` em JSON.
- `engine:heartbeat {id} {worker}` — agora imprime
  `{"cancel_requested": bool}` em vez de nada, para o worker Python saber se
  deve parar.
- `engine:recover-stale` — comportamento interno mudou (retry-then-fail em
  vez de fail direto, mais a checagem de overall timeout), assinatura
  inalterada.

## Comparação com V1

`/opt/backup-manager-local` foi consultado como fonte de conhecimento
operacional (não como código a portar) especificamente sobre lock, retry,
timeout, jobs abandonados, recuperação após restart, concorrência, execução
duplicada, estabilização de upload, scheduler, retenção e limpeza de jobs
presos. Achados relevantes a esta fase:

| Comportamento no V1 | Classificação | Decisão na V2 |
| --- | --- | --- |
| Lock table (`backup_worker_locks`) + `UPDATE ... WHERE status='queued'` como segunda camada de CAS, com TTL do lock deliberadamente maior que o timeout do job | **PRESERVAR CONCEITO** | Já era o padrão da V2 desde ENGINE-1 (`SELECT ... FOR UPDATE SKIP LOCKED` + `UPDATE` atômico) — confirmação de que o mecanismo estava certo. `engine_stale_seconds`/`engine_execution_timeout_seconds` são, por design, o "TTL do lease" — documentado que devem superar a duração realista de um job. |
| Duplo lock por `job_id` **e** `equipment_id` no `process_run`, mais checagem de duplicata em `queue_run()` antes mesmo de tentar o lock | **PRESERVAR CONCEITO** | O guard por device já existia em `claim()` (ENGINE-1: `whereNotExists` de execução `running` no mesmo device). O que faltava — rejeitar a *criação* de uma segunda execução ao vivo para o mesmo device — foi adicionado em `BackupExecution::createManual()` e `BackupScheduler::run()` (`LIVE_STATUSES`). |
| "Commitar antes de abrir a sessão SSH/Telnet" para não segurar lock de escrita do SQLite durante I/O lento — lição explícita de um incidente documentado em comentário | **PRESERVAR CONCEITO — já seguido** | A V2 já faz isso desde ENGINE-1: `claim()` é uma transação curta que termina antes do Python abrir a sessão SSH; nenhuma transação Postgres fica aberta durante I/O de rede. Confirmado, nenhuma mudança necessária. |
| Detecção de stale só por idade de `started_at` (sem heartbeat real) — um job saudável mas lento é indistinguível de um worker morto | **MELHORAR — já feito diferente antes desta fase, mantido** | A V2 já tinha heartbeat real desde ENGINE-1 (`heartbeat_at`, atualizado periodicamente pelo worker). Esta fase adicionou a segunda camada que faltava: o timeout geral (`engine_execution_timeout_seconds`), que pega justamente o caso "heartbeat fresco mas preso" que nem o heartbeat nem o V1 cobriam. |
| Retry automático **inexistente** para o job de backup em si (`trigger_type='retry'` existe no enum mas nunca é usado) — só retry manual pelo operador | **MELHORAR — implementado nesta fase** | ENGINE-2 adiciona retry automático real com backoff (`retry_wait`, `next_attempt_at`, `attempt`/`max_attempts`), usando a classificação `is_retryable()` que já existia desde ENGINE-1 mas não era consultada por nada. |
| "Timeout geral" que apenas relabela a linha no banco — nunca de fato interrompe a conexão/processo em andamento | **DESCARTAR o comportamento, PRESERVAR o modelo de 3 camadas** | O modelo de timeout em camadas (connect/command/overall) é bom e foi mantido; a diferença deliberada é que a detecção do timeout geral aqui alimenta o mesmo mecanismo de retry/terminalização usado pelo stale, e o cancelamento cooperativo dá ao worker uma chance real de parar — não é só uma etiqueta. |
| Retry com backoff exponencial + `retry_after` + histórico de tentativas, mas só para filas de entrega assíncrona (notificações, cloud sync, Telegram) — nunca para o job de backup | **PRESERVAR CONCEITO (o padrão de backoff), aplicado onde faltava** | O formato de backoff (capado, incremental) foi reaproveitado para o job de backup em si — exatamente o lugar onde o V1 não tinha esse padrão. |
| "Lease de processamento" de fila resetada no início do próximo ciclo se presa há mais de N minutos (`notification_queue`), mas essa mesma política **não existe** para uploads FTP presos em `processing` | **MELHORAR — lacuna do V1 fechada nesta fase (lado V2)** | A V2 já reprocessa automaticamente qualquer arquivo deixado em `processing/` a cada ciclo de scan (sem precisar de uma janela de "preso há N minutos" — ela tenta de novo sempre). O que faltava era desistir eventualmente: adicionado `MAX_PROCESSING_RETRIES` com quarentena ao esgotar — a política que o V1 tinha para notificações mas nunca estendeu ao FTP. |
| Cancelamento existe (`cloud_sync.cancel_item`, `telegram_backup.cancel_item`) mas só para itens ainda não iniciados (`queued`/`retry_wait`); jobs de backup em si não têm cancelamento algum, mesmo pré-início | **MELHORAR — implementado nesta fase, incluindo o caso que o V1 nunca cobriu** | ENGINE-2 cobre tanto o caso fácil (pending/queued/retry_wait — cancelamento imediato, equivalente ao que o V1 já fazia em outros subsistemas) quanto o caso que o V1 nunca resolveu em lugar nenhum: cancelar um trabalho **em andamento**, via sinalização cooperativa (heartbeat + checkpoint pós-driver + checkpoint intra-driver no MikroTik). |
| Nenhuma lógica dedicada de "recuperação após restart" — depende inteiramente do sweep genérico de timeout eventualmente notar a linha presa | **PRESERVAR CONCEITO** | A V2 adota a mesma filosofia (um sweep único e genérico, `engine:recover-stale` a cada minuto, cobre qualquer tipo de interrupção) em vez de lógica dedicada por tipo de reinício — validado como a escolha certa, sem necessidade de mudança. |
| Sem paralelismo real entre workers — um processo `oneshot` por tick de timer, processando até 25 jobs em série | **DESCARTAR (limitação, não padrão)** | Já não se aplica — o engine Python roda um `ThreadPoolExecutor(max_workers=4)` desde ENGINE-1, com paralelismo real entre devices diferentes. |
| Duplicação de conteúdo idêntico sob operações diferentes tratada como caso normal (não "duplicata"), com um terceiro estado (`suspicious`) para hash igual em slot diferente | **ADIAR** | Não há hoje um caso de uso reportado para detecção de duplicata por conteúdo (hash) entre execuções distintas na V2; registrado como possível refinamento futuro do storage, não implementado. |

**Resumo:** a maior parte da infraestrutura "difícil" de fila (claim exclusivo,
lock por device, heartbeat, não segurar lock de banco durante I/O lento) já
existia na V2 desde ENGINE-1 e foi só confirmada como correta pela comparação.
As lacunas reais que o V1 também tinha (sem retry automático de job, sem
cancelamento de trabalho em andamento em nenhum subsistema, timeout geral que
não interrompe nada de verdade, upload preso sem teto de tentativas) foram
todas fechadas nesta fase — nenhuma delas era um "resolver diferente do V1",
eram lacunas que os dois sistemas compartilhavam e que o ENGINE-2 existe
justamente para fechar.

## O que ficou fora desta fase (deliberadamente)

- **Restore** — fora de escopo, como instruído.
- **Kill de processo/grupo de processo pelo Laravel** — não existe uma
  relação "Laravel spawna um subprocesso Python por job" nesta arquitetura (o
  engine Python é um processo de longa duração que *chama* o Laravel via
  `php artisan`, nunca o contrário); não há um processo filho por job para o
  Laravel matar. O cancelamento cooperativo (acima) é o equivalente possível
  nesta topologia.
- **`ENGINE_MAX_CONCURRENT_JOBS` configurável** e **partial unique index por
  device** — ambos citados acima como possíveis evoluções futuras, não
  necessários para fechar esta fase.
- **Retry/quarentena com teto configurável por variável de ambiente** para o
  FTP espontâneo — o teto foi adicionado como constante
  (`MAX_PROCESSING_RETRIES`); torná-lo configurável fica para quando houver
  necessidade real de ajustá-lo por ambiente.

## Nota (ENGINE-3)

A camada de observabilidade construída em cima deste lifecycle (health por
check, diagnóstico, `/system/health`) está documentada separadamente em
[docs/ENGINE_HEALTH.md](ENGINE_HEALTH.md) — inclui, entre outras coisas, o
primeiro uso real de Redis no projeto (heartbeat do scheduler) e a correção
de uma regressão encontrada neste documento: `EngineHealth::oldestAgeSeconds()`
retornava um valor com sinal invertido (nunca coberto por teste até então).
