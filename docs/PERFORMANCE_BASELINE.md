# PERF-1 — baseline de carga e resiliência do engine/core

**PERF-2 executado em 28/09/2026.** O PERF-1 abaixo permanece como histórico e
referência BEFORE. A seção final registra as otimizações, AFTER, regressões,
limites e validação completa. Nenhum serviço real foi reiniciado; sem push.

Data: 28/09/2026. Checkout inicial: `f1850d2`; correções P1 em `f6a7395`,
harness inicial em `5a3ef1c`. **PERF-1 fechado no escopo isolado e sintético.**
Engine/Artisan, PostgreSQL/Redis, Pure-FTPd/FTPS e o receiver foram exercitados
com bancos, contas e arquivos exclusivos. As duas suítes Laravel completas
passam. Os números medem este laboratório; **não declaram capacidade,
saturação ou SLA do deployment real**. Disco persistente, WAN, equipamentos
SSH reais e durabilidade em queda de energia continuam fora da evidência.

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
| Bancos exercitados | SQLite temporário e PostgreSQL 17.11 isolado, banco UTF-8 independente por cenário |
| Redis | 8.0.2 isolado em loopback:56379, sem persistência |
| FTP/FTPS | Pure-FTPd 1.0.50, PureDB/chroots temporários, loopback:52121; TLS 1.3, certificado temporário verificado pelo cliente |
| Serviços de produção | Não reiniciados, migrados ou usados como alvo da carga |

O host tem outros serviços e não foi reservado para benchmarks. Uma medição
por carga publicada; houve uma passagem exploratória PostgreSQL adicional. A
passagem final do engine foi repetida sem outras suítes PERF concorrentes;
retenção e integração FTPS finais foram sequenciais. Valores não são um SLA. Migração e seed ficam fora das medições.
O scheduler mede duas chamadas: criação e repetição para verificar idempotência.
O engine mantém seus sleeps reais de 1/5 segundos e quatro threads. Drivers
SSH são substituídos por exports sintéticos; nenhum socket de equipamento é
aberto. Todos os arquivos criados e removidos pertencem a diretórios temporários
exclusivos do teste. Nenhum artifact real foi removido; nenhum equipamento foi
alterado; não houve uso de socket Docker nem push.

Os scripts recusam `APP_ENV` diferente de `testing`, SQLite fora do workspace
`/tmp/bm-perf-1-*` e storage/FTP fora dessa raiz. O modo PostgreSQL exige socket
`/tmp/bm-perf-1-*/socket`, porta lógica 55432, usuário `perf`, nome
`bm_perf_1_*` e confirma `SHOW data_directory` antes de criar/migrar dados.
Runtime PHP/extensões, PostgreSQL e Redis foram extraídos de pacotes para
`/tmp`, sem instalação no sistema. Somente processos sintéticos foram
parados/reiniciados; o teste PureDB usou portas 52122/53100–53109 após o perfil
original encontrar portas passivas ocupadas (`425`). Não se alterou nenhum
serviço, certificado, conta, artifact ou configuração do ambiente real.

## Mapa operacional e limites atuais

| Área | Implementação/limite |
| --- | --- |
| Worker | `engine/backup_engine.py`: `ThreadPoolExecutor(max_workers=4)`; um dispatcher por processo |
| Concorrência global | 4 por processo; não há limite global compartilhado entre vários processos de engine |
| Device | `NOT EXISTS running_jobs`, transação e `FOR UPDATE SKIP LOCKED`; índice parcial único `backup_executions_one_running_device` garante no máximo um running/device |
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
| Pure-FTPd | 20 clientes globais, 5 por IP e 10 portas passivas configurados; sexto cliente do mesmo IP rejeitado; RLIMIT_FSIZE de 64 MiB exercitado no laboratório |
| Artifact | SSH até 8 MiB; FTP padrão 8 MiB, configurável e limitado a 64 MiB |
| Storage | Confina paths, rejeita links, publica sem overwrite por hard link, fsync do arquivo; Python lê payload inteiro; SHA-256 |
| Retention | Desabilitada por padrão; horário 04:30 quando habilitada; chunks de 100 fontes, mas todos os artifacts de cada fonte são carregados/lockados juntos |

Índices encontrados: status/created_at, device_id/created_at, status/heartbeat_at,
status/next_attempt_at, ftp_account_id e o índice parcial único de running/device.
Há unicidade de ocorrência, artifact/execução, relative_path e tokens FTP.
**Correção do baseline anterior:** o índice parcial já existia nas migrations;
a afirmação de ausência de constraint estava errada. A barreira entre duas
transações PostgreSQL confirmou um running e um queued: o perdedor recebeu
`23505`, sem perda de row ou dupla execução. O tratamento dessa disputa fica
para PERF-2. EXPLAIN no banco pequeno do probe usou índices de status e running
com anti join; isso não comprova o plano/custo sob histórico grande.

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
Nesta etapa SQLite, retention foi dry-run com arquivos sintéticos de 4.320
bytes; delete em grande volume foi medido depois, no PostgreSQL abaixo.
Várias fontes e artifacts grandes não foram exercitados nessa retenção.

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
disco persistente. Na etapa inicial, conexões PostgreSQL, ops/erros Redis e rede ainda não eram
observáveis. O fechamento abaixo acrescenta conexões amostradas, comandos Redis
e transporte loopback; I/O físico e WAN continuam sem medidas.

## Fechamento PostgreSQL, Redis e FTPS

Os resultados SQLite acima são o histórico da primeira etapa. A tabela abaixo
é a referência final do engine com PostgreSQL real isolado, mantendo quatro
threads, sleeps reais e exports SSH sintéticos. Migração/seed fora da medida.

| Engine → Artisan → PostgreSQL | Sucessos | Tempo total | Sucessos/min | Latência média / p95 | Queue p95 |
| --- | --- | --- | --- | --- | --- |
| 20 queued, driver 20 ms | 20/20 | 15.50 s | 77.43 | 5.98 / 9.66 s | 8.69 s |
| 50 queued, driver 20 ms | 50/50 | 30.35 s | 98.83 | 13.70 / 24.29 s | 23.52 s |
| 100 queued, driver 20 ms | 100/100 | 55.39 s | 108.32 | 25.84 / 47.11 s | 46.36 s |
| 20 queued, driver 2 s | 20/20 | 25.73 s | 46.64 | 12.50 / 19.98 s | 17.24 s |
| 5 queued no mesmo device, driver 100 ms | 5/5 | 34.21 s | 8.77 | 15.47 / 29.05 s | 27.92 s |

**195/195 succeeded**, 195 artifacts, zero erros de subprocesso, zero jobs sem
acknowledgment e zero overlap de device nos cinco lotes. Pico de devices ativos:
3/3/3 nos lotes rápidos, 4 no lote lento e 1 no mesmo device. No lote 100,
Artisan média/p95 **0.418/0.546 s**;
execução média/p95 **0.870/1.030 s**,
para 20 ms de driver. Foram 323 subprocessos bem-sucedidos,
CPU filhos 110.13 s, RSS Python 41.4 MiB
e filho PHP 52.5 MiB (máximo individual).
A amostragem parcial do final do engine observou pico de
uma conexão do workload e zero waiters de lock,
com intervalo de 50 ms; não é o pico comprovado de todos os lotes nem de produção.

| Control plane PostgreSQL, passagem inicial | Serviço | Queries | Pico PHP |
| --- | --- | --- | --- |
| Scheduler, 100 | 0.216 s | 311 | 32 MiB |
| Scheduler, 1,000 | 2.443 s | 3083 | 36 MiB |
| Retention dry-run, 1,000 | 0.416 s | 8 | 38 MiB |
| Retention dry-run, 10,000 | 2.225 s | 8 | 104 MiB |
| Stale, 1,000 | 3.263 s | 3013 | 36 MiB |
| Retry storm, 100 | 2.699 s | 2406 | 32 MiB |
| Cancelamento, 100 | 0.311 s | 401 | 32 MiB |

Scheduler criou 100/1.000 ocorrências e repetiu com zero duplicações. Stale
recuperou 1.000 rows em dez lotes de 100. Retry persistiu 300 attempts/100
falhas terminais, sem bypass de backoff; cancelamento terminou 100/100.

**Retenção final de 10.000 artifacts, uma fonte:** dry-run de confirmação em
2.227 s, 9 queries e
104 MiB PHP; 9.990 candidatos.
Apply sintético em **29.759 s**, **9.990 removidos**, dez arquivos
preservados, 9998 queries, 108 MiB PHP,
zero erros/anomalias. As rows históricas permanecem, com status deleted/available.
Todos os deletes ficaram restritos ao storage temporário do probe.

**Lock contention:** uma segunda transação tentou atualizar um artifact durante
o dry-run de retenção; `pg_stat_activity` confirmou `Lock/transactionid`, com
um bloqueador. Também observou o contender sem espera após a liberação.
O contender levou **2.397 s** (inclui conexão/amostragem)
e o trecho após aquisição dos locks no worker durou 2.358 s;
não houve sleep artificial segurando lock. No apply, o trecho após aquisição
dos locks durou 29.565 s. Zero deadlocks nesse probe.
São amostras únicas, sem p95 de lock. Claim com a primeira row bloqueada reclamou
a segunda em 47.29 ms via serviço, validando SKIP LOCKED.
Na corrida do mesmo device, o índice preservou exclusão, mas o perdedor recebeu
23505; o loop atual registra claim_failed e espera cinco segundos.

**Outage/retorno:** Redis aceitou um mutex e recusou o segundo. Com Redis parado,
`backups:schedule` direto criou duas rows e o lifecycle persistiu retry_wait;
`schedule:run` com cache Redis não executou o evento e lançou RedisException.
Após retorno, criou duas rows e o mutex voltou a funcionar. Foram observados
20 comandos acumulados antes da parada e
17 após restart (contadores reiniciam; não são ops/s).
PostgreSQL parado rejeitou claim; após restart preservou duas rows e retomou
lifecycle. Zero deadlocks no banco desse probe. Não foram simuladas outages longas.

| Transporte isolado, 4 sessões persistentes | Tempo total | Arquivos/s | MiB/s | STOR p95 |
| --- | --- | --- | --- | --- |
| FTP, 100 × 4 KiB | 2.423 s | 41.27 | 0.16 | 2.01 ms |
| FTPS, 100 × 4 KiB | 3.662 s | 27.31 | 0.11 | 56.11 ms |
| FTPS, 4 × 8192 KiB | 3.346 s | 1.20 | 9.56 | 104.47 ms |
| FTPS, 4 × 65536 KiB | 3.634 s | 1.10 | 70.44 | 607.50 ms |

Tempo total inclui login/fechamento; STOR p95 mede somente o comando de upload,
por isso não equivale ao p95 de uma nova sessão. Tamanho/hash conferidos em cada
arquivo. TLS 1.3 com certificado temporário confiado explicitamente pelo cliente;
chroot, no-overwrite, rotação/desativação e sexto cliente/IP rejeitado passaram.
64 MiB + 1 byte falhou; o arquivo parcial observado ficou com 64 MiB e pertence
apenas ao teste. Não se comprovou saturação global de 20 clientes/10 data channels.
O `docker/ftp/server.py` atual não habilita TLS: FTPS é uma configuração sintética,
sem mudança no deployment.

**FTPS obrigatório → receiver real → Artisan → PostgreSQL:** 20 backups + 20
file_server de 4 KiB, quatro produtores, uma nova sessão por upload. Upload:
37.117 s (1.078 arquivos/s);
três scans seriais: 41.269 s (0.969 arquivos/s).
Do início do lote ao último receipt: **78.387 s**,
**0.510 arquivos/s**, latência de receipt do
lote média/p95 **63.518/77.040 s**.
P95 de sessão de upload 5.250 s; p95 do comando
receipt 0.492 s. Resultado: 20 succeeded,
20 artifacts, 40 receipts/tokens únicos, zero processing pendente, zero deadlocks;
file_server não gerou BackupExecution. Hash/tamanho de todos os receipts conferidos.
A estabilidade foi zero e o scanner foi chamado diretamente; polling/estabilidade
padrão e concorrência com SSH adicionam espera não medida nesse lote.

O upload adicional de resiliência parou PostgreSQL **após publicação e antes de
completion**: ficou um único arquivo com sidecar pendente, sem artifact novo.
Após retorno e nova scan/contexto: 21 artifacts e 41 receipts únicos, os mesmos
41 arquivos publicados e zero processing. A medida de throughput acima exclui
essa injeção de falha. Não se duplicou a publicação na retomada.

Kill/restart de engine: processo Python real com dois exports sintéticos lentos
foi encerrado com SIGKILL e todo o seu grupo de processos de teste. Recovery
imediato recuperou zero; com timestamps do banco sintético avançados, recuperou
dois stale para retry_wait. Novo engine concluiu 2/2, com dois artifacts e attempt
2, em 2.775 s até acknowledgment. Backoff/stale não
foram aguardados em tempo real; nenhum processo de produção foi encerrado.

## Matriz dos 20 cenários solicitados

| # | Cenário | Evidência e limite |
| --- | --- | --- |
| 1 | 20/50/100 pendentes | SQLite histórico + PostgreSQL final; jobs já queued; pending→queued fora do cronômetro |
| 2 | Concorrência device/global | 4 threads; dois processos Laravel com barreira PostgreSQL; índice único preserva device; vários engines completos em carga não medidos |
| 3 | Jobs lentos | Rodado, 20 jobs com driver de 2 s |
| 4 | Timeout | Suíte Laravel `EngineRecoveryTest` e Python de transporte; clock/falhas simulados; 1.800 s reais não aguardados |
| 5 | Retry/backoff | Serviço real e suíte `EngineRetryTest`; bloqueio antecipado e máximo de attempts |
| 6 | Retry storm | 100 jobs × 3 attempts no serviço; sem jitter; sem carga de rede |
| 7 | Cancelamento | 100 queued + suites de running/cooperativo; heartbeat acelerado em testes unitários |
| 8 | Restart de worker durante job | Receiver antes/depois de link + SIGKILL do engine real com driver sintético, stale e retomada PostgreSQL |
| 9 | Restart de engine | Nova scan/contexto e novo engine confirmados; deployment inteiro não foi alterado |
| 10 | Redis indisponível/retorno | Redis real isolado: mutex, outage/retorno, lifecycle independente; schedule:run depende do mutex |
| 11 | PostgreSQL indisponível/retorno | Stop/start do cluster sintético; rows preservadas; completion após publicação retomada com um arquivo/receipt |
| 12 | Storage quase cheio | ENOSPC injetado no fsync: STORAGE_FAILED, sem final e sem partial; quota/filesystem realmente cheio pendente |
| 13 | Artifact grande | 8 MiB backup e 64 MiB file_server; oversize/empty/links nas suites |
| 14 | Muitos artifacts pequenos | 1.000 uploads de cada finalidade e 10.000 artifacts na retention |
| 15 | FTP simultâneo | FTP/FTPS em loopback, quatro sessões/produtores; chroot, TLS, limites e hash conferidos |
| 16 | File_server simultâneo | 100/1.000 filesystem + FTPS obrigatório/PostgreSQL integrado; 20 file_server sem BackupExecution |
| 17 | Stale recovery em volume | 1.000 rows; cap 100 confirmado; 1.000 retries persistidos |
| 18 | Scheduler em volume | 100/1.000 associações; repetição idempotente |
| 19 | Retention em volume | 10.000 artifacts PostgreSQL: dry-run, lock wait e apply de 9.990 sintéticos; dez preservados |
| 20 | Duplicação/idempotência | Ocorrências, retomada FTP/DB, receipts/tokens únicos e disputa PostgreSQL preservando running/device |

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

Seis regressões adicionadas na etapa inicial, com evidência antes/depois.
Nenhuma correção crítica de produção adicional nesta continuação.
ENOSPC já funcionava; nenhum
P0 foi encontrado. Não foram otimizados scheduler, queries, hashes ou limites.
Compatibilidade: o Laravel já aceita `-exec-ID`. Não houve migration nem
limpeza/reparação de artifacts ou receipts históricos. Sidecars antigos que
não registraram o caminho publicado ainda usam o fallback anterior; órfãos
históricos exigem reconciliação específica em PERF-2. Os testes não comprovam
durabilidade em queda de energia; somente falha de processo/serviço.

## Profiling, gargalos e PERF-2

| Severidade | Evidência | Recomendação |
| --- | --- | --- |
| P1, corrigido | Três falhas de retomada/publicação acima; retomada também confirmada com outage PostgreSQL real isolado | Manter regressões; validar o pin Paramiko do deployment |
| P2 | Disputa entre dois claims: índice running/device já existente impede duplicação; perdedor recebe 23505 e o loop espera 5 s | Tratar colisão concorrente preservando constraint e rollback, com teste multiprocesso |
| P2 | PostgreSQL: driver 20 ms vira ~0,87 s; 323 subprocessos/100 jobs; ~110 s CPU em filhos | Medir bootstrap separado, queries por comando e reaproveitamento de processo antes de propor mudança de protocolo |
| P2 | PostgreSQL queue p95 8,69→23,52→46,36 s; idle poll 5 s; mesmo device 5 jobs em 34,21 s | Medir SLO de espera com 1/4 workers e intervalos de polling; configurar somente com evidência |
| P2 | Scheduler 3.083 queries/1.000 fontes em duas chamadas | Perfil N+1 de busy guard/insert; considerar batching, sem retirar unicidade de ocorrência |
| P2 | `secret()` chama `job()` duas vezes; job resolve relações, schema e configurações em cada processo | Contar queries em PostgreSQL; reduzir consultas repetidas e eager loading de site com regressão |
| P2 | Retention 104/108 MiB dry-run/apply; contender esperou ~2,40 s; apply manteve locks por ~29,57 s após aquisição | Paginar por fonte, preservar latest válido e revalidar no delete; medir com jobs concorrentes |
| P2 | Complete PHP usa file_get_contents e hash dentro da transação; Python lê limite inteiro; retry file_server pode manter duas cópias | Streaming de hashing/leitura e transações menores, mantendo identidade/TOCTOU/path safety |
| P2 | Scanner FTP síncrono; 40 uploads com callbacks Artisan/PostgreSQL reais ocuparam 41,27 s | Medir 100/1.000 receipts reais e heartbeat/claims sob essa carga; impor orçamento de scan somente depois |
| P2 | Processing retries a cada ciclo, até 21ª falha→quarantine, sem backoff; Redis bloqueia mutex do schedule:run | Verificar duração de outage tolerável, jitter e backoff sem perder token/estado persistente |
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
Locks/wait events e plano PostgreSQL foram observados no fechamento acima;
ainda falta perfil de transações/EXPLAIN ANALYZE em histórico grande. Não reduzir
proteções de path/identidade para ganhar throughput.

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
logs/views em `/tmp` quando as permissões locais não permitem runtime do app;
a passagem PostgreSQL usa configuração forçada e banco sintético próprio.

Validações finais:

- Python engine: **89/89 passam**, incluindo seis regressões P1 de publicação/retomada.
- Python ftp-admin: **16/16 passam**.
- PureDB: **passa** com portas exclusivas; FTP/FTPS e fluxo PostgreSQL: **passam**.
- Laravel SQLite: **341/341**, 2.089 assertions.
- Laravel PostgreSQL isolado: **341/341**, 2.096 assertions.
- O antigo 340/341 foi resolvido alinhando a expectativa ao texto atual
  `Coleta via SSH`. Fixtures agora usam o FTP root configurado; operações que
  esperam erro de FK usam savepoint; cenários de IDs históricos reiniciam somente
  sequences de tabelas vazias do teste; o teste de conta inválida respeita a
  constraint PostgreSQL. Nenhum código/UI/migration de produção foi alterado
  nesta continuação. Savepoints preservam a transação após erro esperado:
  [PostgreSQL 17](https://www.postgresql.org/docs/17/sql-savepoint.html),
  [transações Laravel 13](https://laravel.com/docs/13.x/database#database-transactions).
- Pint nos testes alterados e script PHP; `php -l`, compilação Python e
  `git diff --check`. O harness também rejeitou ambiente production, SQLite fora
  do workspace e FTP root real; retention_apply SQLite 20 preservou dez arquivos.
- Secret scan anterior encontrou apenas a fixture truncada preexistente de
  `test_driver_contract.py`; revisão das mudanças finais não acrescenta segredo
  ou certificado. Não equivale a gitleaks nem inclui `.env`/storage real.

Para reproduzir cargas PostgreSQL, primeiro criar **um cluster exclusivamente
sintético** com socket `/tmp/bm-perf-1-*/socket`, data directory irmão `pgdata`,
porta 55432 e usuário `perf`; usar runtime PHP com pdo_pgsql:

```sh
PYTHONPATH=engine python3 scripts/perf_baseline.py --php /caminho/php \
  --pg-socket /tmp/bm-perf-1-services/socket --pg-bin /caminho/pg/bin \
  --only engine --output /tmp/perf-pg-engine.json
PUREDB_TEST_PORT=52122 PUREDB_TEST_PASSIVE_PORTS=53100:53109 \
  python3 docker/ftp/test_purepw_integration.py
```

O teste PureDB precisa de privilégios para chown/chroot. A suíte PostgreSQL usa
cópia de phpunit.xml em `/tmp`, com DB_CONNECTION/DB_HOST/DB_DATABASE forçados
para o banco `bm_perf_1_phpunit`, root de FTP/storage e views temporários;
nenhuma migration de teste é permitida contra banco real. Os probes de outage,
barreira, retenção e FTPS ficaram em `/tmp/perf-1-*.py`; seus resultados finais
estão preservados no JSON versionado. Os serviços sintéticos foram encerrados
após a coleta.

**Limites remanescentes:** não declarar aprovação integral em produção dos vinte
cenários. ENOSPC foi injetado, sem filesystem realmente cheio; tmpfs/loopback
ocultam disco/rede; não se mediram power-loss, WAN, equipamentos reais, vários
engines completos em carga, limite global FTP sob 20 sessões nem outages além
da janela de grace. Stale/backoff avançaram relógio apenas no banco sintético.
Paramiko local 3.5.1 difere do pin 4.0.0. Redis sem persistência não testa restore
ou mutex residual. A retenção usa uma fonte e artifacts de 4.320 bytes.
PERF-2 recebe os gargalos acima; nenhuma otimização P2 foi aplicada. Alterar ou
medir o ambiente real continua exigindo confirmação. Sem push.

## PERF-2 — otimizações e comparação

Referência principal: PERF-1 acima, checkout `5096b7a`. Mesmo runtime isolado,
PostgreSQL 17, quatro vCPUs, tmpfs e exports sintéticos; nenhum equipamento,
credencial, arquivo, conta ou serviço de produção foi usado. Resultados detalhados
em `perf_2_execution` de [PERFORMANCE_BASELINE_RESULTS.json](PERFORMANCE_BASELINE_RESULTS.json).
Migrations/seed ficam fora do cronômetro. Comparações por otimização foram
sequenciais; a primeira reprodução do engine coincidiu com seed de retenção e
foi preservada apenas como observação, sem ser a referência canônica.

### BEFORE / AFTER

BEFORE é o fechamento PostgreSQL/FTPS do PERF-1. AFTER é a passagem final do
PERF-2. Uma amostra por cenário, sem intervalo de confiança; os números menores
do transporte variam entre passagens. P95 continua nearest rank.

| Métrica / cenário | BEFORE | AFTER | Variação |
| --- | --- | --- | --- |
| 100 jobs, driver 20 ms: jobs/min | 108,32 | **255,95** | **+136,3%** |
| 100 jobs: latência p95 | 47,11 s | **22,39 s** | −52,5% |
| 100 jobs: queue wait p95 | 46,36 s | 21,57 s | −53,5% |
| FTPS → receipt PostgreSQL, 40 uploads: files/s | 0,510 | **1,423** | +179,0% |
| Receiver → PostgreSQL, mesmo lote: files/s | 0,969 | **22,437** | +2.215,4% |
| FTPS → receipt: p95 do lote | 77,04 s | 28,07 s | −63,6% |
| Retention 10k: dry-run | 2,227 s | **4,746 s** | **+113,1%: regressão** |
| Retention 10k: apply | 29,759 s | **10,267 s** | **−65,5%** |
| Retention: pico heap PHP, dry-run / apply | 104 / 108 MiB | **32 / 34 MiB** | −69,2% / −68,5% |
| Retention dry-run: wall time do contender | 2,397 s | 0,058 s | −97,6%; AFTER sem Lock observado |
| Retention apply: fase após aquisição de locks | 29,565 s | 10,147 s | −65,7% |
| 100 jobs: subprocessos Artisan | 323 | **206** | −36,2% |
| Receiver: subprocessos para callbacks de 40 uploads | 100 na reprodução | **1** | −99,0% |
| `secret()`, 100 jobs: queries | 2.001 na reprodução | **1.101** | −45,0% |
| Retention apply 10k: queries | 9.998 | **67** | −99,3% |
| Retention dry-run 10k: queries | 9 | 47 | +422,2%; hidratação em páginas |
| Engine: RSS Python / maior filho PHP individual | 41,4 / 52,5 MiB | 40,0 / 54,3 MiB | −3,4% / +3,5% |
| Engine: waiters de lock amostrados | 0, observação parcial | 0, lote completo | amostragem a cada 50 ms |

Heap de retenção é `memory_get_peak_usage(true)` em processo PHP novo; RSS do
engine usa `ru_maxrss`, inclusive filhos, e não é a soma dos processos ativos.
O pico amostrado de conexões do workload final de 100 jobs foi **5**, zero waiters
de lock em 457 amostras. O sampler usa outra conexão no banco `postgres`, excluída
desse número; o cluster sintético permite 40 conexões. Não se mediu um pool global
de produção nem p95 de espera de lock. Os 0,058 s do contender incluem conexão e
amostragem; não representam duração de lock. A fase de apply ainda pertence a uma
transação por fonte, com locks acumulados até o commit.

### Evidência por otimização

1. **Receiver/Artisan:** uma sessão local via stdin/stdout executa os comandos
   Laravel existentes e é fechada ao terminar o scan; há limite de 1.000 requests,
   tamanho máximo, timeout e allowlist sem comandos de segredo. Não há regra de
   negócio ou acesso SQL novo em Python. Reprodução anterior: upload 38,03 s,
   scan 40,15 s, total 78,18 s. Primeira passagem validada: scan 1,875 s; final:
   upload 26,31 s, scan 1,783 s, total 28,11 s. **O transporte/TLS não foi
   otimizado**: a mudança comprovada é o custo do receiver, não a variação do
   login/upload. Permanecem 20 succeeded/artifacts, 40 receipts/tokens únicos,
   hashes/tamanhos corretos e zero processing pendente. Outage PostgreSQL depois
   da publicação deixa sidecar; retorno produz 41 arquivos/receipts e 21 artifacts,
   sem duplicação. Erro do transporte não é erro de integridade do arquivo: ele
   preserva a retomada. Caches de filesystem e de timezone são renovados por request.
2. **Queries de secret:** `job()` é resolvido uma vez por chamada, sem cache de
   elegibilidade entre chamadas. 100 resoluções: 2,514 s/2.001 queries →
   1,557 s/1.101 queries. Ownership, revogação de credencial e FD de segredo
   continuam validados; nenhum segredo passa no JSON/stdout da sessão.
3. **Retention:** materializa somente IDs ordenados e hidrata 500 artifacts por
   página, com apenas os campos de execução exigidos por `ArtifactStorage`.
   Verificação, hash, latest válido, inode/TOCTOU e unlink por arquivo permanecem;
   updates de status são agrupados por página e motivo. Apply continua serializado
   pelo lock da fonte e mantém locks dos artifacts até commit. Dry-run não adquire
   locks de fontes/artifacts nem altera seus statuses/arquivos; a auditoria de
   resumo permanece. A primeira paginação repetia o filtro/sort do
   histórico: dry-run 8,23 s; foi rejeitada e substituída pela seleção única de IDs.
   **Tradeoff final:** menor memória e nenhum bloqueio no preview, com dry-run
   mais lento. Reprodução cold do original: 2,536 s/96 MiB → 4,746 s/32 MiB.
   Apply reproduzido: 27,389 s/108 MiB → 10,267 s/34 MiB. Dez arquivos preservados,
   9.990 removidos somente do storage sintético, histórico intacto. IDs ainda
   ocupam O(n) memória; não se declara memória constante para fontes ilimitadas.
4. **Scheduler/Redis:** mutexes e sinais de pause/resume/interrupt usam o store
   database existente. No Laravel 13, mudar só o mutex não basta: `schedule:run`
   consulta pausa no cache. O binding do repository fica restrito aos comandos
   do scheduler; demais caches/telemetria mantêm seus fluxos. Redis indisponível:
   antes `RedisException`/zero ocorrências; depois duas ocorrências em 1,445 s,
   repetição mantém duas. Exclusão do mutex, pausa e retomada também passam.
5. **23505:** rollback e até três tentativas apenas para a constraint
   `backup_executions_one_running_device`; demais erros continuam propagados.
   Barreira multiprocesso: antes um claim e um erro 23505, outro device aguardando;
   depois dois claims de devices distintos, perdedor reclama o terceiro job em
   46,6 ms. Mesma barreira com um único device: um running, outro queued, ambos
   processos sem erro. A colisão física ainda pode acontecer; eliminou-se sua
   propagação para o backoff de cinco segundos do engine, sem retirar a constraint.
6. **Waits/concurrency:** dispatcher espera conclusão de futures enquanto há
   trabalho ativo; poll totalmente idle e falhas continuam com cinco segundos.
   Cinco jobs no mesmo device: 31,31 → 7,70 s nessa etapa. Limite por processo
   configurável via `BACKUP_ENGINE_WORKERS`, entre 1 e 4, default 4. Não foi ampliado.
7. **Reuso no dispatcher:** claims usam uma sessão PHP independente, sequencial,
   com os mesmos comandos, transações e payload sem senha. Antes desta única
   alteração: 100 jobs em 47,66 s, p95 45,77 s, 312 subprocessos. Depois:
   **23,44 s, p95 22,39 s, 206 subprocessos**; CPU dos filhos 110,51 → 60,64 s.
   São dois spawns por job SSH curto (secret/complete), mais sessões amortizadas;
   heartbeat, falha e observação de host key acrescentam comandos conforme o fluxo.
   Timeout/EOF não fazem replay automático de operação que pode ter dado commit.

Todas as passagens finais de engine terminaram com acknowledgment, artifacts
esperados e zero overlap de device. Lote lento: 20 jobs de 2 s em 15,70 s,
76,45 jobs/min. Cinco jobs no mesmo device terminaram em 6,04 s, pico ativo 1.
Comparação final de 20 jobs rápidos, após reuso do dispatcher:

| Workers por processo | Jobs/min | Queue p95 | Latência p95 | Pico conexões | Overlap |
| --- | --- | --- | --- | --- | --- |
| 1 | 57,92 | 19,18 s | 19,93 s | 2 | 0 |
| 4, default preservado | 212,69 | 4,47 s | 5,25 s | 4 | 0 |

V1 consultado em `backup_manager/ftp_importer.py`, `ftp_pipeline.py`,
`ftp_storage.py`, `worker.py`, `jobs.py` e `lifecycle.py`. O claim durável antes de
hash/publicação e o controle no banco serviram de referência; a V1 usa worker
serial e retenção com materialização/trash. Não se importou esse modelo nem se
substituíram as regras/safety checks do V2.

### Validação, reprodução e limites restantes

- Laravel SQLite completo: **349/349**, 2.129 assertions.
- Laravel PostgreSQL completo: **349/349**, 2.136 assertions, banco/root temporários.
- Python completo: **95/95**; ftp-admin completo: **16/16**.
- Pint, `git diff --check`, `php -l` em **147 arquivos** e `py_compile` em
  **32 arquivos** passam. Secret scan local por padrões, sem novos achados;
  somente a fixture truncada preexistente revisada. Não equivale a gitleaks.
- Regressões novas cobrem transporte sem replay, erro/timeout/EOF, reciclagem,
  argumentos, allowlist sem segredo, caches frescos, claim/ownership, dispatcher,
  paginação com datas iguais e arquivos inválidos, latest e mutex/pausa sem Redis.
  O teste de aquisição repetida do mutex PostgreSQL usa conexão autocommit própria:
  a violação de unicidade esperada dentro do `RefreshDatabase` abortava a transação
  externa da fixture. Corrigido o isolamento do teste, sem alteração no vendor.

Scripts versionados para o runtime sintético PERF-1 existente em `/tmp`:

```sh
export LD_LIBRARY_PATH=/tmp/perf-1-runtime/usr/lib/x86_64-linux-gnu
PYTHONPATH=engine python3 scripts/perf_baseline.py \
  --php /tmp/perf-1-runtime/bin/php \
  --pg-socket /tmp/bm-perf-1-services/socket \
  --pg-bin /tmp/perf-1-runtime/usr/lib/postgresql/17/bin \
  --only engine --engine-cases remaining --output /tmp/perf-2-engine.json
python3 scripts/perf_pipeline.py --session --output /tmp/perf-2-ftp.json
python3 scripts/perf_retention.py --output /tmp/perf-2-retention.json
python3 scripts/perf_scheduler.py --output /tmp/perf-2-scheduler.json
python3 scripts/perf_claim.py --other-device --output /tmp/perf-2-claim.json
```

O cluster precisa ser exclusivamente sintético; não apontar esses scripts para
produção. O probe FTPS cria contas/certificado temporários e interrompe apenas
esse PostgreSQL para testar retomada. Scheduler pressupõe Redis sintético offline.
O harness do engine admite `--workers 1|2|4` e `--engine-cases quick` para o lote
20; `--only secret` mede as queries de secret. Os guardrails do PERF-1 permanecem.

**Regressão de performance aceita e explícita:** dry-run leva mais tempo em troca
da queda de memória e ausência de locks; o custo das verificações de segurança
não foi reduzido. Apply ainda mantém transação/locks por fonte por ~10 s; fontes
maiores e arquivos grandes precisam de outra medição antes de fragmentar commits.
Scanner continua serial e pode atrasar claims em lotes grandes; seu paralelismo
não foi aumentado. Secret/complete continuam criando PHP, e hashing de completion
fica dentro da transação. Idle poll de cinco segundos, cap de recovery, N+1 do
scheduler, concorrência global entre processos, disco/fsync físico e WAN continuam
como gargalos/limites. Nenhuma claim de throughput real, saturação ou SLA; sem
novas dependências, migration, restart real ou push.
