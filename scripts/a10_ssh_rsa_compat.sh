#!/usr/bin/env bash
set -euo pipefail

# Enable the legacy host-key signature required by the ACOS 4.1.4-GR1-P14
# SFTP client. SSH remains filtered by UFW; do not add weaker KEX/ciphers.
config=/etc/ssh/sshd_config.d/backup-manager-a10-ssh-rsa.conf
expected='HostKeyAlgorithms +ssh-rsa'

if [[ ${1:-} != --apply ]]; then
    echo 'Usage: sudo bash scripts/a10_ssh_rsa_compat.sh --apply' >&2
    exit 2
fi

if [[ -e $config ]]; then
    if [[ $(cat "$config") != "$expected" ]]; then
        echo "Refusing to replace $config" >&2
        exit 1
    fi
else
    printf '%s\n' "$expected" > "$config"
    chmod 0644 "$config"
fi

if ! sshd -t; then
    rm -f "$config"
    echo 'Invalid sshd configuration; compatibility file removed' >&2
    exit 1
fi

if ! systemctl reload ssh; then
    rm -f "$config"
    systemctl reload ssh || true
    echo 'SSH reload failed; compatibility file removed' >&2
    exit 1
fi

if ! sshd -T -C user=a10backup,addr=172.22.254.234,host=backup-manager |
    grep -Eq '^hostkeyalgorithms .*[, ]ssh-rsa(,|$)'; then
    echo 'ssh-rsa not active in effective configuration' >&2
    exit 1
fi

echo 'A10 ssh-rsa host-key compatibility active'
