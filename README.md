# Backup Manager V2

Instalação inicial para a equipe que administra os servidores. O painel pode ser acessado por domínio ou por IPv4. A URL usa HTTPS; sem certificado fornecido, o instalador gera um certificado local.

## Guias por equipamento

A [wiki de backup por equipamento](docs/WIKI_EQUIPAMENTOS.md) reúne os procedimentos de configuração e validação para ZTE, Huawei OLT, Huawei de rede, MikroTik, VSOL e A10. Cada guia distingue testes manuais da confirmação de agendamentos.

## 1. Preparar o servidor

- Use Debian ou Ubuntu com systemd, apt, acesso administrativo e acesso à internet para os pacotes e imagens. O instalador instala os programas do host que faltarem e, em servidor novo, configura o repositório oficial do Docker para instalar Engine e Compose.
- Se já houver Docker instalado sem Compose, ou pacotes Docker conflitantes, o instalador interrompe a operação para preservar os outros serviços do host.
- Opcionalmente, forneça certificado TLS e chave privada. Sem eles, o instalador gera um certificado local para o domínio ou IP informado. O navegador avisará que esse certificado não tem autoridade confiável; confira o endereço e a impressão digital antes de aceitá-lo, ou instale sua CA interna nos computadores que abrirão o painel.
- Defina a interface de entrada do FTP, o IPv4 de destino visto pelo servidor após NAT e pelo menos um CIDR de origem autorizado. Abra no firewall externo apenas as portas necessárias: HTTPS e, para as origens autorizadas, FTP TCP 21 e 30000–30009.

O instalador exige o checkout em `/opt/backup-manager-v2`. A equipe deve ter acesso ao repositório pelo GitHub:

```bash
sudo git clone git@github.com:TrevizamNetwork001/backup-manager-v2-.git /opt/backup-manager-v2
cd /opt/backup-manager-v2
```

O usuário que executa o clone precisa de uma chave com acesso de leitura ao repositório. Para verificar os parâmetros sem alterar o host, acrescente `--preflight` ao comando abaixo.

## 2. Instalar

Na VM limpa, o modo guiado pede a URL HTTPS e confirma a interface e o IPv4 recebido pelo host após NAT. Se os equipamentos acessarem outro IPv4 público, informe-o como endereço passivo do FTP; caso contrário, aceite o valor sugerido. O assistente instala os serviços, mas deixa o receptor FTP desligado e sem regra de firewall até um administrador salvar as origens permitidas em **Configurações → Origens permitidas no FTP**. O timer do host aplica a regra antes de iniciar o receptor. Para conferir os dados sem instalar, use `sudo bash setup.sh --preflight`.

```bash
sudo bash setup.sh
```

Se a conexão cair, execute `sudo bash setup.sh` novamente; o assistente detecta a instalação pendente e a retoma. Use o comando completo abaixo para opções avançadas, como TLS próprio, GeoIP ou A10 SFTP.

Exemplo com IPs reservados para documentação; substitua todos os endereços pela rede real:

```bash
sudo bash install.sh \
  --url https://192.0.2.10 \
  --ftp-interface eth0 \
  --ftp-destination 192.0.2.10 \
  --ftp-allow-cidr 192.0.2.0/24
```

Para um domínio, use `--url https://backup.exemplo.com`; você também pode informar `--tls-cert /caminho/fullchain.pem --tls-key /caminho/privkey.pem`. Para porta HTTPS alternativa, acrescente `:8443` à URL. Se o IPv4 público anunciado aos equipamentos for diferente do IPv4 recebido pela VM após NAT, use `--ftp-passive-address IPV4_PUBLICO`; `--ftp-destination` continua sendo o endereço recebido pelo host. A porta HTTP 80 redireciona para a URL HTTPS. O instalador instala os programas necessários, cria os segredos, constrói as imagens, instala as dependências da aplicação, detecta a sub-rede Docker para configurar os proxies confiáveis, inicia banco e containers, aplica migrações e políticas padrão, configura o proxy Nginx, a rotação de logs e o firewall de origens FTP. Ele não modifica instalações existentes sem `--resume` e nunca troca a `APP_KEY` ao retomar.

Na instalação nova, entre pelo navegador com usuário `backup_manager` e a senha aleatória exibida ao final do instalador. O sistema exige e-mail e senha nova (mínimo de 12 caracteres com maiúsculas, minúsculas, número e símbolo), encerra a sessão e pede login com esses dados. A senha inicial não é fixa nem gravada em arquivo; se a conexão cair antes da troca, `--resume` gera outra senha e a mostra novamente. Restrinja a porta HTTPS no firewall às suas origens até concluir essa troca. O certificado local fica em `/etc/backup-manager-v2/tls/`; `--resume` o reutiliza e não troca a chave.

A sessão do painel expira após 30 minutos sem interação. Um aviso aparece no último minuto e permite continuar conectado; o navegador encerra a sessão ao terminar o prazo. O instalador compila as views com o usuário da aplicação e só anuncia a instalação pronta depois de verificar os serviços, o receptor FTP, o login e o CSS no proxy interno e no HTTPS do host. As páginas e os arquivos estáticos do painel já fazem parte do checkout.

Se uma etapa falhar, corrija a causa e execute o mesmo comando com `--resume`. Depois do primeiro acesso, ajuste a lista de origens em **Configurações → Origens permitidas no FTP**; o timer do host aplica as mudanças. Veja o [exemplo de preenchimento do firewall](docs/FTP_ACCESS_CONTROL.md#exemplo-de-preenchimento), com origem privada RFC 1918 e prefixo público ilustrativo.

Para mostrar país, bandeira e ASN dos IPs na Auditoria, o instalador reutiliza as bases `GeoLite2-Country.mmdb` e `GeoLite2-ASN.mmdb` já presentes em `/usr/share/GeoIP`. Em um servidor novo, forneça os dois arquivos locais com `--geoip-country-db` e `--geoip-asn-db`, ou um ZIP privado com ambos na raiz usando `--geoip-archive /caminho/GeoIP.zip`. O instalador copia as bases para `/usr/share/GeoIP` e as monta somente para leitura no container. Sem elas, a instalação continua e a Auditoria indica país e ASN não identificados. Mantenha as bases atualizadas e fora do Git; um ZIP não muda as regras de redistribuição da [MaxMind](https://www.maxmind.com/en/geolite/eula).

Após as migrations, o instalador cria as políticas padrão em banco novo, incluindo **MikroTik FTP** e **ZTE FTP**, antes de iniciar engine e scheduler. Nenhum equipamento ou conta FTP é criado automaticamente; associe a política e a conta conforme a [wiki de equipamentos](docs/WIKI_EQUIPAMENTOS.md). Em uma instalação existente, o seeder preserva o catálogo já configurado.

Para usar o backup A10 via SFTP, acrescente `--a10-sftp` na instalação nova. O instalador prepara a conta SFTP restrita no SSH do host e habilita o recurso nos containers. Por padrão, o A10 enviará ao IPv4 de `--ftp-destination`; se usar outro endereço alcançável pelo equipamento, informe `--a10-sftp-host ENDEREÇO`. Restrinja a porta SSH/TCP 22 às origens autorizadas. Cadastre o equipamento, sua credencial e uma política A10 no painel; o fluxo aceita o modelo e a versão homologados na [wiki A10](docs/WIKI_A10.md).

## 3. Conferir e preservar

```bash
sudo docker compose ps
sudo docker compose exec -T app php artisan system:recovery-check
sudo docker compose exec -T app php artisan engine:health
sudo systemctl status backup-manager-ftp-acl.timer
sudo bash scripts/system-backup.sh
```

Confirme login e certificado HTTPS. Depois de salvar as origens FTP pela web e ver **Aplicada no firewall**, confira a conexão FTP a partir de uma origem autorizada e de uma não autorizada. Guarde a `APP_KEY` de `app/.env` em um cofre fora do servidor; faça cópia externa do dump de `database/backups/` e do volume de artefatos. O dump do banco sozinho não permite recuperação completa. Consulte [recuperação](docs/DISASTER_RECOVERY.md) e [atualização](docs/UPGRADE.md).

## Atualizar

Após publicar e revisar uma release com tag `vMAJOR.MINOR.PATCH`, busque a tag no servidor e execute `sudo bash update.sh --tag vMAJOR.MINOR.PATCH --preflight`. Para aplicar, repita sem `--preflight`. O procedimento, as exigências de custódia e os limites do retorno automático estão em [atualização](docs/UPGRADE.md).
