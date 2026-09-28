# RELEASE-1 — homologação final e preparação para produção

Data: 28/09/2026. **NOT READY para `v2.0.0` neste deployment.**
O core e as suítes sintéticas estão homologados; os gates operacionais abaixo
continuam abertos. Nenhuma tag foi criada. Sem novas features ou tuning.

Base: [CORE_STATUS.md](CORE_STATUS.md), [PERFORMANCE_BASELINE.md](PERFORMANCE_BASELINE.md),
[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) e [UPGRADE.md](UPGRADE.md).
HEAD inicial: `6b342cac03b5040d0ed6b7fcbaa9f3b895c4bb8d`.

## READY — evidência funcional

- [x] Laravel completo em SQLite e PostgreSQL **isolado**, incluindo RBAC,
  login/logout, telas, relatórios/CSV, auditoria, lifecycle e storage.
- [x] Python completo, ftp-admin completo e integração PureDB real **sintética**.
- [x] Worker, scheduler/mutex, retry/backoff, stale recovery, cancellation,
  queue/claim e idempotência cobertos pelas suítes existentes. Equipamentos,
  drivers de rede e dados reais não são alvos desses testes.
- [x] FTP backup/file_server, receipts, quarentena, stale, paths e symlinks
  cobertos em `HuaweiOltFtpTest`, `FtpAdminTest`, testes do receiver e storage.
- [x] Retention dry-run, proteção do mais recente, hash/tamanho/inode e
  download exercitados apenas com arquivos temporários. A suíte também
  remove suas próprias fixtures; nenhum arquivo real é removido.
- [x] Bug de download corrigido: o retorno agora é `BinaryFileResponse`.
  Regressão reproduziu HTTP 500 antes; após correção, admin/operator/viewer
  recebem 200/download, auditor recebe 403 e arquivo adulterado recebe 404.
  Referência: [Laravel — file downloads](https://laravel.com/docs/13.x/responses#file-downloads)
  e `ResponseFactory` da versão instalada.
- [x] Bug de diagnóstico do scheduler corrigido: timestamp numérico recebido
  como string do Redis é validado e interpretado. Antes havia tick recente
  com UNKNOWN; depois, HEALTHY. Timestamp ausente/inválido continua UNKNOWN;
  atraso continua WARNING/CRITICAL, sem mudar thresholds.

## Gates pendentes — produção

- [ ] **Migration legítima pendente**:
  `2026_09_27_000001_add_missing_index_to_backup_executions_ftp_account_id`.
  As outras 25 constam como Ran. `up()` cria apenas um índice em
  `backup_executions.ftp_account_id`; `down()` o remove. Não há migration
  inesperada identificada. A criação normal do índice pode bloquear escritas:
  escolher janela, validar backup e pedir confirmação manual antes de
  `migrate --force`. Não foi aplicada no PostgreSQL real.
- [ ] **Storage acessível ao usuário web**: `/data/backups` está em
  `65534:65534`, modo 0700. Engine/scheduler (`nobody`) têm acesso;
  PHP-FPM (`www-data`, UID 33) não tem leitura nem travessia. CLI root reporta
  storage HEALTHY, mas isso **não homologa download/preview web real**.
  Definir permissões/ACL ou identidade compartilhada com mínimo acesso;
  validar primeiro com arquivo sintético usando UID 33, incluindo paths
  internos, e garantir a mesma regra para novos arquivos do engine.
  Não executar chmod/chown recursivo indiscriminado nem alterar artifacts
  reais durante homologação. O bug PHP corrigido não resolve este gate.
- [ ] **URL oficial e redirect**: configuração observada é
  `https://backup.trevizamnetwork.com.br:8443`. HTTPS local com Host/SNI correto
  responde 200; certificado validado, TLS 1.3, SAN correspondente, validade
  até 07/12/2026. A porta 80 redireciona para `https://backup.trevizamnetwork.com.br/`
  (`:443`, V1), não para o V2. Confirmar URL oficial e plano de convivência
  antes de reconfigurar o proxy. Validação local não comprova DNS/rota externa.
- [ ] **Backend HTTP exposto**: `8081` publica em `0.0.0.0` e `::`, responde
  200 sem redirect e gera cookies sem Secure. Há `trustProxies('*')`:
  o backend deve ficar restrito ao proxy confiável, com headers sobrescritos
  por ele. Preparar bind loopback/ACL e confiança de proxy conforme topologia;
  recriação de container/firewall somente após autorização.
- [ ] **Sessão e headers**: HTTPS observado usa cookie de sessão
  Secure/HttpOnly/SameSite=Lax; config `session.secure=null` depende do esquema
  informado pelo proxy. Definir `SESSION_SECURE_COOKIE=true` para produção e
  rever exposição HTTP. Proxy tem Permissions-Policy; não foram observados
  HSTS, X-Content-Type-Options, X-Frame-Options ou Referrer-Policy no login.
  Preparar headers básicos no proxy e validar antes de autorização de reload.
  Não ativar HSTS incluindo o V1 sem revisar o domínio compartilhado.
- [ ] **Backup pré-release e custódia da chave**: confirmar APP_KEY atual
  em cofre independente e cópia protegida de config/artifacts/FTP. O diretório
  existente contém quatro dumps antigos, de 23–24/09, sem manifestos: não
  equivalem a backup pré-release verificável. Um backup real novo requer
  confirmação explícita antes de executar `system-backup.sh`; ainda não foi
  executado. Validar checksum/tamanho e `pg_restore --list`, sem restore.

## Produção / configuração revisada

| Item | Observação read-only |
| --- | --- |
| Ambiente | `APP_ENV=production`, `APP_DEBUG=false` |
| APP_KEY | Presente e plausível; valor nunca impresso; custódia externa não comprovada |
| Timezone | App/DB timestamps UTC; instância `America/Sao_Paulo` |
| PostgreSQL / Redis | `postgres:5432` e `redis:6379`, conectados, sem portas publicadas no host |
| Cache / sessão / queue config | Redis / database / Redis; sessão JSON, HttpOnly, Lax, encrypt=false |
| Queue do engine | Lifecycle em `backup_executions`/PostgreSQL; Python é o worker; nenhuma classe `ShouldQueue` de aplicação encontrada que exija `queue:work` adicional |
| Scheduler | `schedule:work`; schedule:list confirma backups:schedule e recover-stale a cada minuto; mutex no database; tick Redis recente |
| FTP efetivo | Endereço configurado e passivo coincidem; porta 21; 3 contas backup ativas e 1 file_server ativa; PureDB presente (0600) |
| Receipts reais | 17 stored e 17 quarantined, sem processing no agregado; apenas contagens lidas, sem reprocessar |
| Storage | Volume persistente backups; host cerca de 68 GiB livres em 93 GiB; health calculou 27% de uso; acesso web pendente |
| Retention | Desabilitada; horário configurado 04:30; 4 políticas com 30 dias e 1 com count=1; nenhuma retenção real acionada |

Thresholds mantidos: backlog warning/critical 6/21; scheduler 3/10 minutos;
success rate 90/70% em 24h; storage 80/90%; freshness diária 30/48h e semanal
192/240h; 3 falhas consecutivas; FTP processing stale 15min; cache health 30s.
Engine: stale 300s, timeout 1800s, máximo 3 attempts. Sem recalibração.

## Containers e listeners

Os oito containers estão **running**, todos `restart: unless-stopped`.
PostgreSQL e Redis têm healthcheck Docker **healthy**. App, engine, scheduler,
nginx, ftp e ftp-admin não têm healthcheck Docker; running não prova saúde.
Engine/scheduler usam `nobody`; app tem master root e pool PHP-FPM `www-data`.
FTP/ftp-admin têm configuração de usuário default, necessária ao provisioning
atual; rever endurecimento posteriormente. Nginx tem mounts read-only.
App monta código RW, backups RW, FTP RO e snapshot compartilhado; **nenhum
Docker socket montado no app**. Todos os volumes esperados estão presentes.

| Porta no host | Serviço / avaliação |
| --- | --- |
| TCP 8443 | Proxy HTTPS V2 |
| TCP 8081 | Nginx interno V2, publicado IPv4/IPv6; exposição desnecessária, gate acima |
| TCP 21, 30000–30009 | Pure-FTPd V2, publicado IPv4/IPv6; FTP sem TLS conforme perfil atual |
| TCP 80 / 443 | Proxy compartilhado / V1; redirect atual aponta para V1 |
| TCP 22 | SSH do host; firewall IPv4 restringe origem administrativa |
| UDP 69 | TFTP legado do host/V1; fora do V2, firewall restringe origem específica |
| Loopback 8080, 6011, porta do Codex e 323 UDP | V1/local, sessão SSH, ferramenta e chrony; não alterados |

UFW ativo: deny incoming/routed por padrão; 21 e faixa passiva liberados
globalmente. A faixa permitida **30000–30100** é maior que a utilizada pelo
V2 (**30000–30009**); revisar necessidade do V1 antes de estreitar. Docker
mantém DNAT/forward para 8081: ausência de allow UFW 8081 não prova isolamento.
Não se fez teste externo de alcançabilidade nem alteração de firewall.
FTP público sem TLS exige decisão operacional de rede/origens confiáveis;
FTPS medido em PERF não significa FTPS habilitado em produção.

## HEALTH — classificação final

`engine:health` e `engine:diagnose`: **UNKNOWN**, sem WARNING/CRITICAL nos
checks nativos. O exit code 0 desses comandos não deve ser interpretado
como "tudo saudável".

| Classe | Checks |
| --- | --- |
| HEALTHY (13) | database, redis, engine, driver_registry, scheduler, queue, stale_jobs, retry, failure, devices, storage (CLI root), ftp, file_server |
| WARNING (0) | Nenhum check nativo |
| UNKNOWN (2) | worker: ocioso, sem execução running; retention: nunca executada |
| CRITICAL (0 nativos) | Nenhum check nativo; acesso web ao storage é bloqueio operacional independente |

`system:recovery-check`: **HEALTHY** nos cinco checks (app_key, database,
schema, storage, config), sob CLI root. Não comprova cofre externo, backup
recente, restore íntegro ou permissões de PHP-FPM.
Diagnose não identifica commit dentro do container (`app_commit` desconhecido);
usar o HEAD auditado acima e os commits desta fase como referência.

## Smoke tests e verificações finais

Os testes HTTP passam pelo kernel Laravel em bases sintéticas, sem login
com credencial real. Cobertura: `RbacTest`, `AuditTest`, `SystemHealthTest`,
testes de sites/devices/credentials/FTP/policies/executions/artifacts/users,
`DashboardTimezoneTest`, `ReportsSmokeTest`, `ReportsAuthorizationTest` e
`ReportExportTest`. Status 200/302/403 são verificados por permissão;
download inválido usa 404 esperado. Login→dashboard→logout→guest foi validado
com auditoria do logout. Não houve automação visual de navegador.

| Verificação | Resultado final |
| --- | --- |
| Laravel SQLite completo | 359 testes, 2.186 assertions, passou |
| Laravel PostgreSQL completo | 359 testes, 2.193 assertions, passou |
| Python engine completo | 103 testes, passou |
| ftp-admin completo | 16 testes, passou, sem skips |
| PureDB integração | login/chroot/upload sintético/limite/rotação/desativação passaram |
| php -l | 150 arquivos PHP, sem erros (Blade validado pelos testes HTTP) |
| py_compile | 35 arquivos Python, passou |
| Pint | Aplicado somente aos 5 arquivos PHP alterados, sem alterações restantes de formato |
| Bash / backup | bash -n passou; cópia do script gerou custom dump do DB sintético, manifesto/checksum/tamanho válidos; pg_restore --list passou; parser de fingerprint com fixture sintética |
| Git / secrets | diff --check passou; scan de valores reais do .env e marcadores de chave privada passou; único marcador é fixture truncada em test_driver_contract.py, não uma chave utilizável |

Laboratório exclusivo: `/tmp/bm-release-1`; PostgreSQL 17 com socket Unix
exclusivo, porta lógica 55433, sem listener TCP; data_directory conferido
antes de criação/migration/pg_dump. APP_KEY de teste sintética, caches,
storage, FTP e views isolados; `.env` real não governa esses valores.
PHP CLI de laboratório 8.4.23, produção 8.4.25; Paramiko host 3.5.1,
engine de produção 4.0.0. Essa diferença limita equivalência de runtime;
os testes de driver não autenticam em equipamento real.
Evidência local, não versionada: `/tmp/bm-release-1/{sqlite,pgsql,python,ftp-admin,puredb}.log`,
JUnit das suítes, health/diagnose/recovery JSON e inventário inicial do Git.

## Backup, DR e upgrade — revisão, sem execução real

`system-backup.sh` usa pg_dump custom, exige dump não vazio, calcula SHA-256,
registra tamanho/formato/commit e fingerprint não reversível da APP_KEY.
Não copia a chave ou os artifacts. `database/backups/` é ignorado pelo Git.
O teste usa cópia do script e substitui somente suas chamadas Docker por
um dump do banco sintético e resposta de recovery de fixture; isso valida
o fluxo, sem certificar um backup real nem a custódia da chave.
Executar backup real futuro com umask 077 e destinação protegida/off-host:
os dumps antigos observados têm modos 0644/0664 e contêm dados sensíveis.

Ordem segura a seguir na recuperação: manter engine/scheduler/ftp-admin/FTP
parados; restaurar config e APP_KEY exata; subir somente dependências de DB;
validar checksum e restaurar DB; restaurar artifacts **e ftp-data/ftp-db**
(incoming/processing/sidecars/quarantine e contas PureDB); conferir paths e
permissões por UID; revisar migrate:status; recovery-check e smoke sintético;
só então liberar os processos que escrevem/recebem jobs. Dumps não contêm
os arquivos nem a chave. Preservar snapshots de config e volumes separados.
O passo genérico de "subir a stack" do guia DR deve ser interpretado com
essa ordem para não liberar workers antes da recuperação completa.
Não se executou restore, key:generate ou teste contra equipamento.
Upgrade com mudanças de Compose exige recriação (`up -d`), não simples
restart; ambos ficam pendentes de autorização na instância real.

## WARNINGS

- UNKNOWN de worker ocioso e retenção desabilitada mantidos como ausência
  de evidência. Não criar jobs reais ou rodar retenção para "limpar" health.
- FTP sem TLS, faixa de firewall maior que o perfil V2, ausência de probe
  TCP FTP no health e ausência de healthchecks de seis containers.
- Recovery/health sob root não verificam leitura por UID web; não substituem
  os gates operacionais ou ensaio futuro de DR em destino independente.
- Alterações preexistentes preservadas: `app/AGENTS.md`, trechos originais
  em CORE_STATUS/DR/UPGRADE/UI_MODERNIZATION, `system-backup.sh`, três imagens
  deletadas em `referencia/` e `docs/IDEIAS_FUTURAS.md` não rastreado. Não
  foram incluídas nos commits desta fase. O worktree permanece sujo.

## PÓS-RELEASE

- Monitorar taxas/freshness, capacidade, receipts/quarantine e backlog com
  dados reais; confirmar políticas e janela de retenção antes de habilitar.
- Exercitar DR em destino independente com autorização e verificação de
  custódia da chave/volumes, sem sobrescrever produção.
- Registrar identidade da versão dentro dos diagnósticos e monitoramento
  externo de containers; avaliar endurecimento de usuários/mounts.
- Refinamentos visuais restantes e dívida P2 documentada no CORE_STATUS.

## V2.1 / FUTURO

Manter o escopo já documentado: órfãos/lixeira de artifacts, invalidação de
sessões em reset administrativo, export/import da instância, metadata VRP,
XLSX/PDF, notificações e MikroTik FTP Push. Nenhum desses itens foi criado
nesta fase. Novo tuning somente com gargalo medido, conforme PERF-3.

## Fechamento / tag

Commits `fix:` separados para bugs e `chore: prepara backup manager v2 para release`
para esta documentação. Stage explícito por arquivo/hunk, sem add . / add -A.
Sem push. **Não recomendar nem criar `v2.0.0` enquanto os gates pendentes
não forem resolvidos e o estado do Git não estiver reconciliado.**

Confirmado: nenhum equipamento real alterado; nenhum backup ou artifact real
removido; nenhum segredo exposto; nenhum restart/migration real/firewall/
certificado executado sem autorização; nenhum restore, tag ou push.
