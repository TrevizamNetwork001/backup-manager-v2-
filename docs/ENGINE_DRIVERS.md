# Contrato de drivers do engine (ENGINE-1)

## Objetivo

Antes desta fase, o engine (`engine/backup_engine.py`) escolhia o driver de
backup com um `dict` fixo (`{'mikrotik': export_config, 'huawei':
export_huawei_config}`) mais um `if olt: ... else: ...` separado para o fluxo
FTP da OLT — cada função de driver tinha uma assinatura diferente, e a
classe de erro `BackupError` era definida em `drivers/mikrotik_ssh.py` e
importada de lá por quatro outros módulos. Esta fase não muda **nenhum**
comando real enviado a um equipamento, nem o protocolo Laravel↔Python (que
continua sendo `subprocess` chamando `php artisan engine:*`/`ftp:*`) — só
padroniza a estrutura interna do lado Python para que adicionar um novo
vendor não signifique mais um novo `if vendor == ...`.

## Separação transport / analysis / storage / business rule

- **Transport** (Python, dentro de cada driver): conectar, autenticar,
  executar comando ou receber payload, verificar integridade física,
  devolver bytes/path/metadata técnica.
- **Analysis** (Python, `driver.analyze()`): reconhecer vendor/formato,
  extrair hostname/versão, gerar warnings — **sempre informativo, nunca
  motivo para rejeitar um backup transportado com integridade** (princípio
  "backup first, parser later", já documentado em `docs/HUAWEI_OLT_FTP.md` e
  preservado integralmente aqui).
- **Storage**: continua em `engine/storage.py` (`store()`,
  `validate_received_file_integrity()`) — não foi tocado nesta fase.
- **Business rule**: continua 100% no Laravel (`EngineJobService.php`) —
  RBAC, sites, lifecycle administrativo, retenção, exclusão, auditoria de
  usuário. O Python nunca decide nada disso; a única lógica que o Python
  mantém (e já mantinha antes desta fase) é a elegibilidade estrita de
  vendor/platform/method/schedule/origin necessária para saber **qual driver
  chamar** — isso não foi movido para o Laravel nesta fase porque já existia
  e alterá-lo teria risco de regressão sem benefício arquitetural real (o
  Laravel já calcula `eligible` independentemente e o Python revalida).

## Contrato de driver

`engine/driver_base.py`:

```python
class BackupDriver(ABC):
    name = 'unknown'
    vendor = 'unknown'
    transport = 'unknown'
    capabilities = frozenset()

    def probe(self, context) -> ProbeResult: ...
    def backup(self, context, secret=None) -> BackupResult: ...
    def analyze(self, payload, context=None) -> AnalysisResult: ...
```

Um driver só implementa os métodos das capabilities que declara —
`capabilities` é a lista de verdade; o registry/dispatcher nunca chama um
método que o driver não declarou. Chamar um método não implementado levanta
`NotImplementedError` (erro de programação, não deve acontecer em produção
porque o dispatcher sempre confere `capabilities` antes).

Exceptions internas (`engine/driver_base.py`), todas subclasses de
`BackupError` (então qualquer `except BackupError` existente continua
funcionando sem alteração): `DriverAuthError`, `DriverTimeoutError`,
`DriverCommandError`, `DriverProtocolError`, `DriverValidationError`. Nenhum
driver atual foi migrado para levantar essas subclasses especializadas em
vez de `BackupError(code)` direto — elas existem para uso incremental futuro
sem quebrar o catálogo de código existente.

## Contexts

`engine/contexts.py` — `ProbeContext` e `BackupContext`, dataclasses simples
com apenas os campos que um driver precisa (device id, host, port, username,
vendor, platform, method, timeouts, host key observado). **Nunca carregam o
segredo** — a senha continua sendo passada como argumento explícito de vida
curta (`secret=...`, `del password` logo após o uso), exatamente como antes.

## Resultados estruturados

`engine/results.py` — `ProbeResult`, `BackupResult`, `AnalysisResult`
(dataclasses com `to_dict()` para logging estruturado). `BackupResult.to_dict()`
**nunca inclui `payload`** (o conteúdo bruto do backup) — só metadados. Nenhum
driver levanta exception para fora de `backup()`/`probe()`: internamente
capturam `BackupError` e devolvem um resultado com `success=False` e o mesmo
`code` de antes — o dispatcher (`backup_engine.execute()`) então decide o que
fazer (hoje: relança como `BackupError` para manter o fluxo de
`engine:fail` idêntico ao anterior).

## Catálogo de erros

`engine/errors.py` — `BackupError` (movida para cá; `drivers/mikrotik_ssh.py`
reexporta para compatibilidade, então `from drivers.mikrotik_ssh import
BackupError` — usado em `huawei_vrp_ssh.py`, `storage.py`,
`ftp_incoming.py`, `ftp_spontaneous.py`, `backup_engine.py` — continua
funcionando sem alteração) + todas as constantes de código já em uso antes
desta fase (`SSH_AUTH_FAILED`, `HUAWEI_PAGING_FAILED`, `FTP_FILE_INVALID`
etc.), agora num único lugar em vez de strings soltas repetidas.

**Decisão deliberada: os códigos não foram renomeados.** O catálogo sugerido
na tarefa (`auth_failed`, `connection_timeout` em minúsculas) seria uma
segunda taxonomia paralela à que já existe e que o mapa de mensagens
PT-BR do Laravel (`EngineJobService::fail()`) já usa em produção — renomear
exigiria uma tabela de tradução dos dois lados só por estética, sem ganho
funcional, com risco real de dessincronizar Python e Laravel. Os códigos
existentes já formam um catálogo coerente por domínio (SSH, Huawei CLI, FTP,
storage, dispatch); esta fase apenas os centraliza.

`is_retryable(code)` classifica cada código como retryable ou não
(`RETRYABLE_CODES`/`NON_RETRYABLE_CODES`, ver tabela abaixo) — campo
preparado para a política de retry automático do ENGINE-2, **nenhuma
lógica de retry foi implementada** nesta fase.

| Retryable | Códigos |
| --- | --- |
| Sim | `SSH_TIMEOUT`, `SSH_CONNECTION_REFUSED`, `SSH_CONNECT_FAILED`, `SSH_NEGOTIATION_FAILED`, `FTP_RECEIVE_TIMEOUT`, `STORAGE_FAILED`, `ENGINE_FAILED` |
| Não | `SSH_AUTH_FAILED`, `SSH_HOST_KEY_UNKNOWN`, `SSH_HOST_KEY_MISMATCH`, `ARTIFACT_INVALID`, `EXPORT_FAILED`, `HUAWEI_PROMPT_FAILED`, `HUAWEI_PAGING_FAILED`, `HUAWEI_EXPORT_FAILED`, `UNSUPPORTED_VENDOR`, `UNSUPPORTED_POLICY`, `FTP_ACCOUNT_UNAVAILABLE`, `CREDENTIAL_INVALID`, `FTP_FILE_INVALID`, `invalid_account`, `unsupported_device`, `missing_backup_policy`, `invalid_backup_policy` |

Código desconhecido → `is_retryable()` retorna `False` (fail closed).

## Registry

`engine/registry.py` (`DriverRegistry`) + `engine/registry_setup.py` (a
instância única, populada uma vez):

```python
registry.register(('mikrotik', 'network', 'ssh_pull'), MikroTikRouterOsSshDriver())
registry.register(('huawei', 'network', 'ssh_pull'), HuaweiVrpSshDriver())
registry.register(('huawei', 'olt', 'ftp_push'), HuaweiOltFtpReceivedDriver())
```

`registry.resolve(vendor, platform, method)` substitui o antigo `dict` +
`if olt: ... elif pull: ...` em `backup_engine.py::execute()`. Comparação
case-insensitive (`.strip().casefold()`), igual ao comportamento anterior.
Combinação desconhecida levanta **exatamente os mesmos códigos de antes**:
`UNSUPPORTED_VENDOR` se o vendor não tem nenhum driver registrado,
`UNSUPPORTED_POLICY` se o vendor existe mas a combinação
platform+method não está registrada para ele.

## Capabilities por driver

| Driver | `capabilities` | Observação |
| --- | --- | --- |
| `mikrotik_routeros_ssh` | `probe`, `backup`, `analyze` | `analyze()` é novo nesta fase — heurística simples (conteúdo começa com `#`), não existia parser MikroTik antes; ver limitação abaixo. |
| `huawei_vrp_ssh` | `probe`, `backup`, `analyze` | `analyze()` é novo — procura `sysname` na saída, mesma limitação de confiança. |
| `huawei_olt_ftp_received` | `analyze`, `received_payload` | Sem `probe`/`backup` — o engine nunca abre sessão com a OLT; o payload chega sozinho. `analyze()` delega para a única implementação existente (`storage.analyze_content`), sem duplicar a lógica MA5800. |

`file_server` (FTP genérico) **não tem driver** — nunca teve parser de
vendor, nunca cria `BackupExecution`/`BackupArtifact` (só recibo em
`ftp_received_files`), e continua assim. Não faz sentido "encaixar" um
recurso sem análise de conteúdo num contrato pensado para análise —
documentado aqui como decisão explícita, não lacuna.

`restore` não foi implementado em nenhum driver (fora de escopo desta fase,
por instrução explícita).

### Limitação conhecida: `analyze()` de MikroTik e VRP é heurístico

Diferente do parser Huawei OLT (validado com fixture real MA5800 e já em
produção desde antes desta fase), o `analyze()` de MikroTik e Huawei VRP é
**novo, best-effort, sem fixture de produção validando o formato exato**.
Ele nunca bloqueia nada (é só informativo), mas não deve ser tratado como
tão confiável quanto o parser OLT até ser observado contra exports reais.

## Probe

`probe()` conecta e autentica, **nunca executa comando de configuração**.
Implementado para MikroTik (`probe_connection()`, conecta e mede latência,
sem `/export terse`) e Huawei VRP (`probe_connection()`, conecta, autentica
e espera o prompt inicial, sem `display current-configuration`). Nenhuma UI
nova usa esse resultado ainda — é infraestrutura pronta para uma tela de
"testar conexão" futura, sem redesenhar tela nesta fase.

## Fluxo FTP recebido (OLT + file_server)

Sem alteração de comportamento. `ftp_spontaneous.py` (auto-backup real,
chamado direto do loop principal, nunca passa por `execute()`/registry) e
`ftp_incoming.py` (diagnóstico manual, via `bm-exec-<id>.cfg`) continuam
chamando `storage.analyze_content()` diretamente — a mesma função que
`HuaweiOltFtpReceivedDriver.analyze()` agora expõe através do contrato. Não
há duas implementações de parser: há uma função e dois pontos de entrada
(o antigo, direto, já testado; o novo, via driver, para quem quiser passar
pelo registry no futuro).

`collect_huawei_olt_config()` (`drivers/huawei_olt_ftp.py::collect_config`)
continua exatamente como antes — só passou a ser resolvido via
`registry.resolve(...)` em vez de um `if olt:` inline em `backup_engine.py`.

## Laravel ↔ Engine

**Sem alteração do canal de comunicação.** Continua sendo `subprocess.run`
chamando `php artisan engine:claim|secret|complete|fail|observe-host-key|heartbeat`
e `ftp:expected|accounts|receive|receipt` — nunca HTTP, nunca acesso direto
ao Postgres pelo Python. O "resultado estruturado" desta fase existe **dentro**
do processo Python (drivers devolvem `BackupResult`/`ProbeResult`); ele não
virou um novo payload JSON trocado com o Laravel porque isso exigiria mudar
o contrato Artisan existente (que já funciona e já está coberto por
`EngineJobService.php`) sem necessidade real — o dispatcher extrai
`result.payload`/`result.code` e continua chamando exatamente os mesmos
comandos Artisan de antes, com os mesmos argumentos.

## Logging

Sem alteração no formato de log já existente (`backup_engine.py` já loga
`execution_id, device_id, policy_id, status, error_code, duration_seconds`
em JSON de uma linha, e o filtro de segredos já existente em
`test_paramiko_info_is_filtered_and_worker_id_is_safe`/
`test_secret_and_exception_details_are_absent_from_logs` continua passando
sem alteração). Nenhum novo campo de log foi adicionado.

## Compatibilidade

- Assinaturas antigas preservadas: `export_config()` (MikroTik e Huawei VRP)
  e `collect_config()` (OLT FTP) continuam existindo e funcionando —
  `MikroTikRouterOsSshDriver.backup()`/`HuaweiVrpSshDriver.backup()` chamam
  essas mesmas funções internamente, não as substituem.
- `BackupError` continua importável de `drivers.mikrotik_ssh` (reexport).
- Testes Python existentes (`test_engine.py`, `test_ftp_spontaneous.py`) —
  67 testes, incluindo os 2 novos ajustes de `patch.object` necessários
  porque `backup_engine.py` deixou de importar `export_config`/
  `export_huawei_config` diretamente para o próprio namespace (agora
  chamados de dentro dos módulos de driver) — os testes que faziam mock
  desses nomes foram atualizados para fazer o patch no módulo correto
  (`drivers.mikrotik_ssh`/`drivers.huawei_vrp_ssh`), sem mudar o que é
  testado.
- Suíte Laravel (231 testes) roda sem alteração — nenhum arquivo PHP foi
  tocado nesta fase.

## Adicionando um novo driver

1. Criar `engine/drivers/<vendor>_<transport>.py` com uma classe herdando
   `BackupDriver`, declarando `capabilities` só com o que ela de fato
   implementa.
2. Reaproveitar `errors.py` para qualquer código de erro novo — só criar um
   código novo se nenhum existente descrever a falha.
3. Registrar em `engine/registry_setup.py`:
   `registry.register(('<vendor>', '<platform>', '<method>'), MeuDriver())`.
4. Se o driver expõe `backup()`, `backup_engine.py::execute()` já sabe lidar
   com ele automaticamente (não precisa tocar no dispatcher) — só o caso
   `received_payload` (payload que chega sozinho, sem sessão ativa do
   engine) precisa de um branch dedicado, como o da OLT.
5. Escrever testes em `engine/tests/` seguindo o padrão de
   `test_driver_contract.py` (mock da função de transporte real, nunca uma
   conexão de verdade).

## Comparação com V1

Antes de implementar o contrato, o legado (`/opt/backup-manager-local`, um
projeto Python maduro com anos de produção e incidentes reais documentados)
foi consultado como fonte de conhecimento operacional — não como código a
portar. Achados relevantes ao escopo desta fase, classificados:

| Comportamento no V1 | Classificação | Decisão na V2 |
| --- | --- | --- |
| Classe base de driver por vendor com métodos sobrescritos (`test_commands`, `backup_commands`, `validate_output`) | **PRESERVAR CONCEITO** | É exatamente a forma de `BackupDriver` (`probe`/`backup`/`analyze` + `capabilities`) desta fase — validação independente de que o formato é o caminho certo. |
| Huawei VRP: shell interativo + regex de prompt até estabilizar, limite de bytes | **PRESERVAR CONCEITO** | Já era a abordagem da V2 antes desta fase (`huawei_vrp_ssh.py`); confirmado que é a mesma lição aprendida pelo V1. |
| `normalize_ssh_exception()` mapeando exceções brutas para códigos estáveis | **PRESERVAR CONCEITO** | Já era o padrão da V2 (`BackupError(code)`); esta fase só centralizou os códigos em `errors.py`. |
| `validate_output()`: rejeita saída vazia, curta (`MIN_OUTPUT_BYTES=20`) e com marcadores de erro de CLI | **PRESERVAR CONCEITO (parcial)** | V2 já rejeita vazio/erro/paginação (`validate_ssh_command_output`); **não tem** um mínimo de bytes plausível como o V1. Registrado como melhoria candidata, **não implementada nesta fase** (mexeria em `storage.py`, compartilhado e já testado, sem necessidade concreta reportada ainda). |
| RouterOS: detecção de versão (major 6.43+/7) antes de aceitar | **ADIAR** | Não implementado — exigiria um comando extra (`/system resource get version`) que a V2 não executa hoje; fica para uma fase que precise dessa informação de verdade (ex.: matriz de suporte por versão). |
| RouterOS: script + scheduler instalado remotamente no próprio equipamento ("managed backup") | **ADIAR** | Recurso bem maior que um driver de backup — é gerência remota de agendamento no equipamento. Fora do escopo de ENGINE-1; não deve ser um driver, seria uma feature própria se algum dia for necessária. |
| RouterOS: sinalizar conteúdo sensível embutido no export (`/user ... password=`, `/tool fetch`, `/system script/scheduler add`, chave privada PEM) como "suspicious" | **MELHORAR — aplicado nesta fase** | Único achado do V1 diretamente aproveitável e de baixo risco: adicionado a `MikroTikRouterOsSshDriver.analyze()` como `warnings` (nunca bloqueia, só sinaliza — ver `_SENSITIVE_EXPORT_MARKERS` em `drivers/mikrotik_ssh.py`), com testes dedicados. |
| Huawei: comando de backup disparado via SSH que instrui a própria OLT a fazer FTP push (`backup configuration ftp <host> <file>`) | **DESCARTAR** | Não se aplica — a V2 nunca dirige a OLT por SSH; o auto-backup já é configurado no equipamento e chega sozinho via FTP (arquitetura deliberadamente diferente, já documentada em `docs/HUAWEI_OLT_FTP.md`). |
| Bloqueio de caracteres de shell em "comando customizado" genérico | **DESCARTAR** | V2 não tem conceito de comando customizado por política — cada driver roda um comando fixo, não há superfície de injeção equivalente. |
| Correlação de upload FTP por `run_id` embutido no nome do arquivo (MikroTik) / lista de `expected_files` + deadline (OLTs genéricas) | **MELHORAR — já feito diferently na V2** | A V2 correlaciona por conta FTP dedicada por equipamento (`ftp_accounts`/`home_layout`), não por parsing de nome de arquivo — mais simples e sem depender de convenção de nomenclatura. Nenhuma mudança necessária, só registrado que a V2 já resolveu isso de forma mais robusta. |
| Estabilização por comparação de `size`+`mtime_ns` entre varreduras consecutivas, com N segundos sem mudança | **PRESERVAR CONCEITO** | Já é exatamente o que `ftp_spontaneous.py`/`ftp_incoming.py` fazem (`BACKUP_FTP_STABLE_SECONDS`) — confirmação de que a V2 já aprendeu essa lição, sem necessidade de mudança. |
| Ignorar sufixos "em progresso" (`.part`, `.tmp`, `.partial`, `.upload`) e dotfiles na descoberta | **ADIAR (verificar)** | Não confirmado com certeza se o scanner da V2 já ignora todos esses sufixos — candidato a auditoria pontual numa fase futura de hardening do FTP, não implementado agora para não alterar um fluxo já homologado sem motivo concreto reportado. |
| Path físico `<uuid>.backup` desacoplado do nome original (nome amigável só como metadado) | **MELHORAR — decisão própria já tomada, diferente** | A V2 optou por um path estruturado por site/equipamento/data (navegável por humanos), com a validação estrita de regex + `realpath`/confinamento já implementada (`ArtifactStorage`/`BackupRetention`, ADMIN-3). Trade-off consciente: menos simples que um UUID puro, mas mais operável; a proteção contra colisão/traversal já existe por outro caminho. |
| Sem retry automático de reconexão SSH — só re-enfileiramento manual | **PRESERVAR CONCEITO** | A V2 também não implementa retry automático (`is_retryable()` só classifica o código; nenhuma lógica de nova tentativa existe ainda) — alinhado com o próprio limite que o V1 escolheu não cruzar, deixado para o ENGINE-2. |
| Retry com backoff exponencial para filas de entrega assíncrona (cloud sync, Telegram) | **ADIAR** | V2 ainda não tem esses destinos de entrega; o padrão (`base * 2^(tentativa-1)`, teto, respeito a `retry_after`) fica registrado aqui como referência para quando existirem. |
| Retenção em duas fases: `available` → lixeira (com prazo de graça) → purga física definitiva, com reverificação de SHA-256 antes de mover/apagar | **MELHORAR — parcialmente adotado, lacuna registrada** | V2 (ADMIN-3) já reverifica hash/path antes de apagar (`ArtifactStorage.verify()`/`remove()`, TOCTOU-safe) e usa confirmação forte + auditoria — mas **não tem estágio de lixeira com prazo de graça**: a exclusão manual já remove o arquivo fisicamente na mesma operação. Registrado como melhoria candidata para uma fase futura de retenção/exclusão, não implementada agora (mudaria a primitive já testada em ADMIN-3 nesta mesma sessão). |
| Nunca incluir comando/segredo em mensagem de exceção (aprendido depois de um vazamento) | **PRESERVAR CONCEITO — já seguido** | Confirmado: `BackupError` na V2 só carrega o código (string curta), nunca o comando ou a saída; testes dedicados (`test_secret_and_exception_details_are_absent_from_logs`) já garantem isso desde antes desta fase. |
| Lock otimista (`UPDATE ... WHERE status=...`) contra dupla importação por processos concorrentes | **PRESERVAR CONCEITO — já seguido, forma diferente** | V2 usa publicação exclusiva via `os.link()` (falha com `FileExistsError` se já existe) e claim via rename atômico — mesma garantia, mecanismo de SO em vez de CAS em banco. |
| Vocabulário de estado fragmentado por tabela, com camada de tradução para um conjunto canônico (`operation_states.py`) — dívida técnica reconhecida e documentada pelo próprio V1 | **PRESERVAR CONCEITO (a lição, não o problema)** | A V2 já nasceu com um único enum canônico em `BackupExecution::STATUSES` — exatamente o que o V1 relata não ter conseguido migrar por custo. Nenhuma ação necessária; registrado como validação de que a V2 evitou essa dívida desde o início. |
| SQLite travando durante conexão SSH longa, mitigado com `sleep(0.01)` | **DESCARTAR** | Não se aplica — V2 usa PostgreSQL (MVCC) e o Laravel só segura transações curtas ao redor das chamadas Artisan, nunca durante a sessão SSH em si. |

**Resumo:** a maior parte do que o V1 aprendeu sobre transporte SSH, tratamento
de erro e estabilização de upload **já estava preservada** na V2 antes desta
fase — a consulta serviu principalmente para confirmar que os conceitos
certos já tinham sido absorvidos, identificar uma melhoria de segurança de
baixo risco e aplicável agora (detecção de segredo embutido em export
MikroTik), e registrar um conjunto pequeno de lacunas conscientes para fases
futuras (lixeira com prazo de graça na retenção, detecção de versão
RouterOS, auditoria de sufixos "em progresso" no scanner FTP) sem reabrir
código já testado sem necessidade concreta.

## Nota (ENGINE-3)

A auditoria de sufixos "em progresso" do scanner FTP, listada acima como
pendência, foi revisitada no ENGINE-3: confirmado que `ftp_spontaneous.py`/
`ftp_incoming.py` já protegem uploads em andamento via janela de estabilidade
(`BACKUP_FTP_STABLE_SECONDS`), não apenas por sufixo de nome — não havia
lacuna funcional a fechar. O engine Python também ganhou seu primeiro
consumidor de saída voltado a diagnóstico (`engine/health_snapshot.py`, sem
tocar o contrato de driver descrito acima) e um teste de integração real
Laravel↔Python que fecha a dívida citada no topo deste documento — ver
[docs/ENGINE_HEALTH.md](ENGINE_HEALTH.md).
