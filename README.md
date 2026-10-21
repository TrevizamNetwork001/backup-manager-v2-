# Backup Manager V2

Instalação inicial para a equipe que administra os servidores. O painel pode ser acessado por domínio ou por IPv4. A URL usa HTTPS; para acesso por IP, o instalador pode gerar um certificado local.

## 1. Preparar o servidor

- Use Debian ou Ubuntu com acesso administrativo.
- Instale Docker Engine com o plugin `docker compose` pelo guia oficial para [Debian](https://docs.docker.com/engine/install/debian/) ou [Ubuntu](https://docs.docker.com/engine/install/ubuntu/).
- Instale os programas do host: `sudo apt-get install nginx python3 openssl git iptables logrotate`.
- Para domínio, separe um certificado TLS e a chave privada. Para IP, você pode fornecê-los ou deixar o instalador criar um certificado local. O navegador avisará que esse certificado local não tem autoridade confiável; confira o IP e a impressão digital antes de aceitá-lo, ou instale sua CA interna nos computadores que abrirão o painel.
- Defina a interface de entrada do FTP, o IPv4 de destino visto pelo servidor após NAT e pelo menos um CIDR de origem autorizado. Abra no firewall externo apenas as portas necessárias: HTTPS e, para as origens autorizadas, FTP TCP 21 e 30000–30009.

O instalador exige o checkout em `/opt/backup-manager-v2`. A equipe deve ter acesso ao repositório pelo GitHub:

```bash
sudo git clone git@github.com:TrevizamNetwork001/backup-manager-v2-.git /opt/backup-manager-v2
cd /opt/backup-manager-v2
```

O usuário que executa o clone precisa de uma chave com acesso de leitura ao repositório. Para verificar os parâmetros sem alterar o host, acrescente `--preflight` ao comando abaixo.

## 2. Instalar

Exemplo com IP privado; substitua os valores pela rede real:

```bash
sudo bash install.sh \
  --url https://192.168.1.10 \
  --ftp-interface eth0 \
  --ftp-destination 192.168.1.10 \
  --ftp-allow-cidr 192.168.1.0/24
```

Para um domínio, use `--url https://backup.exemplo.com` com `--tls-cert /caminho/fullchain.pem --tls-key /caminho/privkey.pem`; para porta HTTPS alternativa, acrescente `:8443` à URL. A porta HTTP 80 redireciona para a URL HTTPS. O instalador cria os segredos, constrói as imagens, instala as dependências da aplicação, inicia banco e containers, configura o proxy Nginx, a rotação de logs e o firewall de origens FTP. Docker, Nginx e os demais programas do host precisam estar instalados antes da execução. Ele não modifica instalações existentes sem `--resume` e nunca troca a `APP_KEY` ao retomar.

Na instalação nova, entre pelo navegador com usuário `backup_manager` e senha `backup_manager`. O sistema exige e-mail e senha nova (mínimo de 12 caracteres com maiúsculas, minúsculas, número e símbolo), encerra a sessão e pede login com esses dados. Não há código de ativação no terminal. Restrinja a porta HTTPS no firewall às suas origens até concluir essa troca. O certificado local fica em `/etc/backup-manager-v2/tls/`; `--resume` o reutiliza e não troca a chave.

A sessão do painel expira após 30 minutos sem interação. Um aviso aparece no último minuto e permite continuar conectado; o navegador encerra a sessão ao terminar o prazo. O instalador compila as views com o usuário da aplicação e só anuncia a instalação pronta depois que o login responde no proxy interno.

Se uma etapa falhar, corrija a causa e execute o mesmo comando com `--resume`. Depois do primeiro acesso, ajuste a lista de origens em **Configurações → Origens permitidas no FTP**; o timer do host aplica as mudanças.

Para mostrar país, bandeira e ASN dos IPs na Auditoria, o instalador reutiliza as bases `GeoLite2-Country.mmdb` e `GeoLite2-ASN.mmdb` já presentes em `/usr/share/GeoIP`. Em um servidor novo, forneça os dois arquivos locais com `--geoip-country-db` e `--geoip-asn-db`, ou um ZIP privado com ambos na raiz usando `--geoip-archive /caminho/GeoIP.zip`. O instalador copia as bases para `/usr/share/GeoIP` e as monta somente para leitura no container. Sem elas, a instalação continua e a Auditoria indica país e ASN não identificados. Mantenha as bases atualizadas e fora do Git; um ZIP não muda as regras de redistribuição da [MaxMind](https://www.maxmind.com/en/geolite/eula).

## 3. Conferir e preservar

```bash
sudo docker compose ps
sudo docker compose exec -T app php artisan system:recovery-check
sudo docker compose exec -T app php artisan engine:health
sudo systemctl status backup-manager-ftp-acl.timer
sudo bash scripts/system-backup.sh
```

Confirme login, certificado HTTPS e conexão FTP a partir de uma origem autorizada e de uma não autorizada. Guarde a `APP_KEY` de `app/.env` em um cofre fora do servidor; faça cópia externa do dump de `database/backups/` e do volume de artefatos. O dump do banco sozinho não permite recuperação completa. Consulte [recuperação](docs/DISASTER_RECOVERY.md) e [atualização](docs/UPGRADE.md).

## Atualizar

Após publicar e revisar uma release com tag `vMAJOR.MINOR.PATCH`, busque a tag no servidor e execute `sudo bash update.sh --tag vMAJOR.MINOR.PATCH --preflight`. Para aplicar, repita sem `--preflight`. O procedimento, as exigências de custódia e os limites do retorno automático estão em [atualização](docs/UPGRADE.md).
