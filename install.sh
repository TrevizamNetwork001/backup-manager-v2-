#!/usr/bin/env bash
# First installation on a Debian/Ubuntu host administered by the operator's team.
set -euo pipefail
umask 077

PROJECT=/opt/backup-manager-v2
URL=''
FTP_INTERFACE=''
FTP_DESTINATION=''
TLS_CERT=''
TLS_KEY=''
GEOIP_COUNTRY_DB=''
GEOIP_ASN_DB=''
GEOIP_ARCHIVE=''
RESUME=false
PREFLIGHT=false
FTP_CIDRS=()

usage() {
    cat <<'HELP'
Usage: sudo bash install.sh --url https://HOST[:PORT] \
  --ftp-interface INTERFACE --ftp-destination IPv4 \
  --ftp-allow-cidr IPv4/CIDR [--ftp-allow-cidr IPv4/CIDR ...] \
  [--tls-cert /absolute/fullchain.pem --tls-key /absolute/privkey.pem] \
  [--geoip-country-db /absolute/GeoLite2-Country.mmdb --geoip-asn-db /absolute/GeoLite2-ASN.mmdb] \
  [--geoip-archive /absolute/GeoIP.zip] \
  [--resume] [--preflight]

Requires a clean checkout at /opt/backup-manager-v2, Docker with Compose v2,
Nginx, Python 3, OpenSSL and Git. For IPv4 without a supplied TLS pair,
generates a local certificate with the IP in SAN. DNS requires a supplied pair.
--resume keeps existing secrets and creates initial access only if no users exist.
--preflight validates requirements and parameters without changing the host.
GeoIP: existing files in /usr/share/GeoIP are reused; optional file paths copy
your own Country and ASN databases there for local-only IP lookup in Audit.
Alternatively, --geoip-archive accepts a private ZIP containing exactly
GeoLite2-Country.mmdb and GeoLite2-ASN.mmdb at its root.
HELP
}

while (($#)); do
    case "$1" in
        --url|--tls-cert|--tls-key|--ftp-interface|--ftp-destination|--ftp-allow-cidr|--geoip-country-db|--geoip-asn-db|--geoip-archive)
            if (($# < 2)); then usage >&2; exit 2; fi
            case "$1" in
                --url) URL=$2 ;;
                --tls-cert) TLS_CERT=$2 ;;
                --tls-key) TLS_KEY=$2 ;;
                --ftp-interface) FTP_INTERFACE=$2 ;;
                --ftp-destination) FTP_DESTINATION=$2 ;;
                --ftp-allow-cidr) FTP_CIDRS+=("$2") ;;
                --geoip-country-db) GEOIP_COUNTRY_DB=$2 ;;
                --geoip-asn-db) GEOIP_ASN_DB=$2 ;;
                --geoip-archive) GEOIP_ARCHIVE=$2 ;;
            esac
            shift 2 ;;
        --resume) RESUME=true; shift ;;
        --preflight) PREFLIGHT=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

if ((EUID != 0)); then echo 'Execute como root.' >&2; exit 1; fi
if [[ ! -f /etc/os-release ]]; then echo 'Sistema operacional não reconhecido.' >&2; exit 1; fi
source /etc/os-release
if [[ " ${ID:-} ${ID_LIKE:-} " != *' debian '* && " ${ID:-} ${ID_LIKE:-} " != *' ubuntu '* ]]; then
    echo 'Somente Debian ou Ubuntu.' >&2; exit 1
fi
if [[ "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" != "$PROJECT" ]]; then
    echo "Instale o checkout em $PROJECT." >&2; exit 1
fi
for program in docker git python3 openssl nginx systemctl iptables logrotate; do
    if ! command -v "$program" >/dev/null 2>&1; then
        echo "Falta $program. Instale os requisitos do host antes de continuar." >&2; exit 1
    fi
done
docker compose version >/dev/null
if [[ ! -f "$PROJECT/app/composer.lock" || ! -f "$PROJECT/compose.yml" ]]; then
    echo 'Checkout incompleto.' >&2; exit 1
fi
if [[ "$PREFLIGHT" == true ]]; then
    :
elif [[ -f "$PROJECT/.env" || -f "$PROJECT/app/.env" ]]; then
    if [[ "$RESUME" != true ]]; then
        echo 'Instalação existente: use --resume para preservar os segredos.' >&2; exit 1
    fi
    if [[ ! -f "$PROJECT/.env" || ! -f "$PROJECT/app/.env" ]]; then
        echo 'Apenas um dos dois arquivos .env existe. Corrija manualmente antes de retomar.' >&2; exit 1
    fi
elif [[ "$RESUME" == true ]]; then
    echo 'Nenhuma instalação anterior para retomar.' >&2; exit 1
fi

if [[ -z "$URL" || -z "$FTP_INTERFACE" || -z "$FTP_DESTINATION" || ${#FTP_CIDRS[@]} -eq 0 ]]; then
    usage >&2; exit 2
fi
if [[ -n "$TLS_CERT" && -z "$TLS_KEY" || -z "$TLS_CERT" && -n "$TLS_KEY" ]]; then
    echo 'Informe certificado e chave TLS juntos.' >&2; exit 1
fi
if [[ -n "$GEOIP_COUNTRY_DB" && -z "$GEOIP_ASN_DB" || -z "$GEOIP_COUNTRY_DB" && -n "$GEOIP_ASN_DB" ]]; then
    echo 'Informe os dois arquivos GeoIP juntos: Country e ASN.' >&2; exit 1
fi
if [[ -n "$GEOIP_ARCHIVE" && -n "$GEOIP_COUNTRY_DB" ]]; then
    echo 'Use --geoip-archive ou os dois arquivos .mmdb, não ambos.' >&2; exit 1
fi
for geoip_file in "$GEOIP_COUNTRY_DB" "$GEOIP_ASN_DB"; do
    if [[ -n "$geoip_file" ]] && { [[ "$geoip_file" != /* || ! -f "$geoip_file" || -L "$geoip_file" || ! -r "$geoip_file" ]] || (( $(stat -c %s "$geoip_file") > 512 * 1024 * 1024 )); }; then
        echo 'Arquivo GeoIP inválido: use um arquivo regular, legível, absoluto e com até 512 MB.' >&2; exit 1
    fi
done
if [[ -n "$GEOIP_ARCHIVE" ]]; then
    if [[ "$GEOIP_ARCHIVE" != /* || ! -f "$GEOIP_ARCHIVE" || -L "$GEOIP_ARCHIVE" || ! -r "$GEOIP_ARCHIVE" ]] ||
        (( $(stat -c %s "$GEOIP_ARCHIVE") > 512 * 1024 * 1024 )); then
        echo 'ZIP GeoIP inválido: use arquivo regular, legível, absoluto e com até 512 MB.' >&2; exit 1
    fi
    python3 - "$GEOIP_ARCHIVE" <<'PY'
import stat
import sys
import zipfile

expected = {'GeoLite2-Country.mmdb', 'GeoLite2-ASN.mmdb'}
try:
    with zipfile.ZipFile(sys.argv[1]) as archive:
        names = archive.namelist()
        if len(names) != 2 or set(names) != expected:
            raise ValueError('O ZIP deve conter somente as duas bases .mmdb na raiz.')
        for name in names:
            info = archive.getinfo(name)
            mode = info.external_attr >> 16
            if stat.S_ISLNK(mode) or info.file_size < 1024 or info.file_size > 512 * 1024 * 1024:
                raise ValueError('Entrada GeoIP inválida no ZIP.')
except (OSError, ValueError, zipfile.BadZipFile) as error:
    raise SystemExit(f'ZIP GeoIP inválido: {error}')
PY
fi

# Validate all operator supplied network values before changing the host.
URL_PARTS_OUTPUT=$(python3 - "$URL" "$FTP_INTERFACE" "$FTP_DESTINATION" "${FTP_CIDRS[@]}" <<'PY'
import ipaddress
import re
import sys
from urllib.parse import urlsplit

url, interface, destination, *cidrs = sys.argv[1:]
parsed = urlsplit(url)
try:
    port = parsed.port if parsed.port is not None else 443
except ValueError:
    raise SystemExit('Porta da URL inválida.')
if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.path or parsed.query or parsed.fragment:
    raise SystemExit('A URL precisa ser https://HOST[:PORT], sem caminho.')
host = parsed.hostname
try:
    parsed_ip = ipaddress.ip_address(host)
    if parsed_ip.version != 4:
        raise SystemExit('Use um endereço IPv4 ou nome DNS na URL.')
except ValueError:
    if not re.fullmatch(r'[A-Za-z0-9.-]{1,253}', host):
        raise SystemExit('Host inválido.')
if not re.fullmatch(r'[A-Za-z0-9_.-]{1,15}', interface):
    raise SystemExit('Interface FTP inválida.')
try:
    destination_ip = ipaddress.ip_address(destination)
except ValueError:
    raise SystemExit('Destino FTP inválido.')
if not isinstance(destination_ip, ipaddress.IPv4Address):
    raise SystemExit('Destino FTP deve ser IPv4.')
for cidr in cidrs:
    try:
        network = ipaddress.ip_network(cidr, strict=True)
    except ValueError:
        raise SystemExit('Origem FTP inválida: ' + cidr)
    if network.version != 4 or network.prefixlen < 8:
        raise SystemExit('Origem FTP deve ser IPv4/CIDR, com prefixo de /8 a /32.')
if len(cidrs) > 32:
    raise SystemExit('Máximo de 32 origens FTP.')
print(host)
print(port)
PY
)
mapfile -t URL_PARTS <<< "$URL_PARTS_OUTPUT"
if ((${#URL_PARTS[@]} != 2)); then echo 'Parâmetros de rede inválidos.' >&2; exit 1; fi
HOST=${URL_PARTS[0]}
HTTPS_PORT=${URL_PARTS[1]}
if ((HTTPS_PORT < 1 || HTTPS_PORT > 65535)); then echo 'Porta HTTPS inválida.' >&2; exit 1; fi
if [[ "$HOST" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    CERT_CHECK=(-checkip "$HOST")
else
    CERT_CHECK=(-checkhost "$HOST")
fi
if [[ -z "$TLS_CERT" ]]; then
    if [[ "${CERT_CHECK[0]}" != '-checkip' ]]; then
        echo 'Para domínio, informe --tls-cert e --tls-key.' >&2; exit 1
    fi
    TLS_CERT=/etc/backup-manager-v2/tls/ip-cert.pem
    TLS_KEY=/etc/backup-manager-v2/tls/ip-key.pem
    GENERATE_TLS=true
else
    GENERATE_TLS=false
fi
if [[ "$TLS_CERT" != /* || "$TLS_KEY" != /* || ! "$TLS_CERT" =~ ^/[A-Za-z0-9_./-]+$ || ! "$TLS_KEY" =~ ^/[A-Za-z0-9_./-]+$ ]]; then
    echo 'Caminhos TLS devem ser absolutos e usar caracteres simples.' >&2; exit 1
fi
if [[ "$GENERATE_TLS" == true ]]; then
    if { [[ -e "$TLS_CERT" ]] && [[ ! -e "$TLS_KEY" ]]; } || { [[ ! -e "$TLS_CERT" ]] && [[ -e "$TLS_KEY" ]]; }; then
        echo 'Certificado local incompleto; revise os arquivos antes de continuar.' >&2; exit 1
    fi
fi
validate_tls() {
    if [[ ! -r "$TLS_CERT" || ! -r "$TLS_KEY" ]] ||
        ! openssl x509 -in "$TLS_CERT" -noout "${CERT_CHECK[@]}" 2>/dev/null | grep -q 'does match certificate' ||
        ! openssl x509 -in "$TLS_CERT" -noout -checkend 0 >/dev/null 2>&1; then
        echo 'Certificado TLS ausente, expirado ou sem cobertura para o host.' >&2; exit 1
    fi
    if ! cmp -s <(openssl x509 -in "$TLS_CERT" -pubkey -noout) <(openssl pkey -in "$TLS_KEY" -pubout 2>/dev/null); then
        echo 'A chave TLS não corresponde ao certificado.' >&2; exit 1
    fi
}
if [[ "$GENERATE_TLS" != true || -e "$TLS_CERT" ]]; then
    validate_tls
fi
if [[ "$PREFLIGHT" == true ]]; then
    echo "Pré-verificação aprovada para $URL. Nenhuma alteração feita."
    if [[ "$GENERATE_TLS" == true ]]; then echo 'Na instalação será criado um certificado local para o IP.'; fi
    if [[ -n "$GEOIP_COUNTRY_DB" || -n "$GEOIP_ARCHIVE" ]]; then
        echo 'Na instalação, as bases GeoIP informadas serão copiadas para /usr/share/GeoIP.'
    elif [[ ! -f /usr/share/GeoIP/GeoLite2-Country.mmdb || ! -f /usr/share/GeoIP/GeoLite2-ASN.mmdb ]]; then
        echo 'Bases GeoIP ausentes: país e ASN ficarão não identificados até receber os arquivos.'
    fi
    exit 0
fi
cd "$PROJECT"

if [[ "$RESUME" != true && -n "$(git status --porcelain)" ]]; then
    echo 'Use um checkout limpo para a primeira instalação.' >&2; exit 1
fi

if [[ "$RESUME" == true ]]; then
    EXISTING_URL=$(sed -n 's/^APP_URL=//p' app/.env | tail -1)
    if [[ "$EXISTING_URL" != "$URL" ]]; then
        echo 'A URL informada difere da instalação existente.' >&2; exit 1
    fi
    if [[ "$GENERATE_TLS" == true && ! -e "$TLS_CERT" && -e /etc/nginx/sites-available/backup-manager-v2.conf ]]; then
        echo 'A instalação existente usa certificado próprio; informe --tls-cert e --tls-key ao retomar.' >&2; exit 1
    fi
fi

if [[ "$RESUME" != true ]]; then
    if [[ -e /etc/nginx/sites-available/backup-manager-v2.conf || -e /etc/backup-manager-v2/ftp-acl.env ]]; then
        echo 'Configuração de host já existe; revise antes de instalar.' >&2; exit 1
    fi
fi

install -d -m 0755 /usr/share/GeoIP
if [[ -n "$GEOIP_ARCHIVE" ]]; then
    GEOIP_TEMP=$(mktemp -d)
    trap 'rm -rf -- "${GEOIP_TEMP:?}"' EXIT
    python3 - "$GEOIP_ARCHIVE" "$GEOIP_TEMP" <<'PY'
import os
import shutil
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1]) as archive:
    for name in ('GeoLite2-Country.mmdb', 'GeoLite2-ASN.mmdb'):
        with archive.open(name) as source, open(os.path.join(sys.argv[2], name), 'wb') as target:
            shutil.copyfileobj(source, target)
PY
    install -m 0644 "$GEOIP_TEMP/GeoLite2-Country.mmdb" /usr/share/GeoIP/GeoLite2-Country.mmdb
    install -m 0644 "$GEOIP_TEMP/GeoLite2-ASN.mmdb" /usr/share/GeoIP/GeoLite2-ASN.mmdb
    rm -rf -- "$GEOIP_TEMP"
    trap - EXIT
elif [[ -n "$GEOIP_COUNTRY_DB" ]]; then
    if [[ "$GEOIP_COUNTRY_DB" != /usr/share/GeoIP/GeoLite2-Country.mmdb ]]; then
        install -m 0644 "$GEOIP_COUNTRY_DB" /usr/share/GeoIP/GeoLite2-Country.mmdb
    fi
    if [[ "$GEOIP_ASN_DB" != /usr/share/GeoIP/GeoLite2-ASN.mmdb ]]; then
        install -m 0644 "$GEOIP_ASN_DB" /usr/share/GeoIP/GeoLite2-ASN.mmdb
    fi
fi
if [[ -f /usr/share/GeoIP/GeoLite2-Country.mmdb && -f /usr/share/GeoIP/GeoLite2-ASN.mmdb ]]; then
    echo 'Bases GeoIP locais prontas: país e ASN.'
else
    echo 'Bases GeoIP ausentes; a Auditoria exibirá país e ASN como não identificados.'
fi

if [[ "$GENERATE_TLS" == true && ! -e "$TLS_CERT" ]]; then
    install -d -m 700 /etc/backup-manager-v2/tls
    openssl req -x509 -newkey rsa:3072 -noenc -sha256 -days 365 \
        -subj "/CN=$HOST" -addext "subjectAltName=IP:$HOST" \
        -addext 'basicConstraints=critical,CA:FALSE' \
        -addext 'keyUsage=critical,digitalSignature,keyEncipherment' \
        -addext 'extendedKeyUsage=serverAuth' \
        -keyout "$TLS_KEY" -out "$TLS_CERT" >/dev/null 2>&1
    chmod 600 "$TLS_KEY"
    chmod 644 "$TLS_CERT"
    validate_tls
fi

if [[ "$RESUME" != true ]]; then
    POSTGRES_SECRET=$(openssl rand -hex 32)
    REDIS_SECRET=$(openssl rand -hex 32)
    APP_SECRET="base64:$(openssl rand -base64 32)"
    cat > .env <<EOF
POSTGRES_DB=backup_manager
POSTGRES_USER=backup_manager
POSTGRES_PASSWORD=$POSTGRES_SECRET
REDIS_PASSWORD=$REDIS_SECRET
BACKUP_FTP_PASSIVE_ADDRESS=$FTP_DESTINATION
BACKUP_FTP_HOST=$FTP_DESTINATION
BACKUP_RETENTION_ENABLED=false
BACKUP_A10_ENABLED=false
EOF
    cat > app/.env <<EOF
APP_NAME="Backup Manager"
APP_ENV=production
APP_KEY=$APP_SECRET
APP_DEBUG=false
APP_URL=$URL
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=backup_manager
DB_USERNAME=backup_manager
DB_PASSWORD=$POSTGRES_SECRET
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=$REDIS_SECRET
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=30
LOG_LEVEL=warning
SESSION_LIFETIME=30
EOF
    chown 65534:65534 app/.env
    chmod 600 .env app/.env
fi

install -d -m 700 /etc/backup-manager-v2
if [[ ! -f /etc/backup-manager-v2/ftp-acl.env ]]; then
    printf 'BACKUP_FTP_ACL_INTERFACE=%s\nBACKUP_FTP_ACL_DESTINATION=%s\n' "$FTP_INTERFACE" "$FTP_DESTINATION" > /etc/backup-manager-v2/ftp-acl.env
fi
if [[ ! -f /etc/backup-manager-v2/ftp-acl.json ]]; then
    python3 - /etc/backup-manager-v2/ftp-acl.json "${FTP_CIDRS[@]}" <<'PY'
import json
import sys
from pathlib import Path

Path(sys.argv[1]).write_text(json.dumps({'revision': 0, 'cidrs': sys.argv[2:]}))
PY
fi
chmod 600 /etc/backup-manager-v2/ftp-acl.env /etc/backup-manager-v2/ftp-acl.json

docker compose config --quiet
docker compose build app
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$PROJECT/app:/var/www/html" -w /var/www/html \
    --entrypoint composer backup-manager-v2-app install --no-dev --no-interaction --prefer-dist --optimize-autoloader
chown -R 65534:33 app/storage app/bootstrap/cache
install -m 0644 docker/php/logrotate.conf /etc/logrotate.d/backup-manager-php-fpm
docker compose up -d --build postgres redis app engine scheduler ftp-admin nginx
docker compose exec -T app php artisan migrate --force --no-interaction
docker compose exec -u 65534:33 -T app php artisan view:cache --no-interaction
docker compose exec -T app php artisan system:recovery-check

install -m 0644 scripts/backup-manager-ftp-acl.service scripts/backup-manager-ftp-acl.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now backup-manager-ftp-acl.timer
systemctl start backup-manager-ftp-acl.service
docker compose up -d --build ftp

install -m 0644 docker/nginx/host-audit-log.conf /etc/nginx/conf.d/backup-manager-audit-log.conf
if [[ ! -e /etc/nginx/sites-available/backup-manager-v2.conf ]]; then
    cat > /etc/nginx/sites-available/backup-manager-v2.conf <<EOF
server {
    listen 80;
    server_name $HOST;
    return 301 $URL\$request_uri;
}
server {
    listen $HTTPS_PORT ssl;
    server_name $HOST;
    access_log /var/log/nginx/backup-manager-v2-$HTTPS_PORT-access.log backup_manager_audit;
    ssl_certificate $TLS_CERT;
    ssl_certificate_key $TLS_KEY;
    ssl_session_tickets off;
    ssl_protocols TLSv1.2 TLSv1.3;
    server_tokens off;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Content-Security-Policy "frame-ancestors 'self'" always;
    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $HOST:$HTTPS_PORT;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port $HTTPS_PORT;
        proxy_set_header X-Forwarded-Host "";
        proxy_set_header Forwarded "";
        proxy_connect_timeout 10s;
        proxy_send_timeout 180s;
        proxy_read_timeout 180s;
    }
}
EOF
fi
if [[ ! -e /etc/nginx/sites-enabled/backup-manager-v2.conf ]]; then
    ln -s /etc/nginx/sites-available/backup-manager-v2.conf /etc/nginx/sites-enabled/backup-manager-v2.conf
fi
nginx -t
systemctl enable --now nginx
systemctl reload nginx

USER_COUNT=$(docker compose exec -T app php artisan tinker --execute 'echo \App\Models\User::count();')
if [[ ! "$USER_COUNT" =~ ^[0-9]+$ ]]; then echo 'Não foi possível verificar os usuários.' >&2; exit 1; fi
if ((USER_COUNT == 0)); then
    docker compose exec -T app php artisan app:create-admin --bootstrap
    BOOTSTRAP_CREATED=true
else
    BOOTSTRAP_CREATED=false
fi

docker compose exec -T app php artisan engine:health
HTTP_STATUS=$(docker compose exec -T nginx curl --silent --output /dev/null --write-out '%{http_code}' \
    --header "Host: $HOST:$HTTPS_PORT" http://127.0.0.1/login)
if [[ "$HTTP_STATUS" != 200 ]]; then
    echo "A página de login não respondeu corretamente no proxy interno (HTTP $HTTP_STATUS)." >&2
    exit 1
fi
echo "Instalação pronta em $URL."
if [[ "$BOOTSTRAP_CREATED" == true ]]; then
    echo 'Primeiro acesso: backup_manager / backup_manager. Cadastre seu e-mail e nova senha no navegador.'
else
    echo 'Acesse com as credenciais já configuradas.'
fi
