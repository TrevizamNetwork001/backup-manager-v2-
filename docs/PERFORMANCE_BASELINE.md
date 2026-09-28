# PERF-1 — baseline de carga e resiliência do engine/core

Data: 28/09/2026. Checkout inicial: `f1850d2`. **Baseline provisório: não há
evidência suficiente para declarar a capacidade do deployment PostgreSQL.**
Foram executadas cargas sintéticas no engine Python real com Artisan/Laravel
real e SQLite isolado, cargas no receptor de arquivos e regressões de falha.
PostgreSQL/Redis isolados e o transporte Pure-FTPd exigem a etapa manual ainda
pendente. Não confundir resultados de SQLite, tmpfs ou callbacks sintéticos
com throughput de PostgreSQL, disco persistente, FTP ou FTPS.

## Ambiente e isolamento

| Item | Ambiente observado |
| --- | --- |
| Host | Debian 13, Linux 6.12.107, VM VMware, x86_64 |
| CPU | 4 vCPUs Intel Xeon E5-2680 v4 @ 2,40 GHz |
| RAM | 7.947 MiB; aproximadamente 5.960 MiB disponíveis no início; swap sem uso |
| Storage | Projeto em volume de 93 GiB, 69 GiB disponíveis; testes em `/tmp` tmpfs de 3,9 GiB |
| Python | 3.13; Paramiko disponível 3.5.1, diferente do pin 4.0.0 do projeto |
| PHP | 8.4.23 CLI, extraído de pacotes locais em `/tmp`, sem instalação no sistema |
| Laravel | Framework 13 instalado em `app/vendor`; suíte PHPUnit do projeto |
| Banco exercitado | SQLite temporário, banco independente por cenário; não é o PostgreSQL do deployment |
| Serviços de produção | Não reiniciados, migrados ou usados como alvo da carga |

O host tem outros serviços e não foi reservado para benchmarks. Uma repetição
por carga; valores não são um SLA. Migração e seed ficam fora das medições.
O scheduler mede duas chamadas: criação e repetição para verificar idempotência.
O engine mantém seus sleeps reais de 1/5 segundos e quatro threads. Drivers
SSH são substituídos por exports sintéticos; nenhum socket de equipamento é
aberto. Todos os arquivos criados e removidos pertencem a diretórios temporários
exclusivos do teste. Nenhum artifact real foi removido; nenhum equipamento foi
alterado; não houve uso de socket Docker nem push.

Os scripts recusam banco fora de `/tmp/bm-perf-1-*`, ambiente diferente de
`testing`, storage diferente da raiz sintética e configuração de banco diferente
de SQLite. Isso é intencional: não apontar esse harness ao banco de produção.

## Mapa operacional e limites atuais

| Área | Implementação/limite |
| --- | --- |
| Worker | `engine/backup_engine.py`: `ThreadPoolExecutor(max_workers=4)`; um dispatcher por processo |
| Concorrência global | 4 por processo; não há limite global compartilhado entre vários processos de engine |
| Device | `EngineJobService::claim`: `NOT EXISTS running_jobs`, transação e `FOR UPDATE SKIP LOCKED`; no processo único, claims são seriais |
| Queue | `backup_executions` no PostgreSQL; `pending` precisa ser enfileirado; dispatcher reclama `queued`/`retry_wait` vencido; exclui `ftp_received` |
| Fonte de verdade | PostgreSQL guarda lifecycle, ownership, heartbeat, attempts, cancelamento, artifacts e receipts |
| Redis | Não participa do claim/lifecycle; Laravel usa coordenação do scheduler (`withoutOverlapping`); não há fila de jobs Python em Redis |
| Retry | Máximo padrão 3 attempts; espera de 60/300 segundos para attempts 2/3; 900 segundos se configurado attempt 4 ou superior; sem jitter |
| Heartbeat | 30 segundos; falha interrompe a thread de heartbeat; `updated=false` não interrompe o driver |
| Timeouts | Artisan 45 s; stale 300 s; execução 1.800 s; SSH conexão/auth/banner 10 s, channel 20 s; receiver manual FTP 180 s |
| Recovery | Scheduler a cada minuto; máximo 100 rows por chamada; ownership antigo não pode concluir/falhar outra tentativa |
| Cancelamento | Imediato em pending/queued/retry_wait; running cooperativo pelo heartbeat; stale também termina cancelamento pendente |
| Scheduler | Grace padrão 5 min; chunks de 100 associações; unicidade associação/ocorrência; verifica device ocupado por ocorrência |
| FTP scanner | Serial no loop principal, intervalo mínimo 5 s; estabilidade padrão 5 s; pode atrasar claims de SSH |
| FTP processing | Sidecar e token duráveis; até 20 ciclos de retry, depois quarantine; não é backoff exponencial |
| Pure-FTPd | 20 clientes globais, 5 por IP, 10 portas passivas, RLIMIT_FSIZE conforme limite FTP; transporte não medido nesta etapa |
| Artifact | SSH até 8 MiB; FTP padrão 8 MiB, configurável e limitado a 64 MiB |
| Storage | Confina paths, rejeita links, publica sem overwrite por hard link, fsync do arquivo; Python lê payload inteiro; SHA-256 |
| Retention | Desabilitada por padrão; horário 04:30 quando habilitada; chunks de 100 fontes, mas todos os artifacts de cada fonte são carregados/lockados juntos |

Índices encontrados: status/created_at, device_id/created_at, status/heartbeat_at,
status/next_attempt_at e ftp_account_id. Há constraints únicas de ocorrência,
artifact por execução, relative_path e tokens FTP. Não foi executado EXPLAIN em
PostgreSQL; a eficiência desses índices e a corrida entre claims de processos
distintos continuam sem validação. `NOT EXISTS` sozinho não comprova exclusão
por device com transações concorrentes em snapshots distintos.

V1 consultado: `backup_manager/ftp_pipeline.py` (suffixes de upload em progresso),
`ftp_importer.py` (claim persistente e hashing fora da transação) e
`ftp_storage.py`. O V2 já incorporou suffixes e limite de retries do processing.
Streaming de hash e transações curtas são aprendizados úteis para PERF-2;
nenhuma arquitetura foi substituída nesta fase.

## Resultados de carga

Os resultados detalhados estão em [PERFORMANCE_BASELINE_RESULTS.json](PERFORMANCE_BASELINE_RESULTS.json).
P95 usa nearest rank. Queue = início do lote até início de `execute`;
latência = início do lote até confirmação de conclusão/falha via Artisan.
Throughput abaixo conta **somente jobs persistidos como succeeded** e inclui
a espera do dispatcher para perceber o término do lote.

| Engine → Artisan → SQLite | Sucessos | Tempo total | Sucessos/min | Latência média / p95 | Queue média / p95 | Falhas iniciais |
| --- | --- | --- | --- | --- | --- | --- |
| 20 queued, driver 20 ms | 20/20 | 13,89 s | 86,42 | 5,09 / 8,11 s | 4,37 / 7,47 s | 0% |
| 50 queued, driver 20 ms | 48/50 | 41,00 s | 70,24 | 20,20 / 34,99 s | 19,43 / 34,26 s | 4% |
| 100 queued, driver 20 ms | 90/100 | 60,81 s | 88,79 | 28,78 / 53,55 s | 28,00 / 52,88 s | 10% |
| 20 queued, driver lento de 2 s | 20/20 | 24,36 s | 49,27 | 11,20 / 18,23 s | 8,46 / 15,51 s | 0% |
| 5 queued no mesmo device, driver 100 ms | 5/5 | 32,35 s | 9,27 | ver JSON | 13,53 / 26,31 s | 0% |

Pico observado de devices ativos: 3/4/4 para lotes 20/50/100; 4 nos jobs
lentos e 1 no mesmo device; zero sobreposições de device. Isso valida o
dispatcher único usado no teste, não múltiplos engines contra PostgreSQL.
Os lotes com falha ficaram em retry_wait, não perderam a row persistente.
Foram registrados 4/12 erros de subprocesso `database is locked` nos lotes
50/100. **São falhas do ambiente SQLite; não são bugs comprovados do PostgreSQL.**
Uma execução exploratória anterior de 100 jobs ficou com 99 succeeded e um
running após falha de reporting; foi encerrada no harness. A recuperação
periódica não estava ligada nesse ensaio. O harness final encerra ao terminar
a primeira passagem ou após 180 s, e reporta jobs sem acknowledgment.

No lote de 100: execução média/p95 de 0,78/1,15 s para apenas 20 ms de driver;
Artisan médio/p95 de 0,355/0,502 s. Foram 323 subprocessos bem-sucedidos:
101 claims, 100 secrets, 90 completes, 10 fails e 22 consultas FTP, além dos
subprocessos com erro. CPU Python 0,54 s e filhos 104,22 s; aproximadamente
1,72 cores médios no intervalo. RSS máximo Python 40,7 MiB; filho PHP 51,9 MiB
(máximo individual, não soma dos processos simultâneos).

| Control plane real, SQLite | Resultado | Serviço | Queries | Pico PHP |
| --- | --- | --- | --- | --- |
| Scheduler 100 associações | 100 criadas; repetição cria 0 | 0,150 s | 311 | 32 MiB |
| Scheduler 1.000 associações | 1.000 criadas; repetição cria 0 | 1,775 s | 3.083 | 36 MiB |
| Retention 1.000 artifacts de uma fonte | 990 candidatos; 0 removidos | 0,325 s | 8 | 38 MiB |
| Retention 10.000 artifacts de uma fonte | 9.990 candidatos; 0 removidos | 2,057 s | 8 | 104 MiB |
| Stale 1.000 rows | 10 lotes de 100; 1.000 retry_wait | 1,250 s | 3.013 | 36 MiB |
| Retry storm 100 jobs | 300 attempts; 100 falhas terminais; early claim bloqueado | 1,131 s | 2.406 | 32 MiB |
| Cancelamento 100 queued | 100 cancelled | 0,158 s | 401 | 32 MiB |

Stale foi drenado chamando o serviço repetidamente, sem esperar um minuto.
Com a frequência atual, 1.000 stale rows exigem aproximadamente **10 ciclos de
scheduler**, mesmo que o custo computacional total seja baixo. Retry avançou
`next_attempt_at` somente no banco sintético; não esperou 60/300 s reais.
Retention foi dry-run com arquivos sintéticos de 4.320 bytes; não há medida de
delete em grande volume, várias fontes ou artifacts grandes nessa carga.

| Receiver local, 8 produtores simultâneos | Tempo | Receipts únicos | Observação |
| --- | --- | --- | --- |
| 100 backups de 4 KiB | 0,448 s | 100 | Callback de banco sintético |
| 1.000 backups de 4 KiB | 3,446 s | 1.000 | Zero falhas; terceira scan não repete receipt |
| 100 file_server de 4 KiB | 0,257 s | 100 | Nenhuma BackupExecution |
| 1.000 file_server de 4 KiB | 1,902 s | 1.000 | Nenhuma BackupExecution |
| 4 backups de 8 MiB | 0,581 s | 4 | Read + hash médio/p95 64,6/70,8 ms; publicação 23,5/28,7 ms |
| 4 file_server de 64 MiB | 1,858 s | 4 | Read + hash médio/p95 275,1/363,0 ms |
| 100 backups, callback com 10 ms | 2,478 s | 100 | Latência p95 2,35 s; processamento serial bloqueia o loop |

Todos os arquivos armazenados foram conferidos por tamanho e SHA-256.
Esses ensaios começam após produtores gravarem os arquivos em paralelo;
não são conexões simultâneas a Pure-FTPd e receipts são callbacks em memória.
Não validam constraints de receipt sob sessões de banco concorrentes.
Hash SHA-256 isolado, 10 amostras: 8 MiB média/p95 39,5/59,1 ms;
64 MiB 238,4/314,0 ms. P95 com 10 amostras é o máximo. O pico RSS do harness
chegou a 221 MiB no cenário 64 MiB, incluindo buffer do produtor; não atribuir
todo esse consumo ao engine em produção. Métricas RSS são máximos desde o início
do processo, não picos independentes por cenário.

`/proc/self/io` observou, no lote 100, 2,59 GB de leitura lógica e 29,17 MB de
escrita lógica, incluindo subprocessos e arquivos de runtime. read_bytes e
write_bytes físicos foram zero em tmpfs. Não se mediram IOPS/latência/fsync de
disco persistente. Conexões PostgreSQL, ops/erros Redis e latência de rede: **não
observáveis neste ambiente**; não foram preenchidos com zeros artificiais.

## Matriz dos 20 cenários solicitados

| # | Cenário | Evidência e limite |
| --- | --- | --- |
| 1 | 20/50/100 pendentes | Rodado com jobs já queued, engine real e SQLite; etapa pending→queued não incluída no cronômetro |
| 2 | Concorrência device/global | Rodado, 4 threads e um dispatcher; concorrência de vários engines/PostgreSQL pendente |
| 3 | Jobs lentos | Rodado, 20 jobs com driver de 2 s |
| 4 | Timeout | Suíte Laravel `EngineRecoveryTest` e Python de transporte; clock/falhas simulados; 1.800 s reais não aguardados |
| 5 | Retry/backoff | Serviço real e suíte `EngineRetryTest`; bloqueio antecipado e máximo de attempts |
| 6 | Retry storm | 100 jobs × 3 attempts no serviço; sem jitter; sem carga de rede |
| 7 | Cancelamento | 100 queued + suites de running/cooperativo; heartbeat acelerado em testes unitários |
| 8 | Restart de worker durante job | Processo real do receiver encerrado antes/depois de link; suíte stale/ownership; kill do worker SSH completo pendente |
| 9 | Restart de engine | Nova scan/contexto recupera sidecar; stop/start do deployment inteiro pendente |
| 10 | Redis indisponível/retorno | Pendente: nenhum Redis isolado disponível; lifecycle não usa Redis por inspeção, scheduler precisa ser exercitado |
| 11 | PostgreSQL indisponível/retorno | Falha/retorno dos callbacks de completion/receipt reproduzida; stop/start de PostgreSQL isolado pendente |
| 12 | Storage quase cheio | ENOSPC injetado no fsync: STORAGE_FAILED, sem final e sem partial; quota/filesystem realmente cheio pendente |
| 13 | Artifact grande | 8 MiB backup e 64 MiB file_server; oversize/empty/links nas suites |
| 14 | Muitos artifacts pequenos | 1.000 uploads de cada finalidade e 10.000 artifacts na retention |
| 15 | FTP simultâneo | Produtores simultâneos no filesystem; sessões Pure-FTPd/FTPS pendentes |
| 16 | File_server simultâneo | 100/1.000 produtores, hash/receipt conferidos; transporte e DB real pendentes |
| 17 | Stale recovery em volume | 1.000 rows; cap 100 confirmado; 1.000 retries persistidos |
| 18 | Scheduler em volume | 100/1.000 associações; repetição idempotente |
| 19 | Retention em volume | 1.000/10.000 arquivos dry-run; sem tocar artifact real |
| 20 | Duplicação/idempotência | Ocorrências scheduler, um artifact por retomada FTP, tokens preservados, ausência de receipt repetido; disputa PostgreSQL pendente |

Suites FTP adicionais cobrem suffixes em progresso, arquivo mudando durante
leitura, sidecar corrompido, quarantine, retries esgotados e prontidão da conta.
Unicidade física do claim e retomada foram verificadas com um scanner;
não executar vários scanners contra o mesmo processing até validar ownership.

## Bugs P1 comprovados e correções limitadas

1. **Completion indisponível após publicação:** segunda tentativa criava outro
   arquivo e novas tentativas podiam esgotar nomes/quarentenar upload válido.
   Regressão falhou antes com dois arquivos, passou após cinco falhas de
   completion, retomada de sidecar, um arquivo e um receipt. Novos backups
   espontâneos usam nome exclusivo `-exec-ID` e retomam somente bytes idênticos,
   regulares, sem links/symlinks. Uploads distintos continuam distintos.
2. **Receipt após commit e colisão:** retomada de `succeeded` usava o nome base
   em vez do arquivo publicado. Regressão comprovou paths diferentes antes.
   O caminho efetivo agora é persistido atomicamente no sidecar antes de
   completion e reutilizado ao gravar receipt.
3. **Crash durante publicação:** `file_server` deixava `.TOKEN.tmp` e ficava
   bloqueado por O_EXCL; crash depois de link também deixava nlink=2, impedindo
   validação em backup/file_server. Processos de teste foram encerrados com
   `os._exit` nessas janelas. Retomada remove apenas temporary do claim regular
   ou hard link temporário com o mesmo inode/device do final. Preserva arquivos
   não relacionados e revalida hash/conteúdo antes de confirmar.

Seis regressões novas, com evidência antes/depois. ENOSPC já funcionava; nenhum
P0 foi encontrado. Não foram otimizados scheduler, queries, hashes ou limites.
Compatibilidade: o Laravel já aceita `-exec-ID`. Não houve migration nem
limpeza/reparação de artifacts ou receipts históricos. Sidecars antigos que
não registraram o caminho publicado ainda usam o fallback anterior; órfãos
históricos exigem reconciliação específica em PERF-2. Os testes não comprovam
durabilidade em queda de energia; somente falha de processo/serviço.

## Profiling, gargalos e PERF-2

| Severidade | Evidência | Recomendação |
| --- | --- | --- |
| P1, corrigido | Três falhas de retomada/publicação acima | Manter regressões; validar contrato no PostgreSQL e pin Paramiko do deployment |
| P1 potencial, não confirmado | Claim por row + NOT EXISTS; sem constraint running/device | Testar dois processos com barreira de transações em PostgreSQL antes de mudar locking |
| P2 | Driver 20 ms vira ~0,78 s; 323 subprocessos bem-sucedidos/100 jobs; ~104 s CPU em filhos | Medir bootstrap separado, queries por comando e reaproveitamento de processo antes de propor mudança de protocolo |
| P2 | Queue p95 7,47→34,26→52,88 s; idle poll 5 s; mesmo device 5 jobs em 32,35 s | Medir SLO de espera com 1/4 workers e intervalos de polling; configurar somente com evidência |
| P2 | Scheduler 3.083 queries/1.000 fontes em duas chamadas | Perfil N+1 de busy guard/insert; considerar batching, sem retirar unicidade de ocorrência |
| P2 | `secret()` chama `job()` duas vezes; job resolve relações, schema e configurações em cada processo | Contar queries em PostgreSQL; reduzir consultas repetidas e eager loading de site com regressão |
| P2 | Retention 104 MiB/10.000 artifacts; uma transação abrange get/hydration/hash/verify de toda a fonte | Paginar por fonte, preservar latest válido e revalidar no delete; medir lock/transaction time com jobs concorrentes |
| P2 | Complete PHP usa file_get_contents e hash dentro da transação; Python lê limite inteiro; retry file_server pode manter duas cópias | Streaming de hashing/leitura e transações menores, mantendo identidade/TOCTOU/path safety |
| P2 | Scanner FTP síncrono; 100 callbacks de 10 ms já ocupam 2,48 s | Medir 100/1.000 receipts reais e heartbeat/claims sob essa carga; impor orçamento de scan somente depois |
| P2 | Processing retries a cada ciclo, até 21ª falha→quarantine, sem backoff; Redis/scheduler outage sem teste integrado | Verificar duração de outage tolerável, jitter e backoff sem perder token/estado persistente |
| P2 | Recovery limita 100/min; job deadline/heartbeat em minutos | Medir backlog e definir orçamento de recovery; verificar fencing do driver após ownership expirado |
| P2 | Hash/link/fsync e scans dependem do volume; tmpfs oculta latência física | Repetir em volume sintético persistente com quota, I/O e power-loss/fdatasync quando possível |

Perfil cProfile de 1.000 backups pequenos: 2,97 milhões de chamadas, 5,56 s
(com instrumentação; não usar como throughput). Três scans consumiram 4,93 s;
process 3,78 s, store_claimed 2,46 s, store 1,48 s, claim 0,91 s.
Foram ~7.009 resolves e ~17.013 stats; mocks também contribuíram com custo.
Há repetição de resolução/path checks, mas **não há O(n²) comprovado** pelo
ensaio. Retention 1.000→10.000 cresceu ~6,3× em tempo e aumentou a memória;
o padrão get integral é o problema observável. Query mais lenta capturada:
54,88 ms no select de 10.000 artifacts; não é uma slow query de PostgreSQL.
Não foram medidos row locks, wait events, plano SQL ou duração de transações
PostgreSQL. Não reduzir proteções de path/identidade para ganhar throughput.

Quick wins para PERF-2: métricas por comando/claim/reporting, alertas de backlog
e retries, contagem de stale e falhas de heartbeat, eliminar job() duplicado em
secret, medir índice device/status com EXPLAIN e orçamento do scanner. São
recomendações, não alterações realizadas nesta fase.

## Reprodução e validação

```sh
PYTHONPATH=engine python3 scripts/perf_baseline.py --php /caminho/php --output /tmp/perf-1.json
PYTHONPATH=engine python3 -m unittest discover -s engine/tests -v
PYTHONPATH=docker/ftp python3 -m unittest discover -s docker/ftp -p test_admin.py -v
```

O PHP deve ter ctype, fileinfo, mbstring, DOM/XML, PDO SQLite, tokenizer e
openssl. O harness admite `--only engine|control|ftp`. O stdout é JSON por
cenário; a saída detalhada é atualizada após cada cenário. Não rodar em app com
configuração cacheada de produção. As suites PHPUnit usam banco em memória e
logs/views em `/tmp` quando as permissões locais não permitem runtime do app.

Validações realizadas:

- Python engine completo: **89/89 passam**, incluindo seis novas regressões.
- Python ftp-admin: **16/16 passam**. Integração PureDB privilegiada não roda
  aqui: chown para UID 65534 falha no sandbox. Não declarada aprovada.
- Laravel completo: **340/341 passam, 2.086 assertions**. Única falha anterior
  ao escopo: `DeviceBackupHealthTest:180` espera texto `SSH Pull` que a interface
  atual traduz. Nenhum arquivo de UI/app foi alterado para mascarar esse resultado.
- `php -l`: **189 arquivos**; `py_compile` de engine/drivers/tests/FTP/harness;
  Pint apenas no novo script PHP; `git diff --check`.
- Secret scan local por padrões de private keys/tokens no checkout versionado
  e novos arquivos: única ocorrência é fixture truncada preexistente em
  `test_driver_contract.py:171`, não uma chave válida. Sem novos segredos.
  Não equivale a gitleaks nem inclui `.env`/storage real.

Etapa manual necessária para fechar PERF-1: obter runtime isolado e exercitar
PostgreSQL/Redis e Pure-FTPd/FTPS sem volumes/contas de produção, incluindo
conexões DB, ops Redis, multi-engine, outage/retorno e volume com pouco espaço.
Foi solicitado um comando manual por vez, começando pela identificação da
imagem app (`docker inspect --format '{{.Config.Image}}' backup-manager-v2-app`).
**Enquanto essa etapa não for executada, não afirmar onde o engine de produção
satura nem que todos os 20 cenários passaram integralmente.**
