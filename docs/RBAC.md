# RBAC — Usuários, papéis e permissões (ADMIN-2)

## Propósito

Até esta fase, autorização era binária: `users.is_admin` (booleano) decidia
acesso a três áreas (`/ftp`, `/settings`, `/audit`); todo o resto do sistema
(sites, equipamentos, credenciais, políticas, execuções, artefatos, contas
FTP por equipamento) era acessível a **qualquer** usuário autenticado, sem
distinção. Esta fase substitui esse modelo por papéis (roles) e permissões
centralizadas, sem introduzir RBAC dinâmico/configurável — os papéis e a
matriz são fixos em código (`App\Support\Rbac`), deliberadamente simples e
fáceis de auditar.

Não foi implementado: multiempresa, escopo por site/usuário, SSO/OAuth/LDAP,
tokens de API, exclusão física de usuário, convite/recuperação de senha por
e-mail.

## Papéis

| Papel | Identificador | Descrição |
| --- | --- | --- |
| Administrador | `admin` | Acesso total, incluindo usuários, configurações e auditoria. |
| Operador | `operator` | Opera a infraestrutura no dia a dia (sites, equipamentos, credenciais, FTP, políticas, execuções, artefatos) sem acesso administrativo. |
| Somente leitura | `viewer` | Visualiza tudo que o operador visualiza, sem poder alterar nada. |
| Auditor | `auditor` | Visualização operacional + auditoria; sem mutações. |

`role` é uma coluna `string(20)` em `users` — não um sistema de papéis
dinâmicos com tabela própria. Isso é intencional: a matriz de permissões é
pequena, estável e não precisa de configuração em runtime nesta fase. Se no
futuro isso mudar (papéis customizáveis por instância), `App\Support\Rbac` é
o único lugar a reescrever — nenhum controller ou view depende do formato
interno das permissões.

## Matriz de permissões

Definida inteiramente em `App\Support\Rbac` (constantes `PERMISSIONS` e um
`match` por papel em `permissionsForRole()`):

| Permissão | Admin | Operador | Viewer | Auditor |
| --- | :-: | :-: | :-: | :-: |
| `dashboard.view` | ✅ | ✅ | ✅ | ✅ |
| `sites.view` / `sites.manage` / `sites.delete` | ✅ / ✅ / ✅ | ✅ / ✅ / — | ✅ / — / — | ✅ / — / — |
| `devices.view` / `devices.manage` / `devices.delete` | ✅ / ✅ / ✅ | ✅ / ✅ / — | ✅ / — / — | ✅ / — / — |
| `credentials.view` / `credentials.manage` / `credentials.disable` | ✅ / ✅ / ✅ | ✅ / ✅ / — | ✅ / — / — | ✅ / — / — |
| `ftp.view` / `ftp.manage` / `ftp.delete` | ✅ / ✅ / ✅ | ✅ / ✅ / — | ✅ / — / — | ✅ / — / — |
| `backup_policies.view` / `.manage` / `.delete` | ✅ / ✅ / ✅ | ✅ / ✅ / — | ✅ / — / — | ✅ / — / — |
| `backup_executions.view` / `.run` | ✅ / ✅ | ✅ / ✅ | ✅ / — | ✅ / — |
| `backup_artifacts.view` / `.download` / `.delete` | ✅ / ✅ / ✅ | ✅ / ✅ / — | ✅ / ✅ / — | ✅ / — / — |
| `audit.view` | ✅ | — | — | ✅ |
| `settings.view` / `settings.manage` | ✅ / ✅ | — / — | — / — | — / — |
| `users.view` / `users.manage` | ✅ / ✅ | — / — | — / — | — / — |
| `system_health.view` | ✅ | ✅ | ✅ | ✅ |

Notas:

- `ftp.delete` cobre a exclusão destrutiva de conta FTP (`FtpAdminController::delete`)
  — deliberadamente fora do alcance do Operador, mesmo ele tendo `ftp.manage`
  (criar, rotacionar senha, ativar/desativar, preparar backup).
- `backup_artifacts.delete`, `sites.delete`, `devices.delete`,
  `credentials.disable` e `backup_policies.delete` são as permissões
  destrutivas dedicadas introduzidas em ADMIN-3 (ver
  [docs/DESTRUCTIVE_ACTIONS.md](DESTRUCTIVE_ACTIONS.md)) — todas exclusivas
  do papel `admin`. Até ADMIN-2, a exclusão física desses quatro recursos
  (exceto artifact, que não tinha rota) usava a permissão `.manage`
  correspondente, que o Operador também possui; ADMIN-3 separou "operar"
  de "excluir permanentemente".
- `credentials.view` nunca expõe segredo em texto plano — isso já era
  garantido pelas views antes desta fase (nenhuma view de credencial
  renderiza o campo `secret`) e continua valendo para todos os papéis,
  incluindo quem só tem `credentials.view`.
- Não existe uma permissão `backup_policies.view`-only equivalente a "ver
  detalhe" — como o sistema nunca teve rota `show` para políticas (só
  `index`/`edit`), quem não tem `backup_policies.manage` só vê a listagem,
  não o detalhe/associações de uma política. É uma limitação herdada da
  estrutura de rotas existente, não uma decisão nova desta fase.
- `system_health.view` (ENGINE-3) é concedida a todos os quatro papéis — a
  página `/system/health` é somente leitura, nunca muta nada e não expõe
  segredo algum (ver [docs/ENGINE_HEALTH.md](ENGINE_HEALTH.md),
  "Segurança/sanitização"), então não há motivo para restringir Viewer ou
  Auditor, e Auditor em particular se beneficia diretamente de um
  diagnóstico operacional somente leitura.

## Autorização centralizada

- `App\Support\Rbac`: única fonte de verdade — papéis, permissões e a matriz.
- `App\Models\User::hasPermission()` / `hasRole()`: única forma de checar
  autorização a partir de um usuário.
- `App\Providers\AppServiceProvider::boot()` registra um `Gate::define()`
  para cada permissão de `Rbac::PERMISSIONS`, delegando para
  `$user->hasPermission($permission)`.
- `App\Http\Controllers\Controller` agora usa a trait `AuthorizesRequests`
  do Laravel, e todo controller autoriza com `$this->authorize('permissao')`
  logo no início do método — mesmo padrão em todos os controllers, nada de
  string mágica espalhada sem padrão.
- Views usam `@can('permissao')` / `@canany([...])` (Blade), nunca leem
  `is_admin` nem comparam `role` diretamente.

Não foi criado middleware de rota `can:` nem policies por model — dado que
a autorização aqui é por **ação/recurso**, não por instância de model
(não há regra tipo "só o dono pode editar"), `Gate::define` + `$this->authorize()`
no controller é suficiente e mais simples que Policies por model.

## Compatibilidade com `is_admin`

A coluna `users.is_admin` **permanece no schema**, mas não é mais lida por
nenhuma checagem de autorização (a busca por `is_admin` no código agora
existe só em dois lugares deliberados):

1. **Migration de backfill** (`2026_09_24_000002_add_role_and_status_to_users_table.php`):
   ao adicionar `role`/`is_active`, faz um `UPDATE` único, uma vez, sobre as
   linhas existentes: `is_admin = true → role = admin`; `is_admin = false → role = viewer`
   (o papel mais restrito, por segurança — não assume nada sobre quem essas
   contas são).
2. **Bridge de compatibilidade no model** (`App\Models\User::booted()`, evento
   `creating`): se um código legado (ou teste) criar um `User` só com
   `is_admin`, sem `role` explícito, o model preenche `role` automaticamente
   (`is_admin ? admin : operator`). Note que o fallback aqui é `operator`,
   **não** `viewer` como no backfill da migration — propositalmente: a
   migration é conservadora para contas reais desconhecidas; o bridge do
   model existe para não quebrar código/testes que só conheciam `is_admin`
   e que, com o sistema antigo, davam a qualquer usuário autenticado acesso
   de CRUD a sites/equipamentos/credenciais/políticas/execuções — o que é
   exatamente o que `operator` reproduz.

`is_admin` está formalmente **depreciado** como mecanismo de autorização.
Uma fase futura pode removê-lo do schema; por ora ele só é mantido por
segurança/compatibilidade (rollback do RBAC sem perda de informação) e não
deve ganhar nenhum uso novo.

## Usuário admin existente

A migration preserva qualquer usuário com `is_admin = true` como `role = admin`
e `is_active = true` (novo default da coluna). Não há lockout: o teste
`RbacTest::test_migration_backfills_existing_admin_and_preserves_access`
recria esse cenário (rollback → insere admin legado via SQL cru → reaplica a
migration → confirma `role = admin` e acesso às telas administrativas).

## Proteção do último administrador

`User::isLastActiveAdmin()`: verdadeiro quando o usuário é `admin`, está
ativo, e nenhum outro usuário `admin` ativo existe. `UserController` usa isso
em dois pontos:

- `update()`: rejeita (`422`, erro no campo `role`) trocar o papel do último
  admin ativo para qualquer outro papel.
- `status()`: rejeita (`422`, erro no campo `is_active`) desativar o último
  admin ativo.

Adicionalmente, `status()` bloqueia **qualquer** auto-desativação
(`$user->id === $request->user()->id`), independentemente de haver outros
admins — evitar que alguém se tranque fora do próprio painel é mais
importante do que permitir essa ação em um cenário com múltiplos admins.

Limitação de design conhecida: como `users.manage` só existe para o papel
`admin` nesta fase, a checagem de "último admin" só é de fato alcançável no
caminho de auto-ação (rota HTTP) — um admin nunca consegue desativar/rebaixar
*outro* admin de forma a deixar zero admins ativos, porque o próprio ator
(sendo admin ativo) sempre conta como "outro admin" para o alvo. Isso é
verificado diretamente no model em `UserManagementTest::test_last_active_admin_model_check`.

## Usuários desativados (`is_active`)

- Coluna `users.is_active` (boolean, default `true`).
- `AuthController::store`: após `Auth::attempt` bem-sucedido, se
  `! $user->is_active`, desloga imediatamente e retorna a **mesma** mensagem
  genérica usada para credenciais inválidas: *"Credenciais inválidas ou
  acesso indisponível."* — nunca revela que a conta existe mas está
  desativada (evita um oráculo de enumeração de contas).
- `App\Http\Middleware\EnsureUserIsActive` (alias de rota `active`, aplicado
  em `Route::middleware(['auth', 'active'])` em `routes/web.php`): em toda
  requisição autenticada, se o usuário logado estiver `is_active = false`,
  a sessão é invalidada e o usuário é redirecionado para o login. Isso cobre
  o caso de uma sessão já aberta ser desativada no meio do caminho — não é
  necessário aguardar um novo login para a desativação ter efeito.
- Não há "banimento temporário" nem expiração agendada — é um estado binário
  simples, como pedido nesta fase.

## Tela de usuários

Rotas (todas dentro do grupo `auth`+`active`, protegidas por `users.view`/`users.manage`):

```
GET    /users                      UserController@index    (users.view)
GET    /users/create               UserController@create   (users.manage)
POST   /users                      UserController@store    (users.manage)
GET    /users/{user}/edit          UserController@edit     (users.manage)
PUT    /users/{user}               UserController@update   (users.manage)
PATCH  /users/{user}/status        UserController@status   (users.manage)
POST   /users/{user}/reset-password UserController@resetPassword (users.manage)
```

Não existe exclusão física de usuário — apenas desativação (`is_active = false`).
A view segue o padrão visual já usado por Sites/Equipamentos: listagem com
`page-header` + `toolbar` + `table-shell`/`data-table` + badges; formulários
de criar/editar seguem o padrão "legado" (`panel`/`field`/`form-grid`) já
usado por Sites/Equipamentos/Credenciais/Políticas — nenhum template novo
foi introduzido.

Redefinição de senha é um formulário separado na própria tela de edição
(sem fluxo de e-mail): admin define uma nova senha, o hash é salvo via o
cast `hashed` já existente no model `User`, e um evento de auditoria é
gravado sem o valor da senha. Não existe (ainda) um mecanismo tipo
`must_change_password` — o usuário simplesmente passa a usar a nova senha no
próximo login. Fica documentado aqui como possível melhoria futura.

## Auditoria

Ações registradas em `audit_events` (resource_type `user`, resource_id = ID
do usuário afetado, resource_label = nome do usuário afetado):

| Action | Quando |
| --- | --- |
| `user.created` | Novo usuário criado |
| `user.updated` | Nome/e-mail atualizados sem troca de papel |
| `user.role_changed` | Papel alterado (metadata: `old_role`, `new_role`) |
| `user.enabled` / `user.disabled` | Status alterado (metadata: `old_status`, `new_status`) |
| `user.password_reset` | Senha redefinida por um admin |

Metadata sempre inclui `target_user_id`, `target_user_name` (e
`target_user_email` quando relevante) — **nunca** senha, hash ou token.
`App\Services\AuditPresenter` foi atualizado com rótulos amigáveis para
essas actions, para o `resource_type` `user` → "Usuário", e para formatar
`old_role`/`new_role` (via `Rbac::roleLabel()`) e `old_status`/`new_status`
("Ativo"/"Desativado") no detalhe do evento — a sanitização recursiva de
segredos já existente (ADMIN-1) se aplica normalmente a esses eventos também.

## Views — ocultação condicional

Além do backend bloquear tudo via `$this->authorize()`, a navegação lateral
(`layouts/app.blade.php`) e as telas de FTP/Sites/Equipamentos/Credenciais/
Políticas usam `@can`/`@canany` para esconder links e botões de mutação que o
usuário não pode executar (ex.: Operador não vê "Excluir conta" em `/ftp`,
porque não tem `ftp.delete`; Viewer não vê nenhum botão de criar/editar).
Isso é só UX — a garantia real está no backend.

## Índices / performance

Migration adiciona um índice simples em `users.role` (consultas de listagem
por papel, contagem de admins ativos). Nenhum índice composto foi criado por
falta de necessidade concreta — a tabela `users` é pequena por natureza.

## Futuras integrações com RBAC

- Papéis customizáveis por instância (hoje fixos em `App\Support\Rbac`).
- Permissão dedicada para operações que hoje herdam `ftp.manage` mas que no
  futuro podem merecer granularidade própria (ex.: separar "criar conta FTP"
  de "rotacionar senha").
- `must_change_password` após reset de senha por admin.
- Descontinuar `users.is_admin` do schema, uma vez que nenhum código externo
  dependa dele.
- Auditoria com escopo (registrar histórico de acesso à própria auditoria).
