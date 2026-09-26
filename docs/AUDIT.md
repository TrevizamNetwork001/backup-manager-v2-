# Auditoria administrativa (ADMIN-1)

> Este documento registra a implementação inicial de ADMIN-1. O fluxo atual, incluindo Central de segurança, eventos de autenticação, RBAC e a correção para PostgreSQL, está descrito em [CHANGES_2026-09-26.md](CHANGES_2026-09-26.md). As referências abaixo a `is_admin` e à ausência de `audit.view` são históricas; atualmente o acesso usa a permissão `audit.view`.

## Propósito

`audit_events` é a fonte central de auditoria do Backup Manager. Esta fase
(ADMIN-1) transformou uma tabela já usada internamente pelo fluxo FTP em uma
área administrativa consultável: listagem com filtros, paginação real e
detalhe de evento em `/audit`.

Esta fase é apenas de **auditoria/consulta**. Não implementa RBAC completo,
gestão de usuários, exclusão genérica de equipamentos/sites, nem redesenha o
dashboard. A autorização usa o mecanismo já existente (`users.is_admin`,
checado inline nos controllers) — não foi criado middleware, Gate ou Policy
novos.

## Schema

Tabela `audit_events` (migration
`database/migrations/2026_09_24_000001_create_audit_events_and_ftp_deletions.php`,
já existente antes desta fase):

| Coluna | Tipo | Observações |
| --- | --- | --- |
| `id` | bigint | PK |
| `actor_user_id` | FK nullable → `users` | `NULL` = ação do sistema |
| `action` | string(100) | código técnico, formato `recurso.acao[.detalhe]` |
| `resource_type` | string(100) | ex.: `ftp_account`, `backup_execution` |
| `resource_id` | string(100) nullable | id do recurso, como string |
| `resource_label` | string(255) nullable | rótulo legível (ex.: username) |
| `result` | string(30) | `success`, `pending`, `failed`, etc. |
| `ip_address` | inet nullable | IP do ator |
| `metadata` | json | payload livre — **nunca deve conter segredos** |
| `created_at` | timestamp | UTC; sem `updated_at` |

Índices existentes (avaliados nesta fase e considerados suficientes — nenhuma
migration nova foi criada): `(action, created_at)`, `(resource_type,
resource_id)`, `(actor_user_id, created_at)`.

O gravador continua sendo `App\Services\AuditEvents::record()` (inserção via
`DB::table`, inalterado nesta fase). Leitura passou a usar o model Eloquent
`App\Models\AuditEvent` (somente para consulta — `$guarded = ['*']`, sem
timestamps automáticos).

## Append-only

A UI de auditoria é somente leitura. Não existe edição, exclusão ou "marcar
como lido" de eventos — nenhum botão destrutivo aparece na tela. Isso é uma
decisão de produto, não uma restrição de banco: a tabela em si não tem
proteção especial além da ausência de rotas de escrita.

## Sanitização de segredos

`App\Services\AuditPresenter::sanitizeMetadata()` percorre o metadata
recursivamente e substitui por `[REDACTED]` qualquer valor cuja chave
contenha (case-insensitive) um dos termos:

```
password, passwd, pass, secret, token, api_key, apikey, authorization,
cookie, app_key, private_key, credential, confirmation_hash
```

Essa sanitização acontece na camada de apresentação (view de detalhe), mesmo
que o produtor do evento já devesse evitar gravar segredos — é uma defesa em
profundidade, não a única barreira.

## Actions conhecidas → rótulo humano

Mapeadas em `AuditPresenter::ACTION_LABELS`. Ações desconhecidas exibem o
próprio código técnico, sem quebrar a tela.

| Código técnico | Rótulo |
| --- | --- |
| `ftp.account.create` | Criação de conta FTP |
| `ftp.account.delete` | Excluir conta FTP |
| `ftp.account.delete_with_data` | Excluir conta FTP + dados |
| `ftp.account.delete_all` | Excluir conta FTP + todos os dados |
| `ftp.account.password_rotated` | Rotação de senha FTP |
| `ftp.account.enable` / `ftp.account.disable` | Ativação / Desativação de conta FTP |
| `ftp.backup_policy.prepared` | Preparação de backup FTP |
| `ftp.physical.inspect` | Inspeção física FTP |
| `ftp.physical.request` | Solicitação de inspeção FTP |
| `backup.content_analyzed` | Análise de conteúdo de backup |
| `user.created` / `user.updated` / `user.role_changed` | Criação / Atualização / Alteração de papel do usuário |
| `user.enabled` / `user.disabled` / `user.password_reset` | Ativação / Desativação / Redefinição de senha do usuário |
| `backup_artifact.delete` / `backup_artifact.delete_failed` | Excluir artefato de backup / Falha ao excluir (ADMIN-3, ver [docs/DESTRUCTIVE_ACTIONS.md](DESTRUCTIVE_ACTIONS.md)) |

A action técnica original é sempre preservada e exibida no detalhe do
evento, ao lado do rótulo amigável.

## Resource types conhecidos

| Código técnico | Rótulo |
| --- | --- |
| `ftp_account` | Conta FTP |
| `device` | Equipamento |
| `site` | Site/POP |
| `backup_execution` | Execução de backup |
| `backup_artifact` | Artefato de backup |

Tipos desconhecidos exibem o valor técnico.

## Resultados conhecidos

| Valor persistido | Rótulo | Badge |
| --- | --- | --- |
| `success`, `ok`, `completed`, `recognized` | Sucesso | verde |
| `pending` | Pendente | amarelo |
| `partial`, `warning` | Parcial / Aviso | amarelo |
| `failed`, `error` | Falha | vermelho |
| `blocked` | Bloqueado | vermelho |
| `puredb_revoked` | PureDB revogado | azul |

Os valores persistidos no banco não são alterados — apenas a apresentação.
Resultados desconhecidos exibem o valor técnico com badge neutro.

## Filtros (server-side)

Implementados em `AuditController::index`:

- **Período**: `today`, `7d`, `30d` ou `custom` (com `date_from`/`date_to`).
  O cálculo do intervalo usa o timezone de exibição da instância
  (`App\Services\InstanceTimezone`), convertido para UTC apenas na hora de
  consultar o banco (a coluna continua armazenada em UTC).
- **Usuário** (`actor`): id do usuário, ou o valor especial `system` para
  eventos com `actor_user_id IS NULL`.
- **Ação** (`action`), **Recurso** (`resource_type`), **Resultado**
  (`result`): valores exatos, com as opções do `<select>` vindas de
  `SELECT DISTINCT` sobre a própria tabela (colunas indexadas).
- **Busca livre** (`q`): compara contra `resource_label`, `action`,
  `resource_id`, `ip_address` (cast para texto) e `metadata` (cast para
  texto). Usa `ILIKE` no PostgreSQL (produção) e `LIKE` com `CAST(...AS
  TEXT)` em outros drivers (ex.: SQLite nos testes), para evitar depender de
  operadores específicos do Postgres fora dele.

## Paginação

`paginate(50)->withQueryString()`, ordenação `created_at DESC, id DESC`. A
tabela nunca é carregada inteira em memória.

## Timezone de exibição

Toda data mostrada na UI de auditoria passa por
`InstanceTimezone::format()`, a mesma dependência já usada em
`backup-executions`. O banco continua gravando em UTC.

## Eventos de sistema já emitindo para `audit_events`

Sem exigir cobertura de todos os módulos nesta fase, as seguintes operações
administrativas já emitem eventos (as três primeiras foram adicionadas nesta
fase, em `FtpAccountManager`; as demais já existiam):

- Criação de conta FTP (`ftp.account.create`)
- Rotação de senha FTP (`ftp.account.password_rotated`)
- Ativação/desativação de conta FTP (`ftp.account.enable` / `disable`)
- Exclusão de conta FTP, em qualquer modo (`ftp.account.delete*`)
- Preparação de backup FTP (`ftp.backup_policy.prepared`)

### Tabela legada `ftp_account_audits`

Existe uma tabela mais antiga e estreita (`ftp_account_audits`, colunas
`action` limitada a 16 caracteres: `create`/`rotate`/`enable`/`disable`),
gravada por `FtpAccountManager::audit()`. Ela **não foi migrada nem
removida** nesta fase — continua sendo gravada em paralelo a
`audit_events`, mas não é lida pela tela de Auditoria nem por nenhum outro
lugar do sistema. Uma eventual descontinuação dela é decisão de uma fase
futura.

## Futuras integrações com RBAC

Esta fase reutiliza o único mecanismo de autorização hoje existente
(`users.is_admin`, checado inline via `abort_unless` — sem Gate/Policy). Uma
fase futura de RBAC completo poderia:

- Restringir a visibilidade de determinados `resource_type`/`action` por
  papel;
- Definir uma permissão dedicada (`audit.view`) em vez de reaproveitar
  `is_admin`;
- Adicionar auditoria sobre a própria auditoria (quem consultou o quê), se
  necessário para compliance.

Nenhuma dessas mudanças foi implementada agora — o controller e a view estão
isolados o suficiente (`AuditController::admin()`) para que a checagem de
autorização seja trocada por uma Policy/Gate no futuro sem tocar no restante
do módulo.
