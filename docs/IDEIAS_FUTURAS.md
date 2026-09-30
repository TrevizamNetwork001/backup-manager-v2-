# Ideias futuras

Este documento registra possibilidades ainda não implementadas. Cada item precisa de decisão de escopo antes de virar uma tarefa de desenvolvimento.

## MikroTik: backup iniciado pelo roteador via FTP Push

**Estado:** ideia pendente; não implementada na v2. Para backup de configuração MikroTik hoje, a v2 oferece `ssh_pull` com `/export terse`. O `ftp_push` da v2 atende Huawei OLT, e sua associação exige uma OLT Huawei com conta FTP ativa.

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

> Nota: desde a introdução de `Device::isHuaweiFtpEligible()`, o `ftp_push` da v2
> já não é mais exclusivo de OLT — também atende roteador/switch Huawei
> (`platform=network`), usando o mesmo `set save-configuration
> backup-to-server` do VRP. Validado com um NE8000 real. O parágrafo acima
> ("sua associação exige uma OLT Huawei") ficou desatualizado por essa
> mudança; mantido aqui só para não mexer em conteúdo de outra sessão em
> andamento — ver `docs/CORE_STATUS.md` para a decisão completa.

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
