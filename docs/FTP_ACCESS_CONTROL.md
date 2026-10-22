# Controle de origem do FTP

Atualizado em 02/10/2026. Este documento cobre o FTP de recebimento do Backup Manager V2 em `45.239.157.250`. O SFTP usado pelo A10 utiliza SSH/TCP 22 e tem controle de acesso separado.

## Configuração no painel

Em **Configurações → Origens permitidas no FTP**, um administrador informa um IPv4 ou bloco CIDR por linha e clica em **Salvar origens FTP**. Para um único endereço, use `/32`, por exemplo `198.51.100.10/32`. Informe o **IP de origem visto pelo servidor depois do NAT**. A aplicação aceita de 1 a 32 entradas IPv4, com prefixo de `/8` a `/32`; normaliza endereços de rede e remove duplicatas. A alteração é auditada como `ftp.access.changed`. Outros papéis podem consultar a lista, mas não alterá-la.

### Exemplo de preenchimento

Suponha que uma OLT da rede interna chegue ao servidor com IP de origem `192.168.50.10` (RFC 1918) e outra chegue após NAT por um prefixo público. No campo **Redes e IPs permitidos**, digite uma entrada por linha:

```text
192.168.50.10/32
198.51.100.0/24
```

`198.51.100.0/24` simula um prefixo público, mas é **reservado para documentação**; substitua pelo prefixo público real visto pelo servidor. Se somente um IP público deve acessar, use `/32`, por exemplo `198.51.100.10/32`. O IP `203.0.113.10` usado nos comandos das wikis representa o **destino FTP**, portanto não entra nesta lista de **origens**. Inclua um endereço RFC 1918 apenas se o servidor realmente enxergar esse endereço, por exemplo por uma rota privada ou VPN; quando houver NAT, informe o IP de saída após NAT. Clique **Salvar origens FTP** e aguarde o indicador **Aplicada no firewall** antes de testar o envio.

Registro histórico da lista aplicada nesta instância em 02/10/2026 (não use como modelo para outra instalação):

```text
10.0.0.0/8
172.16.0.0/12
192.168.0.0/16
100.64.0.0/10
45.239.156.0/22
```

O bloco público acima foi solicitado pelo operador. A lista é explícita: não é calculada automaticamente por ASN ou rota BGP. Ao receber outra rede do provedor ou IP público de equipamento, acrescente o CIDR no painel e confirme o indicador **Aplicada no firewall** antes de depender do envio. **Aguardando aplicação** significa que a revisão salva ainda não foi confirmada pelo aplicador no host; verifique o serviço antes de presumir que a nova origem está liberada. Remover um CIDR pode interromper o próximo envio dos equipamentos que usam aquela origem.

## Aplicação no host

O Compose publica somente IPv4 nas portas TCP **21** e **30000–30009**. As antigas regras amplas do UFW para FTP foram removidas. Como as portas do contêiner passam pelo encaminhamento do Docker, a restrição de origem é aplicada na cadeia `DOCKER-USER` do `iptables`, para tráfego que entra por `ens192` e tem destino original `45.239.157.250` nessas portas. Cada CIDR permitido retorna ao processamento normal do Docker; os demais recebem `REJECT`. Isso limita a conexão de rede e não substitui a autenticação da conta FTP nem o isolamento da pasta por equipamento.

O aplicador [`scripts/ftp_acl_host.py`](../scripts/ftp_acl_host.py) lê a revisão do banco pelo comando `php artisan ftp:acl-export`, valida os CIDRs e troca a cadeia ativa entre `BM_FTP_A` e `BM_FTP_B`. Em caso de indisponibilidade temporária da aplicação, usa a última lista aplicada em `/etc/backup-manager-v2/ftp-acl.json` (arquivo root, modo `0600`). Após aplicar uma revisão do painel, grava esse arquivo e marca a revisão com `php artisan ftp:acl-applied`. Não edite diretamente o arquivo de fallback para cadastrar redes; use o painel.

O serviço [`backup-manager-ftp-acl.service`](../scripts/backup-manager-ftp-acl.service) e o [timer](../scripts/backup-manager-ftp-acl.timer) foram instalados em `/etc/systemd/system`. O timer é habilitado, inicia após o boot e reconcilia a regra aproximadamente a cada minuto; o serviço tenta novamente após falha. O script usa `/opt/backup-manager-v2`; a interface e o IP de destino podem ser definidos em `/etc/backup-manager-v2/ftp-acl.env` (`BACKUP_FTP_ACL_INTERFACE` e `BACKUP_FTP_ACL_DESTINATION`). Sem esse arquivo, preserva os valores desta instalação (`ens192` e `45.239.157.250`). Se a topologia, IP ou faixa passiva mudar, revise também o Compose e valide a publicação antes de considerar a proteção efetiva. O timer não constitui prova de bloqueio sem intervalo durante uma reinicialização; isso exige ensaio específico de boot/rede externa.

Ao reconstruir esta instância em outro host, aplique a migration `2026_10_02_194500_add_ftp_acl_to_application_settings_table.php`, configure a lista no painel e confirme que `ftp:acl-export` retorna uma revisão positiva. Configure interface e destino no arquivo de ambiente do serviço antes de publicar o FTP. O `install.sh` da raiz faz isso na instalação inicial. Para instalação manual das unidades:

```bash
install -m 0644 scripts/backup-manager-ftp-acl.service scripts/backup-manager-ftp-acl.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now backup-manager-ftp-acl.timer
systemctl start backup-manager-ftp-acl.service
```

Confirme as regras efetivas em `DOCKER-USER` e a conectividade de uma origem autorizada antes de depender do serviço. Se o aplicativo ainda não estiver disponível, o aplicador só conseguirá usar o fallback depois que `/etc/backup-manager-v2/ftp-acl.json` tiver sido criado por uma aplicação bem-sucedida anterior. As regras UFW amplas não devem ser recriadas como forma de cadastrar novos IPs; a lista do painel é a fonte de verdade neste host.

Comandos de diagnóstico no host, sem revelar credenciais:

```bash
systemctl status backup-manager-ftp-acl.timer
systemctl show backup-manager-ftp-acl.service -p Result -p ExecMainStatus
iptables -S DOCKER-USER
iptables -S BM_FTP_A
iptables -S BM_FTP_B
docker compose exec -T app php artisan ftp:acl-export
```

Uma das cadeias `BM_FTP_*` deve estar apontada por `DOCKER-USER` e conter os CIDRs atuais seguidos de `REJECT`. O painel compara `ftp_acl_revision` com `ftp_acl_applied_revision` para mostrar o estado. Se permanecer **Aguardando aplicação**, confira `journalctl -u backup-manager-ftp-acl.service` e a disponibilidade do Docker/banco. O serviço roda como root no host; a conta web não executa comandos de firewall.

## Validação e limites

Em 02/10/2026, a revisão 1 do painel continha as cinco redes acima e `ftp_acl_applied_revision=1`; a cadeia ativa `BM_FTP_B` tinha cinco regras de permissão e a regra final de rejeição. O timer estava habilitado e o último serviço terminou com `Result=success` e `ExecMainStatus=0`. `docker compose config --quiet` passou; o FTP estava publicado apenas em IPv4. A suíte Laravel passou com **448 testes, 1 ignorado e 3.017 asserções**, incluindo validação de CIDR, autorização de administrador e estado pendente. Essas verificações provam configuração aplicada e testes internos; não incluem uma tentativa de conexão a partir de uma origem proibida externa nem um ensaio de reinicialização.

O primeiro upload real do `BNG-NE8000` após a configuração foi recebido em 02/10/2026 às 19:38:21 UTC: recibo **#70** `stored`, execução **#220** `ftp_received/succeeded`, artefato **#81** `available` com 6.714 bytes. `ArtifactStorage::verify()` retornou `valid`. Isso confirma o caminho de recebimento do BNG; a consulta registrada não identifica qual IP de origem foi visto pelo firewall.

Para diagnosticar ausência de arquivo, confira primeiro a origem pós-NAT e a regra, depois conectividade nas portas de controle e passivas, autenticação da conta, log do equipamento e recibos no painel. O controle de origem não agenda backup e não prova que o equipamento tenha gerado um arquivo; o procedimento Huawei está em [FTP_SSH_METHOD_SWITCH.md](FTP_SSH_METHOD_SWITCH.md).
