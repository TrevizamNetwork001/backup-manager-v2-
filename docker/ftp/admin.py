"""Local fixed-command reconciler for virtual PureDB accounts."""

import json
import base64
import deletion
import os
import re
import uuid
import subprocess
import tempfile
import time
from pathlib import Path


ARTISAN = ['php', '/var/www/html/artisan']
PASSWD = '/etc/backup-ftp/pureftpd.passwd'
PUREDB = '/etc/backup-ftp/pureftpd.pdb'
ROOT = Path('/data/ftp')
USER = re.compile(r'[a-z][a-z0-9_-]{2,31}\Z')


def account_home(row):
    username = row['username']
    if not isinstance(username, str) or not USER.fullmatch(username):
        raise RuntimeError('account_invalid')
    layout = row.get('home_layout', 'legacy')
    if layout == 'account':
        account_uuid = str(uuid.UUID(row['account_uuid']))
        if account_uuid != row['account_uuid']:
            raise RuntimeError('account_invalid')
        return ROOT / 'accounts' / account_uuid / 'incoming'
    if layout != 'legacy':
        raise RuntimeError('account_invalid')
    device_id = int(row['device_id'])
    if device_id < 1:
        raise RuntimeError('account_invalid')
    return ROOT / str(device_id) / 'incoming'


def artisan(*args, secret=False):
    if not secret:
        result = subprocess.run(ARTISAN + list(args), capture_output=True, timeout=30, check=True)
        return result.stdout
    read_fd, write_fd = os.pipe()
    try:
        env = os.environ.copy()
        env['ENGINE_SECRET_FD'] = str(write_fd)
        subprocess.run(ARTISAN + list(args), env=env, pass_fds=(write_fd,),
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       timeout=30, check=True)
        os.close(write_fd)
        write_fd = -1
        return os.read(read_fd, 4096)
    finally:
        os.close(read_fd)
        if write_fd >= 0:
            os.close(write_fd)


def pure(*args, password=None):
    result = subprocess.run(['/usr/bin/pure-pw', *args], input=password,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            timeout=30, check=False)
    if result.returncode:
        raise RuntimeError('pure_pw_failed')


def sync_once():
    root = ROOT
    if root.is_symlink() or not root.is_dir():
        raise RuntimeError('ftp_root_invalid')
    quarantine = root / 'quarantine'
    if quarantine.is_symlink():
        raise RuntimeError('ftp_root_invalid')
    quarantine.mkdir(mode=0o700, exist_ok=True)
    os.chown(quarantine, 65534, 65534)
    os.chmod(quarantine, 0o700)
    processing = root / 'processing'
    if processing.is_symlink():
        raise RuntimeError('ftp_root_invalid')
    processing.mkdir(mode=0o700, exist_ok=True)
    os.chown(processing, 65534, 65534)
    os.chmod(processing, 0o700)
    rows = json.loads(artisan('ftp:accounts'))
    revoked = [row for row in rows if row.get('deletion_mode') and not row['is_active']]
    requested = set(json.loads(artisan('ftp:inspection-requests')))
    for row in rows:
        if row['id'] not in requested:
            continue
        report = deletion.inspect(row, root)
        encoded = base64.urlsafe_b64encode(json.dumps(report, separators=(',', ':')).encode()).decode()
        artisan('ftp:physical-report', str(int(row['id'])), row['updated_at'], encoded)
    provisioned = []
    fd, temporary_passwd = tempfile.mkstemp(prefix='.pureftpd-passwd-', dir=Path(PASSWD).parent)
    os.close(fd)
    temporary_db = None
    try:
        for row in rows:
            username = row['username']
            home = account_home(row)
            device_root = home.parent
            if any(part.is_symlink() for part in (home, device_root, device_root.parent)):
                raise RuntimeError('home_invalid')
            if row.get('home_layout') == 'account':
                accounts_root = root / 'accounts'
                accounts_root.mkdir(mode=0o755, exist_ok=True)
                os.chown(accounts_root, 0, 0)
                os.chmod(accounts_root, 0o755)
            device_root.mkdir(mode=0o755, exist_ok=True)
            if device_root.is_symlink():
                raise RuntimeError('home_invalid')
            os.chown(device_root, 0, 0)
            os.chmod(device_root, 0o755)
            home.mkdir(mode=0o700, exist_ok=True)
            os.chown(home, 65534, 65534)
            os.chmod(home, 0o700)
            if not row['is_active']:
                continue
            password = artisan('ftp:secret', str(int(row['id'])), secret=True)
            if not 12 <= len(password) <= 128 or not re.fullmatch(rb'[\x21-\x7e]+', password):
                raise RuntimeError('secret_invalid')
            pure('useradd', username, '-u', '65534', '-g', '65534', '-d', str(home),
                 '-f', temporary_passwd, password=password + b'\n' + password + b'\n')
            provisioned.append((str(int(row['id'])), row['updated_at']))
        fd, temporary_db = tempfile.mkstemp(prefix='.pureftpd-db-', dir=Path(PUREDB).parent)
        os.close(fd)
        pure('mkdb', temporary_db, '-f', temporary_passwd)
        os.chmod(temporary_db, 0o600)
        os.chmod(temporary_passwd, 0o600)
        os.replace(temporary_db, PUREDB)
        temporary_db = None
        os.replace(temporary_passwd, PASSWD)
        temporary_passwd = None
    finally:
        if temporary_db and os.path.exists(temporary_db):
            os.unlink(temporary_db)
        if temporary_passwd and os.path.exists(temporary_passwd):
            os.unlink(temporary_passwd)
    for account_id, version in provisioned:
        artisan('ftp:provisioned', account_id, version)
    for row in revoked:
        account_id = str(int(row['id']))
        try:
            # The published passwd file is the source used to build this PureDB.
            with open(PASSWD, encoding='utf-8') as passwd_file:
                if any(line.split(':', 1)[0] == row['username'] for line in passwd_file):
                    raise deletion.PhysicalError('puredb_revoke_failed')
            artisan('ftp:puredb-revoked', account_id)
            result = deletion.cleanup(row, root, row['deletion_mode'])
            encoded = base64.urlsafe_b64encode(json.dumps(result, separators=(',', ':')).encode()).decode()
            artisan('ftp:finalize-deletions', account_id, encoded)
        except deletion.PhysicalError as error:
            artisan('ftp:physical-failed', account_id, error.code)


if __name__ == '__main__':
    while True:
        try:
            sync_once()
        except Exception:
            try:
                artisan('ftp:sync-failed')
            except Exception:
                pass
            print('ftp_account_sync_failed', flush=True)
        time.sleep(15)
