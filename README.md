# Backup Manager V2

Instalação inicial para a equipe que administra os servidores. O painel pode ser acessado por domínio ou por IPv4. Em ambos os casos, a URL deve usar HTTPS e o certificado precisa cobrir o nome ou IP informado.

## 1. Preparar o servidor

- Use Debian ou Ubuntu com acesso administrativo e um terminal interativo.
- Instale Docker Engine com o plugin `docker compose` pelo guia oficial para [Debian](https://docs.docker.com/engine/install/debian/) ou [Ubuntu](https://docs.docker.com/engine/install/ubuntu/).
- Instale os programas do host: `sudo apt-get install nginx python3 openssl git iptables`.
- Separe um certificado TLS e a chave privada para o domínio ou IP escolhido. Se usar uma CA interna para um IP privado, instale essa CA nos computadores que abrirão o painel.
- Defina a interface de entrada do FTP, o IPv4 de destino visto pelo servidor após NAT e pelo menos um CIDR de origem autorizado. Abra no firewall externo apenas as portas necessárias: HTTPS e, para as origens autorizadas, FTP TCP 21 e 30000–30009.

O instalador exige o checkout em `/opt/backup-manager-v2`. A equipe deve ter acesso ao repositório pelo GitHub:

```bash
sudo git clone git@github.com:TrevizamNetwork001/backup-manager-v2-.git /opt/backup-manager-v2
cd /opt/backup-manager-v2
```

O usuário que executa o clone precisa de uma chave com acesso de leitura ao repositório. Para verificar os parâmetros sem alterar o host, acrescente `--preflight` ao comando abaixo.

## 2. Instalar

Exemplo com IP privado; substitua os valores pela rede e pelos arquivos reais:

```bash
sudo bash install.sh \
  --url https://192.168.1.10 \
  --tls-cert /etc/ssl/backup-manager/fullchain.pem \
  --tls-key /etc/ssl/backup-manager/privkey.pem \
  --ftp-interface eth0 \
  --ftp-destination 192.168.1.10 \
  --ftp-allow-cidr 192.168.1.0/24
```

Para um domínio, use `--url https://backup.exemplo.com`; para porta HTTPS alternativa, acrescente `:8443` à URL. A porta HTTP 80 redireciona para a URL HTTPS. O instalador configura os segredos, dependências, banco, containers, proxy Nginx, firewall de origens FTP e pede os dados do primeiro administrador. Ele não modifica instalações existentes sem `--resume` e nunca troca a `APP_KEY` ao retomar.

Se uma etapa falhar, corrija a causa e execute o mesmo comando com `--resume`. Depois do primeiro acesso, ajuste a lista de origens em **Configurações → Origens permitidas no FTP**; o timer do host aplica as mudanças.

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
