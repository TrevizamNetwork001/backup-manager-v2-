# Ideias futuras

Este documento registra possibilidades ainda não implementadas. Cada item precisa de decisão de escopo antes de virar uma tarefa de desenvolvimento.

## Funções da V1 ainda não aplicadas na V2 — revisão de 30/09/2026

A V2 aproveitou da antiga área **Configurações** apenas a organização: fuso horário e retenção têm controles próprios, e a seção **Administração** agora reúne atalhos para Políticas, FTP, Usuários, Saúde do sistema e Auditoria. Os atalhos apontam para telas já existentes. Nenhuma das funções abaixo foi implementada por esse ajuste de interface.

| Função da V1 | Situação na V2 | Quando retomar |
| --- | --- | --- |
| Alerta por atraso no recebimento FTP | Pendente; o envio depende do equipamento e aparece como manual no agendador | Próxima melhoria operacional, com intervalo esperado configurado por equipamento |
| Lixeira com restauração de backups | Pendente; a retenção atual pode excluir candidatos definitivamente | Depois do alerta FTP, antes de ampliar o uso da limpeza automática |
| Exportar/importar configuração | Pendente; a V2 não oferece pacote de migração administrativa | Quando houver necessidade de reconstruir ou migrar a instância |
| Backup de OLT FiberHome por Telnet e FTP | Pendente; credencial Telnet pode ser cadastrada, mas não executa backup | Quando houver OLT real disponível para homologação |
| MikroTik `.backup` binário enviado pelo roteador | Pendente; configuração textual já é coletada por SSH | Quando houver necessidade de restauração binária ou envio autônomo |

As seções abaixo registram o comportamento desejado e os cuidados de implementação. Esta lista não ativa funcionalidades nem altera políticas, equipamentos ou retenção em produção.

### Exportação e importação da configuração

Na V1, **Backup da Configuração** exporta um JSON sem segredos para migração (`/opt/backup-manager-local/docs/SETTINGS.md` e `backup_manager/settings_center.py`). A V2 ainda não tem exportação/importação equivalente; a ideia também está citada em `docs/CORE_STATUS.md`.

Para a V2, definir um formato versionado próprio com fuso, sites/POPs, equipamentos, políticas, associações e demais cadastros administrativos que façam sentido. O arquivo deve informar sua versão e o que foi omitido. **Não incluir** senhas, credenciais SSH/Telnet, tokens, chaves, hashes de usuários, sessões, certificados, conteúdo dos backups, artefatos nem caminhos internos de armazenamento.

A importação deve primeiro validar formato, versão, referências entre registros e conflitos; apresentar uma prévia; e só então aplicar as alterações em transação, com auditoria. Credenciais e integrações importadas sem segredos não podem ficar operacionais até serem reconfiguradas. Especificar também como tratar registros já existentes e usuários importados, sem sobrescrever senhas ou associar equipamento à política errada. Testar ida e volta em base isolada e um procedimento de recuperação antes de oferecer a função na interface.

**Critério para retomar:** migração de servidor, recuperação de desastre ou necessidade concreta de clonar cadastros entre instâncias.

### Outras preferências da antiga central

A V1 oferecia nome da instalação, empresa, contato administrativo, formatos de data/hora e página inicial por preferência. Hoje a V2 usa identificação e formatos padronizados; essas opções não foram trazidas. Considerar **nome da instalação e contato** se houver mais de uma instância ou necessidade de identificar responsáveis. Formatos de data/hora e página inicial só devem virar controles se houver demanda operacional; cada opção exige aplicação consistente em todas as telas e testes de navegação.

O controle de **domínio e certificado HTTPS** da V1 usa Certbot e Nginx no host. A V2 usa outra arquitetura de implantação, então esse fluxo não pode ser copiado diretamente. Se houver necessidade de administrá-lo pelo painel, especificar primeiro a integração com o proxy/contêiner real e o plano de reversão. Até lá, manter HTTPS na configuração de infraestrutura.

Usuários, saúde do sistema, políticas, FTP e auditoria já têm telas na V2; não há função antiga adicional para copiar desses atalhos. rclone, notificações e atualizações da V1 são iniciativas separadas e não foram incluídas neste reaproveitamento.

## MikroTik: backup iniciado pelo roteador via FTP Push

**Estado:** ideia pendente; não implementada na v2. Para backup de configuração MikroTik hoje, a v2 oferece `ssh_pull` com `/export terse`. Huawei OLT usa FTP manual/spontâneo conforme o fluxo OLT, e roteadores/switches Huawei VRP recebem FTP espontâneo. Esses fluxos não habilitam FTP para MikroTik.

O sistema antigo contém um fluxo MikroTik FTP Push em `backup_manager/mikrotik_ftp_scripts.py`, com templates para RouterOS 6 e 7 em `mikrotik_routeros_v6.py` e `mikrotik_routeros_v7.py`. Ele cria `.rsc` e/ou `.backup`, instala script e agendamento no roteador, envia os arquivos por `/tool fetch mode=ftp upload=yes` e limpa as cópias locais após o envio. Esses arquivos são referência de comportamento, não um recurso pronto para copiar diretamente para a arquitetura da v2.

**Decisão atual:** manter SSH Pull como opção padrão para quem precisa do export de configuração em texto. Considerar o fluxo iniciado pelo roteador quando houver necessidade comprovada do arquivo binário `.backup`, de envio autônomo pelo MikroTik ou quando a conectividade impedir o pull por SSH. O `.backup` binário e o `.rsc` atendem necessidades distintas: segundo a MikroTik, o binário é destinado à restauração no mesmo dispositivo e deve ser protegido por senha; o export em texto não inclui senhas de usuários, certificados e chaves SSH.

Para implementar a ideia na v2, avaliar em conjunto:

- formatos suportados (`.rsc`, `.backup` ou ambos), criptografia do binário e procedimento de restauração;
- integração do script/agendamento RouterOS 6/7, instalação, atualização, teste e remoção;
- conta de recebimento isolada por equipamento e transporte adequado à rede; FTP simples expõe credenciais e conteúdo, então deve ser avaliado antes de reutilizar o fluxo antigo;
- reconhecimento e validação dos dois formatos, correlação dos uploads com equipamento e execução, tratamento de arquivo incompleto, histórico, retenção e alertas de ausência/falha;
- regras de política e agendamento sem confundir o scheduler SSH da v2 com o scheduler instalado no roteador.

**Critério para retomar:** existir uma demanda operacional concreta por `.backup` ou envio autônomo, acompanhada de um MikroTik de teste para homologar RouterOS, transporte, recebimento e restauração. Até lá, usar o fluxo SSH existente.

Referências: [driver SSH da v2](../engine/drivers/mikrotik_ssh.py), [registro de drivers](../engine/registry_setup.py), [restrição de associação FTP na v2](../app/app/Http/Controllers/DeviceBackupPolicyController.php), [comparação com o sistema antigo](ENGINE_DRIVERS.md), [Backup RouterOS](https://manual.mikrotik.com/docs/getting-started/configuration-management/backup/), [Configuration Management](https://manual.mikrotik.com/docs/getting-started/configuration-management/) e [Fetch](https://manual.mikrotik.com/docs/cli-reference/tool/fetch/).

Ver também [a documentação da troca entre SSH e FTP](FTP_SSH_METHOD_SWITCH.md) para a diferença entre FTP manual de OLT e recebimento automático de Huawei VRP.

## Reaproveitamento selecionado do sistema antigo — revisão de 30/09/2026

1. **Cadência esperada para FTP por equipamento — prioridade alta.** O V1 já relacionava status do backup, idade do último arquivo e agendamento nas telas operacionais (`backup_manager/observability.py`, `backup_manager/report_views.py`). Na V2, Huawei FTP usa `schedule_type=manual` porque quem envia é o equipamento; `DeviceBackupHealth::dominantCadence()` classifica esse vínculo como manual. Assim, um envio diário ausente não recebe o mesmo alerta de atraso de uma coleta SSH diária. Aproveitar o conceito de acompanhamento do V1, acrescentando na configuração FTP do equipamento um intervalo esperado e mostrando atraso/ausência em Status dos backups. O campo deve representar expectativa de monitoramento, sem prometer que o Backup Manager agenda o upload.
2. **Lixeira antes da exclusão definitiva — prioridade alta após a ativação da retenção.** O V1 implementa `available → trashed → deleted`, restauração dentro do prazo, simulação e auditoria em `backup_manager/lifecycle.py`. A V2 já tem prévia e protege o último backup válido, mas a limpeza remove fisicamente os demais candidatos. Reaproveitar o fluxo de duas etapas e restauração, redesenhado para `BackupArtifact` e o storage da V2; não copiar SQL/paths da V1.
3. **FiberHome OLT por Telnet + FTP — quando houver equipamento de homologação.** O V1 tem comandos `upload ftp config` e `upload ftp system`, transporte Telnet e correlação de dois arquivos em `backup_manager/fiberhome_olt.py` e `docs/OLT_BACKUPS.md`. A V2 permite cadastrar uma credencial Telnet, mas ainda não executa backup FiberHome. Reaproveitar o roteiro e casos de teste após validar modelo, firmware, formato `.txt`/`.db`, negociação Telnet e recebimento FTP no hardware real.
4. **MikroTik `.backup` binário por envio do roteador — sob demanda.** O V1 possui script e agendamento RouterOS 6/7; a V2 já atende configuração textual por SSH. Retomar somente se houver necessidade concreta de restauração binária ou de envio autônomo, conforme a seção específica acima.

Relatórios básicos/CSV, health do motor, falhas consecutivas e diagnóstico já foram incorporados ou melhorados na V2. Exportação XLSX/PDF, notificações Telegram e clonagem de configuração são trabalhos separados e não entram como dependência das quatro prioridades acima.

## Equipamentos e políticas "arquivados" (terceiro estado além de ativo/inativo)

**Estado:** ideia pendente; não implementada na v2.

Hoje um equipamento (ou uma política) só tem dois estados: ativo ou inativo.
Inativo continua aparecendo normalmente nas listas (Equipamentos, Políticas),
só some da operação (agendador, dashboard de saúde, novas associações). Isso
é intencional — nunca esconder histórico real sem que a pessoa peça
explicitamente.

A ideia levantada: um terceiro estado, **arquivado**, para equipamentos
definitivamente fora de operação (vendidos, trocados de vendor, substituídos)
que a pessoa não quer mais ver nas telas do dia a dia — mesmo já estando
inativos. Diferente de inativo, arquivado **some da lista principal por
padrão**, mas sem apagar nada; precisa de uma forma explícita de consultar
depois (filtro "mostrar arquivados" ou aba separada), preservando as mesmas
garantias de auditoria que já existem hoje (Execuções/Artefatos/Auditoria
nunca filtram por equipamento estar ativo).

Pontos a decidir antes de implementar:

- coluna nova (`archived_at` ou `is_archived`) em `devices` e, separadamente,
  se `backup_policies` também precisa do mesmo conceito;
- pré-condição para arquivar (equipamento já precisa estar inativo? política
  sem associações ativas?) — evitar arquivar algo que ainda está em uso;
- onde o filtro "mostrar arquivados" aparece em cada tela (Equipamentos,
  Políticas, e possivelmente Credenciais/FTP, que têm o mesmo problema);
- se arquivamento é reversível (desarquivar) — provavelmente sim, mesma
  filosofia de "inativo" hoje;
- RBAC: quem pode arquivar/desarquivar (provavelmente igual a quem já pode
  desativar).

**Critério para retomar:** quando o volume de equipamentos/políticas
inativas acumuladas começar a atrapalhar a navegação nas listas do dia a
dia — não há esse problema hoje.

Origem: conversa sobre desativação de credencial/associação SSH após migrar
um equipamento (BNG-NE8000) para FTP push; ver `docs/CORE_STATUS.md`.

## Telegram: o que ficou fora da primeira entrega

A V2 entrega só alertas + normalização (`docs/TELEGRAM_NOTIFICATIONS.md`). Do V1
continuam pendentes, na ordem em que provavelmente fazem sentido: resumos
diário/semanal (fuso da instância, chave por período para não duplicar após
reinício), aviso opcional de backup concluído, mais de um destino e tópico por
equipamento/grupo, resumo executivo (só com atividade administrativa) e cópia
do arquivo de backup pelo Telegram. **Critério para retomar:** o operador
pedir; nada aqui bloqueia o fluxo atual.
