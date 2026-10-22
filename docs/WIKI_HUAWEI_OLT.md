# Wiki: backup da OLT Huawei por FTP

**Referência de campo:** MA5800-X15, MA5800V100R021C11 SPH306. O envio manual da configuração em ZIP foi validado. Os comandos podem variar por firmware; confira a ajuda `?` da própria OLT.

## Preparar no Backup Manager

1. Cadastre `OLT-EXEMPLO` como **Huawei / OLT**, ativa e no POP correto. Substitua o nome e o POP pelos dados reais.
2. Crie uma conta FTP **Backup** vinculada a ela e aguarde o provisionamento. Confirme associação ativa à política Huawei FTP (`ftp_push/config/manual`).
3. Consulte host, usuário e senha em **Contas FTP**. O destino `203.0.113.10` abaixo é ilustrativo; use o host exibido no painel. O FTP usa TCP 21 e portas passivas TCP 30000–30009. Libere o IP de origem visto após NAT em **Configurações → Origens permitidas no FTP**.

## Configurar na OLT

O teste manual e o auto-backup usam destinos configurados separadamente. `ftp set` prepara o envio manual; `file-server auto-backup configuration` prepara o agendado. A CLI observada solicitou usuário e senha em prompts após a escolha de `ftp user`; confirme a sintaxe com `?` antes de digitar a credencial.

```text
ftp set
file-server auto-backup configuration primary 203.0.113.10 ftp user
auto-backup period configuration interval 1 time 05:00
auto-backup period configuration enable
display file-server auto-backup configuration
display auto-backup period configuration config
save
```

`05:00` é um **exemplo**; ajuste ao horário desejado e ao relógio da OLT. O campo de agendamento no Backup Manager permanece **manual** para FTP Push, pois quem envia é a OLT. Configuração de `data` é separada e não faz parte da política de configuração aqui descrita. Não coloque senha em exemplos, tickets ou capturas.

## Testar sem aguardar o horário

Para validar o destino manual configurado por `ftp set`, use um nome novo e verifique a confirmação na CLI:

```text
backup configuration ftp 203.0.113.10 teste-config.zip format zip
```

Se a versão suportar, teste também **o destino do auto-backup** por `auto-backup manual configuration`. Confira a sintaxe com `auto-backup manual ?`; esse disparo é diferente do `backup configuration ftp` acima. Uma execução de teste aberta no painel que exige `bm-exec-<ID>.cfg` só termina com o nome exato indicado naquela tentativa.

## Conferir sucesso

Em **Contas FTP → conta → Recebimentos recentes**, confirme um arquivo novo `Armazenado`. Depois confira a execução **FTP recebido / Concluído** e o artefato disponível. A MA5800 real enviou ZIP de configuração com armazenamento e integridade válidos; isso não prova, por si só, o próximo horário periódico. Para homologar a agenda, observe outro arquivo no horário configurado.

Se houver erro de usuário, senha ou servidor, confira a senha atual da conta no painel e atualize **ambas** as configurações da OLT: `ftp set` e `file-server auto-backup configuration`. Veja a cronologia e as variantes de CLI em [Huawei OLT por FTP Push](HUAWEI_OLT_FTP.md).
