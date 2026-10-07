"""Start Pure-FTPd with a fixed local profile and optional passive address."""

import ipaddress
import os
import re
import resource
import socket
from pathlib import Path


PUREDB = Path('/etc/backup-ftp/pureftpd.pdb')
LOG_SOCKET = '/dev/log'


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


def format_log_line(raw):
    """One printable line from a syslog datagram (priority prefix removed, bounded)."""
    text = raw.decode('utf-8', 'replace').replace('\x00', '').strip()

    return re.sub(r'^<\d+>', '', text)[:500]


def start_log_sink(path=LOG_SOCKET):
    """Pure-FTPd reports logins (including refused ones) through syslog, and a
    container has no syslog daemon, so those records were silently lost. Forward
    them to stdout (docker logs). Never prevents the server from starting."""
    try:
        sink = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
        try:
            os.unlink(path)
        except FileNotFoundError:
            pass
        sink.bind(path)
        os.chmod(path, 0o666)
        if os.fork() == 0:
            while True:
                print(format_log_line(sink.recv(4096)), flush=True)
        sink.close()
    except OSError as error:
        print(f'ftp_log_sink_unavailable: {error.__class__.__name__}', flush=True)


if __name__ == '__main__':
    if not PUREDB.is_file():
        raise SystemExit('puredb_missing')
    limit = file_limit()
    resource.setrlimit(resource.RLIMIT_FSIZE, (limit, limit))
    start_log_sink()
    os.execv('/usr/sbin/pure-ftpd', arguments(passive_address()))
