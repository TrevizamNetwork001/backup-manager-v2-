"""Pure-FTPd/PureDB integration on loopback inside a networkless container."""

import ftplib
import io
import os
import resource
import socket
import subprocess
import tempfile
import time
from pathlib import Path

PORT = int(os.environ.get('PUREDB_TEST_PORT', '2121'))
PASSIVE_PORTS = os.environ.get('PUREDB_TEST_PASSIVE_PORTS', '30000:30009')
first, last = (int(value) for value in PASSIVE_PORTS.split(':'))
if not (1024 <= PORT <= 65535 and 1024 <= first <= last <= 65535) or first <= PORT <= last:
    raise ValueError('Invalid isolated test ports')


def call(*args, secret=None):
    result = subprocess.run(['/usr/bin/pure-pw', *args], input=secret,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            timeout=15, check=False)
    if result.returncode:
        raise AssertionError('pure_pw_failed')


def login(username, secret):
    client = ftplib.FTP()
    client.connect('127.0.0.1', PORT, timeout=20)
    client.login(username, secret.decode())
    return client


with tempfile.TemporaryDirectory(prefix='bm-puredb-test-') as name:
    root = Path(name)
    os.chmod(root, 0o755)
    passwd = str(root / 'pureftpd.passwd')
    db = str(root / 'pureftpd.pdb')
    homes = {}
    secrets = {}
    for device_id in (12, 13):
        parent = root / str(device_id)
        home = parent / 'incoming'
        home.mkdir(parents=True)
        os.chmod(parent, 0o755)
        os.chown(home, 65534, 65534)
        os.chmod(home, 0o700)
        homes[device_id] = home
        secret = os.urandom(24).hex().encode()
        secrets[device_id] = secret
        call('useradd', f'bmdev{device_id}', '-u', '65534', '-g', '65534', '-d', str(home),
             '-f', passwd, secret=secret + b'\n' + secret + b'\n')
    call('mkdb', db, '-f', passwd)
    assert Path(db).is_file() and Path(db).stat().st_size > 0
    server = subprocess.Popen(['/usr/sbin/pure-ftpd', '-l', f'puredb:{db}', '-E', '-A', '-R', '-K', '-G', '-r',
                               '-u', '1', '-S', f'127.0.0.1,{PORT}', '-p', PASSIVE_PORTS],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                              preexec_fn=lambda: resource.setrlimit(resource.RLIMIT_FSIZE, (1048576, 1048576)))
    try:
        for _ in range(50):
            if server.poll() is not None:
                raise AssertionError('pure_ftpd_exited')
            try:
                with socket.create_connection(('127.0.0.1', PORT), timeout=1):
                    break
            except OSError:
                time.sleep(0.1)
        else:
            raise AssertionError('pure_ftpd_not_ready')
        with login('bmdev12', secrets[12]) as client:
            assert client.pwd() == '/'
            client.storbinary('STOR bm-exec-1.cfg', io.BytesIO(b'config'))
            client.storbinary('STOR bm-exec-1.cfg', io.BytesIO(b'config-2'))
            assert 'bm-exec-1.cfg' in client.nlst()
            try:
                client.cwd('/13/incoming')
            except ftplib.error_perm:
                pass
            else:
                raise AssertionError('cross_device_navigation')
        assert (homes[12] / 'bm-exec-1.cfg').read_bytes() == b'config'
        assert (homes[12] / 'bm-exec-1.cfg.1').read_bytes() == b'config-2'
        assert (homes[12] / 'bm-exec-1.cfg').stat().st_uid == 65534
        assert not (homes[13] / 'bm-exec-1.cfg').exists()
        client = login('bmdev12', secrets[12])
        try:
            try:
                client.storbinary('STOR oversized.cfg', io.BytesIO(b'x' * 1048577))
            except ftplib.all_errors:
                pass
        finally:
            client.close()
        oversized = homes[12] / 'oversized.cfg'
        assert not oversized.exists() or oversized.stat().st_size <= 1048576
        new_secret = os.urandom(24).hex().encode()
        call('passwd', 'bmdev12', '-f', passwd, secret=new_secret + b'\n' + new_secret + b'\n')
        replacement = str(root / 'pureftpd.pdb.new')
        call('mkdb', replacement, '-f', passwd)
        os.replace(replacement, db)
        try:
            login('bmdev12', secrets[12])
        except ftplib.error_perm:
            pass
        else:
            raise AssertionError('old_password_accepted')
        with login('bmdev12', new_secret):
            pass
        call('userdel', 'bmdev12', '-f', passwd)
        call('mkdb', replacement, '-f', passwd)
        os.replace(replacement, db)
        try:
            login('bmdev12', new_secret)
        except ftplib.error_perm:
            pass
        else:
            raise AssertionError('disabled_account_accepted')
        with login('bmdev13', secrets[13]):
            pass
    finally:
        server.terminate()
        server.wait(timeout=5)
print('synthetic_puredb_login_chroot_rotation_disable_ok')
