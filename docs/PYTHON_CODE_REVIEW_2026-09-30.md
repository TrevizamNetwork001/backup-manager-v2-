# Revisão do código Python — 30/09/2026

Esta revisão cobre os 37 arquivos Python do repositório: engine, drivers, recepção/administração FTP, ferramentas de desempenho e testes existentes. O foco foi seguir os caminhos de SSH, FTP manual de OLT, FTP espontâneo de Huawei VRP, `file_server`, armazenamento, recuperação após falha e reconciliação do PureDB.

## Fluxos encontrados

| Fluxo | Entrada | Processamento | Resultado |
| --- | --- | --- | --- |
| SSH pull | `backup_engine.main()` reivindica job Laravel | `registry_setup` seleciona driver; o driver valida host key, autentica e exporta; `storage.store()` publica exclusivamente | `engine:complete` cria artifact e conclui execução |
| FTP manual de OLT | Execução manual cria `bm-exec-<ID>.cfg` esperado | `huawei_olt_ftp.collect_config()` aguarda arquivo estável; scanner reserva nomes `bm-exec-*` para esse fluxo | Artifact vinculado à execução manual ou timeout |
| Huawei VRP FTP | Arquivo aparece no home isolado da conta | `ftp_spontaneous.scan()` reclama por rename, grava sidecar, Laravel cria `ftp_received`, integridade é conferida e publicação é retomável | Artifact e recibo; não passa pela fila SSH nem inicia envio no roteador |
| FTP `file_server` | Upload em conta sem device | Scanner valida e publica no namespace UUID da conta; registra recibo | Arquivo e recibo, sem `BackupExecution`/`BackupArtifact` |
| Administração FTP | `docker/ftp/admin.py` consulta comandos Artisan de lista fixa | Cria homes isolados, monta arquivos temporários de passwd/PureDB, sincroniza e aplica exclusão física validada por `deletion.py` | Conta provisionada/revogada e dados removidos conforme o modo aprovado no painel |

## Correções nesta revisão

- `engine/drivers/vsol_ssh.py`: os padrões de prompt usavam `$` com `MULTILINE`, que podia reconhecer uma linha de configuração terminada em `>` ou `#` como prompt final. Agora prompt, login e senha só são reconhecidos no fim do buffer. A coleta `show running-config` exige prompt final antes de aceitar a saída; uma pausa do CLI não basta para salvar conteúdo incompleto.
- `engine/ftp_incoming.py`: `existing_files()` podia abortar o início do processo se o cliente FTP removesse/renomeasse um arquivo entre `iterdir()` e `lstat()`. Arquivos que desaparecem durante a captura inicial agora são ignorados e o worker continua.
- `engine/artisan_session.py`: o fechamento da sessão agora limpa a referência do processo antes do cleanup e fecha stdout mesmo se fechar stdin falhar, evitando deixar o objeto apontando para um processo encerrado em uma falha de transporte.
- `engine/backup_engine.py`: erro transitório ao enviar heartbeat não encerra mais definitivamente o monitor; novas tentativas continuam no próximo intervalo. Se Laravel responder `updated=false`, o worker marca a coleta para cancelamento e não tenta publicar um resultado de uma execução que já perdeu.
- `EngineJobService::heartbeat()` e o comando `engine:heartbeat`: a renovação agora é um `UPDATE` condicional por execução, status e worker, removendo a janela entre consultar a posse e atualizar o timestamp. O comando retorna o campo `updated` para o engine reconhecer perda do lease.
- `app/app/Http/Controllers/DeviceController.php`: mudanças de fabricante/tipo agora são recusadas quando existem policies, execuções, conta FTP ou integração OLT vinculadas. As execuções leem a classificação atual do device; trocar essa classificação reinterpretaria jobs antigos e poderia selecionar outro driver. A mensagem orienta criar um novo registro para preservar a trilha histórica.
- `docs/FTP_SSH_METHOD_SWITCH.md`, `docs/HUAWEI_OLT_FTP.md`, `docs/CHANGES_2026-09-30.md`, `docs/FIELD_HOMOLOGATION.md` e `docs/IDEIAS_FUTURAS.md`: distinguidos os fluxos de OLT manual e Huawei VRP automático; removidas as afirmações correntes de que Huawei network FTP não existe.

## Controles conferidos

- O registry normaliza vendor/platform/método e falha fechado para combinações desconhecidas.
- Os drivers SSH não carregam host keys do sistema; uma chave nova é observada e precisa ser confiada explicitamente. Senhas não entram nos logs estruturados.
- Saída de SSH e arquivos recebidos têm limites de tamanho; uploads FTP são abertos sem seguir symlink e verificados por inode, tamanho, modo, links, leitura completa e SHA-256.
- Storage e homes são limitados ao root configurado. Artifacts são publicados sem sobrescrever arquivo existente. Claim/sidecar FTP permite retomar processamento após falha do banco.
- O daemon FTP usa argumentos fixos sem shell, PureDB, usuários virtuais em chroot e limite de tamanho. `admin.py` transmite segredo por pipe, não argumento ou log.
- Exclusão física valida path, symlink, tipo, inode e identidade do diretório antes de remover. Upload recente e claim ativo bloqueiam a exclusão.
- Os scripts `perf_*` criam workspaces e bancos temporários com nomes isolados; os scripts que fazem operação no PostgreSQL verificam o diretório de dados do cluster de desempenho antes de continuar.

## Limites e pontos para atenção operacional

- Cancelamento de coleta não é imediato para os drivers interativos Huawei VRP e VSOL porque eles não consultam `cancel_check` durante leitura bloqueante. O timeout/stale do engine continua sendo a proteção de duração; o driver MikroTik verifica cancelamento no loop de leitura.
- Um comando de heartbeat pode demorar até o timeout de transporte antes da próxima tentativa. As falhas agora são repetidas a cada intervalo, mas não há backoff exponencial; os logs `heartbeat_failed` devem ser monitorados durante indisponibilidades prolongadas.
- O fluxo FTP depende do filesystem compartilhado entre Pure-FTPd e engine. Ele tolera reinício por sidecar, mas não substitui backup do volume FTP, banco e `APP_KEY`.
- As ferramentas `scripts/perf_*.py` são harnesses locais de laboratório, com caminhos e pressupostos do runtime isolado `/tmp/perf-1-runtime` e `/tmp/bm-perf-1-services`; não são comandos genéricos de operação ou produção.
- Parsers de Huawei/VSOL/MikroTik são informativos. O formato desconhecido não deve impedir a retenção de payload íntegro. `file_server` não equivale a backup de equipamento.

## Verificação feita

Os 37 arquivos Python foram analisados pelo parser AST do Python 3; não houve erro de sintaxe. `git diff --check` passou. Os arquivos PHP alterados passaram em `php -l` no container. Pint foi executado em cópias temporárias porque o mount da aplicação é somente leitura; a ordenação de imports do controller foi corrigida. O formatter mostrou diferenças antigas fora das linhas desta revisão em outros arquivos, que não foram aplicadas para evitar reformatação alheia. A suíte Python não foi executada nesta rodada; a validação funcional pelo painel ficará com o operador, conforme combinado.
