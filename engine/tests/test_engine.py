import errno
import io
import logging
import socket
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import backup_engine
from drivers.mikrotik_ssh import BackupError, _mikrotik_transport, export_config
from storage import store, validate


class EngineTests(unittest.TestCase):
    def setUp(self):
        self.job = dict(id=1, device_id=2, policy_id=3, host='192.0.2.1', port=22,
                        username='backup', method='ssh_pull', artifact_mode='config',
                        vendor='MiKroTik', eligible=True,
                        relative_path='2/2026/09/22/execution-1-config.rsc')

    def test_storage_uses_atomic_replace_and_rejects_traversal_and_invalid_data(self):
        with tempfile.TemporaryDirectory() as root:
            data = b'# RouterOS\n/interface bridge\nadd name=br1\n'
            with patch('storage.os.replace', wraps=os.replace) as rename:
                store(root, self.job['relative_path'], data)
                rename.assert_called_once()
            target = Path(root, self.job['relative_path'])
            self.assertEqual(data, target.read_bytes())
            self.assertEqual(0o600, target.stat().st_mode & 0o777)
            with self.assertRaises(BackupError):
                store(root, '../escape.rsc', data)
            for invalid in [b'', b'error: denied', b'\x00', b'x' * (8 * 1024 * 1024 + 1)]:
                with self.assertRaises(BackupError):
                    validate(invalid)

    def test_policy_vendor_and_credential_gate_before_secret(self):
        for change, code in [({'method': 'ftp_push'}, 'UNSUPPORTED_POLICY'),
                             ({'artifact_mode': 'binary'}, 'UNSUPPORTED_POLICY'),
                             ({'vendor': 'Huawei'}, 'UNSUPPORTED_VENDOR'),
                             ({'eligible': False}, 'CREDENTIAL_INVALID')]:
            with self.subTest(change=change), patch.object(backup_engine, 'secret_for') as secret, \
                 patch.object(backup_engine, 'command') as command:
                backup_engine.execute({**self.job, **change})
                secret.assert_not_called()
                command.assert_called_once_with('engine:fail', 1, code)

    def test_ssh_error_codes_are_sanitized(self):
        for error in ['SSH_CONNECT_FAILED', 'SSH_CONNECTION_REFUSED',
                      'SSH_NEGOTIATION_FAILED', 'SSH_AUTH_FAILED', 'SSH_TIMEOUT']:
            with self.subTest(error=error), patch.object(backup_engine, 'secret_for', return_value='private'), \
                 patch.object(backup_engine, 'export_config', side_effect=BackupError(error)), \
                 patch.object(backup_engine, 'command') as command:
                backup_engine.execute(self.job)
                command.assert_called_once_with('engine:fail', 1, error)

    def test_invalid_host_does_not_connect(self):
        with patch('drivers.mikrotik_ssh.paramiko.SSHClient') as client:
            with self.assertRaises(BackupError) as error:
                export_config('../../host', 22, 'backup', 'secret')
            self.assertEqual('SSH_CONNECT_FAILED', error.exception.code)
            client.assert_not_called()

    def test_mikrotik_transport_adds_only_ssh_rsa_when_missing(self):
        for keys in [('ssh-ed25519', 'rsa-sha2-256'),
                     ('ssh-ed25519', 'ssh-rsa')]:
            with self.subTest(keys=keys), patch('drivers.mikrotik_ssh.paramiko.Transport') as factory:
                options = factory.return_value.get_security_options.return_value
                options.key_types = keys
                options.ciphers = ('aes128-ctr',)
                options.digests = ('hmac-sha2-256',)
                options.kex = ('curve25519-sha256@libssh.org',)
                transport = _mikrotik_transport(object(), disabled_algorithms=None)
                self.assertIs(transport, factory.return_value)
                self.assertEqual(keys if 'ssh-rsa' in keys else keys + ('ssh-rsa',),
                                 options.key_types)
                self.assertEqual(('aes128-ctr',), options.ciphers)
                self.assertEqual(('hmac-sha2-256',), options.digests)
                self.assertEqual(('curve25519-sha256@libssh.org',), options.kex)

    def test_connection_uses_driver_only_transport_and_password_authentication(self):
        with patch('drivers.mikrotik_ssh.paramiko.SSHClient') as factory:
            channel = factory.return_value.get_transport.return_value.open_session.return_value
            channel.recv_ready.return_value = False
            channel.exit_status_ready.return_value = True
            channel.recv_exit_status.return_value = 1
            with self.assertRaises(BackupError) as error:
                export_config('192.0.2.1', 22, 'backup', 'private-secret')
            self.assertEqual('EXPORT_FAILED', error.exception.code)
            kwargs = factory.return_value.connect.call_args.kwargs
            self.assertIs(kwargs['transport_factory'], _mikrotik_transport)
            self.assertEqual('private-secret', kwargs['password'])
            self.assertFalse(kwargs['look_for_keys'])
            self.assertFalse(kwargs['allow_agent'])
            factory.return_value.close.assert_called_once()

    def test_connection_failures_have_specific_codes(self):
        for failure, code in [(paramiko.AuthenticationException(), 'SSH_AUTH_FAILED'),
                              (socket.timeout(), 'SSH_TIMEOUT'),
                              (paramiko.SSHException('Incompatible ssh peer (no acceptable host key)'),
                               'SSH_NEGOTIATION_FAILED'),
                              (paramiko.ssh_exception.NoValidConnectionsError({
                                  ('192.0.2.1', 22): ConnectionRefusedError(errno.ECONNREFUSED, 'refused')}),
                               'SSH_CONNECTION_REFUSED'),
                              (ConnectionRefusedError(), 'SSH_CONNECTION_REFUSED'),
                              (paramiko.SSHException('transport closed'), 'SSH_CONNECT_FAILED')]:
            with self.subTest(code=code), patch('drivers.mikrotik_ssh.paramiko.SSHClient') as factory:
                factory.return_value.connect.side_effect = failure
                with self.assertRaises(BackupError) as error:
                    export_config('192.0.2.1', 22, 'backup', 'secret')
                self.assertEqual(code, error.exception.code)

    def test_secret_and_exception_details_are_absent_from_logs(self):
        output = io.StringIO()
        handler = logging.StreamHandler(output)
        logger = logging.getLogger()
        logger.addHandler(handler)
        try:
            with patch.object(backup_engine, 'secret_for', return_value='private-secret'), \
                 patch('drivers.mikrotik_ssh.paramiko.SSHClient') as factory, \
                 patch.object(backup_engine, 'command') as command:
                factory.return_value.connect.side_effect = paramiko.SSHException(
                    'Incompatible ssh peer (no acceptable host key): private-secret')
                backup_engine.execute(self.job)
            command.assert_called_once_with('engine:fail', 1, 'SSH_NEGOTIATION_FAILED')
            self.assertNotIn('private-secret', output.getvalue())
            self.assertNotIn('Incompatible ssh peer', output.getvalue())
        finally:
            logger.removeHandler(handler)


if __name__ == '__main__':
    unittest.main()
