# Wiki: backup de roteadores, switches e BNG Huawei VRP

O Backup Manager suporta **SSH Pull** e, nos equipamentos Huawei de rede homologados, **FTP Push espontâneo**. Escolha o método pela política ativa do equipamento. O `SWITCH-POP-IPE`, o `BGP-VS-ADMIN`, o `VS-01-BGP1` e o `BNG-NE8000` já tiveram recebimentos FTP reais registrados; Huawei VRP por SSH também foi homologado em campo.

## Opção A — FTP Push periódico

### Preparar no painel

1. Cadastre o equipamento como **Huawei / Rede** e associe ao POP correto.
2. Crie uma conta FTP **Backup** própria para ele. Confirme que está ativa, provisionada e vinculada a uma política Huawei FTP `ftp_push/config/manual` ativa.
3. Confira o host FTP e libere o IP de saída visto após NAT. Nesta instância, o destino é `45.239.157.250`, TCP 21 e portas passivas TCP 30000–30009.

### Configurar no VRP

Use os dados da conta própria do equipamento. A linha do servidor configura o **destino**; a linha `interval` configura a **cadência**. A sintaxe abaixo foi usada como referência no VRP da rede; confirme a ajuda `?` no modelo/firmware antes de aplicar.

```text
system-view
set save-configuration backup-to-server server 45.239.157.250 transport-type ftp user <USUARIO_FTP> password <SENHA_FTP>
set save-configuration interval 1440 delay 60
commit
```

`1440` significa 24 horas. Nos equipamentos informados pelo operador, `delay 60` foi usado para aguardar após mudança de configuração; ele não define uma hora fixa do dia. Alguns VRP exigem `commit`; outros podem ter fluxo de gravação diferente. Não use `path` nesta instalação, pois a conta já possui uma raiz isolada. Não compartilhe a linha com senha, mesmo se a saída a apresentar codificada.

### Validar

Confira **na configuração aplicada da CLI** as duas linhas, destino e intervalo, e confirme o `commit` quando exigido. A ajuda `?` mostra opções, mas não confirma o valor salvo. Depois de uma tentativa, procure na conta FTP um recibo `Armazenado`, uma execução `FTP recebido / Concluído` e um artefato íntegro. Um único envio confirma o transporte; observe outro ciclo para confirmar a cadência de 24 horas. O salvamento automático pode não gerar arquivo novo se não houver mudança de configuração no VRP.

## Opção B — SSH Pull

Cadastre credencial SSH do equipamento no painel, confirme a chave de host e associe uma política Huawei SSH `ssh_pull/config` ativa. No equipamento, mantenha o serviço SSH e um usuário com privilégios suficientes para a leitura da configuração. O Backup Manager abre a sessão e coleta o texto; não é necessário configurar FTP ou destino remoto nessa opção.

Faça **Executar backup** no painel e confira execução concluída e artefato `.cfg`. Se for migrar de SSH para FTP, mantenha a política SSH ativa até o primeiro FTP íntegro; depois desative somente a associação SSH daquele equipamento. Não altere o método da política antiga, pois ela preserva o histórico.

Procedimento detalhado e evidências de campo: [FTP e SSH: troca segura de método](FTP_SSH_METHOD_SWITCH.md).
