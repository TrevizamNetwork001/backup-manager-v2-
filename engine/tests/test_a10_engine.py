import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import backup_engine


NAME = 'CGNAT-A10_20261002110000-exec-177.tar.gz'


class A10EngineTests(unittest.TestCase):
    def job(self):
        return {'id': 177, 'device_id': 1, 'policy_id': 1, 'host': '192.0.2.20',
                'port': 22, 'username': 'admin', 'vendor': 'A10 Networks',
                'platform': 'network', 'method': 'a10_system', 'artifact_mode': 'binary',
                'a10_transfer_interface': 'management',
                'eligible': True, 'relative_path':
                'Backup Manager/POP/CGNAT-A10/02-10-2026/CGNAT-A10_20261002110000.tar.gz'}

    def test_existing_upload_is_completed_without_retriggering_device(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = Path(root)
            (root_path / 'incoming').mkdir()
            (root_path / 'incoming' / NAME).write_bytes(b'archive')
            (root_path / 'auth.key').write_bytes(b'x' * 32)
            os.chmod(root_path / 'auth.key', 0o600)
            calls = []

            def command(*args):
                calls.append(args)
                return json.dumps({'filename': NAME}).encode() if args[0] == 'a10:expected' else b''

            with patch.dict(os.environ, {'BACKUP_A10_ENABLED': 'true', 'BACKUP_A10_TRANSPORT': 'scp', 'BACKUP_A10_SCP_ROOT': root,
                                         'BACKUP_A10_SCP_HOST': '192.0.2.10',
                                         'BACKUP_STORAGE_ROOT': root}):
                with patch.object(backup_engine, 'command', side_effect=command), \
                        patch.object(backup_engine, 'wait_for_archive', return_value=(root_path / 'incoming' / NAME,
                                     (1, 2, 7, 3, 1, 33152), b'archive', 'digest')), \
                        patch.object(backup_engine, 'store_archive', return_value='stored.tar.gz'), \
                        patch.object(backup_engine, 'remove_received') as removed, \
                        patch('drivers.a10_acos.A10AcosDriver.backup', side_effect=AssertionError('SSH retriggered')):
                    backup_engine.execute(self.job())
            self.assertEqual('a10:expected', calls[0][0])
            self.assertEqual('engine:complete', calls[1][0])
            removed.assert_called_once()

    def test_disabled_transfer_fails_before_contacting_receiver(self):
        calls = []
        with patch.dict(os.environ, {'BACKUP_A10_ENABLED': 'false'}):
            with patch.object(backup_engine, 'command', side_effect=lambda *args: calls.append(args) or b''):
                backup_engine.execute(self.job())
        self.assertEqual('engine:fail', calls[0][0])
        self.assertEqual('UNSUPPORTED_POLICY', calls[0][2])

    def test_sftp_uses_existing_ssh_server_and_private_password_file(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = Path(root)
            (root_path / 'incoming').mkdir()
            (root_path / 'transfer.password').write_text('A' * 32)
            os.chmod(root_path / 'transfer.password', 0o600)
            calls = []
            transfer = []

            def command(*args):
                calls.append(args)
                if args[0] == 'a10:expected':
                    return json.dumps({'filename': NAME}).encode()
                return b''

            def backup(context, secret, **kwargs):
                transfer.append((context.metadata['command_options'], secret[1]))
                return SimpleNamespace(success=True, code=None)

            with patch.dict(os.environ, {'BACKUP_A10_ENABLED': 'true', 'BACKUP_A10_TRANSPORT': 'sftp',
                                         'BACKUP_A10_SFTP_ROOT': root, 'BACKUP_A10_TRANSFER_HOST': '192.0.2.10',
                                         'BACKUP_A10_SFTP_USER': 'a10backup', 'BACKUP_STORAGE_ROOT': root}):
                with patch.object(backup_engine, 'command', side_effect=command), \
                        patch.object(backup_engine, 'secret_for', return_value='ssh-secret'), \
                        patch.object(backup_engine, 'wait_for_archive', return_value=(root_path / 'incoming' / NAME,
                                     (1, 2, 7, 3, 1, 33152), b'archive', 'digest')), \
                        patch.object(backup_engine, 'store_archive', return_value='stored.tar.gz'), \
                        patch.object(backup_engine, 'remove_received'), \
                        patch('drivers.a10_acos.A10AcosDriver.backup', side_effect=backup):
                    backup_engine.execute(self.job())
            self.assertEqual('sftp', transfer[0][0]['method'])
            self.assertEqual('incoming', transfer[0][0]['directory'])
            self.assertEqual('a10backup', transfer[0][0]['username'])
            self.assertEqual('192.0.2.10', transfer[0][0]['host'])
            self.assertEqual('A' * 32, transfer[0][1])
            self.assertEqual('engine:complete', calls[-1][0])

    def test_inbox_cleanup_failure_does_not_reverse_committed_artifact(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = Path(root)
            (root_path / 'incoming').mkdir()
            (root_path / 'incoming' / NAME).write_bytes(b'archive')
            (root_path / 'auth.key').write_bytes(b'x' * 32)
            os.chmod(root_path / 'auth.key', 0o600)
            calls = []

            def command(*args):
                calls.append(args)
                return json.dumps({'filename': NAME}).encode() if args[0] == 'a10:expected' else b''

            with patch.dict(os.environ, {'BACKUP_A10_ENABLED': 'true', 'BACKUP_A10_TRANSPORT': 'scp', 'BACKUP_A10_SCP_ROOT': root,
                                         'BACKUP_A10_SCP_HOST': '192.0.2.10', 'BACKUP_STORAGE_ROOT': root}):
                with patch.object(backup_engine, 'command', side_effect=command), \
                        patch.object(backup_engine, 'wait_for_archive', return_value=(root_path / 'incoming' / NAME,
                                     (1, 2, 7, 3, 1, 33152), b'archive', 'digest')), \
                        patch.object(backup_engine, 'store_archive', return_value='stored.tar.gz'), \
                        patch.object(backup_engine, 'remove_received', side_effect=OSError('read-only')):
                    backup_engine.execute(self.job())
            self.assertEqual(['a10:expected', 'engine:complete'], [call[0] for call in calls])
