# Huawei OLT por FTP Push

## Escopo e separação

`devices.platform=olt`, vendor Huawei e política `ftp_push/config` selecionam o recebimento OLT. `platform=network` mantém Huawei VRP (`ssh_pull/config`) e MikroTik sem alteração de driver. O campo pequeno `platform` é explícito para impedir que qualquer Huawei com FTP Push seja tratado como OLT. OLT não usa credencial SSH na associação: `credential_id` é nulo; a conta FTP é outro domínio.

O dispatcher chama `engine/drivers/huawei_olt_ftp.py` apenas para orquestrar a coleta do arquivo; `engine/ftp_incoming.py` implementa a inspeção do volume. Não há transporte SSH/Telnet nesse driver.

Nesta fase a V2 **não conecta à OLT**, não dispara automaticamente o backup e não muda configuração persistente. O operador prepara o FTP na OLT e executa manualmente o comando de backup com o nome mostrado na página da execução. Políticas `ftp_push/config` aceitam somente `schedule_type=manual`; o scheduler ignora explicitamente `ftp_push`, inclusive dados legados diários ou semanais. Sem disparo automático, essas execuções terminariam em `FTP_RECEIVE_TIMEOUT`. Não correlacionamos nomes fixos por janela de tempo, pois um upload atrasado poderia concluir o job errado. Um futuro disparo pontual sob demanda poderá permitir agendamento automático, sem alterar a configuração permanente da OLT.

## Conhecimento funcional do V1

O driver `huawei_olt_ssh_ftp` do V1 usava SSH como controle e FTP como transferência. A preparação de sessão era `enable`, `config`, `undo interactive`, `undo smart`, `scroll`. No cadastro/troca da integração, o operador executava `ftp set`, informava usuário/senha FTP, `quit`, `save`, voltava a `config`. A execução recorrente enviava `backup configuration ftp <servidor> <arquivo>.cfg` e `backup data ftp <servidor> <arquivo>.dat`, aguardava a confirmação `backing up files is successful` e, no modo completo, exigia ambos os arquivos. Os nomes antigos usavam hostname e timestamp; o V1 registrava nomes esperados e correlacionava por conta/equipamento e nome. Esta V2 recebe apenas `.cfg` em `config`. `file-server auto-backup` era do fluxo ZTE, não um comando necessário para Huawei. Não há evidência no fluxo Huawei estudado de que `auto-backup period configuration` seja exigido.

Foram reaproveitados os conceitos validados de conta virtual isolada, upload estável, correlação por equipamento e nome esperado, quarentena, validação, SHA256, promoção atômica e conclusão após artefato. A arquitetura antiga e seu helper monolítico não foram portados.

No V1, a operação OLT tinha prazo padrão de 180 segundos para receber os arquivos; o executor SSH Huawei ampliava o prazo de comando para pelo menos 300 segundos. A V2 mantém 180 segundos como padrão de recebimento, sem prazo de comando SSH porque não executa a OLT.

## Conta FTP e Pure-FTPd

`ftp_accounts` guarda uma conta por dispositivo, `username=bmdev<id>`, segredo criptografado pelo cast Laravel `encrypted`, estado ativo e `provisioned_at`. A senha aleatória aparece uma vez na criação ou rotação; não está em `toArray()`, saída de `ftp:accounts`, logs ou argumentos. O serviço local `ftp-admin` lê apenas IDs/metadados via Artisan e recebe o segredo por pipe anônimo. Ele valida username/path derivados do ID e chama `/usr/bin/pure-pw` com lista fixa de argumentos, sem shell, sem argumentos livres da web. PureDB e passwd têm modo `0600` no volume `ftp-db`. O serviço `ftp` recebe apenas esse volume em leitura e o volume `ftp-data`; não tem código Laravel, `.env` ou endpoint de administração.

`ftp-admin` roda como root para administrar PureDB e ownership. Pure-FTPd precisa iniciar com privilégios para escutar porta 21 e autenticar; usuários virtuais mapeiam para UID/GID 65534 e ficam presos em `/data/ftp/<device-id>/incoming`. O container FTP usa autenticação PureDB, desabilita anônimos, chroot, chmod, rename e delete, e limita clientes. A imagem local usa FTP sem TLS; credenciais e conteúdo trafegam sem criptografia. Restrinja a rede ou VPN e configure FTPS com certificado e teste do modelo antes de uso em rede não confiável. A UI mostra `Pronta no PureDB` somente após uma sincronização bem sucedida; isso não prova conectividade ou configuração da OLT.

O binário Debian `/usr/sbin/pure-ftpd` usa capacidades Linux na inicialização; o container recebe apenas `DAC_READ_SEARCH` e `SYS_NICE` além do conjunto padrão do Docker. Sem essas duas capacidades ele encerra com código 252. O processo principal inicia como root e os processos de sessão trocam para UID/GID 65534 após autenticação e chroot. O lançador aplica `RLIMIT_FSIZE` com `BACKUP_FTP_MAX_BYTES` antes do `exec`; o limite é herdado pelas sessões e impede escrita além desse tamanho por arquivo. O engine aplica o mesmo limite antes de promover o artefato.

O `ftp-admin` monta o código Laravel e `app/.env` para obter a conta criptografada; esse container tem acesso à `APP_KEY` e ao banco. Ele deve ser protegido no mesmo nível do app e não possui porta publicada. O segredo de criação/rotação é mostrado diretamente em resposta HTML `no-store`, sem flash de sessão; fechar a página o remove da UI.

`BACKUP_FTP_HOST` é o endereço ou hostname que a OLT usa para alcançar o FTP; ele aparece para o operador e no comando sugerido. `BACKUP_FTP_PASSIVE_ADDRESS` é o endereço anunciado pelo Pure-FTPd nas conexões passivas (valor literal de IP). Em redes roteadas privadas, ambos podem ser o mesmo IPv4 privado. Na homologação temporária com NAT/IP público, configure ambos com o endereço externo apropriado à rota da OLT. Sem endereço passivo configurado, o lançador não adiciona a opção `-P` e o Pure-FTPd usa seu comportamento padrão. `BACKUP_FTP_PUBLIC_IP` está deprecated: é aceito apenas como fallback quando `BACKUP_FTP_PASSIVE_ADDRESS` está vazio.

O Compose não publica porta FTP no host nesta fase. Para homologação, um override controlado pode publicar apenas 21/TCP e 30000–30009/TCP. O firewall e o roteador precisam permitir os mesmos destinos; a V2 não os altera automaticamente. Não há painel administrativo público do FTP.

### Testes locais de FTP

A imagem FTP contém somente `server.py` e `admin.py`; os testes ficam no repositório e são montados apenas durante a execução. A partir da raiz do projeto, com a imagem `backup-manager-v2-ftp:latest` construída:

```sh
docker compose run --rm --no-deps -v "$PWD/docker/ftp/test_admin.py:/ftp/test_admin.py:ro" ftp python3 -m unittest discover -s /ftp -p test_admin.py -v
docker run --rm --network none --cap-add DAC_READ_SEARCH --cap-add SYS_NICE --mount "type=bind,source=$PWD/docker/ftp/test_purepw_integration.py,target=/ftp/test_purepw_integration.py,readonly" backup-manager-v2-ftp:latest python3 /ftp/test_purepw_integration.py
```

O primeiro comando roda os 8 testes unitários; executar `python3 /ftp/test_admin.py` diretamente apenas define os testes. O segundo executa o teste sintético PureDB/Pure-FTPd somente em loopback dentro de um container efêmero. As duas capacidades são as mesmas do serviço `ftp` no Compose. A descoberta ampla `python3 -m unittest discover -s docker/ftp` no host também importa o teste de integração e tenta `chown` do home para UID/GID 65534. Em ambientes de host com mapeamento de usuário restrito a UID 0, esse `chown` retorna `EINVAL`; execute a integração no container, onde o UID 65534 está mapeado.

Exemplo de override **somente para laboratório**, criado pelo operador no momento da homologação:

```yaml
services:
  ftp:
    ports:
      - "21:21"
      - "30000-30009:30000-30009"
```

Defina `BACKUP_FTP_HOST`, `BACKUP_FTP_PASSIVE_ADDRESS` quando for necessário anunciar um endereço passivo explícito, `BACKUP_FTP_STABLE_SECONDS`, `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS` e `BACKUP_FTP_MAX_BYTES` no `.env` da raiz do Compose. O volume de dados FTP e o PureDB precisam entrar no plano de backup e recuperação junto do banco e da `APP_KEY`.

## Recebimento e artefato

Cada execução espera exatamente `bm-exec-<execution-id>.cfg` no home FTP do próprio device ID. O nome não contém segredo. O engine limita uma execução `running` por equipamento; o índice parcial no banco protege contra workers concorrentes. A inspeção exige arquivo regular sem hardlinks, tamanho de 1 byte até `BACKUP_FTP_MAX_BYTES` (padrão 8 MiB; teto 64 MiB), duas observações de tamanho/mtime sem mudança, e idade de mtime de ao menos `BACKUP_FTP_STABLE_SECONDS` (padrão 5). A espera é feita numa thread de execução, com até quatro jobs simultâneos, mantendo heartbeat e claim. `BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS` (padrão 180) termina o job com `FTP_RECEIVE_TIMEOUT`.

Arquivos de outro ID/nome, inválidos ou tardios são mantidos em `/data/ftp/quarantine` com nome aleatório e sidecar JSON com motivo fixo. Uma varredura periódica também identifica uploads órfãos quando nenhum job aguarda. Não há limpeza automática agressiva de incoming/quarentena. A validação exige UTF-8, texto sem NUL, separador `#`, pelo menos um marcador de configuração OLT (`sysname`, interface GPON/EPON, ONT, service-port ou VLAN) e ausência de respostas de erro conhecidas. O formato exato de `.cfg` deve ser confirmado em equipamento real; falha segura envia à quarentena. O engine escreve no storage definitivo por temporário `0600`, fsync e rename atômico. Laravel reabre, valida, calcula SHA256 e cria `BackupArtifact` na transação que marca a execução `succeeded`. Uma queda entre rename e transação pode deixar órfão no storage, mas não um artefato válido. A retenção existente enxerga apenas artefatos `available`, validados e vinculados a execução `succeeded`, nunca incoming/quarentena.

## Roteiro de homologação manual futura

1. Em janela de laboratório, revisar e aplicar a migration pendente em procedimento próprio. Preservar banco, `APP_KEY` e volumes; verificar que não há duas execuções `running` para o mesmo dispositivo antes do índice parcial.
2. Configurar `BACKUP_FTP_HOST` com endereço acessível pela OLT e `BACKUP_FTP_PASSIVE_ADDRESS` conforme a rota passiva. Criar override de portas apenas na rede de teste; publicar 21/TCP e 30000–30009/TCP, ajustar NAT e firewall manualmente. Iniciar `ftp-admin`, `ftp` e `engine`. Conferir logs sem segredos.
3. Cadastrar Huawei OLT com `platform=olt`, criar conta FTP, copiar uma vez a senha e aguardar `Pronta no PureDB`. Confirmar por teste FTP isolado na rede de laboratório que o login cai no diretório do equipamento, não permite acessar outro diretório e envia um arquivo sintético; remover o arquivo sintético somente após examinar sua quarentena. O painel ainda não tem botão de teste ativo.
4. Por console confiável na OLT, preparar manualmente a sessão e executar `ftp set`, usuário, senha, `quit`, `save`, conforme prompts reais. A V2 nunca envia esses comandos. Confirmar FTP host/porta e permissões. Não configurar auto-backup ou scheduler da OLT como efeito desta fase.
5. Criar política `ftp_push/config` manual, associar OLT, criar execução e enfileirar. Na página da execução, copiar `backup configuration ftp <BACKUP_FTP_HOST> bm-exec-<id>.cfg` e executá-lo manualmente na OLT já preparada. Acompanhar upload, estabilidade, SHA256, artefato e `succeeded`; confirmar que não houve arquivo `.dat` exigido.
6. Repetir com arquivo inválido e com execução sem upload para observar quarentena e timeout. Ensaiar upload tardio e de outro equipamento; não devem concluir outra execução. Em seguida, conferir dry-run de retenção em artefatos de teste isolados. Nunca alterar datas ou apagar artefatos operacionais para forçar limpeza.

## Pendências explícitas

- O formato real `.cfg` e os prompts de `ftp set` dependem de modelo/firmware e exigem homologação.
- O painel mostra estado de provisionamento PureDB, mas ainda não tem teste ativo de login/upload da integração.
- O scheduler de `ftp_push` permanece desabilitado. Cada execução exige o comando manual com seu ID; um futuro disparo pontual poderá liberar agendamento, sem configurar persistentemente a OLT. Um nome fixo de auto-backup não oferece correlação segura.
- FTPS e publicação externa de portas dependem de certificado, rede e suporte do equipamento; não foram habilitados.
- A origem é identificada pela conta virtual/home, não por IP de origem autenticado pelo PureDB. Uma senha compartilhada ou comprometida exige rotação e restrição de rede.
- Incoming e quarentena exigem política futura de limpeza com preservação de evidências.
