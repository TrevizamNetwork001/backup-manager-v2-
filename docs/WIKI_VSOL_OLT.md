# Wiki: backup da OLT VSOL V1600GT

**Modelo de campo:** VSOL V1600GT. O método validado é **SSH Pull**. O teste de FTP nesse modelo falhou em campo; a conta e a associação FTP foram removidas e o cadastro de FTP para V1600GT está bloqueado. `OLT-EXEMPLO` é um nome fictício usado nos passos.

## Preparar no Backup Manager

1. Cadastre `OLT-EXEMPLO` como **VSOL / OLT**, no POP correto e com IP de gerenciamento alcançável. Substitua o nome pelo do equipamento real.
2. Cadastre a credencial SSH, confirme a chave de host e associe a política **OLT-VSOL** com método `ssh_pull/config`. Na instância de campo, a política tinha agendamento diário às **02:00**.
3. Confira que o usuário consegue entrar no modo privilegiado e ler a configuração. O driver usa uma sessão SSH interativa e executa `show running-config`; se a conta não alcançar o prompt privilegiado, a coleta falhará.

## Testar

Use **Executar backup** na associação ou no equipamento pelo painel. A execução deve ficar **Concluída** e gerar um artefato de configuração disponível. Se falhar, confira conectividade SSH, usuário/senha, chave de host, privilégio e paginação/prompt da CLI. Não configure FTP apenas porque outro modelo VSOL aceita o protocolo.

Para uma conferência manual **somente de leitura** na CLI da OLT, o comando de referência é:

```text
show running-config
```

O teste de restauração exige procedimento separado para o firmware específico. Histórico do ensaio e limite de suporte: [Homologação de campo](FIELD_HOMOLOGATION.md) e [troca FTP/SSH](FTP_SSH_METHOD_SWITCH.md).
