# ENGINE-3 — Health, diagnóstico e observabilidade operacional

Camada de observabilidade sobre a fila/lifecycle já construída em ENGINE-1/2.
Responde, sem depender de acesso ao Docker daemon e sem executar nada contra
equipamento real: o engine está vivo? o scheduler está rodando? existe job
preso? há equipamentos falhando? o storage está ok? quando foi o último
sucesso/falha? Não é (ainda) o dashboard visual definitivo — é a base de dados
estruturada que um dashboard futuro (ou uma notificação futura) vai consumir.

## Modelo de status

Um vocabulário único em todo o sistema (`App\Support\HealthStatus`):
`healthy` / `warning` / `critical` / `unknown`. Nunca `ok`/`good`/`error`/
`running` soltos — todo check passa por este enum. `HealthStatus::worst()`
agrega uma lista de status pelo pior deles (ordem de severidade:
`critical` &gt; `warning` &gt; `unknown` &gt; `healthy`); é assim que o
`overall_status` do relatório é calculado a partir dos checks individuais.

`unknown` é usado deliberadamente sempre que não há dados suficientes para
avaliar (nenhuma execução recente, engine nunca reportou, Redis fora do ar) —
nunca forçado para `healthy` só porque nada de ruim foi visto. Um sistema sem
nenhuma execução não é "100% saudável", é "sem dados" (ver `checkFailure()`).

## Fonte de verdade por camada

- **PostgreSQL**: estado de lifecycle (`backup_executions`), FTP
  (`ftp_received_files`), auditoria (`audit_events`) — tudo que precisa
  sobreviver a um restart de qualquer processo.
- **Redis**: usado por exatamente uma coisa nesta fase — o heartbeat do
  scheduler (`health:scheduler:last_tick`, escrito por `backups:schedule` a
  cada execução, lido por `EngineHealth::checkScheduler()`). Isso é
  coordenação transitória de propósito: se Redis cair, o scheduler continua
  funcionando normalmente (o `Cache::store('redis')->put()` está em
  try/catch, nunca falha o agendamento em si) e o check correspondente
  degrada para `unknown`, nunca para um estado incorreto. Nenhum outro check
  depende de Redis.
- **Snapshot de arquivo do engine Python**: ver seção dedicada abaixo — é a
  única forma de Laravel saber algo sobre o processo Python.

## Por que o engine escreve um arquivo em vez de Laravel chamá-lo

Investigado antes de decidir: o container `app` (Laravel/PHP-FPM) não monta
`./engine` nem o virtualenv Python (`compose.yml` — só `engine`/`scheduler`
montam `./engine:/engine:ro`, e só `engine` roda
`/opt/engine-venv/bin/python`). Não existe `python3` utilizável a partir do
container `app`. Portanto Laravel **não pode** executar um comando Python
diretamente — a única forma de saber algo sobre o processo Python é ele
mesmo relatar.

Solução (mesmo padrão do `service_snapshot.py` do V1 — ver "Comparação com
V1"): o próprio `backup_engine.py`, já rodando continuamente, escreve
periodicamente (a cada `BACKUP_ENGINE_HEALTH_SNAPSHOT_SECONDS`, padrão 30s)
um JSON pequeno e sanitizado via `engine/health_snapshot.py::write_snapshot()`
(escrita atômica: arquivo temporário no mesmo diretório, `fsync`, depois
`os.replace()` — um leitor nunca vê um snapshot parcialmente escrito). Laravel
só lê esse arquivo (`EngineHealth::engineSnapshot()`) e julga a **idade** de
`generated_at`: mais velho que `engine_health_snapshot_stale_seconds` (padrão
90s) e o check `engine` vira `critical` — o próprio atraso do snapshot *é* o
sinal de "engine parado", sem precisar de um heartbeat separado do coletor.

Conteúdo do snapshot (`engine/health_snapshot.py::build_snapshot()`):
`engine_version`, `python_version`, `worker_id`, `pid`, `driver_count`,
`drivers` (vendor/platform/method/name/capabilities por driver registrado,
com falha de um driver isolada — nunca derruba o snapshot inteiro),
`workspace_writable` (probe de criar+apagar um arquivo minúsculo em
`BACKUP_STORAGE_ROOT`, nunca deixa nada para trás), `generated_at`. Nunca
inclui: credenciais, payload de backup, RBAC, nada específico de equipamento.

**Onde esse arquivo mora**: um volume Docker dedicado, pequeno, world-writable
(`engine-health`, montado em `storage/app/engine-health/` tanto em `app`
quanto em `engine`) — porque `engine`/`scheduler` rodam como usuário `nobody`
e `app` roda como `www-data` (php-fpm padrão), UIDs sem grupo em comum no
mesmo Dockerfile-base. Como o conteúdo nunca é sensível (só versões e nomes de
driver), esse trade-off de permissão é aceito e documentado — nunca reutilize
esse volume para outra coisa. Ver `compose.yml` (`engine-health-init`).

## Checks

Cada check retorna `{check, status, code, message, checked_at, metadata}`
(`App\Support\HealthCheckResult`). Nenhum guarda segredo (ver "Segurança").

| Check | O que verifica | healthy | warning | critical | unknown |
| --- | --- | --- | --- | --- | --- |
| `database` | `SELECT 1` + latência | conectou | — | exceção na conexão | — |
| `redis` | `PING` + latência | conectou | falha na conexão (nunca crítico — coordenação transitória) | — | — |
| `engine` | idade do snapshot do engine Python | snapshot recente | — | snapshot ausente/inválido → **unknown**; snapshot velho → **critical** | snapshot nunca existiu ou sem `generated_at` válido |
| `driver_registry` | drivers esperados (mikrotik, huawei) presentes no snapshot, sem erro | todos presentes | um ou mais ausentes/quebrados | — | sem snapshot para avaliar |
| `worker` | heartbeat das execuções `running` (dados do próprio ENGINE-2, nenhum heartbeat novo inventado) | heartbeat em dia | — | heartbeat expirado num job `running` | nenhuma execução em andamento |
| `scheduler` | idade de `health:scheduler:last_tick` (Redis) | tick recente | atrasado (`scheduler_warning_minutes`) | muito atrasado (`scheduler_critical_minutes`) | nunca tickou / Redis indisponível |
| `queue` | backlog = pending+queued+retry_wait | abaixo do limite | `backlog_warning` | `backlog_critical` | — |
| `stale_jobs` | execuções `running` com heartbeat/started_at expirado (mesmo critério do `recoverStale()`) | nenhuma | detectada, idade &lt; 2× o threshold | idade ≥ 2× o threshold (indica que a recuperação automática também não está rodando) | — |
| `retry` | contagem de `retry_wait` + top `error_code` | zero | &gt; 0 | — | — |
| `failure` | taxa de sucesso em `recent_window_hours` (24h) | ≥ `success_rate_warning_percent` | ≥ `success_rate_critical_percent` | abaixo disso | zero execuções terminadas na janela |
| `devices` | resumo do `DeviceBackupHealth` (ver abaixo) | maioria saudável | algum warning | algum critical | nenhum device ativo |
| `storage` | `disk_free_space`/`disk_total_space` na raiz de backups | abaixo de `storage_warning_percent` | ≥ warning | ≥ `storage_critical_percent` | raiz não é um diretório / disco não pôde ser consultado |
| `ftp` / `file_server` | `ftp_received_files` (24h) por conta `purpose=backup`/`file_server`: `stored`/`quarantined`/processamento preso | fluindo | 1–4 presos em `processing` | ≥ 5 presos | tabela ainda não existe |
| `retention` | último `audit_events` com `action=backup_retention.completed` | sem erros | `errors &gt; 0` no último run | — | nunca rodou / auditoria indisponível |

## Backlog e outros thresholds

Todos em `config/health.php`, nenhum "achismo" travado no código:
`backlog_warning`/`backlog_critical` (6/21 — ponto de partida, não medido
contra tráfego real ainda — revisar com histórico de produção),
`success_rate_warning_percent`/`_critical_percent` (90/70),
`storage_warning_percent`/`_critical_percent` (80/90),
`scheduler_warning_minutes`/`_critical_minutes` (3/10),
`device_daily_warning_hours`/`_critical_hours` (30/48),
`device_weekly_warning_hours`/`_critical_hours` (192/240),
`device_consecutive_failures_critical` (3), `ftp_processing_stale_minutes`
(15 — mesmo valor que o V1 usava para cloud-sync/Telegram, ver "Comparação
com V1"). Todos configuráveis por variável de ambiente.

## Backup Health por equipamento (`App\Services\DeviceBackupHealth`)

Serviço deliberadamente separado de `EngineHealth` — classifica cada device,
não o sistema como um todo, preparando o terreno para uma página futura de
"Backup Health" por equipamento sem precisar redesenhar nada aqui.

Regras (ver `DeviceBackupHealth::classify()`):
1. **Falhas consecutivas** (`device_consecutive_failures_critical`, padrão 3)
   sempre vencem — `critical`, independente de cadência de agendamento.
2. **Sem cadência agendada** (só policy `manual`, ou nenhuma policy ativa):
   nunca julgado por atraso. Com pelo menos um sucesso histórico → `healthy`;
   nunca fez backup → `unknown` (não `critical` — não há expectativa
   quebrada, só ausência de dado; distinção explicitamente copiada do V1, ver
   abaixo).
3. **Com cadência `daily`/`weekly`** (a mais exigente entre as policies ativas
   do device): nunca teve sucesso → `critical` ("deveria ter rodado e nunca
   rodou" — distinto de "não tem agendamento"); teve sucesso, mas há tempo
   demais → `warning`/`critical` conforme os thresholds daquela cadência;
   caso contrário `healthy`.

A consulta usa duas queries agregadas (não N+1): `MAX(created_at)` por device
para o último sucesso, e uma janela das últimas
`device_recent_executions_sample` (5) execuções terminais por device via
`ROW_NUMBER() OVER (PARTITION BY device_id ...)` (funciona em Postgres e
SQLite) para computar a sequência de falhas consecutivas em PHP.

## Diagnostic snapshot e CLI

- `php artisan engine:health` — saída humana (uma linha por check); com
  `--json`, o relatório completo e estável. Exit code: `0` healthy, `1`
  warning, `2` critical — pensado para uso em monitoramento externo
  (`cron`/Nagios-like/uptime checks) sem precisar parsear JSON.
- `php artisan engine:diagnose [--json]` — `App\Services\EngineDiagnosticSnapshot`,
  um superconjunto pequeno do relatório de health (commit git, versão do PHP,
  versão do Laravel) pensado como "o que colar num ticket de suporte".
  Estritamente somente leitura, nunca muda nada.
- `GET /system/health` — a mesma informação em cards, permissão
  `system_health.view` (todos os quatro papéis — ver docs/RBAC.md). Uma seção
  recolhível "Diagnóstico técnico" mostra o JSON completo (já sanitizado,
  igual ao `--json` da CLI).

## Fail-soft

Cada check em `EngineHealth::report()` roda dentro de `safely()`: se um check
lançar uma exceção não prevista, vira `unknown` com uma mensagem genérica —
nunca derruba os outros 14 checks nem a página inteira. Falhas *esperadas*
(banco fora do ar, Redis fora do ar, storage ausente) já são tratadas dentro
do próprio check com o status correto (`critical`/`warning`/`unknown`
conforme a tabela acima) — `safely()` é a rede de segurança para bugs
genuínos, não o caminho normal.

## Cache

Nenhum cache foi adicionado nesta fase — cada check já é barato (agregações
SQL indexadas, `LIMIT`, uma leitura de arquivo, um `PING`). `cache_seconds`
existe em `config/health.php` como um valor pronto para uso caso um check
futuro se torne caro (ex.: um scan de artifacts órfãos), mas nenhum check
atual precisa dele — documentado aqui para não ser esquecido se isso mudar.

## Auditoria

A página/CLI de health **não gera `audit_events`** — é leitura pura,
executada potencialmente a cada poucos segundos por um monitor externo;
auditar cada acesso seria ruído puro. A única escrita nova em `audit_events`
nesta fase é um resumo por execução da retenção
(`backup_retention.completed`, em `BackupRetention::run()`) — reaproveita a
tabela existente para alimentar o check `retention` sem precisar de
migration nova.

## Alert conditions (domínio, sem notificação)

`App\Support\AlertCondition`: `engine_down`, `worker_stale`,
`scheduler_stale`, `queue_backlog`, `stale_jobs`, `storage_warning`,
`storage_critical`, `repeated_device_failures`, `ftp_processing_stale`,
`retention_failed`. `EngineHealth::report()['alerts']` computa quais estão
ativas agora — **isso não envia nada**, é só o vocabulário que uma fase
futura (Telegram/e-mail) vai consumir. Health é "estado atual calculado";
Alert é "condição que futuramente pode virar notificação" — mantidos como
conceitos distintos deliberadamente (ver "Comparação com V1": o V1 tinha essa
mesma separação, mas de forma incompleta e implícita — ver tabela).

## Segurança / sanitização

Nunca aparece em nenhum check, snapshot, CLI ou página: `APP_KEY`, senhas,
tokens, chaves privadas, credenciais decriptadas, cookies, cabeçalho
`Authorization`, configuração completa de equipamento. `checkDatabase()` e
`checkRedis()` nunca expõem DSN/senha de conexão — só latência e um booleano
de sucesso. `EngineDiagnosticSnapshot` roda `git rev-parse --short HEAD` com
saída validada por regex antes de aceitar (nunca interpola entrada externa).
Testado explicitamente em `SystemHealthTest`/`EngineHealthCliTest`
(`assertDontSee`/busca por `password`, `secret`, `APP_KEY`, stack trace).

## Débito do ENGINE-1 fechado nesta fase

ENGINE-1 documentou a ausência de um teste real Laravel↔Python (tudo mockado
na fronteira do módulo). `EnginePythonIntegrationTest` fecha isso: roda o
interpretador Python de verdade (`engine/health_snapshot.py`, com o driver
registry real, sem equipamento/credencial/rede), valida a forma exata do
JSON, e alimenta esse payload real pelo parser real do `EngineHealth` —
detecta automaticamente se um campo for renomeado, a serialização quebrar, ou
um driver parar de carregar.

## Bug corrigido durante esta fase (regressão do ENGINE-2)

Ao implementar `DeviceBackupHealth`, uma conta de idade deu negativa. Causa:
`Carbon::diffInHours()`/`diffInMinutes()`/`diffInSeconds()` sem o segundo
argumento retornam um valor **com sinal** (`outro - este`), não o valor
absoluto — uma data no passado produz um número negativo. Isso já existia,
não testado, em `EngineHealth::oldestAgeSeconds()` desde o ENGINE-2
(`oldest_pending_seconds` do `snapshot()`, nenhum teste jamais afirmou um
valor para esse campo). Corrigido com `abs()` em todos os cinco pontos do
código que calculam idade (arquivo `EngineHealth.php`/`DeviceBackupHealth.php`)
e coberto por um teste de regressão dedicado
(`test_oldest_pending_seconds_is_a_positive_age_not_a_signed_diff`).

## Comparação com V1

`/opt/backup-manager-local` foi consultado como fonte de conhecimento
operacional (nunca como arquitetura a copiar), com foco em health checks,
status de job/scheduler, equipamentos problemáticos, falhas consecutivas,
FTP/importer health, storage, retenção, diagnóstico e o que operadores
precisavam procurar manualmente.

| Comportamento no V1 | Classificação | Decisão na V2 |
| --- | --- | --- |
| `service_snapshot.py`: coletor privilegiado (systemd) escreve um JSON atômico e size-capped; o app web só lê e trata a idade do arquivo como sinal de saúde do próprio coletor | **PRESERVAR CONCEITO — aplicado diretamente** | É exatamente o mecanismo usado para o snapshot do engine Python (ver seção dedicada acima) — a melhor ideia de todo o V1 para este problema, adotada quase literalmente, adaptada de "processo systemd" para "processo Python de longa duração". |
| `dashboard_snapshot()`: um agregador único, somente leitura, sem side effects, recomputado a cada request | **PRESERVAR CONCEITO** | `EngineHealth::report()` segue o mesmo formato — uma função, um retorno estruturado, sem cache obrigatório. |
| `backup_status()`: idade do último backup em baldes fixos (24h/72h), mas **sem levar em conta se o device tem agendamento** — um device manual sem backup recente é tratado igual a um device agendado que falhou | **MELHORAR — corrigido nesta fase** | `DeviceBackupHealth` distingue explicitamente cadência agendada de manual desde o primeiro dia — a distinção que o V1 só fazia numa página de detalhe separada (nunca no dashboard principal) está embutida na classificação central aqui. |
| `backup_status()` distingue "nunca executado" de "backup expirado" só pela *string* de status, não pelo nível (`level` era `red` para ambos) | **MELHORAR** | V2 usa status diferentes de fato: `unknown` (manual, nunca rodou — sem expectativa quebrada) vs. `critical` (agendado, nunca rodou — expectativa quebrada) vs. `warning`/`critical` por idade (agendado, já rodou mas está atrasado). Três sinais distintos, não dois disfarçados de um. |
| `jobs.py::check_health()`: só dois campos (`running_expirados`, `queued_atrasados`), threshold do segundo campo fixo em 10 minutos (não configurável), exposto só via CLI, nunca no dashboard | **MELHORAR** | `stale_jobs`/`queue`/`worker` cobrem o mesmo terreno com thresholds configuráveis (`config/health.php`) e aparecem tanto na CLI quanto na página `/system/health`. |
| Consecutive-failure tracking existe (`reports.py`, uma CTE) mas é só um número num relatório — nenhum threshold aciona alerta, e é desconectado do mecanismo separado que já marca "última execução falhou" como alerta | **MELHORAR** | `DeviceBackupHealth` unifica isso: um único cálculo de sequência de falhas consecutivas, com threshold configurável, que participa diretamente da classificação do device (não um número solto num relatório). |
| Lock table com TTL deliberadamente maior que o timeout máximo de job (comentário explícito no código, lição de um bug real) | **PRESERVAR CONCEITO — já seguido desde ENGINE-1/2** | Documentado explicitamente em `docs/ENGINE_QUEUE.md`; nada de novo a fazer aqui, só confirmação. |
| Rótulo "Storage" no dashboard na verdade significa integridade de conteúdo, enquanto "Disco" é o indicador de espaço — dois conceitos com nomes que colidem | **DESCARTAR (o erro), aplicar a lição** | Nesta V2, `storage` é exclusivamente espaço em disco; qualquer verificação futura de integridade de artifact ganhará nome próprio (`artifact_integrity` ou similar), nunca reaproveitando "storage". |
| Correlação cloud-sync/Telegram: `status='uploading' AND updated_at &lt; now-15min` = preso; mesmo padrão aplicado consistentemente a duas filas, mas **nunca** ao importador de FTP "comum", que não tem checagem de preso nenhuma | **MELHORAR — lacuna do V1 fechada aqui** | O mesmo padrão (`processing` há mais que `ftp_processing_stale_minutes`, 15min — valor deliberadamente igual ao do V1) agora cobre o FTP real do V2 (`checkFtp`/`checkFileServer`), que era exatamente o buraco que o V1 nunca preencheu. |
| `check_ftp_config()`: probe de socket TCP real para confirmar que o Pure-FTPd está de fato escutando, não só que o serviço systemd está "active" | **ADIAR** | Um probe de rede contra o próprio Pure-FTPd (mesmo host) é de baixo risco, mas não foi implementado nesta fase para não introduzir uma dependência de rede síncrona dentro de um health check que hoje é 100% local/SQL; registrado como candidato de baixo custo para uma fase futura. |
| `lifecycle_runs`: tabela dedicada de histórico de execuções de retenção, com JSON de relatório por execução | **MELHORAR (conceito), implementado mais enxuto** | Em vez de uma tabela nova, o resumo da retenção vai para `audit_events` (reaproveitando a infraestrutura de ADMIN-1) — mesma ideia (histórico consultável de "a retenção rodou, o que ela fez"), sem migration nova. |
| Nenhuma página/comando "diagnostic snapshot"/suporte — operadores reconstruíam incidentes cruzando manualmente múltiplas tabelas/logs (visto no incidente documentado do Google Drive) | **MELHORAR** | `engine:diagnose`/`EngineDiagnosticSnapshot` existe desde o primeiro dia desta funcionalidade — um comando, uma leitura, sanitizado, pronto para colar num ticket. |
| `AUDIT_EVENTS` (notificações): mapa incompleto — condições genuinamente críticas (disco cheio, serviço parado) nunca chegavam a virar notificação Telegram, só apareciam no dashboard | **ADIAR (mas não repetir o erro)** | Notificação real não existe ainda nesta V2. `AlertCondition` (vocabulário de condições) já foi desenhado cobrindo exatamente essas lacunas do V1 (`storage_critical`, `engine_down`) desde o início, para que quando a entrega de notificação for implementada, ela não repita a incompletude do V1 por já ter o catálogo certo pronto. |
| Health (estado no dashboard) e Alert/notificação (evento no Telegram) eram conceitos diferentes, mas só implicitamente — nenhuma documentação ou tipo formal separava os dois | **PRESERVAR CONCEITO, formalizado** | `HealthStatus` (estado atual) e `AlertCondition` (condição nomeada, reutilizável por notificação futura) são dois tipos PHP distintos aqui — a mesma separação do V1, mas explícita e nomeada em vez de implícita. |
| Sufixos "em progresso" (`.part`/`.tmp`/`.partial`/`.filepart`/`.upload`) filtrados na descoberta, **combinados** com uma janela de estabilidade (size+mtime sem mudança) como segunda camada de defesa | **PRESERVAR CONCEITO — já implementado antes desta fase** | Confirmado: `ftp_spontaneous.py`/`ftp_incoming.py` já usam exatamente a janela de estabilidade (`BACKUP_FTP_STABLE_SECONDS`) desde antes do ENGINE-3 — a auditoria pendente registrada no ENGINE-1 sobre "sufixos em progresso" foi revisitada e a proteção real (a janela de estabilidade) já cobre o caso; não havia lacuna funcional para fechar, só uma verificação a fazer. |
| Detecção de versão RouterOS via SSH (`install_routeros_script`), usada só para escolher script de instalação, nunca reconsultada periodicamente | **ADIAR — mantido** | Reavaliado nesta fase (item 43-B do pedido): incorporar como metadata do `analyze()` do MikroTik exigiria um comando SSH adicional (`/system resource print`) fora do fluxo de backup atual — não implementado para não introduzir uma chamada extra num driver já homologado sem necessidade concreta reportada; mantido como dívida registrada, não esquecida. |
| Lixeira com prazo de graça antes da purga física definitiva (retenção em duas fases) | **ADIAR — mantido, fora do escopo desta fase** | Confirmado no item 43-C do pedido: não implementado por fugir do escopo de health/diagnóstico; seguirá como dívida registrada para uma fase futura dedicada a retenção/exclusão. |

**Resumo**: o V1 já tinha, espalhados por múltiplos subsistemas, quase todos
os *conceitos* certos para observabilidade (agregador único, snapshot
privilegiado com heartbeat embutido, detecção de "preso" por idade,
consecutive-failure tracking, separação health/alerta) — mas aplicados de
forma inconsistente entre subsistemas (FTP "comum" nunca ganhou a mesma
checagem de preso que cloud-sync/Telegram tiveram; consecutive-failure nunca
virou alerta de fato; manual-vs-agendado só aparecia numa página de detalhe).
O trabalho desta fase foi majoritariamente **generalizar uma política única
e consistente** para todos os checks, em vez de inventar mecanismos novos.
