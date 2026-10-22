# Wiki: backup do A10 Thunder TH1040

**Equipamento de campo:** CGNAT-A10, Thunder TH1040, ACOS 4.1.4-GR1-P14 build 42. **Método homologado:** execução manual iniciada pelo Backup Manager, controle SSH e envio do pacote completo `.tar.gz` pelo A10 a um SFTP restrito.

## Preparar

1. Cadastre o A10 como equipamento de rede com IP da interface **Management** e credencial SSH ativa. Confirme a chave de host. Na instância validada, o IP de gerenciamento é `172.22.254.234`.
2. Associe a política A10 de método `a10_system`, formato binário e agenda **manual**. O servidor deve ter a integração A10 habilitada e o receptor SFTP restrito acessível a partir da interface Management do equipamento.
3. Confirme conectividade da origem Management até o destino SFTP do Backup Manager na porta TCP 22. O FTP da porta 21 é outro serviço e não participa desse fluxo.

## Executar no painel

Em **Equipamentos → CGNAT-A10 → Ações → Executar backup A10**, inicie uma execução e acompanhe seu detalhe. O Backup Manager entra no A10, verifica a versão, chama o backup system via SFTP e responde aos prompts. O driver utiliza a interface Management e não salva um perfil de destino permanente no A10; portanto, **não é necessário cadastrar manualmente um backup periódico no equipamento** para esse método.

Na CLI P14, a ajuda `backup system ?` mostrou a opção `use-mgmt-port` e destino `sftp://[user@]host/file`. A URL e a senha de cada transferência são geradas/usadas pelo fluxo do servidor; não copie o comando de uma execução antiga nem publique credenciais.

## Validar

A execução deve ficar **Concluída** e gerar um artefato `.tar.gz` disponível com tamanho e SHA-256 válidos. Na execução de campo #211, o artefato #72 passou na verificação física. O pacote original pode ser baixado; a visualização de configuração A10 no painel mostra `startup-config.pri`/`.sec` quando disponível. O modo automático e a restauração não foram homologados.

Procedimento detalhado, limitações de versão e histórico: [A10 Networks / ACOS CGNAT](A10_BACKUP.md).
