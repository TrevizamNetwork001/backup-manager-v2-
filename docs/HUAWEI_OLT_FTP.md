# Huawei OLT por FTP Push

## Vínculo operacional

Uma conta `purpose=backup` ativa e provisionada, sem `sync_error`, vinculada a um dispositivo ativo com `vendor=Huawei` e `platform=olt` pode receber backups espontâneos quando há associação ativa a uma policy ativa `ftp_push/config/manual` com `credential_id` nulo. A criação da conta garante essa associação na mesma transação. Primeiro reutiliza a associação ativa compatível de menor ID; se há vínculo compatível inativo, reativa o de menor ID; caso contrário reutiliza a policy global compatível de menor ID ainda não associada ao dispositivo ou cria `Huawei OLT Manual`. O botão **Preparar backup FTP** aplica a mesma regra às contas antigas. A policy persiste após excluir a conta em qualquer modo; recriar a credencial reaproveita o vínculo.

`olt_ftp_integrations` guarda apenas confirmação manual e resultado do wizard de homologação. Não participa da elegibilidade do upload espontâneo. O teste do wizard reutiliza a associação operacional compatível. Preparar a policy altera somente o control plane: nenhum comando é enviado à OLT e a configuração do auto-backup continua com o operador. O scheduler não executa `ftp_push`.

Rejeições retornam `invalid_account` para conta ausente/inativa/não provisionada/com erro, `unsupported_device` para dispositivo inativo ou não Huawei OLT, `missing_backup_policy` quando o dispositivo não tem associação `ftp_push` e `invalid_backup_policy` quando as associações `ftp_push` estão inativas ou incompatíveis. A quarentena e o receipt preservam ID da conta identificada pelo home e o tamanho observado antes do movimento do arquivo. Arquivos do tipo `file_server` usam fluxo separado; contas legacy conservam o home por ID de dispositivo.

## Recepção espontânea (fase atual)

Na MA5800-X15, versão MA5800V100R021C11 SPH306, a OLT mantém servidor, credencial e agendamento de auto-backup internamente. A V2 não conecta à OLT, não configura seu agendamento e não cria execução antes do upload. O scheduler continua limitado a métodos pull. A política `ftp_push/config` segue `schedule_type=manual` para a associação, sem job periódico.

O PureDB usa o home efetivo da conta. Contas legadas permanecem em `/data/ftp/<device-id>/incoming`; contas novas usam `/data/ftp/accounts/<account_uuid>/incoming`. `engine/ftp_spontaneous.py` identifica a conta pelo home cadastrado, observa tamanho/mtime/inode estáveis e faz claim por rename para `/data/ftp/processing/<token>`. Um sidecar persistente permite retomar após restart. Só depois do claim o Laravel cria idempotentemente a `BackupExecution` com `origin=ftp_received`, `ftp_account_id`, `received_filename`, `received_at` (mtime do arquivo recebido) e `processing_at`. Conta `purpose=backup` provisionada e ativa, Huawei OLT ativo e associação ativa `ftp_push/config` são obrigatórios. Ausência de conta ou associação válida leva o arquivo à quarentena sem execução. O histórico FTP é registrado em `ftp_received_files`.

O engine exige arquivo regular sem symlink/hardlink, não vazio e dentro do limite; abre com `O_NOFOLLOW` e confere identidade, tamanho e leitura completa antes e depois. Laravel revalida a integridade física antes do `BackupArtifact`. **Backup Manager armazena arquivos válidos de transporte independentemente da versão/formato interno. Parsers de vendor são auxiliares e não requisito para retenção.** O destino conserva `Backup Manager/<SITE>/<EQUIPAMENTO>/<DD-MM-AAAA>/<EQUIPAMENTO>_<YYYYMMDDHHMMSS>.cfg`; colisões recebem `-exec-<ID>` sem sobrescrita. O arquivo é escrito em temporário `0600`, sincronizado com `fsync` e publicado exclusivamente. Laravel calcula SHA256 e marca a execução `succeeded` na transação do artefato.

Quarentena ocorre por conta/dispositivo/policy inválidos, nome ou caminho inseguro, symlink, arquivo irregular, tamanho inválido, alteração durante claim/leitura ou falha física/storage. Formato não reconhecido não gera `FTP_FILE_INVALID`. Sidecar JSON e log estruturado guardam motivo, device ID, conta quando disponível, nome original recebido e horário. Não há limpeza automática. Reinício após claim retoma pelo token. Queda após a publicação no storage e antes da transação pode deixar um arquivo órfão; a retomada usa o mesmo token e caminho alternativo `-exec-<ID>`. Hash igual não elimina um evento: cada upload recebe claim e execução próprios.

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

`ftp_accounts` guarda contas `backup` vinculadas a equipamento e contas `file_server` sem equipamento. Contas novas exigem usuário explícito; usuários legados `bmdev<ID>` continuam válidos. O segredo usa o cast Laravel `encrypted`, com exibição somente na resposta de criação ou rotação. O serviço local `ftp-admin` lê apenas IDs/metadados via Artisan e recebe o segredo por pipe anônimo. Ele valida usuário e UUID, preserva o home legado ou deriva o novo home do UUID; chama `/usr/bin/pure-pw` com lista fixa de argumentos, sem shell. PureDB e passwd têm modo `0600` no volume `ftp-db`. O serviço `ftp` recebe apenas esse volume em leitura e o volume `ftp-data`; não tem código Laravel, `.env` ou endpoint de administração. A migration não move arquivos legados. Uma migração física só seria necessária se o layout de uma conta antiga fosse alterado em fase controlada posterior.

`ftp-admin` roda como root para administrar PureDB e ownership. Pure-FTPd precisa iniciar com privilégios para escutar porta 21 e autenticar; usuários virtuais mapeiam para UID/GID 65534 e ficam presos no home efetivo da conta, legado ou baseado em UUID. O container FTP usa autenticação PureDB, desabilita anônimos, aplica chroot e restrições globais de operação, e limita clientes. Não há permissão individual configurável por conta. A imagem local usa FTP sem TLS; credenciais e conteúdo trafegam sem criptografia. Restrinja a rede ou VPN e configure FTPS com certificado e teste do modelo antes de uso em rede não confiável. A UI mostra `Pronta no PureDB` somente após uma sincronização bem sucedida; isso não prova conectividade ou configuração da OLT.

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

Cada execução de diagnóstico manual espera exatamente `bm-exec-<execution-id>.cfg` no home efetivo da conta vinculada ao equipamento. O nome não contém segredo. O engine limita uma execução `running` por equipamento; o índice parcial no banco protege contra workers concorrentes. A inspeção exige arquivo regular sem hardlinks, tamanho de 1 byte até `BACKUP_FTP_MAX_BYTES` (padrão 8 MiB; teto 64 MiB), duas observações de tamanho/mtime sem mudança, e idade de mtime de ao menos `BACKUP_FTP_STABLE_SECONDS` (padrão 5). A espera é feita numa thread de execução, com até quatro jobs simultâneos, mantendo heartbeat e claim. `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS` (padrão 180) termina o job com `FTP_RECEIVE_TIMEOUT`.

Arquivos de outro ID/nome, inválidos ou tardios são mantidos em `/data/ftp/quarantine` com nome aleatório e sidecar JSON com motivo fixo. A varredura periódica também identifica uploads órfãos quando nenhum job aguarda. Arquivos encontrados no início do worker recebem 30 segundos de grace para que uma execução ativa volte a ser reconhecida; depois disso, passam por nova observação de estabilidade e seguem para quarentena se continuarem sem execução `queued` ou `running` correspondente. Execuções `failed` ou expiradas não autorizam reaproveitamento. Não há limpeza automática da quarentena.

A análise MA5800 reconhece marcadores conhecidos e registra `recognized`, `warning` ou `unknown` em auditoria quando disponível. A ausência de `sysname` gera aviso; conteúdo textual ou binário desconhecido pode ser armazenado. R21 com estrutura conhecida é reconhecido; R19 sem `sysname` é armazenado com aviso. O engine escreve no storage definitivo por temporário `0600`, `fsync` e publicação exclusiva. Laravel reabre o arquivo, verifica tamanho e identidade, calcula SHA256 e cria `BackupArtifact` na transação que marca a execução `succeeded`. Uma queda entre publicação e transação pode deixar órfão no storage, mas não um artefato válido. A retenção existente enxerga apenas artefatos `available`, validados e vinculados a execução `succeeded`, nunca incoming/quarentena.

## Homologação MA5800 concluída

A integração real Huawei MA5800 via FTP foi validada ponta a ponta: a execução de homologação #31 terminou `SUCCEEDED`, com arquivo recebido, correlacionado, validado e armazenado; o wizard chegou à Etapa 6, “Integração validada”. As tentativas #24–#30 falharam durante os ajustes de homologação e não são reutilizadas.

O wizard tem seis etapas: conta FTP, sincronização PureDB, servidor FTP, confirmação da configuração manual da OLT, teste de integração e integração validada. A Etapa 5 só termina com execução `succeeded` e artefato validado. O teste permanece manual: a web cria uma nova execução e exibe `bm-exec-<ID>.cfg`; o operador executa `backup configuration ftp <SERVIDOR_FTP> bm-exec-<ID>.cfg` na OLT; a OLT envia ao Pure-FTPd; o engine correlaciona pelo equipamento e nome, valida e armazena; o artefato é registrado e a execução passa a `succeeded`. Um retry cria outro ID e outro nome. O scheduler não agenda nem dispara OLT FTP.

O prazo padrão de recebimento manual é `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS=180`, com estabilidade padrão de `BACKUP_FTP_STABLE_SECONDS=5`. O engine envia heartbeat a cada 30 segundos por padrão; o PHP marca uma execução `running` como interrompida se o heartbeat ficar ausente por `BACKUP_ENGINE_STALE_SECONDS=300`. Esses limites não alteram os drivers SSH de Huawei VRP e MikroTik.

## Pendências explícitas

- Outros modelos ou versões podem receber analisadores informativos sem alterar o requisito de armazenamento.
- O wizard na tela do equipamento cria a conta com usuário escolhido e senha informada ou gerada pelo botão, acompanha a sincronização PureDB com opção de repetir em caso de erro, configura servidor, pede confirmação manual da configuração na OLT e cria uma execução FTP Push de teste com nome único. O operador executa o comando mostrado; o modal atualiza o estado até o arquivo ser recebido e validado ou a execução falhar. A aplicação não acessa a OLT nem testa login FTP diretamente.
- O scheduler de `ftp_push` permanece desabilitado. O comando manual com ID é exigido apenas para o teste do wizard; auto-backup operacional nasce do upload espontâneo.
- FTPS e publicação externa de portas dependem de certificado, rede e suporte do equipamento; não foram habilitados.
- A origem é identificada pela conta virtual/home, não por IP de origem autenticado pelo PureDB. Uma senha compartilhada ou comprometida exige rotação e restrição de rede.
- Incoming e quarentena exigem política futura de limpeza com preservação de evidências.
