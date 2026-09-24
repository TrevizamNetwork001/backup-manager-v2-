# FTP-CORE-2 — exclusão de contas FTP e auditoria

A policy `ftp_push/config/manual` representa o método de backup do equipamento, não a credencial. Os três modos de exclusão da conta preservam a policy e sua associação; após criar outra conta Huawei OLT, o vínculo é reutilizado. `olt_ftp_integrations` mantém somente o estado de confirmação/teste do wizard, que é reiniciado ao trocar a conta.

## Operação

A página de detalhe da conta mostra um preview somente leitura e a Zona de risco. O administrador escolhe um modo e digita exatamente `EXCLUIR <USERNAME>`, `EXCLUIR DADOS <USERNAME>` ou `APAGAR TUDO <USERNAME>`. O servidor valida a frase e exige sessão de administrador e CSRF da rota web.

| Modo | Conta | Dados FTP da conta | Backups finais vinculados |
| --- | --- | --- | --- |
| Somente conta | Excluída | Preservados, incluindo histórico de recebimento | Preservados |
| Conta + dados FTP | Excluída | Removidos, incluindo incoming, quarantine atribuível, file_server e recibos | Preservados |
| Conta + todos os dados | Excluída | Removidos | Execuções e artifacts com `ftp_account_id` comprovado, mais seus arquivos validados, são removidos |

Para `file_server`, o último modo equivale ao segundo quando não existem execuções vinculadas. Para `backup`, a exclusão bem-sucedida libera `device_id` imediatamente. Site/POP, equipamento, credenciais SSH, políticas e execuções antigas sem vínculo comprovado permanecem. A pasta legacy do equipamento não é removida; somente seu `incoming` vazio pode ser removido. O chroot UUID próprio pode ser removido se estiver vazio.

## Fronteira de privilégios

O Laravel é o control plane: calcula conta, equipamento, recebimentos, execuções e artifacts pelo banco e pode gerenciar artifacts finais em `/data/backups`, onde já tem autoridade. Ele apenas **monta o chroot lógico como texto** e nunca abre, percorre ou remove paths em `/data/ftp`. O mount do app em `/data/ftp` permanece read-only. O ftp-admin é o único componente desta operação que inspeciona e limpa o storage FTP físico; seu mount em `/data/ftp` é read-write. Alterar chmod/chown do home para o usuário web ou elevar privilégios do app violaria essa fronteira.

O canal existente de reconciliação banco/Artisan foi ampliado. Ao abrir o detalhe, o Laravel registra um pedido de inspeção em `audit_events`. O ftp-admin consulta pedidos pendentes pelo comando `ftp:inspection-requests`, recebe identidades de contas por `ftp:accounts`, inspeciona somente as contas solicitadas e grava relatório estruturado por `ftp:physical-report`. A página atualiza após um ciclo quando ainda não existe relatório. O preview aceita somente um relatório da versão atual da conta com menos de 45 segundos. Caso o ftp-admin esteja indisponível ou o relatório seja antigo, o preview lógico aparece, mas a exclusão fica bloqueada com a mensagem “Estado físico FTP indisponível; exclusão bloqueada até nova verificação.” O navegador só envia modo e frase de confirmação, sem paths.

O pedido marca a conta inativa e pendente em transação. No ciclo seguinte, o ftp-admin publica um PureDB novo sem a conta e confirma a ausência do usuário no arquivo fonte publicado. Ele então revalida e limpa os paths FTP permitidos, informa o resultado estruturado a `ftp:finalize-deletions`, e o Laravel conclui registros e libera o equipamento apenas após esse resultado. A revogação, falhas e conclusão ficam auditadas. Se a limpeza falhar, a conta segue inativa e pendente; o próximo ciclo tenta novamente sem restaurar acesso. Arquivos já ausentes são aceitos no retry. Uma falha na finalização de `/data/backups` preserva a operação pendente para retry, com rollback dos arquivos finais isolados quando possível.

O ftp-admin deriva `/data/ftp/<device_id>/incoming` para legacy e `/data/ftp/accounts/<account_uuid>/incoming` para UUID. `device_id` é inteiro positivo e UUID deve ser canônico. `lstat` rejeita symlinks nos componentes e arquivos; só arquivos regulares com um link podem ser apagados. Sidecars `processing` e `quarantine` usam a convenção existente de token e metadata, com vínculo explícito à conta ou `device_id` legacy sem outro `account_id`. Claim ativo e upload alterado nos últimos 60 segundos bloqueiam a operação. O pai legacy `/data/ftp/<device_id>` é compartilhado e permanece; no layout UUID, apenas a árvore exclusiva da conta pode ser removida, preservando `/data/ftp/accounts`. Nenhum caminho arbitrário vindo do navegador ou shell `rm -rf` participa desse fluxo.

O Dockerfile do ftp-admin deriva de `php:8.4-cli` e não declara `USER`; `compose.yml` também não define `user` nem `cap_add` para esse serviço. Assim, o processo principal `python3 /ftp/admin.py` executa como UID/GID 0:0 com o conjunto padrão de capabilities do Docker, sem capacidades adicionais. Ele precisa criar homes para o Pure-FTPd e ler os diretórios `0700` pertencentes a UID/GID 65534. O `docker compose exec app id` não descreve o UID do PHP-FPM nem concede essa autoridade ao web request. O socket Docker não estava acessível neste workspace para confirmar o UID do processo real via `/proc`.

## PureDB e concorrência

O snapshot de contas usado na publicação do PureDB limita a finalização aos pedidos pendentes presentes naquele ciclo. Pedidos posteriores aguardam o próximo. A conta permanece ocupando o equipamento até a conclusão. O reconciliador não encerra sessões FTP já abertas automaticamente; a operação deve ocorrer sem uploads em curso. Se o PureDB não puder ser publicado, não há limpeza FTP.

## Paths e rollback de backups

O Laravel mantém a validação de artifacts e arquivos `file_server` em `/data/backups`: path, tamanho, SHA-256 e vínculo com o banco. Arquivos inválidos bloqueiam o modo correspondente. Os arquivos finais são isolados na própria raiz antes da transação; erro de banco tenta restaurá-los. Essa autoridade preexistente sobre `/data/backups` não foi ampliada.

Backup Manager armazena arquivos válidos de transporte independentemente da versão/formato interno. Parsers de vendor são auxiliares e não requisito para retenção. A análise registrada em `audit_events` não altera `stored`, `succeeded`, download ou regras de exclusão; a exclusão continua baseada em vínculo, path, tamanho e SHA-256.

## Auditoria

`audit_events` é genérica: `id`, `actor_user_id` nullable, `action`, `resource_type`, `resource_id` nullable, `resource_label` nullable, `result`, `ip_address` nullable, `metadata` JSON e `created_at`. Há índices por ação/data, recurso/ID e ator/data. Exclusões registram `pending`, `puredb_revoked`, `success`, `failed` ou `partial`, com conta, finalidade, dispositivo, UUID, layout, chroot, modo, contagens, bytes, paths, dados preservados e erro/rollback quando aplicável. Segredos não entram nos eventos. A tabela `ftp_account_audits` antiga permanece para o histórico das ações anteriores; seu vínculo com a conta vira nullable após exclusão.

## Implantação manual posterior

A migration `2026_09_24_000001_create_audit_events_and_ftp_deletions.php` já foi aplicada manualmente no ambiente real. Esta correção não a reaplica e não toca em contas ou storage reais. O deployment requer atualizar o código do app e do ftp-admin juntos, pois o contrato Artisan passou a incluir relatório físico e resultado de cleanup. Não executar `ftp:finalize-deletions` manualmente: a finalização depende da revogação publicada e do resultado privilegiado.

## Verificação

Testes Laravel cobrem preview, modos A/B/C, legacy/UUID, file_server, liberação do equipamento, vínculo de artifact, symlink, claim ativo, confirmação, autorização e auditoria. Testes Python verificam a ordem entre publicação do PureDB e finalização. A migration foi executada e revertida em PostgreSQL 17 efêmero isolado; o campo JSON, os índices e a FK foram conferidos. Nenhum banco real foi migrado.
