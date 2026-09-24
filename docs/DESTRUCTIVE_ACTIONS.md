# Ações destrutivas padronizadas (ADMIN-3)

## Princípios

1. **Preview antes de confirmar.** Toda ação destrutiva mostra o impacto
   (dependências, arquivos afetados, o que é preservado, bloqueios) antes de
   pedir confirmação — nunca um botão que já executa.
2. **Confirmação forte calculada no backend.** O frontend só exibe a frase
   esperada; o backend recalcula e compara com `hash_equals()`. O navegador
   nunca decide o que é "certo".
3. **Backend bloqueia, view apenas esconde.** `$this->authorize(...)` roda em
   todo controller antes de qualquer lógica; `@can`/`@canany` nas views é UX,
   não segurança.
4. **Uma única primitive de remoção física por tipo de recurso.** Para
   `BackupArtifact`, tanto a exclusão manual quanto a retenção automática
   (`BackupRetention`) passam por `App\Services\ArtifactStorage` — nunca duas
   implementações divergentes de "apagar arquivo".
5. **Nada de cascata silenciosa.** Sites, equipamentos, políticas e
   credenciais com dependências ativas bloqueiam a exclusão e mostram o
   motivo — não removem "tudo junto" automaticamente.
6. **Histórico operacional nunca é apagado por uma ação destrutiva de outro
   recurso.** Excluir um artefato nunca apaga a `BackupExecution`; excluir
   uma política nunca apaga execuções antigas.

## Modos conceituais e confirmação

Definidos em `App\Support\DestructiveMode`:

| Modo | Reversível? | Frase de confirmação |
| --- | --- | --- |
| `deactivate` | Sim | `DESATIVAR <nome>` |
| `archive` | Sim | `ARQUIVAR <nome>` |
| `delete` | Depende do recurso | `EXCLUIR <nome>` |
| `delete_with_data` | Não | `EXCLUIR DADOS <nome>` |
| `delete_permanently` | Não | `APAGAR TUDO <nome>` |

`DestructiveMode::confirmationPhrase($mode, $identifier)` monta a frase;
`DestructiveMode::confirmed($mode, $identifier, $provided)` compara com
`hash_equals()`. Este é o mesmo esquema já validado em FTP-CORE-2
(`FtpAccountDeletionService::CONFIRMATION_PREFIXES`), agora generalizado —
`FtpAccountDeletionService` continua com sua própria constante por enquanto
(não foi refatorado nesta fase para não arriscar sua suíte de testes
extensa), mas usa exatamente o mesmo conceito e as mesmas frases.

Nesta fase, `BackupArtifact` usa apenas o modo `delete` (identificador =
`original_filename`). Nenhum outro recurso ganhou uma tela de confirmação
forte própria ainda — Sites/Equipamentos/Credenciais/Políticas continuam com
o `confirm()` simples do navegador que já usavam, com o bloqueio real
acontecendo no backend (ver seções por recurso abaixo).

## Preview genérico

`App\Support\DestructiveActionPreview` é um DTO simples (sem framework de
preview automático): `resourceLabel`, `dependencies` (lista `[label,
count]`), `filesCount`/`filesBytes`, `preserved` (lista de strings),
`removed` (lista de strings), `blockers` (não-vazio bloqueia a ação) e
`warnings` (avisos não-bloqueantes). Cada recurso monta o seu preview à mão
a partir de suas próprias relações — não há introspecção automática de
Eloquent. Isso é deliberado: o objetivo é um contrato de dados comum para a
UI, não um framework genérico de auditoria de relações.

## Componente de UI: `<x-risk-zone>`

`resources/views/components/risk-zone.blade.php` — seção discreta, no fim da
página, com título ("Zona de risco" por padrão), descrição opcional, e um
slot para o botão/gatilho. Não é um componente "mágico" que renderiza modal
e formulário sozinho — cada recurso continua escrevendo seu próprio
`<dialog>`/formulário (como o FTP já fazia), só que agora todos compartilham
o mesmo wrapper visual e a mesma classe CSS (`.risk-zone`). Usado hoje apenas
em `backup-artifacts/show.blade.php`; é o padrão a seguir quando outro
recurso ganhar uma tela de confirmação forte própria no futuro.

## RBAC

Permissões destrutivas adicionadas a `App\Support\Rbac` (todas exclusivas do
papel `admin` — nenhuma foi concedida a `operator`/`viewer`/`auditor`):

| Permissão | Gate em | Observação |
| --- | --- | --- |
| `sites.delete` | `SiteController::destroy` | Antes desta fase, `destroy()` não tinha checagem de dependência alguma — corrigido junto com a permissão. |
| `devices.delete` | `DeviceController::destroy` | `destroy()` já bloqueava por dependência antes desta fase; só a permissão ficou mais restrita. |
| `credentials.disable` | `CredentialController::destroy` | Nome mantido conforme solicitado; na prática gate a exclusão física da credencial (não há endpoint de "disable" separado — desativar continua sendo o checkbox `is_active` do formulário de edição, sob `credentials.manage`). |
| `backup_policies.delete` | `BackupPolicyController::destroy` | `destroy()` já bloqueava por associação ativa antes desta fase; só a permissão ficou mais restrita. |
| `backup_artifacts.delete` | `BackupArtifactController::destroy` | Já existia desde ADMIN-2 (só `admin`); usada agora pela primeira vez por um controller de verdade. |

`ftp.delete` já existia desde ADMIN-2 e não foi alterada.

**Decisão de escopo:** `create`/`edit`/toggle de `is_active` continuam sob a
permissão `.manage` de cada recurso (que `operator` possui) — só a exclusão
física passou a exigir a permissão `.delete`/`.disable`, exclusiva de admin.
Isso bateu exatamente com "Operador: pode ações operacionais normais, mas
NÃO exclusões permanentes críticas" do enunciado. Não havia nenhum teste de
HTTP `DELETE` para Sites/Equipamentos/Credenciais/Políticas antes desta
fase (confirmado por busca no código de testes), então essa mudança não tem
risco de regressão real — apenas os 6 testes que exercitavam esses `DELETE`
diretamente precisaram passar a agir como admin.

## A primitive de storage: `App\Services\ArtifactStorage`

Extraída (não duplicada) do antigo `BackupRetention::verify()`. Único ponto
que resolve, valida e remove o arquivo físico de um `BackupArtifact`:

- **`verify(BackupArtifact $artifact): array`** — re-deriva o path esperado a
  partir da própria `BackupExecution` do artifact (via `EngineJobService`),
  confina ao `realpath(config('backup.storage_root'))`, rejeita `/` como
  raiz, rejeita raiz symlink, verifica symlink em cada segmento do path,
  exige arquivo regular com `nlink === 1`, e confirma que o conteúdo em disco
  ainda bate com `sha256`/`size_bytes` armazenados (proteção contra
  TOCTOU — abre o handle, faz `fstat`, hasheia, e refaz `lstat` depois para
  detectar troca durante a leitura). Retorna `['result' => 'valid', 'path'
  => ..., 'inode' => ...]` só quando tudo passa; qualquer outra chave de
  `result` (`invalid_path`, `invalid_root`, `missing`, `invalid_file`,
  `unreadable`, `size_mismatch`, `hash_mismatch`, `changed_file`) significa
  "não mexa neste arquivo".
- **`remove(BackupArtifact $artifact, ?int $expectedInode = null): array`** —
  chama `verify()` de novo (proteção TOCTOU entre o preview e a confirmação),
  confere o inode esperado se informado, e só então `@unlink()` — nunca
  shell, nunca `rm -rf`. `missing` é tratado como resultado válido e
  idempotente (nada para apagar); qualquer outro resultado que não seja
  `valid`/`deleted` é reportado sem tocar no arquivo.

**Quem usa:** `App\Services\ArtifactDeletionService` (exclusão manual, nova
nesta fase) e `App\Services\BackupRetention` (retenção automática,
refatorado nesta fase para delegar a `ArtifactStorage` em vez de manter sua
própria cópia) e `App\Services\FtpAccountDeletionService` (que já chamava
`BackupRetention::verify()` para o modo `all` da exclusão FTP — atualizado
para chamar `ArtifactStorage::verify()` diretamente). **Não existem mais
duas implementações de "apagar artifact"** — há uma primitive e três
consumidores.

## BackupArtifact — exclusão manual completa

`App\Services\ArtifactDeletionService`:

- **`preview(BackupArtifact $artifact)`** → `DestructiveActionPreview`.
  Bloqueia se: o artifact já está `deleted`; a execução associada está
  `pending`/`queued`/`running`; ou o path falha em `ArtifactStorage::verify()`
  com um resultado que não seja `missing`. Se o arquivo físico já não existe
  (`missing`), isso vira um **aviso**, não um bloqueio — a ação segue
  permitida e apenas limpa o registro lógico (idempotência explícita, ver
  seção Lifecycle abaixo).
- **`delete(BackupArtifact $artifact, int $actorId, ?string $ip)`** — dentro
  de uma `DB::transaction`, relê a linha com `lockForUpdate()`, refaz as
  mesmas checagens de bloqueio (nunca confia só no preview anterior — TOCTOU
  entre a tela e o clique em confirmar), chama `ArtifactStorage::remove()`, e
  só marca `status = 'deleted'` se a remoção física teve sucesso ou o
  arquivo já não existia. Em qualquer falha de remoção que não seja
  "ausente", lança `ValidationException` com mensagem amigável e grava
  `backup_artifact.delete_failed` — **nunca** deixa o registro como
  `deleted` se o arquivo continua no disco por engano.

Rota: `DELETE /backup-artifacts/{backup_artifact}` (`backup_artifacts.delete`).
UI: `resources/views/backup-artifacts/show.blade.php` — nova seção
"Zona de risco" (via `<x-risk-zone>`) com botão que abre um `<dialog>`
mostrando o preview completo (arquivo, equipamento, execução + status,
tamanho, existência física, preservados, avisos, bloqueios) e um campo de
confirmação com a frase `EXCLUIR <nome-do-arquivo>` exibida ao lado do
campo. **Não há ícone de lixeira na listagem** (`backup-artifacts/index.blade.php`)
— a ação só existe na página de detalhe, como pedido.

## Lifecycle / registro

`backup_artifacts` já tinha `status` (`available`/`deleted`/`missing`),
`deleted_at`, `deletion_reason`, `missing_at` desde a fase ADMIN-1 — **nenhuma
migration nova foi criada**. Só foi adicionado o valor `'manual'` à constante
`BackupArtifact::DELETION_REASONS` (e seu rótulo em `deletionReasonLabel()`),
para distinguir uma exclusão manual de uma por retenção automática. O
registro do artifact **nunca é apagado da tabela** — fica com `status =
'deleted'` para sempre, preservando o histórico de que ele existiu.

## Retenção automática

`BackupRetention::run()` manteve exatamente o mesmo comportamento funcional
(mesmos totais retornados, mesma ordem de checagem, mesmo log
`backup_retention`) — a única mudança é que as chamadas internas a
`$this->verify(...)` e o `@unlink()` manual viraram
`$this->storage->verify(...)`/`$this->storage->remove(...)`. Os 10 testes
existentes de `BackupRetentionTest` (incluindo o de ataque por symlink/
traversal/hash/tamanho) passam sem alteração, confirmando que o refactor
preservou o comportamento.

## Falhas parciais

- Se `unlink()` falhar (permissão, disco), o registro **não** é marcado como
  `deleted` — fica `available`, um evento `backup_artifact.delete_failed` é
  gravado, e o usuário vê "Arquivo físico não pôde ser removido." (nunca uma
  stack trace).
- Se o path falhar na validação de segurança, o usuário vê "O caminho do
  arquivo não passou na validação de segurança." — o `result` técnico
  (`invalid_path`, `hash_mismatch`, etc.) fica só no log/auditoria.
- Toda mutação de banco (marcar `deleted`) acontece dentro da mesma
  transação que tenta a remoção física — se o `unlink()` falhar, a
  transação nunca chega a fazer `save()`.

## Auditoria

Ações novas (ver `App\Services\AuditPresenter::ACTION_LABELS`):

| Action | Rótulo | Quando |
| --- | --- | --- |
| `backup_artifact.delete` | Excluir artefato de backup | Exclusão concluída (arquivo removido ou já ausente) |
| `backup_artifact.delete_failed` | Falha ao excluir artefato de backup | `unlink()` falhou ou o path não passou na validação |

Metadata (nunca inclui segredo — artifacts não carregam segredo, mas a
sanitização recursiva do ADMIN-1 continua valendo por defesa em
profundidade): `execution_id`, `device_id`, `relative_path`, `size_bytes`,
`file_existed`, `bytes_removed`, `preserved_execution` (sempre `true`),
`result`.

## Site / Equipamento / Credencial / Política — comportamento nesta fase

| Recurso | O que mudou nesta fase | Comportamento |
| --- | --- | --- |
| **Site** | `SiteController::destroy()` ganhou a checagem que não existia — bloqueia com os nomes/quantidade de equipamentos vinculados; permite excluir só se vazio. | `sites.delete` (admin) |
| **Equipamento** | Só a permissão mudou — o bloqueio por `deviceBackupPolicies`/`credentials`/`ftpAccount` já existia. | `devices.delete` (admin) |
| **Credencial** | Só a permissão mudou — o bloqueio por `deviceBackupPolicies` já existia; desativar via `is_active` continua sob `credentials.manage`. | `credentials.disable` (admin) para exclusão física |
| **Política de backup** | Só a permissão mudou — o bloqueio por `deviceBackupPolicies` já existia; execuções históricas nunca são apagadas. | `backup_policies.delete` (admin) |

Nenhum desses recursos ganhou preview rico (dl com contagens/nomes) ou
confirmação forte nesta fase — a exceção é Site, que ganhou uma mensagem de
bloqueio com nomes resumidos dos equipamentos (via flash `warning`), por ser
a única lacuna real de segurança encontrada (ausência total de checagem).
Isso é deliberado: o enunciado permite "aplicar onde fizer sentido seguro"
sem exigir a mesma UI rica em todos os módulos nesta fase.

## Usuários

Sem alteração de semântica: continua sem exclusão física, apenas
ativar/desativar (`users.manage`), papel e redefinição de senha — exatamente
como entregue em ADMIN-2. `users.disable` **não** foi criado como permissão
separada nesta fase (decisão explícita para não alterar comportamento já
homologado no ambiente real).

## Recursos suportados vs. bloqueados nesta fase

**Suportado com exclusão real:** `BackupArtifact` (com preview, confirmação
forte, path safety, auditoria) e Site (exclusão simples quando vazio, sem
confirmação forte — só bloqueio + mensagem).

**Bloqueado/preservado (sem exclusão nova nesta fase):** Equipamento,
Credencial, Política de backup (todos já bloqueavam por dependência antes
desta fase; só a permissão de quem pode tentar ficou mais restrita),
`BackupExecution` (nunca excluída por nenhuma ação — nem a de artifact, nem
retenção), Usuário (sem exclusão física por design).

## Política futura de "apagar tudo"

Não existe hoje nenhum modo `delete_permanently`/`delete_with_data`
implementado de fato (as constantes existem em `DestructiveMode` para uso
futuro). Antes de implementar um "apagar equipamento + tudo relacionado" ou
"apagar site + tudo", deve-se: (1) decidir explicitamente se
`BackupExecution`/`BackupArtifact` históricos podem ser removidos em
cascata (hoje a resposta é não, por design), (2) reaproveitar
`ArtifactStorage` para qualquer remoção física em lote, nunca reimplementar,
e (3) exigir o modo `delete_with_data`/`delete_permanently` com a frase de
confirmação correspondente, nunca reaproveitar a frase de `delete` simples.
