import sys
import os
import socket
import tempfile
import threading
import unittest
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from a10_scp_server import A10ScpSession, password_for, serve_client


NAME = 'CGNAT-A10_20261002110000-exec-177.tar.gz'


class A10ScpServerTests(unittest.TestCase):
    def test_authenticated_ssh_upload_reaches_private_inbox(self):
        with tempfile.TemporaryDirectory() as root:
            os.chmod(root, 0o700)
            server_socket, client_socket = socket.socketpair()
            key = b'x' * 32
            host_key = paramiko.RSAKey.generate(2048)
            thread = threading.Thread(target=serve_client,
                                      args=(server_socket, host_key, key, root, lambda _: NAME))
            thread.start()
            client = paramiko.Transport(client_socket)
            try:
                client.start_client(timeout=10)
                client.auth_password('bmexec177', password_for(key, 177))
                channel = client.open_session()
                channel.exec_command(f'scp -t /{NAME}')
                self.assertEqual(b'\0', channel.recv(1))
                channel.sendall(f'C0600 7 {NAME}\n'.encode())
                self.assertEqual(b'\0', channel.recv(1))
                channel.sendall(b'archive\0')
                self.assertEqual(b'\0', channel.recv(1))
                self.assertEqual(0, channel.recv_exit_status())
                self.assertEqual(b'archive', (Path(root) / NAME).read_bytes())
            finally:
                client.close()
                thread.join(timeout=10)
            self.assertFalse(thread.is_alive())

    def test_password_binds_authentication_to_one_active_execution(self):
        key = b'x' * 32
        seen = []

        def expected(execution_id):
            seen.append(execution_id)
            return NAME if execution_id == 177 else None

        session = A10ScpSession(key, expected)
        self.assertEqual(paramiko.AUTH_FAILED, session.check_auth_password('bmexec177', 'wrong'))
        self.assertEqual([], seen)
        self.assertEqual(paramiko.AUTH_FAILED,
                         session.check_auth_password('bmexec178', password_for(key, 178)))
        self.assertEqual(paramiko.AUTH_SUCCESSFUL,
                         session.check_auth_password('bmexec177', password_for(key, 177)))
        self.assertEqual(NAME, session.filename)
        self.assertNotEqual(password_for(key, 177), password_for(key, 178))

    def test_exec_requires_exact_filename_and_no_extra_shell_options(self):
        session = A10ScpSession(b'x' * 32, lambda _: NAME)
        session.check_auth_password('bmexec177', password_for(b'x' * 32, 177))
        self.assertFalse(session.check_channel_exec_request(None, b'scp -t /WRONG.tar.gz'))
        self.assertFalse(session.check_channel_exec_request(None, f'scp -t /{NAME};id'.encode()))
        self.assertTrue(session.check_channel_exec_request(None, f'scp -t /{NAME}'.encode()))
        self.assertTrue(session.command_ready.is_set())
