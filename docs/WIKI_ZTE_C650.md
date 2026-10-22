# Wiki: backup da ZTE C650 por FTP

**Modelo validado:** ZTE C650. **Estado em 22/10/2026:** envio manual e novos ZIPs recebidos com integridade válida; falta observar um envio no horário diário configurado. Nos passos abaixo, `OLT-EXEMPLO` e `olt-exemplo` são nomes fictícios.

## Antes de acessar a OLT

1. Cadastre `OLT-EXEMPLO` como **ZTE / OLT** e associe ao POP correto. Use o nome adequado no seu ambiente. Deixe equipamento e política **ZTE FTP** ativos. A política usa `ftp_push/config/manual`; o campo “manual” significa que o Backup Manager apenas recebe, pois o horário é controlado pela OLT.
2. Crie uma conta FTP **Backup** exclusiva para essa OLT e aguarde o provisionamento. `olt-exemplo` é apenas um usuário ilustrativo. Leia usuário e senha atuais em **Contas FTP → conta → Mostrar acesso FTP**; use a credencial da mesma conta na OLT.
3. Em **Configurações → Origens permitidas no FTP**, libere o IP público de saída da OLT visto pelo servidor. O destino `203.0.113.10` abaixo é ilustrativo; use o host exibido no painel. O FTP usa TCP 21 e portas passivas TCP 30000–30009. O IP de gerenciamento da OLT pode ser diferente do IP que chega ao FTP.

## 1. Conferir relógio e fuso

```text
ZXAN#show clock
ZXAN#configure terminal
ZXAN(config)#clock ?
ZXAN(config)#clock timezone ?
ZXAN(config)#end
ZXAN#clock set HH:MM:SS MM-DD-YYYY
ZXAN#show clock
```

Configure o fuso **BRT/UTC-3** com a sintaxe mostrada por `clock timezone ?` no firmware instalado. `clock set` funciona em `ZXAN#`, não em `ZXAN(config)#`. Na OLT homologada, `show clock` passou a mostrar BRT após o ajuste. A sincronização NTP ainda não foi validada; confira periodicamente se a hora permanece correta.

## 2. Configurar o destino e o horário diário

Substitua o IP, o usuário e a senha no terminal da OLT pelos dados da conta no painel. A sintaxe corresponde à configuração observada na C650; confirme as opções com `?` se a versão for diferente. A opção `zip` faz a OLT enviar o arquivo compactado, que o Backup Manager aceita para ZTE.

```text
ZXAN#configure terminal
ZXAN(config)#startrun backup-to server 203.0.113.10 transport-type ftp username olt-exemplo password <SENHA_FTP> zip
ZXAN(config)#auto-write everyday 00:30:00
ZXAN(config)#end
ZXAN#write
```

Não acrescente uma pasta remota para a conta desta instalação: cada conta FTP tem sua própria raiz. Não copie a linha real com senha, nem o valor `encrypted` mostrado pela configuração, para a documentação. `write` salva a configuração e pode gerar um envio fora do horário diário; esse envio não prova que o agendamento executará às 00:30.

## 3. Conferir o que ficou salvo

```text
ZXAN#show clock
ZXAN#show running-config oam
ZXAN#show auto-write
ZXAN#show startrun-backup history
```

Na saída de `show running-config oam`, procure `auto-write everyday 00:30:00` e a linha `startrun backup-to` com o IP e usuário reais da conta FTP. Antes de compartilhar a saída, oculte toda a parte da credencial. `show auto-write` informa o histórico de gravação **local**. `show startrun-backup history` é a consulta da transferência remota; na primeira consulta de 22/10 ela não apresentou linhas.

Na C650 homologada, `show auto-backup` não forneceu o agendamento esperado. Os comandos `file-server` e `auto-backup period` do [procedimento C350](ZTE_OLT_FTP.md#procedimento-c350--exemplo) são de outra família de CLI.

## 4. Teste manual de transporte

Depois de salvar a configuração, faça o teste direto no modo privilegiado, substituindo IP, usuário e senha:

```text
ZXAN#copy ftp root: /datadisk0/DATA0/startrun.dat //203.0.113.10/startrun.dat@olt-exemplo:<SENHA_FTP>
```

O resultado esperado na OLT é `Put file successfully`, seguido no painel por **Recebimentos recentes → Armazenado**, uma execução **FTP recebido → Concluído** e um artefato íntegro. Esse comando coloca a senha na linha digitada; evite capturar ou compartilhar a linha completa. O arquivo manual `startrun.dat` da OLT-VARAN gerou recibo #206, execução #660 e artefato #277.

## 5. Validar o ZIP e o envio automático

O envio da C650 com `zip` produz nomes `ZXAN_<data-hora>.zip`. Na validação de 22/10, os recibos #209 e #210 ficaram `stored`, as execuções #662 e #663 `succeeded`, e os ZIPs passaram em tamanho, SHA-256 e CRC. Eles foram enviados às 10:10 e 10:13 BRT, fora do horário diário.

No próximo ciclo das **00:30 BRT**, compare o `show startrun-backup history` com **um novo** recebimento da conta `olt-varan` e seu artefato. Só esse ciclo confirma o agendamento. O Backup Manager armazena o ZIP original; a análise interna da configuração C650 ainda aparece como **não reconhecida** e não equivale a teste de restauração.

## Se algo faltar

| Sinal | Conferência |
| --- | --- |
| Conta pronta, sem arquivo | Destino e credencial na OLT, IP de origem permitido, porta 21 e portas passivas, relógio e histórico de `startrun-backup`. |
| Arquivo em quarentena por `missing_backup_policy` | Associe a política ZTE FTP ativa ao equipamento e faça novo envio. |
| `invalid_file` | Confira extensão e nome; `.dat`, `.sav`, `.cfg` e `.zip` são aceitos para ZTE. |
| `show auto-write` mostra `success`, sem recibo | A gravação local ocorreu; investigue a etapa FTP em `show startrun-backup history` e no servidor. |

Evidências, IDs e histórico do primeiro ZIP rejeitado estão em [Backup FTP das OLTs ZTE](ZTE_OLT_FTP.md).
