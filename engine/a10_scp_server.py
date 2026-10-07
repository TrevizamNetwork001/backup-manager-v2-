"""Isolated SSH receiver for one legacy SCP upload per active A10 execution."""

import base64
import hashlib
import hmac
import json
import logging
import os
import re
import socket
import subprocess
import threading
from pathlib import Path

import paramiko

from a10_scp import ScpRejected, receive_archive, requested_filename


USERNAME = re.compile(r'bmexec([1-9][0-9]{0,18})\Z')


def password_for(key, execution_id):
    if len(key) < 32 or execution_id < 1:
        raise ValueError('invalid SCP key or execution')
    digest = hmac.new(key, f'a10-scp-exec:{execution_id}'.encode(), hashlib.sha256).digest()
    return base64.urlsafe_b64encode(digest[:24]).decode('ascii')


def expected_from_laravel(execution_id):
    result = subprocess.run(['php', '/var/www/html/artisan', 'a10:expected', str(execution_id)],
                            capture_output=True, timeout=15, check=False)
    if result.returncode:
        return None
    try:
        answer = json.loads(result.stdout)
    except (ValueError, UnicodeDecodeError):
        return None
    return answer.get('filename') if isinstance(answer, dict) else None


class A10ScpSession(paramiko.ServerInterface):
    def __init__(self, key, expected):
        self.key = key
        self.expected = expected
        self.filename = None
        self.command_ready = threading.Event()

    def get_allowed_auths(self, username):
        return 'password'

    def check_auth_password(self, username, password):
        match = USERNAME.fullmatch(username or '')
        if not match:
            return paramiko.AUTH_FAILED
        execution_id = int(match.group(1))
        if not hmac.compare_digest(password or '', password_for(self.key, execution_id)):
            return paramiko.AUTH_FAILED
        filename = self.expected(execution_id)
        if not filename:
            return paramiko.AUTH_FAILED
        self.filename = filename
        return paramiko.AUTH_SUCCESSFUL

    def check_channel_request(self, kind, chanid):
        return paramiko.OPEN_SUCCEEDED if kind == 'session' and self.filename else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED

    def check_channel_exec_request(self, channel, command):
        try:
            name = requested_filename(command.decode('ascii'))
        except (ScpRejected, UnicodeDecodeError):
            return False
        if name != self.filename:
            return False
        self.command_ready.set()
        return True


class ChannelStream:
    def __init__(self, channel):
        self.channel = channel

    def read(self, size):
        return self.channel.recv(size)

    def write(self, data):
        self.channel.sendall(data)


def serve_client(client, host_key, key, inbox, expected=expected_from_laravel):
    transport = paramiko.Transport(client)
    try:
        transport.add_server_key(host_key)
        session = A10ScpSession(key, expected)
        transport.start_server(server=session)
        channel = transport.accept(20)
        if channel is None or not session.command_ready.wait(20):
            return
        channel.settimeout(60)
        receive_archive(ChannelStream(channel), inbox, session.filename)
        channel.send_exit_status(0)
    except (OSError, EOFError, ScpRejected, paramiko.SSHException) as error:
        logging.warning(json.dumps({'event': 'a10_scp_rejected', 'reason': type(error).__name__}))
    finally:
        transport.close()


def load_or_create_key(path, create):
    path = Path(path)
    if path.is_symlink():
        raise RuntimeError('symlinked receiver secret')
    try:
        data = path.read_bytes()
    except FileNotFoundError:
        data = create()
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
    if path.stat().st_mode & 0o077 or len(data) < 32:
        raise RuntimeError('invalid receiver secret permissions or length')
    return data


def main():
    logging.basicConfig(level=logging.INFO, format='%(message)s')
    root = Path(os.environ['BACKUP_A10_SCP_ROOT'])
    if root.is_symlink() or not root.is_dir() or root.stat().st_mode & 0o077:
        raise RuntimeError('receiver storage unavailable')
    inbox = root / 'incoming'
    inbox.mkdir(mode=0o700, exist_ok=True)
    os.chmod(inbox, 0o700)
    key = load_or_create_key(root / 'auth.key', lambda: os.urandom(32))
    host_key_path = root / 'host.key'
    if not host_key_path.exists():
        host_key = paramiko.RSAKey.generate(3072)
        host_key.write_private_key_file(str(host_key_path))
        os.chmod(host_key_path, 0o600)
    if host_key_path.is_symlink() or host_key_path.stat().st_mode & 0o077:
        raise RuntimeError('invalid receiver host key')
    host_key = paramiko.RSAKey.from_private_key_file(str(host_key_path))
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind(('0.0.0.0', 2222))
    listener.listen(8)
    slots = threading.BoundedSemaphore(8)
    while True:
        client, _ = listener.accept()
        if not slots.acquire(blocking=False):
            client.close()
            continue

        def run_connection(connection=client):
            try:
                serve_client(connection, host_key, key, inbox)
            finally:
                slots.release()

        threading.Thread(target=run_connection, daemon=True).start()


if __name__ == '__main__':
    main()
