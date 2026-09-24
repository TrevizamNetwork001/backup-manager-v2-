# FTP-CORE-1

Para Huawei OLT com `purpose=backup`, a criação da conta UUID também garante associação ativa `ftp_push/config/manual`; contas legacy e o layout por device continuam aceitos. A elegibilidade do upload consulta conta, device, `device_backup_policies` e `backup_policies`, sem exigir `olt_ftp_integrations` do wizard. A tela da conta mostra conta, PureDB, policy e prontidão, com ação **Preparar backup FTP** para associações ausentes. A OLT é configurada manualmente pelo operador.

FTP é um serviço de transferência de arquivos da infraestrutura. Backup é uma
finalidade; `file_server` é uma conta independente. A escolha foi limitar as
finalidades a esses dois valores porque `generic` não teria comportamento
operacional distinto nesta fase.

## Contas e compatibilidade

`device_id` é obrigatório para `backup` e nulo para `file_server`. O operador
informa sempre o usuário nas contas novas; `bmdev<ID>` permanece válido para
contas existentes. A senha é informada ou preenchida pelo botão Gerar usando
aleatoriedade criptográfica. O segredo é criptografado e
aparece somente na resposta de criação/rotação. Cada conta tem UUID estável.
Contas legadas usam `home_layout=legacy` e `/data/ftp/<device_id>/incoming`;
novas contas usam `home_layout=account` e
`/data/ftp/accounts/<account_uuid>/incoming`. A migration preenche UUID para
contas legadas sem modificar seus diretórios. Não há migração física automática.

## Recebimento

O engine varre os homes cadastrados. Backup Huawei mantém claim seguro,
validação física e execução `ftp_received`. `file_server` mantém os mesmos limites de
arquivo e claim, mas registra o recebimento em `ftp_received_files`, guarda o
conteúdo em `ftp-files/<uuid>/<token>` no storage local e não cria execução nem
aplica validador Huawei. Backup Manager armazena arquivos válidos de transporte independentemente da versão/formato interno. Parsers de vendor são auxiliares e não requisito para retenção. A tabela registra status, tamanho, hash, destino e erro; arquivo armazenado tem `error_code` nulo.
Os status persistidos são `processing`, `stored` e `quarantined`. O registro
`processing` nasce após o claim; retries mantêm o mesmo token. Falhas transitórias
preservam o arquivo e um sidecar com contador, último erro e hora da tentativa.
O engine só inicia novos claims `file_server` se a conta estiver ativa,
provisionada e sem `sync_error`. Um arquivo já claimed continua o retry mesmo
que a conta mude de estado; um arquivo em `incoming` espera a conta ficar pronta.
Telas de POP e download web não fazem parte da FTP-CORE-1.

## Perfil Pure-FTPd

O daemon é iniciado com opções globais `-E -A -R -K -G -r -u 1`, chroot,
autorename e limites de cliente/arquivo. Não há enforcement de permissão
configurável por conta. A UI mostra apenas o perfil global no próprio chroot.
O daemon pode permitir leitura de arquivos presentes no chroot conforme seu
perfil; não existe nesta fase backend de distribuição ou catálogo de firmware.
Firmware e download não são implementados nesta fase.
