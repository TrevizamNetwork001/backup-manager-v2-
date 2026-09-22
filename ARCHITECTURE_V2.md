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
