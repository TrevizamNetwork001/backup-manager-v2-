# Backup Manager V2 — Architecture

No FTP espontâneo Huawei OLT, a conta de backup provisionada identifica o device pelo home, e uma associação ativa `ftp_push/config/manual` autoriza a execução. O wizard `olt_ftp_integrations` é diagnóstico; a criação da conta prepara a policy sem configurar a OLT remotamente. Excluir e recriar a conta preserva o vínculo operacional.

## Objetivo

O Backup Manager V2 é uma reescrita estrutural do Backup Manager Local.

A V1 permanece como referência funcional e fonte de conhecimento sobre equipamentos,
protocolos, validações e cenários reais de operação. O objetivo da V2 não é portar a
arquitetura antiga literalmente, mas preservar os conceitos que funcionaram e reconstruir
o produto com separação clara de responsabilidades.

O foco inicial do V2 é backup de infraestrutura de provedores de Internet.

Cada instalação deve poder operar localmente dentro do provedor, mantendo os backups
primários no próprio ambiente e permitindo réplicas externas opcionais.

## Princípios

- Laravel é o control plane do produto.
- PostgreSQL é o banco de dados principal.
- Redis é usado para filas, cache e estado transitório.
- Python será utilizado apenas onde fizer sentido como execution engine.
- A interface web não depende do processo Python.
- Código web não executa operações privilegiadas diretamente.
- Credenciais de equipamentos devem seguir princípio de menor privilégio.
- Backups locais são a cópia primária.
- Réplicas externas são tratadas separadamente da cópia local.
- Upload concluído exige ainda integridade física, associação autorizada e armazenamento antes de virar artefato.
- Toda operação relevante deve ser auditável.
- A V2 deve ser instalável de maneira reproduzível.

## Stack inicial

- Debian
- Docker Compose
- Nginx
- PHP 8.4
- Laravel 13
- PostgreSQL 17
- Redis 8

## Componentes

### Nginx

Responsável pela entrada HTTP/HTTPS e encaminhamento das requisições para PHP-FPM.

Somente o diretório `public/` do Laravel deve ser exposto pela aplicação web.

### Laravel

Responsável por:

- autenticação;
- usuários e permissões;
- dashboard;
- sites e POPs;
- equipamentos;
- credenciais;
- políticas de backup;
- agendamentos;
- jobs;
- artefatos;
- histórico;
- retenção;
- restore e download;
- destinos de armazenamento;
- notificações;
- auditoria;
- configurações;
- integrações.

Laravel não deve implementar drivers de equipamentos diretamente nos Controllers.

### PostgreSQL

Fonte persistente principal para os dados de negócio.

Dados físicos do PostgreSQL não são versionados no Git.

### Redis

Utilizado para:

- filas;
- cache;
- locks;
- estado transitório.

Dados físicos do Redis não são versionados no Git.

### Execution Engine

O motor de execução será separado da interface web.

A implementação inicial poderá utilizar Python para reaproveitar conhecimento consolidado
da V1 sobre equipamentos e protocolos.

Responsabilidades esperadas:

- receber jobs;
- conectar aos equipamentos;
- executar operações permitidas;
- gerar artefatos;
- validar resultados;
- devolver status estruturado ao Laravel.

O motor não será responsável por:

- interface web;
- usuários;
- RBAC;
- relatórios de negócio;
- decisões de produto;
- navegação;
- configurações administrativas gerais.

Estrutura conceitual:

    engine/
    ├── core/
    ├── transports/
    │   ├── ssh
    │   ├── telnet
    │   ├── ftp
    │   ├── sftp
    │   └── api
    └── drivers/
        ├── mikrotik
        ├── huawei
        ├── fiberhome
        ├── zte
        ├── vsol
        ├── parks
        ├── cdata
        └── generic

Cada driver deverá declarar suas capacidades.

Exemplos:

- backup_config
- backup_binary
- restore
- inventory
- ssh_pull
- ftp_push

## Artefatos

Um backup será tratado como artefato identificado e validado.

Metadados esperados incluem:

- equipamento;
- operação;
- data e hora;
- tamanho;
- SHA-256;
- formato;
- origem;
- status de validação;
- caminho local;
- estado das réplicas.

Fluxo conceitual:

    job
      -> execução
      -> artefato recebido
      -> estabilização
      -> validação de transporte e integridade
      -> SHA-256
      -> armazenamento local
      -> STORED
      -> análise opcional de conteúdo
      -> réplica externa
      -> verificação remota

## Armazenamento

A cópia local é a fonte primária.

Destinos externos serão abstraídos.

Modelo conceitual:

    StorageTarget
      LocalStorage
      S3Storage
      SftpStorage
      RcloneStorage

Telegram poderá existir como canal adicional de entrega, mas não será considerado
substituto de armazenamento de disaster recovery.

## FTP Push

FTP é um serviço de transferência de arquivos da infraestrutura. `ftp_accounts` tem
identidade própria (`account_uuid`) e finalidade `backup` ou `file_server`. Backup
exige equipamento; `file_server` é standalone e não cria `BackupExecution`.
Contas anteriores à FTP-CORE-1 conservam `home_layout=legacy` e o chroot
`/data/ftp/<device_id>/incoming`. Contas novas usam
`/data/ftp/accounts/<account_uuid>/incoming`. Nenhum diretório legado é movido
ou apagado pela migration; migração física só seria necessária se uma conta
legada fosse explicitamente convertida ao layout novo em uma fase posterior.
O PureDB é reconciliado por conta e usa o home efetivo de cada registro.

`ftp_received_files` registra `processing`, recebimentos armazenados ou enviados
à quarentena. Um claim em retry conserva sidecar com erro e contador de tentativas.
O engine mantém estabilização, claim com identidade, `O_NOFOLLOW`, limite de
tamanho, SHA256 e publicação segura. Para `file_server`, armazena o arquivo em
`BACKUP_STORAGE_ROOT/ftp-files/<account_uuid>/<claim_token>` sem aplicar validador
Huawei. Para `backup`, a execução nasce somente após o claim e segue validação
física, associação com policy e storage de backup. Backup Manager armazena arquivos válidos de transporte independentemente da versão/formato interno. Parsers de vendor são auxiliares e não requisito para retenção. A análise `recognized`, `warning` ou `unknown` pode ser registrada em `audit_events`; não altera `succeeded`, receipt ou download. A expansão de telas de POP e download web fica fora desta fase.

Pure-FTPd usa um perfil global no próprio chroot. Não há permissão individual
configurável: a UI exibe apenas o perfil real. Firmware e distribuição de
firmware ainda não estão implementados.

Pure-FTPd poderá continuar sendo utilizado como serviço FTP/FTPS maduro.

A V2 deve simplificar o código em volta do FTP.

Responsabilidades da aplicação:

- provisionamento controlado;
- credencial individual quando possível;
- correlação do upload;
- estabilização;
- validação;
- hash;
- armazenamento;
- auditoria.

Não haverá servidor FTP escrito em Python.

## Segurança

Serviços web, queue e scheduler não devem executar como root.

Operações privilegiadas futuras devem ficar isoladas em helper ou agente restrito,
com allowlist explícita.

Segredos reais nunca são versionados.

Devem permanecer fora do Git:

- `.env`;
- `app/.env`;
- banco PostgreSQL;
- estado Redis;
- backups;
- uploads;
- quarentena;
- arquivos temporários;
- credenciais reais.

## V1

A V1 é considerada uma especificação funcional executável e uma fonte de conhecimento.

Conceitos importantes a preservar:

- validação de artefatos;
- SHA-256;
- movimentação atômica;
- estabilização de uploads;
- correlação FTP;
- quarentena;
- retenção;
- estados de jobs;
- UUIDs;
- retries;
- auditoria;
- segurança de paths;
- conhecimento dos vendors.

Não devem ser portados estruturalmente:

- aplicação web Python monolítica;
- dispatcher WSGI;
- `app.py` concentrando responsabilidades;
- SQL administrativo espalhado;
- acoplamento entre web e execução;
- proliferação de units/timers sem necessidade.

## Escopo inicial

A primeira versão funcional terá:

- login;
- usuário administrador;
- layout;
- dashboard de Backup Health;
- sites e POPs;
- equipamentos;
- credenciais;
- políticas;
- agendamento;
- jobs;
- SSH Pull;
- FTP Push;
- artefatos;
- histórico;
- retenção;
- download e restore;
- armazenamento local;
- réplica externa;
- auditoria;
- notificações.

SNMP, LLDP, topologia, NMS completo e funcionalidades similares ficam fora do escopo
inicial.

## UX

A interface deve priorizar estado e ação principal.
As diretrizes de identidade própria e modernização visual estão em
[docs/UI_MODERNIZATION.md](docs/UI_MODERNIZATION.md).

Exemplo conceitual de equipamento:

    MK-BORDA-01                         Online
    Último backup: Hoje 03:01           OK
    Política: Diário às 03:00
    Artefatos: .backup + .rsc
    Próxima execução: Amanhã 03:00

    [ Fazer backup agora ] [ Ver backups ]

Ações administrativas e de diagnóstico não devem poluir a tela principal.

Exemplos:

- recriar FTP;
- resetar credencial;
- testar upload;
- reparar integração;
- gerar script.

Essas funções devem ficar agrupadas em áreas de diagnóstico/manutenção.

## Dashboard

O dashboard inicial será orientado a saúde do backup.

Exemplo:

    43 equipamentos

    40 protegidos
    2 atrasados
    1 falhando

    Storage local
    Réplica externa
    Últimas falhas
    Próximas execuções

## Regra de evolução

Novos módulos só devem ser adicionados depois que o núcleo de backup estiver estável,
testado e operacional.

A arquitetura deve permitir evolução futura sem transformar o núcleo de backup em um
monólito de infraestrutura.

## Primeiro backup real: MikroTik SSH Pull config (fase de laboratório)

Uma execução manual nasce `pending`. A ação autenticada **Enfileirar** muda para
`queued` e retorna sem esperar SSH. O container `backup-manager-v2-engine` consulta
localmente `php artisan engine:claim` a cada cinco segundos. Esse comando usa uma
transação PostgreSQL com `FOR UPDATE SKIP LOCKED`, escolhe uma execução por vez e
grava `running` antes de devolver os metadados. Duas instâncias não recebem o mesmo
job. Estados terminais não são reivindicados novamente. Não há retry automático.

O engine chama comandos Artisan locais para acessar o control plane. Ele não acessa
PostgreSQL diretamente nem expõe HTTP. O comando `engine:secret {id}` verifica que
o job está `running` e que a credencial SSH ainda é válida. O Laravel descriptografa
com sua `APP_KEY` e escreve o segredo **somente em um pipe anônimo herdado** pelo
processo Python; stdout, argumentos, arquivos e logs não contêm a senha. O engine
recebe o segredo na memória e executa `/export terse` via Paramiko. Esse comando
não altera a configuração e não solicita `show-sensitive`.

O container engine monta o código Laravel e `app/.env` para poder executar Artisan.
**Risco atual:** quem controlar esse container pode ler a `APP_KEY` e as credenciais
de banco por meio desse volume. O acesso ao container deve ser restrito ao mesmo
nível de confiança do app. Em evolução futura, um broker local por Unix socket
deverá separar essa fronteira. Não há usuário de banco separado para o engine,
pois todas as consultas são feitas pelo Laravel.

O storage é um volume Docker local compartilhado em `/data/backups`: escrita pelo
engine e montagem somente leitura no app. Um serviço de inicialização ajusta a
permissão do volume para o usuário não-root do engine antes de ele iniciar.
`BACKUP_STORAGE_ROOT` configura a raiz. O path usa IDs e
data da criação da execução: `<device-id>/<yyyy>/<mm>/<dd>/execution-<id>-config.rsc`.
O engine confere confinamento à raiz, cria diretórios restritos, escreve um arquivo
temporário com modo `0600`, sincroniza e faz rename atômico. Falhas antes do rename
removem o parcial. Um arquivo órfão após falha no registro deve ser removido em
manutenção controlada; ele nunca é considerado artefato válido.

Antes de `succeeded`, Python e Laravel rejeitam arquivo vazio, acima de 8 MiB, não
UTF-8, binário ou sem linhas de configuração RouterOS. O Laravel reabre o arquivo
somente dentro da raiz, calcula SHA256, registra tamanho e artefato na mesma
transação que marca sucesso. Erros recebem código e mensagem fixa, sem traceback
ou dados da configuração: `SSH_CONNECT_FAILED`, `SSH_CONNECTION_REFUSED`,
`SSH_NEGOTIATION_FAILED`, `SSH_AUTH_FAILED`, `SSH_TIMEOUT`,
`SSH_HOST_KEY_UNKNOWN`, `SSH_HOST_KEY_MISMATCH`, `ENGINE_STALE`,
`UNSUPPORTED_VENDOR`, `UNSUPPORTED_POLICY`, `CREDENTIAL_INVALID`, `EXPORT_FAILED`,
`ARTIFACT_INVALID`, `STORAGE_FAILED`, `ENGINE_FAILED`.

**Host key:** o driver MikroTik usa transporte Paramiko próprio para incluir
`ssh-rsa` entre os algoritmos de host key, sem alterar KEX, cifras, MACs ou
autenticação. Não carrega known_hosts do sistema nem usa `AutoAddPolicy`. A política
calcula `SHA256:` em Base64 sem padding sobre a chave pública apresentada, grava
algoritmo e fingerprint observados no equipamento via Artisan e compara com os
campos confiados. A observação ocorre antes da autenticação e não confia na chave.
Sem confiança, a execução falha com `SSH_HOST_KEY_UNKNOWN`. Uma chave diferente
falha com `SSH_HOST_KEY_MISMATCH`; nunca substitui a confiança automaticamente.
Na tela de edição do equipamento, um usuário autenticado compara a chave observada
por um canal independente e usa **Confiar nesta chave observada**. O servidor copia
somente a observação persistida, sob lock, e registra data e usuário. Alterar o IP
de gerenciamento descarta a observação anterior. Uma mudança legítima de host key
exige nova execução para observar e nova aprovação explícita. Este é TOFU manual
controlado: o primeiro contato não prova a identidade; confirme o fingerprint por
console ou documentação confiável antes de aprovar.
Timeouts: conexão/autenticação/banner 10 segundos,
comando 30 segundos; saída limitada a 8 MiB. O claim atômico grava `claimed_at`,
`heartbeat_at` e um ID aleatório de worker, sem dados de host ou credencial.
Enquanto executa, o engine renova o heartbeat a cada
`BACKUP_ENGINE_HEARTBEAT_SECONDS` (padrão 30). `php artisan engine:recover-stale`
marca até 100 jobs `running` por invocação como `failed`/`ENGINE_STALE` quando o
heartbeat ultrapassa `BACKUP_ENGINE_STALE_SECONDS` (padrão 300); a seleção usa
transação e `FOR UPDATE SKIP LOCKED`, competindo com o update do heartbeat sob o
mesmo row lock. Jobs antigos sem heartbeat usam `started_at`. Não há reexecução
automática. O Laravel scheduler aciona a recuperação a cada minuto;
revise jobs e artefatos órfãos após uma queda. O worker só conclui ou
falha seu próprio job e uma recuperação impede conclusão posterior.

Logs operacionais do Python são JSON estruturado, com IDs, status, código de erro
e duração. Paramiko fica em WARNING ou superior; mensagens informativas de conexão
e autenticação não entram no log normal. Senhas, configuração exportada e detalhes
de exceções não entram nesses eventos. Erros críticos da biblioteca continuam
disponíveis; logs do PHP/Artisan devem ser protegidos como os demais logs do app.

Para executar depois da migration controlada, construir as imagens (`docker compose
build app engine`) e iniciar o engine com `docker compose up -d engine`. O engine não
tem porta pública. Para teste real controlado, cadastrar equipamento MikroTik, uma
credencial SSH de leitura e política `ssh_pull`/`config`; criar execução manual na
política e clicar **Enfileirar**. Conferir execução, artefato e logs estruturados
do container. Testes automatizados usam mocks e não conectam a roteadores.

Esta fase do engine não incluía FTP Push, backup binário, outros vendors,
storage externo, Telegram, download ou retry. A retenção local foi adicionada
posteriormente, conforme descrito abaixo.

## Timezone da instância e scheduler

`application_settings` contém uma única linha (`id=1`) com o timezone IANA da
instância. A instalação começa em `America/Sao_Paulo`. Um administrador pode escolher
qualquer identificador suportado pelo PHP em **Configurações**; o serviço
`InstanceTimezone` valida, lê e formata os horários. A UI exibe a hora atual no
timezone escolhido. O timezone do Laravel permanece UTC para persistência. Trocar
o timezone altera apenas a apresentação e a interpretação das próximas ocorrências;
não reescreve timestamps históricos. Execuções, artefatos e horários de observação e
confiança da host key são apresentados no timezone da instância.

O comando `php artisan backups:schedule` avalia associações, políticas, equipamentos
e credenciais ativos. Políticas manuais são ignoradas. `daily` usa `schedule_time`;
`weekly` usa também `schedule_weekday` com a convenção existente ISO, segunda-feira
= 1 e domingo = 7. O comando recebe um instante controlável no serviço para testes.
Uma ocorrência válida gera diretamente uma execução `queued`, `origin=scheduler`,
`attempt=1`. O engine a reivindica pelo mesmo fluxo de jobs manuais e mantém a
exigência de host key confiada. O scheduler não executa SSH nem lê segredos.

`scheduled_for` identifica a ocorrência lógica em UTC, separada do horário de
criação e do início real pelo engine. A constraint única em
`(device_backup_policy_id, scheduled_for)` impede duplicatas mesmo sob comandos
concorrentes; execuções manuais têm `scheduled_for=NULL`. A inserção com conflito
de unicidade não altera a execução anterior. `withoutOverlapping()`
é uma proteção adicional do agendador Laravel.

A janela é `[horário agendado, horário agendado + N minutos)`, com
`BACKUP_SCHEDULER_GRACE_MINUTES=5` por padrão (aceita 1 a 60). A cada rodada, são consideradas as
ocorrências do dia local atual e do anterior para cobrir a virada da meia-noite.
Não há recuperação retroativa de ocorrências fora
da janela nem catch-up de meses. Horários locais inexistentes por avanço de DST
são ignorados. Horários ambíguos por retorno de DST seguem a interpretação do PHP;
refinar a política para essa rara situação fica para uma fase futura.

O Laravel agenda `backups:schedule` e `engine:recover-stale` a cada minuto. O serviço
Docker `backup-manager-v2-scheduler` executa `php artisan schedule:work` como
`nobody`, sem portas e sem código de engine. Ele precisa estar ativo em produção;
sem ele novas execuções automáticas e a recuperação stale deixam de ocorrer.

Refinamento de UI/UX futuro já decidido: atualização automática da execução sem F5,
indicador “Backup em andamento”, feedback automático de sucesso ou falha e
apresentação automática do artefato após sucesso. Não faz parte desta fase.

## Retenção local e lifecycle dos artefatos

`retention_days` e `retention_count` são limites independentes da política. Um
artefato expira se foi criado há mais de N dias UTC **ou** se ficou fora das N
versões mais recentes. Com ambos configurados vale a união dos candidatos. A
idade usa `created_at` persistido em UTC, sem depender do timezone de exibição.
O ranking é por `created_at DESC, id DESC`, separado por
`device_backup_policy_id`: equipamentos, políticas e associações não competem.
Só entram artefatos registrados, `available`, com `validated_at` e execução
terminal `succeeded`. A execução histórica permanece `succeeded` após limpeza.

Antes de selecionar exclusões, a retenção confere o arquivo de cada artefato do
grupo. O último arquivo disponível e íntegro de cada associação é sempre
protegido, ainda que tenha vencido ambos os limites. Um arquivo ausente não
conta como versão íntegra. `backup_artifacts` preserva a row histórica com
`status=available|deleted|missing`, `deleted_at`, `deletion_reason` e
`missing_at`. Os motivos controlados são `retention_days`, `retention_count` e
`retention_days_and_count`. `missing` significa que o registro dizia disponível,
mas o arquivo não foi encontrado; a aplicação registra a ocorrência e não a
trata como exclusão por retenção. Divergência de tamanho, SHA256 ou identidade
do arquivo é anomalia: bloqueia a exclusão e mantém `available` para investigação.

`php artisan backups:retention` e `--dry-run` apenas calculam e exibem contagens
sanitizadas (`scanned`, `candidates`, `protected_latest`, `missing`, `deleted`,
`anomalies`, `errors`). `--apply` é obrigatório para mudar lifecycle ou remover
arquivos. Opções `--apply` e `--dry-run` juntas são rejeitadas. Os logs operacionais
contêm somente IDs, tamanho, motivo, modo e resultado, sem paths, credenciais ou
conteúdo exportado. Para um ensaio manual futuro, use artefatos **isolados de
teste**, com datas próprias na base de teste; nunca altere timestamps da base
operacional para forçar vencimento.

O apply usa transação e row lock da associação e dos artefatos. Workers de
retenção serializam por associação; uma segunda rodada ignora rows `deleted`.
Antes de `unlink`, confere path derivado da execução, formato permitido,
confinamento à raiz real de `BACKUP_STORAGE_ROOT`, ausência de symlinks, arquivo
regular com um único link, tamanho e SHA256. Revalida imediatamente antes da
remoção. O path vem exclusivamente do registro e é comparado com o path esperado
do job; não há argumento de path nem comando shell. Após `unlink` bem-sucedido,
grava `deleted` e motivo. Se `unlink` falhar, a row continua `available` e o
processamento segue. Banco e filesystem não têm transação conjunta: uma falha
de banco ou queda após `unlink` pode deixar row `available` com arquivo ausente;
a rodada seguinte a marca `missing`, preservando o histórico. Por isso, não há
garantia de atomicidade plena. O volume local deve ser controlado: um processo
externo que altere paths entre a última checagem e o `unlink` ainda pode criar
uma corrida de filesystem. O app e scheduler montam o volume com escrita para
viabilizar apply, ampliando a permissão desses serviços sobre o storage local.

O agendamento automático é diário no timezone IANA da instância, no horário
`BACKUP_RETENTION_TIME` (`04:30` por padrão), com `withoutOverlapping()`.
`BACKUP_RETENTION_ENABLED=false` é o padrão seguro; configure `true` somente
depois de revisar um dry-run no ambiente. O scheduler executa `--apply` quando
habilitado. A leitura do timezone ocorre ao registrar os eventos do scheduler;
após mudar o timezone da instância, reinicie o serviço scheduler para atualizar
esse evento. O timezone do Laravel e os timestamps persistidos seguem UTC.

Refinamentos futuros da UI: feedback automático de backup sem F5, mensagem de
sucesso ou falha, destaque do artefato gerado, labels mais claros para retenção,
ajuda contextual e seleção amigável de timezone por grupos de estados brasileiros.

## APP_KEY e recuperação de credenciais

A `APP_KEY` do Laravel é um ativo crítico do Backup Manager V2.

As credenciais de equipamentos são armazenadas criptografadas usando os mecanismos
nativos do Laravel. A capacidade de descriptografar esses segredos depende da preservação
da mesma `APP_KEY` usada quando os dados foram gravados.

Regras:

- a `APP_KEY` nunca deve ser versionada no Git;
- a `APP_KEY` deve fazer parte do procedimento seguro de backup e disaster recovery;
- restaurar o banco sem restaurar a `APP_KEY` correspondente torna as credenciais
  existentes irrecuperáveis;
- a rotação futura da `APP_KEY` deve exigir procedimento controlado de recriptografia;
- backups da `APP_KEY` devem ser protegidos separadamente dos backups comuns da aplicação.

A recuperação completa do Backup Manager V2 exige, no mínimo:

- banco PostgreSQL;
- `APP_KEY`;
- arquivos/artefatos de backup;
- configuração operacional necessária para reconstruir os serviços.

## Huawei VRP SSH Pull: roteadores e switches

O dispatcher do `engine/backup_engine.py` seleciona explicitamente os drivers
`mikrotik` e `huawei` após normalizar o vendor com trim e casefold. Outros vendors
falham com `UNSUPPORTED_VENDOR`. Ambos usam o mesmo claim, lease, heartbeat,
credencial SSH, observação e confiança explícita da host key, storage local,
conclusão transacional e retenção. O scheduler e a execução manual produzem o
mesmo tipo de job. O driver Huawei não altera KEX, cifra, MAC ou algoritmos de
host key do Paramiko; a inclusão de `ssh-rsa` segue restrita ao driver MikroTik.

O V1 homologou dois perfis: `huawei_vrp` (switch) envia
`screen-length 0 temporary` e depois `display current-configuration` por shell
interativo; `huawei_router` usa `display current-configuration | no-more`.
Na V2, um driver VRP usa o primeiro fluxo e, se o comando de paginação é
recusado pelo CLI, tenta o segundo. Um único driver atende os dois tipos sem
campo obrigatório de subtipo. Se o fallback também for recusado, a execução
falha com `HUAWEI_PAGING_FAILED`. O shell espera os prompts `<HOSTNAME>` e
`[HOSTNAME]` com prazo por etapa, sem presumir o hostname. Marcadores de
paginação `More` durante a leitura também causam falha; saída incompleta não
é armazenada. A homologação com modelos e versões reais ainda é necessária.

Somente `ssh_pull` com `artifact_mode=config` e credencial `ssh` é aceito.
O resultado do comando SSH precisa ser não vazio e caber no limite; mensagens explícitas de erro de CLI, autenticação, HTML e paginação são rejeitadas
no Python e no Laravel. Marcadores de configuração VRP são informativos e não obrigatórios para retenção. O artefato é `type=config`, `storage=local`, SHA256
verificado no registro, escrito via arquivo temporário e rename atômico. O path
mantém IDs/data padronizados e usa `.cfg` para Huawei; `.rsc` permanece para
MikroTik e seus artefatos antigos. A retenção valida ambos os formatos contra
IDs e data do job antes de tocar no arquivo, inclusive se o vendor cadastrado
mudar posteriormente. Novos códigos sanitizados:
`HUAWEI_PROMPT_FAILED`, `HUAWEI_PAGING_FAILED` e `HUAWEI_EXPORT_FAILED`.

Homologação manual futura para **um roteador e um switch**, separadamente:
cadastrar equipamento Huawei e credencial SSH, observar host key por uma
execução, conferir fingerprint por canal independente, confiar na chave,
associar política `ssh_pull`/`config`, enfileirar execução manual, conferir
artefato e SHA256, e depois confirmar execução via scheduler. Não executar
esses passos nos testes automatizados.

Huawei OLT por FTP Push recebe auto-backup espontâneo de conta vinculada ao
equipamento e cria a execução `ftp_received` somente após claim estável. A V2
não configura nem dispara a OLT. `ftp_push/config` permanece com
`schedule_type=manual`; o scheduler ignora políticas FTP Push, inclusive linhas
legadas inconsistentes. O comando `bm-exec-<execution-id>.cfg` mostrado na
execução continua apenas para diagnóstico manual. O fluxo, limites e roteiro de
homologação estão em [docs/HUAWEI_OLT_FTP.md](docs/HUAWEI_OLT_FTP.md).

`BACKUP_FTP_HOST` é o endereço ou hostname usado pela OLT para alcançar o FTP e
mostrado ao operador nos comandos sugeridos. `BACKUP_FTP_PASSIVE_ADDRESS` é o IP
anunciado pelo Pure-FTPd nas conexões passivas. Em rede roteada privada, ambos
podem ser o mesmo IPv4 privado; em homologação com NAT/IP público, podem ser o
endereço externo apropriado à rota da OLT. `BACKUP_FTP_PUBLIC_IP` está deprecated
e serve somente como fallback quando o novo valor não foi definido. Sem ambos,
o lançador não força um endereço passivo.

## FTP-CORE-2

A exclusão de conta FTP usa `FtpAccountDeletionService`: o painel registra o pedido e desativa a conta; `ftp-admin` confirma a publicação do PureDB sem o usuário e conclui a remoção após revalidar claims, paths e vínculos. `audit_events` registra operações destrutivas genericamente. Veja [docs/FTP_CORE_2.md](docs/FTP_CORE_2.md).

## Fronteira de privilégios FTP-CORE-2

O web app calcula impacto lógico e recebe relatórios físicos pelo banco; não inspeciona `/data/ftp`. O ftp-admin, com mount RW e UID 0, deriva paths da identidade da conta, inspeciona, revoga PureDB e limpa dados FTP antes da finalização do Laravel. O app mantém o mount `/data/ftp` RO. Sem relatório físico recente do ftp-admin, exclusão destrutiva fica bloqueada. Ver `docs/FTP_CORE_2.md`.

## ADMIN-1: Auditoria global administrativa

`audit_events` deixou de ser uma tabela de suporte pontual do fluxo FTP e passou a ser a fonte central de auditoria consultável em `/audit` (permissão `audit.view` — ver ADMIN-2 abaixo para o modelo de autorização atual). `AuditController` lista com filtros server-side (período, usuário, ação, recurso, resultado, busca) e paginação real (50/página, `created_at DESC, id DESC`); `/audit/{auditEvent}` mostra o detalhe com metadata sanitizada. `AuditPresenter` converte códigos técnicos (`ftp.account.delete_with_data`, `result`, `resource_type`) em rótulos humanos e redige recursivamente qualquer chave sensível (`password`, `token`, `secret`, `authorization`, etc.) antes de renderizar — a tela nunca expõe segredos, mesmo que o produtor do evento os inclua por engano. A tela é somente leitura (append-only): não há edição, exclusão ou marcação de eventos.

A tabela legada `ftp_account_audits` (log específico de criar/rotacionar/ativar credencial FTP) continua existindo em paralelo e não foi migrada; nesta fase, `FtpAccountManager` passou a também emitir eventos equivalentes em `audit_events` (`ftp.account.create`, `ftp.account.password_rotated`, `ftp.account.enable`/`disable`) para que o ciclo de vida completo da conta FTP fique visível na auditoria global, sem remover o log legado. Detalhes completos em [docs/AUDIT.md](docs/AUDIT.md).

## ADMIN-2: Usuários, papéis e permissões (RBAC)

O controle de acesso binário baseado em `users.is_admin` foi substituído por
papéis (`admin`, `operator`, `viewer`, `auditor`) e uma matriz de permissões
centralizada em `App\Support\Rbac`. `App\Models\User::hasPermission()` é a
única forma de checar autorização; `AppServiceProvider::boot()` registra um
`Gate::define()` por permissão, e todo controller autoriza via
`$this->authorize('recurso.acao')` (trait `AuthorizesRequests` adicionada ao
`Controller` base) — as views usam `@can`/`@canany` para esconder ações que o
usuário não pode executar, mas o bloqueio real é sempre no backend.

`is_admin` permanece no schema por compatibilidade (uma migration faz o
backfill único `is_admin=true → admin`, `is_admin=false → viewer`; o model
tem um bridge equivalente para código legado que só define `is_admin`), mas
deixou de ser lido por qualquer checagem de autorização. Usuários ganharam
`is_active`: desativados não logam (mensagem genérica, sem revelar o motivo)
e sessões abertas são derrubadas na próxima requisição (`EnsureUserIsActive`,
alias de rota `active`). O último administrador ativo não pode ser
rebaixado nem desativado, e autodesativação é sempre bloqueada.

Nova área `/users` (permissões `users.view`/`users.manage`, hoje só o papel
`admin`) permite criar, editar, trocar papel, ativar/desativar e redefinir
senha de usuários — sem exclusão física. Toda ação administrativa relevante
(`user.created`, `user.updated`, `user.role_changed`, `user.enabled`,
`user.disabled`, `user.password_reset`) gera um evento em `audit_events`,
reaproveitando a infraestrutura da ADMIN-1. Detalhes completos, matriz de
permissões e limitações conhecidas em [docs/RBAC.md](docs/RBAC.md).

A migration de `role`/`is_active` foi homologada no PostgreSQL real
(backup `pg_dump --format=custom` antes de aplicar): o admin existente foi
preservado (`role=admin`, `is_active=true`), e a matriz completa
(operator/viewer/auditor) foi validada via HTTP contra a instância real com
três contas de homologação, sem nenhuma divergência frente à suíte
automatizada.

## ADMIN-3: Ações destrutivas padronizadas e exclusão de artefatos

Fundação reutilizável para ações destrutivas: `App\Support\DestructiveMode`
generaliza o esquema de frase de confirmação forte já validado em
FTP-CORE-2 (`DESATIVAR`/`ARQUIVAR`/`EXCLUIR`/`EXCLUIR DADOS`/`APAGAR TUDO
<nome>`, comparado com `hash_equals()` no backend); `App\Support\DestructiveActionPreview`
é um DTO simples de impacto (dependências, arquivos afetados, preservados,
bloqueios) que cada recurso monta à mão; `<x-risk-zone>` é o componente
Blade compartilhado para a seção "Zona de risco" no fim da página de
detalhe.

`App\Services\ArtifactStorage` centraliza a única primitive de remoção
física de `BackupArtifact` (resolve path, confina à raiz configurada,
bloqueia symlink/traversal, exige arquivo regular, confirma hash/tamanho,
remove com `@unlink()`) — extraída do antigo `BackupRetention::verify()` sem
duplicar lógica. `BackupRetention` (retenção automática) e a nova exclusão
manual (`App\Services\ArtifactDeletionService`, primeiro recurso completo
desta fase, com preview + confirmação forte + auditoria) usam exatamente a
mesma primitive, assim como `FtpAccountDeletionService` (atualizado para
não manter sua própria cópia). Execução de backup e equipamento nunca são
apagados por essa ação — só o registro do artifact vira `status=deleted`
(reaproveitando o lifecycle já existente desde ADMIN-1, sem migration nova).

Permissões destrutivas dedicadas (`sites.delete`, `devices.delete`,
`credentials.disable`, `backup_policies.delete`, `backup_artifacts.delete`)
separam "operar" (papel Operador) de "excluir permanentemente" (só
Administrador) — Site também ganhou o bloqueio por equipamentos vinculados
que não existia antes. Detalhes completos, matriz e o que ainda não foi
implementado em [docs/DESTRUCTIVE_ACTIONS.md](docs/DESTRUCTIVE_ACTIONS.md).

## ENGINE-1: Contrato único de drivers do engine

O dispatch de driver do engine Python (`engine/backup_engine.py`), antes um
`dict` fixo por vendor mais um `if olt:` separado, virou um contrato comum
(`engine/driver_base.py::BackupDriver`, com `probe()`/`backup()`/`analyze()`
e `capabilities` declaradas por driver) resolvido por um registry central
(`engine/registry.py` + `engine/registry_setup.py`,
`registry.resolve(vendor, platform, method)`) — sem `if/elif` de vendor
espalhado, mantendo exatamente os mesmos códigos de erro
(`UNSUPPORTED_VENDOR`/`UNSUPPORTED_POLICY`) para combinações desconhecidas.
`BackupError` e todos os códigos de erro já em uso (SSH, Huawei CLI, FTP,
storage) foram centralizados em `engine/errors.py`, sem renomear nenhum —
o mapa de mensagens PT-BR do Laravel (`EngineJobService::fail()`) não
precisou mudar. Resultados passaram a ser objetos estruturados
(`ProbeResult`/`BackupResult`/`AnalysisResult`, `engine/results.py`) em vez
de bytes/exception crus cruzando módulos, e cada código de erro agora tem
uma classificação `retryable`/não-retryable (`is_retryable()`), preparando
— sem implementar — a política de retry automático do ENGINE-2.

MikroTik e Huawei VRP ganharam `probe()` (conectar/autenticar, sem executar
comando) e um `analyze()` best-effort novo; nenhum comando SSH real mudou.
Huawei OLT FTP (`HuaweiOltFtpReceivedDriver`) não tem `probe`/`backup` —
o engine nunca abre sessão com a OLT — e seu `analyze()` delega para a
mesma implementação `storage.analyze_content()` de sempre, preservando
"backup first, parser later" (`docs/HUAWEI_OLT_FTP.md`) sem duplicar a
lógica MA5800. `file_server` continua sem driver (nunca teve parser de
vendor). O canal Laravel↔Python não mudou — continua `subprocess` chamando
`php artisan engine:*`/`ftp:*`, nunca HTTP nem acesso direto ao Postgres
pelo Python. Detalhes completos, catálogo de erros e como adicionar um novo
driver em [docs/ENGINE_DRIVERS.md](docs/ENGINE_DRIVERS.md).

## ENGINE-2: Fila robusta, retry, timeout, stale recovery e cancelamento

O ciclo de vida de `BackupExecution` ganhou os dois estados que faltavam
(`retry_wait`, `timed_out`) e três colunas (`max_attempts`, `next_attempt_at`,
`cancellation_requested_at`) sobre a infraestrutura de claim/heartbeat/stale
que já existia desde ENGINE-1. `EngineJobService::scheduleRetryOrFail()` é o
ponto único que decide, a partir de `is_retryable()` (ENGINE-1) e
`attempt`/`max_attempts`, se um erro reagenda o job (`retry_wait` +
backoff incremental) ou o termina; usado tanto por `fail()` (erro de driver)
quanto por `recoverStale()`, que agora também detecta um segundo tipo de
problema — execução presa além de um orçamento total mesmo com heartbeat
saudável (`engine_execution_timeout_seconds`), não só heartbeat parado.
Cancelamento de um job em execução é cooperativo: `cancellation_requested_at`
é sinalizado pelo Laravel, o worker Python o aprende no próximo
`engine:heartbeat` e confirma via `engine:cancel-ack` — com um checkpoint
extra dentro do próprio loop de polling do driver MikroTik. PostgreSQL
continua sendo a única fonte de verdade do ciclo de vida; Redis não participa
dela (ver justificativa em `docs/ENGINE_QUEUE.md`). Guard de duplicidade por
device adicionado na criação (não só no claim) tanto para execução manual
quanto para o scheduler. Detalhes completos, modelo de estados, e a
comparação com o legado V1 (que tinha lock/heartbeat mais fracos e nenhum
retry ou cancelamento automático de job) em
[docs/ENGINE_QUEUE.md](docs/ENGINE_QUEUE.md).

## ENGINE-3: Health, diagnóstico e observabilidade operacional

Camada de observabilidade sobre o lifecycle do ENGINE-2: um único agregador
(`App\Services\EngineHealth::report()`) roda 15 checks (banco, Redis, engine,
drivers, worker, scheduler, fila, jobs presos, retry, taxa de falha,
equipamentos, storage, FTP, file server, retenção), cada um sempre retornando
um de quatro status uniformes (`App\Support\HealthStatus`:
healthy/warning/critical/unknown), nunca derrubando os demais se um falhar
(fail-soft). Um problema de arquitetura real motivou a peça mais importante
desta fase: o container `app` (Laravel) não tem acesso ao Python/venv do
engine (`compose.yml`), então o próprio processo Python passou a escrever
periodicamente um snapshot JSON atômico e sanitizado
(`engine/health_snapshot.py`) que Laravel só lê — e cuja **idade** já é o
sinal de "engine parado", sem heartbeat separado. Fecha uma dívida do
ENGINE-1 (nenhum teste real Laravel↔Python existia) com
`EnginePythonIntegrationTest`, que roda o interpretador Python de verdade
contra o driver registry real. Um serviço dedicado
(`App\Services\DeviceBackupHealth`) classifica cada equipamento distinguindo
cadência agendada de manual — nunca penaliza um device manual por não ter
backup recente. Página somente leitura em `/system/health`
(`system_health.view`, todos os quatro papéis) e comandos
`engine:health`/`engine:diagnose` (`--json`, exit codes 0/1/2). Redis ganhou
seu primeiro uso real no projeto: heartbeat do scheduler
(`health:scheduler:last_tick`), sempre com fail-soft se Redis cair — nunca
fonte de verdade. Corrigiu, de passagem, uma regressão do ENGINE-2 nunca
coberta por teste (`Carbon::diffInX()` sem `abs()` produzindo idades
negativas). Detalhes completos e a comparação com o legado V1 (que já tinha
quase todos os conceitos certos, espalhados de forma inconsistente entre
subsistemas) em [docs/ENGINE_HEALTH.md](docs/ENGINE_HEALTH.md).
