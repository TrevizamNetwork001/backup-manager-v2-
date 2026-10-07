"""Start Pure-FTPd with a fixed local profile and optional passive address."""

import ipaddress
import os
import re
import resource
import socket
import time
from pathlib import Path


PUREDB = Path('/etc/backup-ftp/pureftpd.pdb')
LOG_SOCKET = '/dev/log'
PASSWD = '/etc/backup-ftp/pureftpd.passwd'
EVENT_DIR = '/var/log/backup-ftp'
EVENT_FILE = 'auth-events.log'
EVENT_ROTATE_BYTES = 512 * 1024

_FAILED = re.compile(r'\[WARNING\] Authentication failed for user \[([^\]\s]{1,64})\]')
_LOGGED_IN = re.compile(r'\[INFO\] (\S{1,64}) is now logged in')
_PEER = re.compile(r'\([^@)]*@([0-9A-Fa-f:.]{1,45})\)')
_known_cache = {'mtime': None, 'users': frozenset()}


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


def known_users(passwd=PASSWD):
    """Usernames of the provisioned virtual accounts (cached by file mtime)."""
    try:
        mtime = os.stat(passwd).st_mtime
        if _known_cache['mtime'] != mtime:
            with open(passwd, encoding='utf-8') as handle:
                _known_cache['users'] = frozenset(line.split(':', 1)[0] for line in handle if line.strip())
            _known_cache['mtime'] = mtime
    except OSError:
        return frozenset()

    return _known_cache['users']


def parse_auth_event(line, now, users):
    """`epoch<TAB>F|S<TAB>user<TAB>ip` for a refused/successful login of a known
    account; None for anything else. Unknown usernames (internet noise) are
    dropped, and the user pattern excludes whitespace so lines cannot be forged."""
    peer = _PEER.search(line)
    ip = peer.group(1) if peer else '-'
    failed = _FAILED.search(line)
    if failed and failed.group(1) in users:
        return f'{int(now)}\tF\t{failed.group(1)}\t{ip}'
    ok = _LOGGED_IN.search(line)
    if ok and ok.group(1) in users:
        return f'{int(now)}\tS\t{ok.group(1)}\t{ip}'

    return None


def record_auth_event(line, directory=EVENT_DIR, now=None, users=None):
    """Append an auth event to the file the panel reads (read-only volume)."""
    event = parse_auth_event(line, time.time() if now is None else now,
        known_users() if users is None else users)
    if event is None:
        return
    try:
        path = Path(directory) / EVENT_FILE
        if path.exists() and path.stat().st_size > EVENT_ROTATE_BYTES:
            os.replace(path, path.with_name(EVENT_FILE + '.1'))
        with open(path, 'a', encoding='utf-8') as handle:
            handle.write(event + '\n')
    except OSError:
        pass


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
        try:
            os.makedirs(EVENT_DIR, mode=0o755, exist_ok=True)
        except OSError:
            pass
        if os.fork() == 0:
            while True:
                line = format_log_line(sink.recv(4096))
                print(line, flush=True)
                record_auth_event(line)
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
