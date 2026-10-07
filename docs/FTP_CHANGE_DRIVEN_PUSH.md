# FTP por alteração (Huawei VRP de rede) × FTP por relógio

Registro de 2026-10-07. Complementa `docs/FTP_SSH_METHOD_SWITCH.md`.

> **Status do código:** a regra descrita abaixo está implementada e testada
> (64 testes passando), mas ainda **não foi commitada**: depende do campo
> `expected_ftp_interval_hours` e da migration dele, trabalho pendente de outra
> sessão. Deve entrar no mesmo commit daquele conjunto. Resumo do dia em
> [CHANGES_2026-10-07.md](CHANGES_2026-10-07.md).

## Descoberta

Cinco equipamentos Huawei de rede (`BNG-NE8000`, `SWITCH-BASE`, `SWITCH-POP-IPE`,
`VS-01-BGP1`, `BGP-VS-ADMIN`) apareciam como "FTP muito atrasado" com o intervalo
esperado de 24 h. Parte do silêncio é o comportamento normal por alteração, mas
o BNG tinha uma **falha real de envio** escondida (ver "Incidente" no fim); nos
outros quatro, ainda falta conferir o log do equipamento:

- A configuração do NE8000 estava correta e igual à da apostila:
  `set save-configuration interval 1440 delay 60` + `backup-to-server … transport-type ftp`.
- Os recebimentos do BNG são irregulares (30/09 12:08 e 12:20, 02/10 16:38,
  04/10 12:43, horário de Brasília) e o tamanho muda a cada arquivo
  (6.708–6.719 B): acompanham **alterações de configuração**, não um relógio.
  (Atenção: as consultas SQL da investigação mostravam horários 6 h adiantados por
  erro de conversão de fuso; os valores acima já estão corrigidos. Forma certa:
  `received_at at time zone 'UTC' at time zone 'America/Sao_Paulo'`.)
- A documentação da Huawei (famílias S e CX, trechos obtidos por busca) diz que o
  salvamento automático compara a configuração atual com a salva e dispara quando
  há diferença; é cancelado com CPU alta (`cpu-limit`).
- OLT Huawei é diferente: envia por horário definido no próprio equipamento
  (`OLT-huawei-base`: 30 arquivos em 7 dias; `OLT-huawei-IPE`: 13).

Conclusão: em **roteadores/switches Huawei (VRP, `platform=network`)** o envio FTP
é **por alteração**. Sem mudança salva, não chega arquivo novo — e isso é normal. O
último arquivo continua sendo cópia fiel da configuração.

## Decisão

| Tipo | Envio FTP | Intervalo esperado / alerta de silêncio |
| --- | --- | --- |
| Huawei rede (VRP) | só quando a configuração muda e é salva | **ignorado e zerado**; nunca alerta "sem backup" por silêncio |
| Huawei OLT | por relógio, definido no equipamento | mantém o campo e o alerta |
| Outros fabricantes | conforme o método | mantêm o campo e o alerta |

Implementação: `Device::pushesOnlyOnConfigChange(vendor, platform)`;
`DeviceBackupHealth::rows()` não aplica `classifyFtp()` a esses equipamentos (o que
vale para tela de saúde, dashboard, Telegram e resumos, pois todos usam `rows()`);
`DeviceController` zera `expected_ftp_interval_hours` ao salvar. Texto "Backup por
alteração" na configuração guiada (`devices/edit.blade.php`). Testes:
`HuaweiChangeDrivenFtpTest` (5 casos).

Continuam alertando: arquivo recebido e rejeitado, execução falha e, quando houver
backup SSH agendado no mesmo equipamento, o atraso do SSH.

## Recomendação operacional

- **Equipamentos críticos** (BNG, core): FTP por alteração **mais** SSH diário. O painel
  aceita as duas associações; a saúde considera o último sucesso de qualquer método.
  Cobre o caso "configuração mudou, o envio FTP falhou".
- **Menos críticos:** só FTP por alteração.
- **Quem não quiser FTP:** só SSH.

## Pendências

- O formulário `devices/_form.blade.php` (do root) ainda mostra o campo de intervalo
  para Huawei de rede; o valor é ignorado e zerado no servidor. Ocultar o campo na
  interface fica para quando o arquivo puder ser editado.
- Os 5 equipamentos ainda têm 24 h gravadas; não gera alerta, mas pode ser zerado
  abrindo e salvando cada um.
- **Experimento no BNG (07/10/2026): inconclusivo pelo painel, reforçado pelo log do
  equipamento.** Com `interval 30` e sem alterações, nenhum arquivo chegou ao painel,
  mas o envio **estava falhando** (ver abaixo), então a ausência de arquivo sozinha
  não provava nada. O `display logbuffer | include FTP` registra toda tentativa
  (sucesso ou falha): as tentativas de 07/10 (10:39, 11:02, 11:53, 14:26, 14:41,
  14:44) acompanham os `commit` do operador, e houve **mais de 2 h sem nenhuma
  tentativa** (11:53 → 14:26) com `interval 30` ativo. Isso é coerente com envio por
  alteração e não por relógio. Ressalva: o horário exato em que o intervalo foi
  alterado não foi anotado, então não é prova definitiva. A conclusão também se apoia
  na documentação da Huawei e nos tamanhos/horários irregulares do histórico.
- Os outros 4 Huawei de rede em silêncio (SWITCH-BASE, SWITCH-POP-IPE, VS-01-BGP1,
  BGP-VS-ADMIN) ainda precisam ter o `display logbuffer | include FTP` conferido
  para distinguir "sem alteração" de "envio falhando".

## Incidente: BNG sem enviar de 04/10 a 07/10 (resolvido)

- Sintoma: nenhum arquivo novo do BNG-NE8000 desde 04/10 12:43 (Brasília); painel
  sem nenhum alerta de falha (só o alerta de atraso, que se confundia com "sem mudança").
- O log do equipamento (`display logbuffer | include FTP`) mostrou o que o painel
  não vê: tentativas em 07/10 às 10:39, 11:02, 11:53, 14:26 e 14:41, todas
  `B2S_BACKUP_FAILED` com `ErrCode=83930255`; a última boa foi 04/10 12:43 (`ErrCode=0`).
- Causa: a linha `set save-configuration backup-to-server … password` foi
  redigitada no equipamento (a senha cifrada exibida mudou) com uma senha diferente
  da da conta no servidor (`credential_changed_at` vazio até então).
- Correção: rotação da senha da conta no painel (14:43) + reconfiguração do
  `backup-to-server` no BNG; envio às 14:44:55 com `ErrCode=0` e recibo `stored`
  (6.711 B).
- Lições: (1) falha de envio é **invisível** para o painel — só o log do equipamento
  mostra; para equipamentos críticos, manter também o SSH diário; (2) o Pure-FTPd do
  projeto roda sem log de conexões, e o banco de contas fica em
  `/etc/backup-ftp/pureftpd.passwd` (não no caminho padrão); (3) o `provisioned_at`
  de todas as contas muda juntos, pois o sincronizador reconstrói o banco inteiro a
  cada alteração — não indica perda de contas.

## Teste de criação e remoção no BNG (07/10/2026, resultado)

Com `interval 1440 delay 5`, o operador criou a `vlan 30`, deu `commit`, e depois a
removeu com `undo vlan 30` e `commit`. O painel recebeu um arquivo para cada
mudança (horários de Brasília, tamanhos em bytes):

| Recebido | Tamanho | Estado da configuração |
| --- | --- | --- |
| 14:44:55 | 6.711 | sem a VLAN de teste |
| 15:11:07 | 6.721 | com a VLAN 30 (+10) |
| 15:14:50 | 6.712 | VLAN 30 removida (−9) |

Conclusões:

- **Adicionar e remover configuração (com `commit`) geram backup**, depois do
  `delay`. O equipamento confirma cada envio com `ErrCode=0` em
  `display logbuffer | include FTP`.
- O arquivo do equipamento acompanha o estado atual: depois da remoção, o conteúdo
  voltou ao tamanho de antes da VLAN.
- Ressalva: se algo for criado e removido **antes** de qualquer salvamento, a
  configuração volta a ser igual à salva e não há o que enviar.
