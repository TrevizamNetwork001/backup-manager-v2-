# Wiki de backup por equipamento

Esta é a entrada dos procedimentos de campo do Backup Manager V2. Cada página separa **configuração no equipamento**, **preparação no painel**, **teste** e **critério de sucesso**. Use a página do modelo e firmware reais; comandos de uma família não devem ser aplicados a outra.

Os IPs `192.0.2.0/24`, `198.51.100.0/24` e `203.0.113.0/24` e os nomes como `OLT-EXEMPLO` usados nos passos são fictícios. Antes de executar comandos, substitua IP, nome do equipamento, usuário FTP, senha e horário pelos valores do seu ambiente e confira o destino no painel.

| Família | Método no Backup Manager | Quem inicia o backup | Guia |
| --- | --- | --- | --- |
| ZTE C650 / Titan | FTP Push de configuração | OLT | [Configurar ZTE C650](WIKI_ZTE_C650.md) |
| ZTE C350 | FTP Push de configuração | OLT | [Procedimento ZTE C350](ZTE_OLT_FTP.md#procedimento-c350--exemplo) |
| Huawei MA5800 OLT | FTP Push de configuração | OLT | [Configurar Huawei OLT](WIKI_HUAWEI_OLT.md) |
| Huawei VRP, switch, roteador e BNG | FTP Push ou SSH Pull, conforme a política ativa | Equipamento no FTP; Backup Manager no SSH | [Configurar Huawei de rede](WIKI_HUAWEI_VRP.md) |
| MikroTik RouterOS 6/7 | FTP Push de `.rsc` ou SSH Pull | RouterOS no FTP; Backup Manager no SSH | [Configurar MikroTik](WIKI_MIKROTIK.md) |
| VSOL V1600GT OLT | SSH Pull | Backup Manager | [Configurar VSOL](WIKI_VSOL_OLT.md) |
| A10 Thunder TH1040 / ACOS P14 | SSH de controle + envio SFTP | Backup Manager inicia; A10 transfere | [Configurar A10](WIKI_A10.md) |

## Regra comum de validação

1. Confirme no painel que o equipamento está ativo, associado ao POP correto e à política compatível. Para FTP, a conta própria do equipamento deve estar ativa e provisionada; confira também a origem permitida no firewall.
2. Execute um teste ou aguarde o envio, conforme a página do equipamento.
3. Confirme **novo recebimento armazenado** quando houver FTP, **execução concluída** e **artefato disponível e íntegro**. Uma mensagem de sucesso apenas na CLI não comprova armazenamento no Backup Manager.
4. Para homologar uma agenda, observe um envio no horário/intervalo configurado. Um teste manual demonstra o transporte, mas não comprova o agendamento.

Credenciais não pertencem à wiki, a tickets ou a capturas da configuração. Consulte a conta no painel e o procedimento da família antes de alterar senhas.

O catálogo de fabricantes também contém marcas sem procedimento de coleta homologado nesta V2. A possibilidade de cadastrar o equipamento não significa que exista um driver de backup. Registre modelo, firmware, método e um envio real antes de publicar um novo guia como operacional.

## Histórico e detalhes técnicos

- [Homologação de campo](FIELD_HOMOLOGATION.md)
- [FTP e SSH: troca segura de método](FTP_SSH_METHOD_SWITCH.md)
- [Controle de origem FTP](FTP_ACCESS_CONTROL.md)
- [Alterações de 22/10/2026](CHANGES_2026-10-22.md)
