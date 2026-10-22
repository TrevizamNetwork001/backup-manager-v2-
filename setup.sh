#!/usr/bin/env bash
# Guided first installation. Advanced options remain available in install.sh.
set -euo pipefail

PROJECT=/opt/backup-manager-v2
PREFLIGHT=false
if [[ ${1:-} == --help || ${1:-} == -h ]]; then
    echo 'Uso: sudo bash setup.sh [--preflight]'
    echo 'Pergunta apenas o endereço do painel e os dados de rede do FTP.'
    exit 0
elif [[ ${1:-} == --preflight && $# == 1 ]]; then
    PREFLIGHT=true
elif (($#)); then
    echo 'Uso: sudo bash setup.sh [--preflight]' >&2
    exit 2
fi
if ((EUID != 0)); then
    echo 'Execute como root: sudo bash setup.sh' >&2
    exit 1
fi
if [[ ! -f "$PROJECT/install.sh" || "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" != "$PROJECT" ]]; then
    echo "Coloque o projeto em $PROJECT antes de executar setup.sh." >&2
    exit 1
fi

route=''
if command -v ip >/dev/null 2>&1; then
    route=$(ip -4 route get 1.1.1.1 2>/dev/null || true)
fi
detected_interface=$(awk '{for (i = 1; i <= NF; i++) if ($i == "dev") {print $(i + 1); exit}}' <<< "$route")
detected_ip=$(awk '{for (i = 1; i <= NF; i++) if ($i == "src") {print $(i + 1); exit}}' <<< "$route")

RESUME=false
if [[ -f "$PROJECT/.env" || -f "$PROJECT/app/.env" ]]; then
    RESUME=true
    if [[ ! -f "$PROJECT/.env" || ! -f "$PROJECT/app/.env" ]]; then
        echo 'Instalação parcial: revise os dois arquivos .env antes de retomar.' >&2
        exit 1
    fi
    url=$(sed -n 's/^APP_URL=//p' "$PROJECT/app/.env" | tail -1)
    ftp_interface=$detected_interface
    ftp_destination=$detected_ip
    if [[ -f /etc/backup-manager-v2/ftp-acl.env ]]; then
        ftp_interface=$(sed -n 's/^BACKUP_FTP_ACL_INTERFACE=//p' /etc/backup-manager-v2/ftp-acl.env | tail -1)
        ftp_destination=$(sed -n 's/^BACKUP_FTP_ACL_DESTINATION=//p' /etc/backup-manager-v2/ftp-acl.env | tail -1)
    fi
    ftp_passive_address=$(sed -n 's/^BACKUP_FTP_PASSIVE_ADDRESS=//p' "$PROJECT/.env" | tail -1)
else
    if [[ ! -t 0 ]]; then
        echo 'Execute setup.sh em um terminal para informar os dados de rede.' >&2
        exit 1
    fi
    read -r -p "URL HTTPS do painel [https://${detected_ip:-IP_DA_VM}]: " url
    url=${url:-https://${detected_ip:-}}
    read -r -p "Interface de rede do FTP [${detected_interface:-informar}]: " ftp_interface
    ftp_interface=${ftp_interface:-$detected_interface}
    read -r -p "IPv4 recebido pela VM após NAT [${detected_ip:-informar}]: " ftp_destination
    ftp_destination=${ftp_destination:-$detected_ip}
    read -r -p "IPv4 anunciado aos equipamentos no FTP [${ftp_destination:-informar}]: " ftp_passive_address
    ftp_passive_address=${ftp_passive_address:-$ftp_destination}
fi

arguments=(--url "$url" --ftp-interface "$ftp_interface" --ftp-destination "$ftp_destination"
    --ftp-passive-address "$ftp_passive_address")
if [[ "$RESUME" == true ]]; then
    arguments+=(--resume)
    if [[ ! -f /etc/backup-manager-v2/ftp-deferred ]]; then
        mapfile -t existing_cidrs < <(python3 -c 'import json; print("\n".join(json.load(open("/etc/backup-manager-v2/ftp-acl.json"))["cidrs"]))')
        for cidr in "${existing_cidrs[@]}"; do
            arguments+=(--ftp-allow-cidr "$cidr")
        done
    fi
else
    arguments+=(--defer-ftp)
fi
if [[ "$PREFLIGHT" == true ]]; then
    arguments+=(--preflight)
fi

exec bash "$PROJECT/install.sh" "${arguments[@]}"
