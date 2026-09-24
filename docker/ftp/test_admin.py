import importlib.util
import subprocess
import sys
import tempfile
import unittest
import runpy
import contextlib
import io
import json
import os
from pathlib import Path
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('ftp_admin', Path(__file__).resolve().parent / 'admin.py')
ftp_admin = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_admin)
server_spec = importlib.util.spec_from_file_location('ftp_server', Path(__file__).resolve().parent / 'server.py')
ftp_server = importlib.util.module_from_spec(server_spec)
server_spec.loader.exec_module(ftp_server)


class FtpAdminTests(unittest.TestCase):
    def test_server_passive_address_accepts_private_ipv4_and_rejects_commands(self):
        self.assertEqual(['-P', '10.23.45.67'], ftp_server.arguments('10.23.45.67')[-2:])
        self.assertNotIn('-P', ftp_server.arguments())
        with self.assertRaises(ValueError):
            ftp_server.arguments('10.23.45.67; command')

    def test_passive_address_prefers_new_name_then_legacy_fallback(self):
        self.assertEqual('10.23.45.67', ftp_server.passive_address({
            'BACKUP_FTP_PASSIVE_ADDRESS': '10.23.45.67',
            'BACKUP_FTP_PUBLIC_IP': '192.0.2.10',
        }))
        self.assertEqual('192.0.2.10', ftp_server.passive_address({
            'BACKUP_FTP_PASSIVE_ADDRESS': '',
            'BACKUP_FTP_PUBLIC_IP': '192.0.2.10',
        }))
        self.assertEqual('', ftp_server.passive_address({}))
        self.assertNotIn('-P', ftp_server.arguments(ftp_server.passive_address({})))

    def test_upload_file_limit_matches_engine_boundaries(self):
        for value, expected in [('1', 1), ('8388608', 8388608), ('999999999', 67108864)]:
            with patch.dict(os.environ, {'BACKUP_FTP_MAX_BYTES': value}):
                self.assertEqual(expected, ftp_server.file_limit())

    def test_account_path_is_derived_from_numeric_device_id(self):
        self.assertEqual(Path('/data/ftp/12/incoming'), ftp_admin.account_home({'device_id': 12, 'username': 'bmdev12'}))
        self.assertEqual(Path('/data/ftp/12/incoming'), ftp_admin.account_home({'device_id': 12, 'username': 'olt_backup-12'}))
        for row in ({'device_id': '../12', 'username': 'bmdev12'},
                    {'device_id': 12, 'username': '../../etc'},
                    {'device_id': -1, 'username': 'bmdev-1'}):
            with self.assertRaises((ValueError, RuntimeError)):
                ftp_admin.account_home(row)

    def test_reconciler_rejects_symlinked_device_directory(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            (root / '12').symlink_to(root, target_is_directory=True)
            row = {'id': 1, 'device_id': 12, 'username': 'bmdev12',
                   'is_active': True, 'updated_at': '2026-09-22T00:00:00Z'}
            with patch.object(ftp_admin, 'ROOT', root), \
                 patch.object(ftp_admin, 'PASSWD', str(root / 'pureftpd.passwd')), \
                 patch.object(ftp_admin, 'PUREDB', str(root / 'pureftpd.pdb')), \
                 patch.object(ftp_admin, 'artisan', return_value=json.dumps([row]).encode()), \
                 patch.object(ftp_admin.os, 'chown'):
                with self.assertRaisesRegex(RuntimeError, 'home_invalid'):
                    ftp_admin.sync_once()

    def test_pure_pw_uses_fixed_binary_argv_and_pipe(self):
        with patch.object(ftp_admin.subprocess, 'run') as run:
            run.return_value.returncode = 0
            ftp_admin.pure('useradd', 'bmdev12', '-f', '/tmp/puredb-test', password=b'synthetic\nsynthetic\n')
            args, kwargs = run.call_args
            self.assertEqual('/usr/bin/pure-pw', args[0][0])
            self.assertNotIn(b'synthetic', str(args).encode())
            self.assertEqual(b'synthetic\nsynthetic\n', kwargs['input'])
            self.assertEqual(subprocess.DEVNULL, kwargs['stderr'])

    def test_launcher_replaces_python_with_pure_ftpd(self):
        with patch.object(Path, 'is_file', return_value=True), patch('os.execv') as execute, \
             patch.dict(os.environ, {'BACKUP_FTP_PASSIVE_ADDRESS': '10.23.45.67',
                                     'BACKUP_FTP_PUBLIC_IP': '192.0.2.10'}), \
             patch('resource.setrlimit') as limit:
            runpy.run_path(str(Path(__file__).resolve().parent / 'server.py'), run_name='__main__')
        self.assertEqual('/usr/sbin/pure-ftpd', execute.call_args.args[0])
        self.assertEqual('/usr/sbin/pure-ftpd', execute.call_args.args[1][0])
        self.assertIn('-r', execute.call_args.args[1])
        self.assertEqual(['-P', '10.23.45.67'], execute.call_args.args[1][-2:])
        limit.assert_called_once()

    def test_failed_puredb_build_preserves_published_database(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            db = root / 'pureftpd.pdb'
            passwd = root / 'pureftpd.passwd'
            db.write_bytes(b'previous-db')
            passwd.write_bytes(b'previous-passwd')
            row = {'id': 1, 'device_id': 12, 'username': 'olt_backup-12',
                   'is_active': True, 'updated_at': '2026-09-22T00:00:00Z'}
            def artisan(command, *args, **kwargs):
                if command == 'ftp:accounts':
                    return __import__('json').dumps([row]).encode()
                if command == 'ftp:secret':
                    return b'Strong!Pass12345'
                raise AssertionError(command)
            def pure(command, *args, **kwargs):
                if command == 'mkdb':
                    raise RuntimeError('pure_pw_failed')
            with patch.object(ftp_admin, 'ROOT', root), \
                 patch.object(ftp_admin, 'PASSWD', str(passwd)), \
                 patch.object(ftp_admin, 'PUREDB', str(db)), \
                 patch.object(ftp_admin, 'artisan', side_effect=artisan), \
                 patch.object(ftp_admin, 'pure', side_effect=pure), \
                 patch.object(ftp_admin.os, 'chown'):
                with self.assertRaises(RuntimeError):
                    ftp_admin.sync_once()
            self.assertEqual(b'previous-db', db.read_bytes())
            self.assertEqual(b'previous-passwd', passwd.read_bytes())
            self.assertEqual([], list(root.glob('.pureftpd-*')))

    @unittest.skipUnless(Path('/usr/bin/pure-pw').exists(), 'pure-pw is unavailable')
    def test_reconcile_creates_rotates_and_disables_real_puredb_without_secret_output(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            db = root / 'pureftpd.pdb'
            passwd = root / 'pureftpd.passwd'
            row = {'id': 1, 'device_id': 12, 'username': 'bmdev12',
                   'is_active': True, 'updated_at': '2026-09-22T00:00:00Z'}
            state = {'secret': b'a' * 48}
            def artisan(command, *args, **kwargs):
                if command == 'ftp:accounts':
                    return json.dumps([row]).encode()
                if command == 'ftp:secret':
                    return state['secret']
                if command == 'ftp:provisioned':
                    return b''
                raise AssertionError(command)
            output = io.StringIO()
            with patch.object(ftp_admin, 'ROOT', root), \
                 patch.object(ftp_admin, 'PASSWD', str(passwd)), \
                 patch.object(ftp_admin, 'PUREDB', str(db)), \
                 patch.object(ftp_admin.os, 'chown'), \
                 patch.object(ftp_admin, 'artisan', side_effect=artisan), \
                 contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
                ftp_admin.sync_once()
                first = db.read_bytes()
                self.assertGreater(len(first), 0)
                self.assertEqual(0o600, db.stat().st_mode & 0o777)
                state['secret'] = b'b' * 48
                ftp_admin.sync_once()
                self.assertNotEqual(first, db.read_bytes())
                row['is_active'] = False
                ftp_admin.sync_once()
                result = subprocess.run(['/usr/bin/pure-pw', 'show', 'bmdev12', '-f', str(passwd)],
                                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                self.assertNotEqual(0, result.returncode)
            self.assertNotIn('a' * 48, output.getvalue())
            self.assertNotIn('b' * 48, output.getvalue())
