# Backup Manager V2 — Architecture

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
- Upload concluído não significa backup validado.
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
      -> validação
      -> SHA-256
      -> armazenamento local
      -> STORED
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
job. Estados terminais não são reivindicados novamente. Não há scheduler ou retry.

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
automática. Acione o comando periodicamente por operação externa até existir
scheduler; revise jobs e artefatos órfãos após uma queda. O worker só conclui ou
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

Esta fase não inclui FTP Push, backup binário, outros vendors, retenção física,
storage externo, Telegram, download, scheduler ou retry. Timestamps operacionais
devem ser armazenados de forma consistente em UTC. Futuramente a instância terá
timezone IANA configurável; neste ambiente a apresentação deverá usar
`America/Sao_Paulo`, sem conversões hardcoded espalhadas pelo código.

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
