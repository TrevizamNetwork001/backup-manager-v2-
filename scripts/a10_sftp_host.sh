#!/usr/bin/env bash
set -euo pipefail

# Prepare or install the restricted SFTP account for A10 system backups.
# Run --check first. --apply changes the host; it never enables A10 jobs.
mode=${1:-}
if [[ "$mode" != --check && "$mode" != --apply ]]; then
    printf 'Usage: %s --check|--apply\n' "$0" >&2
    exit 2
fi

repo=/opt/backup-manager-v2
source_root=$repo/storage/a10-sftp
source_incoming=$source_root/incoming
chroot=/srv/backup-manager-a10
target_incoming=$chroot/incoming
account=a10backup
group=a10sftp
gid=25001
config=/etc/ssh/sshd_config

if [[ -e "$source_root" && -L "$source_root" ]] ||
   [[ -e "$source_incoming" && -L "$source_incoming" ]]; then
    echo 'A10 SFTP path must not be a symlink' >&2
    exit 1
fi
if getent group "$gid" >/dev/null && [[ $(getent group "$gid" | cut -d: -f1) != "$group" ]]; then
    echo 'A10 SFTP GID is already used' >&2
    exit 1
fi
if getent passwd "$account" >/dev/null && [[ $(id -gn "$account") != "$group" ]]; then
    echo 'A10 SFTP account has an unexpected primary group' >&2
    exit 1
fi

config_test=$(mktemp)
admin_before=$(mktemp)
admin_after=$(mktemp)
trap 'rm -f "$config_test" "$admin_before" "$admin_after"' EXIT
cp "$config" "$config_test"
sshd -T -f "$config" -C user=root,host=localhost,addr=127.0.0.1 > "$admin_before"
if ! grep -q '^# BEGIN BACKUP MANAGER A10 SFTP$' "$config_test"; then
    cat >> "$config_test" <<'SSH_CONFIG'

# BEGIN BACKUP MANAGER A10 SFTP
Match User a10backup
    ChrootDirectory /srv/backup-manager-a10
    ForceCommand internal-sftp -d /incoming -u 0007
    PasswordAuthentication yes
    PubkeyAuthentication no
    KbdInteractiveAuthentication no
    PermitTTY no
    AllowTcpForwarding no
    AllowAgentForwarding no
    X11Forwarding no
    PermitTunnel no
    PermitUserRC no
    MaxSessions 1
# END BACKUP MANAGER A10 SFTP
SSH_CONFIG
fi
sshd -t -f "$config_test"
sshd -T -f "$config_test" -C user=root,host=localhost,addr=127.0.0.1 > "$admin_after"
if ! cmp -s "$admin_before" "$admin_after"; then
    echo 'A10 SFTP block changes the effective administrative SSH configuration' >&2
    exit 1
fi
restricted=$(sshd -T -f "$config_test" -C user="$account",host=localhost,addr=127.0.0.1)
for expected in \
    'chrootdirectory /srv/backup-manager-a10' \
    'forcecommand internal-sftp -d /incoming -u 0007' \
    'passwordauthentication yes' \
    'pubkeyauthentication no' \
    'permittty no' \
    'allowtcpforwarding no'; do
    if ! grep -Fxq "$expected" <<< "$restricted"; then
        printf 'Missing SFTP restriction: %s\n' "$expected" >&2
        exit 1
    fi
done

if [[ "$mode" == --check ]]; then
    echo 'A10 SFTP host configuration validated; no host changes applied.'
    exit 0
fi

if [[ $EUID -ne 0 ]]; then
    echo 'Apply requires root' >&2
    exit 1
fi
if ! getent group "$group" >/dev/null; then
    groupadd --gid "$gid" "$group"
fi
if ! getent passwd "$account" >/dev/null; then
    useradd --system --no-create-home --gid "$group" --home-dir /incoming \
        --shell /usr/sbin/nologin "$account"
fi

install -d -m 0700 -o 65534 -g 65534 "$source_root"
install -d -m 2770 -o "$account" -g "$group" "$source_incoming"
secret=$source_root/transfer.password
if [[ ! -e "$secret" ]]; then
    umask 077
    openssl rand -hex 32 > "$secret"
fi
if [[ -L "$secret" || ! -f "$secret" ]]; then
    echo 'A10 SFTP secret is not a regular file' >&2
    exit 1
fi
chown 65534:65534 "$secret"
chmod 0600 "$secret"
python3 - "$secret" "$account" <<'PY'
import pathlib
import subprocess
import sys

secret = pathlib.Path(sys.argv[1]).read_text(encoding='ascii').strip()
if len(secret) < 24 or len(secret) > 128 or not secret.isascii() or not secret.isprintable():
    raise SystemExit('invalid A10 SFTP secret')
subprocess.run(['chpasswd'], input=f'{sys.argv[2]}:{secret}\n', text=True, check=True,
               stdout=subprocess.DEVNULL)
PY

install -d -m 0755 -o root -g root "$chroot"
install -d -m 0755 -o root -g root "$target_incoming"
if ! mountpoint -q "$target_incoming"; then
    mount --bind "$source_incoming" "$target_incoming"
fi
if [[ $(stat -c '%d:%i' "$target_incoming") != $(stat -c '%d:%i' "$source_incoming") ]]; then
    echo 'A10 SFTP bind mount does not point to the expected inbox' >&2
    exit 1
fi
mount -o remount,bind,nosuid,nodev,noexec "$target_incoming"
fstab_line="$source_incoming $target_incoming none bind,nosuid,nodev,noexec 0 0"
if ! grep -Fxq "$fstab_line" /etc/fstab; then
    if grep -Eq "^[^#]+[[:space:]]+$target_incoming[[:space:]]" /etc/fstab; then
        echo 'Another mount is configured for the A10 SFTP inbox' >&2
        exit 1
    fi
    fstab_backup="/etc/fstab.a10-backup-$(date -u +%Y%m%d%H%M%S)"
    cp -p /etc/fstab "$fstab_backup"
    printf '%s\n' "$fstab_line" >> /etc/fstab
    if ! findmnt --verify --tab-file /etc/fstab >/dev/null; then
        cp -p "$fstab_backup" /etc/fstab
        echo 'Mount configuration rejected and restored' >&2
        exit 1
    fi
    systemctl daemon-reload
fi

if ! cmp -s "$config" "$config_test"; then
    backup=$config.a10-backup-$(date -u +%Y%m%d%H%M%S)
    cp -p "$config" "$backup"
    cp "$config_test" "$config"
    chmod 0644 "$config"
    if ! sshd -t -f "$config"; then
        cp -p "$backup" "$config"
        echo 'SSH configuration rejected and restored' >&2
        exit 1
    fi
    if ! systemctl reload ssh; then
        cp -p "$backup" "$config"
        systemctl reload ssh || true
        echo 'SSH reload failed and configuration was restored' >&2
        exit 1
    fi
fi
echo 'A10 SFTP host account ready. A10 integration remains disabled.'
