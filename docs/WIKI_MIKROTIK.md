# Wiki: backup de MikroTik RouterOS

**Famílias testadas:** RouterOS 6 e 7 para export textual `.rsc`. Um equipamento com RouterOS 6.45.7 enviou arquivo real pelo fluxo FTP. `MK-EXEMPLO` é um nome fictício para os passos abaixo. O formato binário `.backup` não é aceito por essa política.

## Opção A — FTP Push com integração pelo painel

1. Cadastre `MK-EXEMPLO` como **MikroTik / Rede** e use o nome adequado no seu ambiente. Crie conta FTP **Backup** própria, ativa e provisionada, e associe a política **MikroTik FTP** `ftp_push/config/manual`.
2. Cadastre também uma credencial SSH funcional e valide a chave SSH. Ela é usada **para instalar a rotina no RouterOS**, não para transferir o arquivo diário.
3. Na conta FTP, escolha o horário diário e clique **Integrar FTP no MikroTik**. O painel instala o script `backup-manager-v2-ftp` e seu scheduler inicialmente desativado, faz um envio de teste e só ativa a agenda depois de confirmar execução e artefato.
4. Confirme na conta o recibo `.rsc` `Armazenado`, a execução `FTP recebido / Concluído` e o artefato disponível. O arquivo diário segue o padrão `EQUIPAMENTO-backup-AAAA-MM-DD.rsc`.

Na CLI RouterOS, confira o script e a agenda instalados, sem imprimir segredos do corpo do script:

```text
/system script print where name="backup-manager-v2-ftp"
/system scheduler print where name="backup-manager-v2-ftp"
```

O scheduler deve estar habilitado, com o horário escolhido e intervalo de um dia. Em campo foi observado **23:00**, mas use o horário escolhido para o seu equipamento. A política FTP continua manual no Backup Manager porque o agendamento pertence ao RouterOS. Se a senha FTP for rotacionada, atualize a integração pelo painel; uma senha antiga no script impedirá novos envios. Para teste pontual sem instalação, consulte os scripts RouterOS 6/7 em [FTP e SSH: troca segura de método](FTP_SSH_METHOD_SWITCH.md).

## Opção B — SSH Pull

Cadastre credencial SSH e associe a política **MikroTik SSH** `ssh_pull/config` ativa. O Backup Manager entra no RouterOS e coleta o export textual `.rsc`; o equipamento não precisa de conta FTP para essa opção. Faça uma execução manual pelo painel e confira execução concluída e artefato. Na instância de campo, a política SSH existente tinha agenda diária às **03:00**.

As duas políticas podem coexistir. Revise a necessidade de manter ambas para evitar cópias duplicadas sem confundir o histórico de cada método. Mais detalhes e evidências: [FTP e SSH: troca segura de método](FTP_SSH_METHOD_SWITCH.md).
