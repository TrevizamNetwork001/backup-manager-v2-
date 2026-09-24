# Huawei OLT por FTP Push

## Recepção espontânea (fase atual)

Na MA5800-X15, versão MA5800V100R021C11 SPH306, a OLT mantém servidor, credencial e agendamento de auto-backup internamente. A V2 não conecta à OLT, não configura seu agendamento e não cria execução antes do upload. O scheduler continua limitado a métodos pull. A política `ftp_push/config` segue `schedule_type=manual` para a associação, sem job periódico.

O PureDB vincula cada conta a `/data/ftp/<device-id>/incoming`. `engine/ftp_spontaneous.py` identifica o equipamento por esse diretório, observa tamanho/mtime/inode estáveis e faz claim por rename para `/data/ftp/processing/<token>`. Um sidecar persistente permite retomar após restart. Só depois do claim o Laravel cria idempotentemente a `BackupExecution` com `origin=ftp_received`, `ftp_account_id`, `received_filename`, `received_at` (mtime do arquivo recebido) e `processing_at`. Conta provisionada e ativa, Huawei OLT ativo e associação ativa `ftp_push/config` são obrigatórios. Ausência de conta ou associação válida leva o arquivo à quarentena sem execução.

O engine exige arquivo regular sem symlink/hardlink, não vazio e dentro do limite; abre com `O_NOFOLLOW`, confere a identidade antes e depois da leitura e usa o validador MA5800 homologado. Laravel revalida antes do `BackupArtifact`: **a validação PHP é a barreira final de persistência**, enquanto Python evita promover arquivos sabidamente inválidos. Os critérios duplicados permanecem compatíveis nesta fase. O destino conserva `Backup Manager/<SITE>/<EQUIPAMENTO>/<DD-MM-AAAA>/<EQUIPAMENTO>_<YYYYMMDDHHMMSS>.cfg`; colisões recebem `-exec-<ID>` sem sobrescrita. O arquivo é escrito em temporário `0600`, sincronizado com `fsync` e publicado exclusivamente. Laravel calcula SHA256 e marca a execução `succeeded` na transação do artefato.

Falhas de conteúdo geram execução `failed` e quarentena. Sidecar JSON e log estruturado guardam motivo, device ID, conta quando disponível, nome original recebido e horário. Não há limpeza automática. Reinício após claim retoma pelo token. Queda após a publicação no storage e antes da transação pode deixar um arquivo órfão; a retomada usa o mesmo token e caminho alternativo `-exec-<ID>`. Hash igual não elimina um evento: cada upload recebe claim e execução próprios.

O Pure-FTPd desta fase usa `-r` (autorename). [O projeto upstream documenta](https://github.com/jedisct1/pure-ftpd/blob/master/README) que `-K` sozinho ainda permite sobrescrita e que `-r` grava um segundo upload como `nome.1`, `nome.2` etc. O engine preserva em `original_filename` o nome efetivamente gravado pelo servidor; o nome solicitado antes do autorename não é observável. O padrão `bm-exec-<ID>.cfg` e suas variantes autorenomeadas ficam reservados temporariamente ao diagnóstico manual, descrito abaixo, e uploads tardios desse padrão sem execução ativa vão à quarentena.

## Diagnóstico manual temporário

O wizard e o teste homologado com `bm-exec-<execution-id>.cfg` continuam operando. Nesse caminho a execução é criada antes do comando manual e o engine espera o nome exato. Os parágrafos históricos abaixo descrevem esse caminho de diagnóstico; o fluxo operacional espontâneo é o descrito acima.

## Escopo e separação

`devices.platform=olt`, vendor Huawei e política `ftp_push/config` selecionam o recebimento OLT. `platform=network` mantém Huawei VRP (`ssh_pull/config`) e MikroTik sem alteração de driver. O campo pequeno `platform` é explícito para impedir que qualquer Huawei com FTP Push seja tratado como OLT. OLT não usa credencial SSH na associação: `credential_id` é nulo; a conta FTP é outro domínio.

O dispatcher chama `engine/drivers/huawei_olt_ftp.py` apenas para orquestrar a coleta do arquivo; `engine/ftp_incoming.py` implementa a inspeção do volume. Não há transporte SSH/Telnet nesse driver.

No diagnóstico manual, o operador executa o comando de backup com o nome mostrado na página da execução. O scheduler ignora `ftp_push`, inclusive dados legados diários ou semanais. Uma execução manual sem upload termina em `FTP_RECEIVE_TIMEOUT`.

## Conhecimento funcional do V1

O driver `huawei_olt_ssh_ftp` do V1 usava SSH como controle e FTP como transferência. A preparação de sessão era `enable`, `config`, `undo interactive`, `undo smart`, `scroll`. No cadastro/troca da integração, o operador executava `ftp set`, informava usuário/senha FTP, `quit`, `save`, voltava a `config`. A execução recorrente enviava `backup configuration ftp <servidor> <arquivo>.cfg` e `backup data ftp <servidor> <arquivo>.dat`, aguardava a confirmação `backing up files is successful` e, no modo completo, exigia ambos os arquivos. Os nomes antigos usavam hostname e timestamp; o V1 registrava nomes esperados e correlacionava por conta/equipamento e nome. Esta V2 recebe apenas `.cfg` em `config`. A análise antiga não cobria o auto-backup interno Huawei; a MA5800-X15 homologada nesta fase suporta `file-server auto-backup configuration` e `auto-backup period configuration`.

Foram reaproveitados os conceitos validados de conta virtual isolada, upload estável, correlação por equipamento e nome esperado, quarentena, validação, SHA256, promoção atômica e conclusão após artefato. A arquitetura antiga e seu helper monolítico não foram portados.

No V1, a operação OLT tinha prazo padrão de 180 segundos para receber os arquivos; o executor SSH Huawei ampliava o prazo de comando para pelo menos 300 segundos. A V2 mantém 180 segundos como padrão de recebimento, sem prazo de comando SSH porque não executa a OLT.

## Conta FTP e Pure-FTPd

`ftp_accounts` guarda uma conta por dispositivo, com usuário sugerido `bmdev<id>` ou usuário manual único, segredo criptografado pelo cast Laravel `encrypted`, estado ativo e `provisioned_at`. O wizard gera uma senha forte ou aceita uma senha manual confirmada; a senha aparece somente na resposta de criação ou rotação e não está em `toArray()`, saída de `ftp:accounts`, logs ou argumentos. O serviço local `ftp-admin` lê apenas IDs/metadados via Artisan e recebe o segredo por pipe anônimo. Ele valida o usuário com uma lista restrita de caracteres e deriva o diretório somente do ID do equipamento; chama `/usr/bin/pure-pw` com lista fixa de argumentos, sem shell. PureDB e passwd têm modo `0600` no volume `ftp-db`. O serviço `ftp` recebe apenas esse volume em leitura e o volume `ftp-data`; não tem código Laravel, `.env` ou endpoint de administração.

`ftp-admin` roda como root para administrar PureDB e ownership. Pure-FTPd precisa iniciar com privilégios para escutar porta 21 e autenticar; usuários virtuais mapeiam para UID/GID 65534 e ficam presos em `/data/ftp/<device-id>/incoming`. O container FTP usa autenticação PureDB, desabilita anônimos, chroot, chmod, rename e delete, e limita clientes. A imagem local usa FTP sem TLS; credenciais e conteúdo trafegam sem criptografia. Restrinja a rede ou VPN e configure FTPS com certificado e teste do modelo antes de uso em rede não confiável. A UI mostra `Pronta no PureDB` somente após uma sincronização bem sucedida; isso não prova conectividade ou configuração da OLT.

O binário Debian `/usr/sbin/pure-ftpd` usa capacidades Linux na inicialização; o container recebe apenas `DAC_READ_SEARCH` e `SYS_NICE` além do conjunto padrão do Docker. Sem essas duas capacidades ele encerra com código 252. O processo principal inicia como root e os processos de sessão trocam para UID/GID 65534 após autenticação e chroot. O lançador aplica `RLIMIT_FSIZE` com `BACKUP_FTP_MAX_BYTES` antes do `exec`; o limite é herdado pelas sessões e impede escrita além desse tamanho por arquivo. O engine aplica o mesmo limite antes de promover o artefato.

O `ftp-admin` monta o código Laravel e `app/.env` para obter a conta criptografada; esse container tem acesso à `APP_KEY` e ao banco. Ele deve ser protegido no mesmo nível do app e não possui porta publicada. O segredo de criação/rotação é mostrado diretamente em resposta HTML `no-store`, sem flash de sessão; fechar a página o remove da UI. FTP é usado por compatibilidade com o equipamento homologado; SFTP é preferível quando o equipamento e o fluxo de exportação oferecerem suporte futuro.

`BACKUP_FTP_HOST` é o endereço ou hostname que a OLT usa para alcançar o FTP; ele aparece para o operador e no comando sugerido. `BACKUP_FTP_PASSIVE_ADDRESS` no `.env` da raiz do Compose é a fonte única do endereço anunciado pelo Pure-FTPd nas conexões passivas (IP literal). O Compose passa esse valor tanto ao container `ftp` quanto ao `app`. O wizard mostra o valor efetivo em campo somente leitura e, ao salvar host/porta, grava o mesmo valor no banco; uma requisição que envie endereço passivo diferente é rejeitada. Um valor antigo no banco não substitui a configuração efetiva. Após editar o `.env`, recrie `app` e `ftp` para carregar o novo endereço. Com o valor vazio, o lançador não adiciona `-P` e o Pure-FTPd usa seu comportamento padrão; a UI mostra "Padrão do servidor". `BACKUP_FTP_PUBLIC_IP` é fallback legado apenas para `ftp`; prefira sempre `BACKUP_FTP_PASSIVE_ADDRESS` para manter o painel coerente. A porta de controle está fixada em 21 no Compose e no wizard. Em redes roteadas privadas, host e endereço passivo podem ser o mesmo IPv4 privado; com NAT/IP público, use o endereço alcançável pela OLT.

O Compose publica 21/TCP e 30000–30009/TCP no host. O Pure-FTPd usa `-p 30000:30009`, exatamente o mesmo intervalo passivo. PostgreSQL, Redis e `ftp-admin` permanecem sem portas publicadas. O firewall e o roteador precisam permitir esses destinos para a OLT; a V2 não os altera automaticamente. Não há painel administrativo público do FTP.

### Testes locais de FTP

A imagem FTP contém somente `server.py` e `admin.py`; os testes ficam no repositório e são montados apenas durante a execução. A partir da raiz do projeto, com a imagem `backup-manager-v2-ftp:latest` construída:

```sh
docker compose run --rm --no-deps -v "$PWD/docker/ftp/test_admin.py:/ftp/test_admin.py:ro" ftp python3 -m unittest discover -s /ftp -p test_admin.py -v
docker run --rm --network none --cap-add DAC_READ_SEARCH --cap-add SYS_NICE --mount "type=bind,source=$PWD/docker/ftp/test_purepw_integration.py,target=/ftp/test_purepw_integration.py,readonly" backup-manager-v2-ftp:latest python3 /ftp/test_purepw_integration.py
```

O primeiro comando roda os 8 testes unitários; executar `python3 /ftp/test_admin.py` diretamente apenas define os testes. O segundo executa o teste sintético PureDB/Pure-FTPd somente em loopback dentro de um container efêmero. As duas capacidades são as mesmas do serviço `ftp` no Compose. A descoberta ampla `python3 -m unittest discover -s docker/ftp` no host também importa o teste de integração e tenta `chown` do home para UID/GID 65534. Em ambientes de host com mapeamento de usuário restrito a UID 0, esse `chown` retorna `EINVAL`; execute a integração no container, onde o UID 65534 está mapeado.

Defina `BACKUP_FTP_HOST`, `BACKUP_FTP_PASSIVE_ADDRESS`, `BACKUP_FTP_STABLE_SECONDS`, `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS` e `BACKUP_FTP_MAX_BYTES` no `.env` da raiz do Compose. O volume de dados FTP e o PureDB precisam entrar no plano de backup e recuperação junto do banco e da `APP_KEY`.

## Recebimento e artefato

Cada execução de diagnóstico manual espera exatamente `bm-exec-<execution-id>.cfg` no home FTP do próprio device ID. O nome não contém segredo. O engine limita uma execução `running` por equipamento; o índice parcial no banco protege contra workers concorrentes. A inspeção exige arquivo regular sem hardlinks, tamanho de 1 byte até `BACKUP_FTP_MAX_BYTES` (padrão 8 MiB; teto 64 MiB), duas observações de tamanho/mtime sem mudança, e idade de mtime de ao menos `BACKUP_FTP_STABLE_SECONDS` (padrão 5). A espera é feita numa thread de execução, com até quatro jobs simultâneos, mantendo heartbeat e claim. `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS` (padrão 180) termina o job com `FTP_RECEIVE_TIMEOUT`.

Arquivos de outro ID/nome, inválidos ou tardios são mantidos em `/data/ftp/quarantine` com nome aleatório e sidecar JSON com motivo fixo. A varredura periódica também identifica uploads órfãos quando nenhum job aguarda. Arquivos encontrados no início do worker recebem 30 segundos de grace para que uma execução ativa volte a ser reconhecida; depois disso, passam por nova observação de estabilidade e seguem para quarentena se continuarem sem execução `queued` ou `running` correspondente. Execuções `failed` ou expiradas não autorizam reaproveitamento. Não há limpeza automática da quarentena.

A validação MA5800 homologada exige UTF-8 sem NUL, tamanho dentro do limite FTP, newline final, separador `#`, cabeçalho `[!Software Version MA5800...]` e `[Saving time: ...]` no início do arquivo, seguidos em ordem por `[global-config]`, `<global-config>`, `sysname` e outro separador. Respostas de erro e HTML conhecidos são rejeitados. O engine escreve no storage definitivo por temporário `0600`, `fsync` e publicação exclusiva. Laravel reabre o arquivo, aplica a mesma validação, calcula SHA256 e cria `BackupArtifact` na transação que marca a execução `succeeded`. Uma queda entre publicação e transação pode deixar órfão no storage, mas não um artefato válido. A retenção existente enxerga apenas artefatos `available`, validados e vinculados a execução `succeeded`, nunca incoming/quarentena.

## Homologação MA5800 concluída

A integração real Huawei MA5800 via FTP foi validada ponta a ponta: a execução de homologação #31 terminou `SUCCEEDED`, com arquivo recebido, correlacionado, validado e armazenado; o wizard chegou à Etapa 6, “Integração validada”. As tentativas #24–#30 falharam durante os ajustes de homologação e não são reutilizadas.

O wizard tem seis etapas: conta FTP, sincronização PureDB, servidor FTP, confirmação da configuração manual da OLT, teste de integração e integração validada. A Etapa 5 só termina com execução `succeeded` e artefato validado. O teste permanece manual: a web cria uma nova execução e exibe `bm-exec-<ID>.cfg`; o operador executa `backup configuration ftp <SERVIDOR_FTP> bm-exec-<ID>.cfg` na OLT; a OLT envia ao Pure-FTPd; o engine correlaciona pelo equipamento e nome, valida e armazena; o artefato é registrado e a execução passa a `succeeded`. Um retry cria outro ID e outro nome. O scheduler não agenda nem dispara OLT FTP.

O prazo padrão de recebimento manual é `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS=180`, com estabilidade padrão de `BACKUP_FTP_STABLE_SECONDS=5`. O engine envia heartbeat a cada 30 segundos por padrão; o PHP marca uma execução `running` como interrompida se o heartbeat ficar ausente por `BACKUP_ENGINE_STALE_SECONDS=300`. Esses limites não alteram os drivers SSH de Huawei VRP e MikroTik.

## Pendências explícitas

- Outros modelos ou versões de firmware podem exigir validação própria dos prompts e do formato `.cfg`.
- O wizard na tela do equipamento cria a conta automática ou manual, acompanha a sincronização PureDB com opção de repetir em caso de erro, configura servidor, pede confirmação manual da configuração na OLT e cria uma execução FTP Push de teste com nome único. O operador executa o comando mostrado; o modal atualiza o estado até o arquivo ser recebido e validado ou a execução falhar. A aplicação não acessa a OLT nem testa login FTP diretamente.
- O scheduler de `ftp_push` permanece desabilitado. O comando manual com ID é exigido apenas para o teste do wizard; auto-backup operacional nasce do upload espontâneo.
- FTPS e publicação externa de portas dependem de certificado, rede e suporte do equipamento; não foram habilitados.
- A origem é identificada pela conta virtual/home, não por IP de origem autenticado pelo PureDB. Uma senha compartilhada ou comprometida exige rotação e restrição de rede.
- Incoming e quarentena exigem política futura de limpeza com preservação de evidências.
