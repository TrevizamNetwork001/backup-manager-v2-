import errno
import base64
import hashlib
import io
import json
import logging
import socket
import os
import sys
import tempfile
import threading
import time
import unittest
from types import SimpleNamespace
from pathlib import Path
from unittest.mock import patch

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import backup_engine
import drivers.mikrotik_ssh as mikrotik_ssh
import drivers.huawei_vrp_ssh as huawei_vrp_ssh
from drivers.mikrotik_ssh import BackupError, VerifiedHostKeyPolicy, _mikrotik_transport, export_config
from drivers.huawei_vrp_ssh import export_config as export_huawei_config
from drivers.huawei_vrp_ssh import _read_prompt
from storage import store, validate, analyze_content, validate_received_file_integrity
from ftp_incoming import receive, directory, existing_files, scan_orphans


class EngineTests(unittest.TestCase):
    def setUp(self):
        self.job = dict(id=1, device_id=2, policy_id=3, host='192.0.2.1', port=22,
                        username='backup', method='ssh_pull', artifact_mode='config',
                        vendor='MiKroTik', platform='network', eligible=True,
                        relative_path='Backup Manager/POP-CENTRO/MK/22-09-2026/MK_20260922121530.rsc')

    def test_storage_uses_exclusive_publish_and_rejects_traversal_and_invalid_data(self):
        with tempfile.TemporaryDirectory() as root:
            data = b'# RouterOS\n/interface bridge\nadd name=br1\n'
            with patch('storage.os.link', wraps=os.link) as publish:
                store(root, self.job['relative_path'], data)
                publish.assert_called_once()
            target = Path(root, self.job['relative_path'])
            self.assertEqual(data, target.read_bytes())
            self.assertEqual(0o600, target.stat().st_mode & 0o777)
            with self.assertRaises(BackupError):
                store(root, '../escape.rsc', data)
            for invalid in [b'', b'error: denied', b'\x00', b'x' * (8 * 1024 * 1024 + 1)]:
                with self.assertRaises(BackupError):
                    validate(invalid)

    def test_storage_collision_keeps_existing_file_and_uses_execution_id(self):
        with tempfile.TemporaryDirectory() as root:
            relative = self.job['relative_path']
            data = b'/interface bridge\nadd name=br1\n'
            first = store(root, relative, data, execution_id=1)
            second = store(root, relative, data + b'# second\n', execution_id=2)
            self.assertEqual(relative, first)
            self.assertEqual(relative[:-4] + '-exec-2.rsc', second)
            self.assertEqual(data, Path(root, first).read_bytes())
            self.assertEqual(data + b'# second\n', Path(root, second).read_bytes())
            with self.assertRaises(BackupError):
                store(root, relative, data, execution_id=2)

    def test_policy_vendor_and_credential_gate_before_secret(self):
        for change, code in [({'method': 'ftp_push'}, 'UNSUPPORTED_POLICY'),
                             ({'artifact_mode': 'binary'}, 'UNSUPPORTED_POLICY'),
                             ({'vendor': 'Unknown'}, 'UNSUPPORTED_VENDOR'),
                             ({'eligible': False}, 'CREDENTIAL_INVALID')]:
            with self.subTest(change=change), patch.object(backup_engine, 'secret_for') as secret, \
                 patch.object(backup_engine, 'command') as command:
                backup_engine.execute({**self.job, **change})
                secret.assert_not_called()
                command.assert_called_once_with('engine:fail', 1, code, backup_engine.WORKER_ID)

    def test_ssh_error_codes_are_sanitized(self):
        for error in ['SSH_CONNECT_FAILED', 'SSH_CONNECTION_REFUSED',
                      'SSH_NEGOTIATION_FAILED', 'SSH_AUTH_FAILED', 'SSH_TIMEOUT']:
            with self.subTest(error=error), patch.object(backup_engine, 'secret_for', return_value='private'), \
                 patch.object(mikrotik_ssh, 'export_config', side_effect=BackupError(error)), \
                 patch.object(backup_engine, 'command') as command:
                backup_engine.execute(self.job)
                command.assert_called_once_with('engine:fail', 1, error, backup_engine.WORKER_ID)

    def test_huawei_dispatch_policy_and_storage(self):
        data = b'#\nsysname Lab\n#\ninterface GigabitEthernet0/0/0\n description test\n#\n'
        with tempfile.TemporaryDirectory() as root, \
             patch.dict(os.environ, {'BACKUP_STORAGE_ROOT': root}), \
             patch.object(backup_engine, 'secret_for', return_value='private-secret') as secret, \
             patch.object(huawei_vrp_ssh, 'export_config', return_value=data) as huawei, \
             patch.object(mikrotik_ssh, 'export_config') as mikrotik, \
             patch.object(backup_engine, 'command') as command:
            job = {**self.job, 'vendor': ' hUaWeI ', 'relative_path': 'Backup Manager/POP-CENTRO/MK/22-09-2026/MK_20260922121530.cfg'}
            backup_engine.execute(job)
            secret.assert_called_once()
            huawei.assert_called_once()
            mikrotik.assert_not_called()
            self.assertEqual(data, Path(root, job['relative_path']).read_bytes())
            self.assertEqual(hashlib.sha256(data).hexdigest(), hashlib.sha256(Path(root, job['relative_path']).read_bytes()).hexdigest())
            command.assert_called_with('engine:complete', 1, job['relative_path'], backup_engine.WORKER_ID)
        for change in [{'method': 'ftp_push'}, {'artifact_mode': 'binary'},
                       {'artifact_mode': 'both'}, {'eligible': False}]:
            with self.subTest(change=change), patch.object(backup_engine, 'secret_for') as secret, \
                 patch.object(backup_engine, 'command') as command:
                backup_engine.execute({**self.job, 'vendor': 'Huawei', **change})
                secret.assert_not_called()
                code = 'CREDENTIAL_INVALID' if change == {'eligible': False} else 'UNSUPPORTED_POLICY'
                command.assert_called_once_with('engine:fail', 1, code, backup_engine.WORKER_ID)

    def test_huawei_content_validation(self):
        valid = b'#\nsysname Lab\n#\ninterface GigabitEthernet0/0/0\n description test\n#\n'
        validate(valid, 'huawei')
        for invalid in [b'', b'Error: command not found\n' + valid,
                        valid + b'---- More ----']:
            with self.subTest(invalid=invalid[:20]), self.assertRaises(BackupError):
                validate(invalid, 'huawei')

    def test_olt_ftp_ma5800_content_validation(self):
        valid = (Path(__file__).parent / 'fixtures/ma5800_ftp.cfg').read_bytes()
        self.assertEqual('recognized', analyze_content(valid, 'huawei_olt')['status'])
        self.assertEqual('warning', analyze_content(valid.replace(b' sysname OLT-LAB', b' no-sysname OLT-LAB'), 'huawei_olt')['status'])
        self.assertEqual('unknown', analyze_content(b'opaque backup\x00', 'huawei_olt')['status'])
        with tempfile.TemporaryDirectory() as root:
            path = Path(root, 'backup.cfg')
            path.write_bytes(valid)
            self.assertEqual(hashlib.sha256(valid).hexdigest(), validate_received_file_integrity(path)[1])
            with patch.dict(os.environ, {'BACKUP_FTP_MAX_BYTES': str(len(valid) - 1)}):
                with self.assertRaises(BackupError):
                    validate_received_file_integrity(path)

    def test_received_file_rejects_inode_change_during_read(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root, 'backup.cfg')
            path.write_bytes(b'complete backup')
            real_fstat = os.fstat
            calls = 0
            def changing_inode(fd):
                nonlocal calls
                calls += 1
                info = real_fstat(fd)
                if calls == 2:
                    return SimpleNamespace(st_dev=info.st_dev, st_ino=info.st_ino + 1,
                        st_size=info.st_size, st_mtime_ns=info.st_mtime_ns,
                        st_nlink=info.st_nlink, st_mode=info.st_mode)
                return info
            with patch('storage.os.fstat', side_effect=changing_inode):
                with self.assertRaises(BackupError) as failure:
                    validate_received_file_integrity(path)
            self.assertEqual('FTP_FILE_INVALID', failure.exception.code)

    def test_olt_ftp_waits_for_stable_file_and_never_opens_ssh(self):
        content = (Path(__file__).parent / 'fixtures/ma5800_ftp.cfg').read_bytes()
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as storage_root:
            home = directory(root, 2)
            home.mkdir(parents=True)
            path = home / 'bm-exec-1.cfg'
            completed = []
            def upload():
                path.write_bytes(content[:20])
                time.sleep(0.3)
                with path.open('ab') as file:
                    file.write(content[20:])
            writer = threading.Thread(target=upload)
            writer.start()
            relative = 'Backup Manager/POP-CENTRO/OLT/22-09-2026/OLT_20260922121530.cfg'
            receive(root, 2, path.name, storage_root, relative,
                    4, 1, lambda final: completed.append(final), poll=0.1)
            writer.join()
            self.assertEqual([relative], completed)
            self.assertFalse(path.exists())
            self.assertEqual(content, Path(storage_root, relative).read_bytes())

    def test_running_ftp_execution_receives_matching_late_upload_before_timeout(self):
        content = (Path(__file__).parent / 'fixtures/ma5800_ftp.cfg').read_bytes()
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as storage_root:
            home = directory(root, 4)
            home.mkdir(parents=True)
            wrong = home / 'bm-exec-25.cfg'
            expected = home / 'bm-exec-23.cfg'
            relative = 'Backup Manager/LAB/OLT/23-09-2026/OLT_20260923200000.cfg'
            completed = []
            def upload():
                time.sleep(0.2)
                wrong.write_bytes(content)
                expected.write_bytes(content[:20])
                time.sleep(0.2)
                with expected.open('ab') as file:
                    file.write(content[20:])
            writer = threading.Thread(target=upload)
            writer.start()
            try:
                receive(root, 4, expected.name, storage_root, relative, 4, 1,
                        lambda final: completed.append(final), poll=0.1)
            finally:
                writer.join()
            self.assertEqual([relative], completed)
            self.assertEqual(content, Path(storage_root, relative).read_bytes())
            self.assertEqual(hashlib.sha256(content).hexdigest(),
                             hashlib.sha256(Path(storage_root, relative).read_bytes()).hexdigest())
            self.assertFalse(expected.exists())
            self.assertFalse(wrong.exists())
            self.assertEqual(1, len(list(Path(root, 'quarantine').glob('*.quarantine'))))

    def test_olt_ftp_quarantines_invalid_and_uncorrelated_and_times_out(self):
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as storage_root:
            home = directory(root, 2)
            home.mkdir(parents=True)
            (home / 'bm-exec-999.cfg').write_bytes(b'late')
            (home / 'bad.txt').write_bytes(b'bad')
            with self.assertRaises(BackupError) as error:
                receive(root, 2, 'bm-exec-1.cfg', storage_root, self.job['relative_path'],
                        2, 1, lambda final: self.fail('invalid complete'), poll=0.1)
            self.assertEqual('FTP_RECEIVE_TIMEOUT', error.exception.code)
            self.assertEqual(2, len(list(Path(root, 'quarantine').glob('*.quarantine'))))
            self.assertEqual(2, len(list(Path(root, 'quarantine').glob('*.json'))))
            self.assertEqual([], list(home.iterdir()))
            with self.assertRaises(BackupError):
                directory(root, '../2')

    def test_orphan_scan_waits_for_stability_and_respects_active_execution(self):
        with tempfile.TemporaryDirectory() as root:
            home = directory(root, 2)
            home.mkdir(parents=True)
            late = home / 'bm-exec-7.cfg'
            active = home / 'bm-exec-8.cfg'
            late.write_bytes(b'late')
            active.write_bytes(b'active')
            seen = {}
            keep = [{'device_id': 2, 'filename': active.name}]
            scan_orphans(root, keep, 1, seen)
            self.assertTrue(late.exists())
            time.sleep(1.1)
            scan_orphans(root, keep, 1, seen)
            self.assertFalse(late.exists())
            self.assertTrue(active.exists())

    def test_startup_file_for_active_execution_remains_eligible(self):
        content = (Path(__file__).parent / 'fixtures/ma5800_ftp.cfg').read_bytes()
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as storage_root:
            home = directory(root, 4)
            home.mkdir(parents=True)
            active = home / 'bm-exec-23.cfg'
            active.write_bytes(content)
            preserved = existing_files(root)
            with patch('ftp_incoming.time.monotonic', return_value=1000000):
                scan_orphans(root, [{'device_id': 4, 'filename': active.name}], 1, {}, preserved)
            self.assertTrue(active.exists())
            relative = 'Backup Manager/LAB/OLT/23-09-2026/OLT_20260923200000.cfg'
            receive(root, 4, active.name, storage_root, relative, 3, 1, lambda _: None, poll=0.1)
            self.assertFalse(active.exists())
            self.assertEqual(content, Path(storage_root, relative).read_bytes())

    def test_startup_orphan_moves_to_uncorrelated_quarantine_after_grace(self):
        with tempfile.TemporaryDirectory() as root:
            home = directory(root, 4)
            home.mkdir(parents=True)
            orphan = home / 'bm-exec-23.cfg'
            orphan.write_bytes(b'historical')
            preserved = existing_files(root)
            started = preserved[('4', orphan.name)][1]
            observed = {}
            with patch('ftp_incoming.time.monotonic', return_value=started + 31), \
                    patch('ftp_incoming.time.time_ns', return_value=orphan.stat().st_mtime_ns + 2_000_000_000):
                scan_orphans(root, [], 1, observed, preserved)
            self.assertTrue(orphan.exists())
            with patch('ftp_incoming.time.monotonic', return_value=started + 32), \
                    patch('ftp_incoming.time.time_ns', return_value=orphan.stat().st_mtime_ns + 3_000_000_000):
                scan_orphans(root, [], 1, observed, preserved)
            self.assertFalse(orphan.exists())
            self.assertEqual(['uncorrelated'],
                             [json.loads(path.read_text())['reason'] for path in Path(root, 'quarantine').glob('*.json')])
            self.assertEqual(1, len(list(Path(root, 'quarantine').glob('*.quarantine'))))

    def test_recent_startup_upload_is_not_quarantined_immediately(self):
        with tempfile.TemporaryDirectory() as root:
            home = directory(root, 4)
            home.mkdir(parents=True)
            recent = home / 'bm-exec-23.cfg'
            recent.write_bytes(b'upload in progress')
            preserved = existing_files(root)
            started = preserved[('4', recent.name)][1]
            observed = {}
            with patch('ftp_incoming.time.monotonic', return_value=started + 29):
                scan_orphans(root, [], 1, observed, preserved)
            self.assertTrue(recent.exists())
            self.assertEqual({}, observed)

    def test_expired_execution_file_is_not_reused_after_startup_grace(self):
        with tempfile.TemporaryDirectory() as root:
            home = directory(root, 4)
            home.mkdir(parents=True)
            expired = home / 'bm-exec-23.cfg'
            expired.write_bytes(b'expired upload')
            preserved = existing_files(root)
            started = preserved[('4', expired.name)][1]
            observed = {}
            # ftp:expected omits failed and expired executions.
            with patch('ftp_incoming.time.monotonic', return_value=started + 31), \
                    patch('ftp_incoming.time.time_ns', return_value=expired.stat().st_mtime_ns + 2_000_000_000):
                scan_orphans(root, [], 1, observed, preserved)
            with patch('ftp_incoming.time.monotonic', return_value=started + 32), \
                    patch('ftp_incoming.time.time_ns', return_value=expired.stat().st_mtime_ns + 3_000_000_000):
                scan_orphans(root, [], 1, observed, preserved)
            self.assertFalse(expired.exists())
            self.assertEqual('uncorrelated',
                             json.loads(next(Path(root, 'quarantine').glob('*.json')).read_text())['reason'])
            new = home / 'bm-exec-25.cfg'
            new.write_bytes(b'new')
            scan_orphans(root, [{'device_id': 4, 'filename': new.name}], 1, observed, preserved)
            self.assertTrue(new.exists())
            self.assertNotEqual(expired.name, new.name)

    def test_olt_dispatch_never_uses_ssh_and_matches_own_device_only(self):
        olt = {**self.job, 'vendor': 'Huawei', 'platform': 'olt', 'method': 'ftp_push',
               'ftp_account_available': True, 'ftp_host': '192.0.2.20', 'ftp_filename': 'bm-exec-1.cfg',
               'relative_path': 'Backup Manager/POP-CENTRO/OLT/22-09-2026/OLT_20260922121530.cfg',
               'schedule_type': 'manual', 'origin': 'manual'}
        with tempfile.TemporaryDirectory() as root, \
             patch.dict(os.environ, {'BACKUP_FTP_ROOT': root, 'BACKUP_STORAGE_ROOT': root}), \
             patch.object(backup_engine, 'secret_for') as secret, \
             patch.object(huawei_vrp_ssh, 'export_config') as vrp, \
             patch.object(backup_engine, 'collect_huawei_olt_config') as receive_file, \
             patch.object(backup_engine, 'command') as command:
            receive_file.side_effect = lambda *args: args[-1](olt['relative_path'])
            backup_engine.execute(olt)
            secret.assert_not_called()
            vrp.assert_not_called()
            command.assert_called_once_with('engine:complete', 1, olt['relative_path'], backup_engine.WORKER_ID)
            self.assertEqual(Path(root, '2/incoming'), directory(root, 2))
            self.assertNotEqual(directory(root, 2), directory(root, 3))

        for change in [{'schedule_type': 'daily'}, {'schedule_type': 'weekly'},
                       {'origin': 'scheduler'}]:
            with self.subTest(change=change), patch.object(backup_engine, 'collect_huawei_olt_config') as receive_file, \
                 patch.object(backup_engine, 'command') as command:
                backup_engine.execute({**olt, **change})
                receive_file.assert_not_called()
                command.assert_called_once_with('engine:fail', 1, 'UNSUPPORTED_POLICY', backup_engine.WORKER_ID)

    def test_olt_invalid_expected_file_is_quarantined(self):
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as storage_root:
            home = directory(root, 2)
            home.mkdir(parents=True)
            wrong_device = directory(root, 3)
            wrong_device.mkdir(parents=True)
            (wrong_device / 'bm-exec-1.cfg').write_bytes(b'other-device')
            path = home / 'bm-exec-1.cfg'
            path.write_bytes(b'')
            with self.assertRaises(BackupError) as failure:
                receive(root, 2, path.name, storage_root, self.job['relative_path'],
                        3, 1, lambda final: self.fail('invalid complete'), poll=0.1)
            self.assertEqual('FTP_FILE_INVALID', failure.exception.code)
            self.assertFalse(path.exists())
            self.assertTrue((wrong_device / 'bm-exec-1.cfg').exists())
            self.assertEqual(1, len(list(Path(root, 'quarantine').glob('*.quarantine'))))

    def test_huawei_shell_commands_prompt_and_fallback(self):
        class Channel:
            closed = False
            def __init__(self, paging_error=False):
                self.pending = [b'Welcome\r\n<EDGE-1>']
                self.commands = []
                self.paging_error = paging_error
            def settimeout(self, value): pass
            def recv_ready(self): return bool(self.pending)
            def recv(self, count): return self.pending.pop(0)
            def exit_status_ready(self): return False
            def close(self): pass
            def sendall(self, data):
                command = data.strip()
                self.commands.append(command)
                if command == 'screen-length 0 temporary':
                    result = 'Error: unsupported' if self.paging_error else ''
                else:
                    result = '#\nsysname Lab\n#\ninterface GigabitEthernet0/0/0\n description test\n#'
                self.pending.append(f'\r\n{command}\r\n{result}\r\n[EDGE-1]'.encode())
        for fallback in [False, True]:
            with self.subTest(fallback=fallback), patch('drivers.huawei_vrp_ssh.paramiko.SSHClient') as factory:
                channel = Channel(fallback)
                factory.return_value.invoke_shell.return_value = channel
                output = export_huawei_config('192.0.2.1', 22, 'backup', 'private', observe=lambda *_: None)
                validate(output, 'huawei')
                self.assertEqual(['screen-length 0 temporary',
                                  'display current-configuration | no-more' if fallback else 'display current-configuration'], channel.commands)
                self.assertNotIn(b'EDGE-1', output)
                self.assertNotIn(b'screen-length', output)
                self.assertNotIn(b'display current', output)
                factory.return_value.load_system_host_keys.assert_not_called()
                self.assertNotIn('transport_factory', factory.return_value.connect.call_args.kwargs)

    def test_huawei_host_key_errors_auth_timeout_and_paging_are_sanitized(self):
        key = unittest.mock.Mock()
        key.get_name.return_value = 'ssh-ed25519'
        key.asbytes.return_value = b'public-key-only'
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(b'public-key-only').digest()).decode().rstrip('=')
        for trusted, expected in [(None, 'SSH_HOST_KEY_UNKNOWN'), ('SHA256:' + 'A'*43, 'SSH_HOST_KEY_MISMATCH'),
                                  (fingerprint, None)]:
            policy = VerifiedHostKeyPolicy('ssh-ed25519', trusted, lambda *_: None)
            if expected:
                with self.assertRaises(BackupError) as error:
                    policy.missing_host_key(None, '192.0.2.1', key)
                self.assertEqual(expected, error.exception.code)
            else:
                policy.missing_host_key(None, '192.0.2.1', key)
        class PagedChannel:
            closed = False
            def recv_ready(self): return True
            def recv(self, count): return b'#\nsysname Lab\n---- More ----'
            def exit_status_ready(self): return False
        with self.assertRaises(BackupError) as error:
            _read_prompt(PagedChannel(), 1, 'HUAWEI_EXPORT_FAILED')
        self.assertEqual('HUAWEI_PAGING_FAILED', error.exception.code)
        for failure, expected in [(paramiko.AuthenticationException('private-secret'), 'SSH_AUTH_FAILED'),
                                  (socket.timeout('private-secret'), 'SSH_TIMEOUT')]:
            with patch('drivers.huawei_vrp_ssh.paramiko.SSHClient') as factory:
                factory.return_value.connect.side_effect = failure
                with self.assertRaises(BackupError) as error:
                    export_huawei_config('192.0.2.1', 22, 'backup', 'private-secret', observe=lambda *_: None)
                self.assertEqual(expected, error.exception.code)

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
                export_config('192.0.2.1', 22, 'backup', 'private-secret', observe=lambda *_: None)
            self.assertEqual('EXPORT_FAILED', error.exception.code)
            kwargs = factory.return_value.connect.call_args.kwargs
            self.assertIs(kwargs['transport_factory'], _mikrotik_transport)
            self.assertEqual('private-secret', kwargs['password'])
            self.assertFalse(kwargs['look_for_keys'])
            self.assertFalse(kwargs['allow_agent'])
            factory.return_value.load_system_host_keys.assert_not_called()
            self.assertIsInstance(factory.return_value.set_missing_host_key_policy.call_args.args[0], VerifiedHostKeyPolicy)
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
                    export_config('192.0.2.1', 22, 'backup', 'secret', observe=lambda *_: None)
                self.assertEqual(code, error.exception.code)

    def test_host_key_unknown_mismatch_and_trusted(self):
        key = unittest.mock.Mock()
        key.get_name.return_value = 'ssh-rsa'
        key.asbytes.return_value = b'public-key-only'
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(b'public-key-only').digest()).decode().rstrip('=')
        observed = []
        for algorithm, trusted, expected in [(None, None, 'SSH_HOST_KEY_UNKNOWN'),
                                              ('ssh-rsa', 'SHA256:' + 'A' * 43, 'SSH_HOST_KEY_MISMATCH'),
                                              ('ssh-ed25519', fingerprint, 'SSH_HOST_KEY_MISMATCH')]:
            policy = VerifiedHostKeyPolicy(algorithm, trusted, lambda *identity: observed.append(identity))
            with self.assertRaises(BackupError) as error:
                policy.missing_host_key(None, '192.0.2.1', key)
            self.assertEqual(expected, error.exception.code)
            self.assertEqual(('ssh-rsa', fingerprint), observed[-1])
        policy = VerifiedHostKeyPolicy('ssh-rsa', fingerprint, lambda *identity: observed.append(identity))
        policy.missing_host_key(None, '192.0.2.1', key)
        self.assertEqual(('ssh-rsa', fingerprint), observed[-1])

    def test_paramiko_info_is_filtered_and_worker_id_is_safe(self):
        self.assertRegex(backup_engine.WORKER_ID, r'^[a-f0-9]{32}$')
        self.assertGreaterEqual(logging.getLogger('paramiko').level, logging.WARNING)
        output = io.StringIO()
        handler = logging.StreamHandler(output)
        logger = logging.getLogger('paramiko.transport')
        logger.addHandler(handler)
        try:
            logger.info('Authentication (password) successful! private-secret')
            self.assertEqual('', output.getvalue())
        finally:
            logger.removeHandler(handler)

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
            command.assert_called_once_with('engine:fail', 1, 'SSH_NEGOTIATION_FAILED', backup_engine.WORKER_ID)
            self.assertNotIn('private-secret', output.getvalue())
            self.assertNotIn('Incompatible ssh peer', output.getvalue())
        finally:
            logger.removeHandler(handler)


if __name__ == '__main__':
    unittest.main()
