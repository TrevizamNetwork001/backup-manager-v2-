"""Start Pure-FTPd with a fixed local profile and optional passive address."""

import ipaddress
import os
import resource
from pathlib import Path


PUREDB = Path('/etc/backup-ftp/pureftpd.pdb')


def file_limit():
    return max(1, min(64 * 1024 * 1024, int(os.environ.get('BACKUP_FTP_MAX_BYTES', '8388608'))))


def passive_address(environment=None):
    environment = os.environ if environment is None else environment
    return environment.get('BACKUP_FTP_PASSIVE_ADDRESS') or environment.get('BACKUP_FTP_PUBLIC_IP', '')


def arguments(passive_ip=''):
    args = ['/usr/sbin/pure-ftpd', '-l', 'puredb:/etc/backup-ftp/pureftpd.pdb',
            '-E', '-A', '-R', '-K', '-G', '-r', '-u', '1', '-p', '30000:30009',
            '-U', '177:077', '-c', '20', '-C', '5']
    if passive_ip:
        args += ['-P', str(ipaddress.ip_address(passive_ip))]
    return args


if __name__ == '__main__':
    if not PUREDB.is_file():
        raise SystemExit('puredb_missing')
    limit = file_limit()
    resource.setrlimit(resource.RLIMIT_FSIZE, (limit, limit))
    os.execv('/usr/sbin/pure-ftpd', arguments(passive_address()))
