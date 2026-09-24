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
sys.path.insert(0, str(Path(__file__).resolve().parent))
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

    def test_standalone_account_home_uses_validated_uuid(self):
        identifier = '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'
        row = {'home_layout': 'account', 'account_uuid': identifier, 'device_id': None, 'username': 'fileshare'}
        self.assertEqual(Path('/data/ftp/accounts') / identifier / 'incoming', ftp_admin.account_home(row))
        for bad in ('../escape', identifier + '/elsewhere'):
            with self.assertRaises((ValueError, RuntimeError)):
                ftp_admin.account_home({**row, 'account_uuid': bad})

    def test_reconciler_provisions_standalone_home_and_disables_account(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            identifier = '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'
            row = {'id': 3, 'device_id': None, 'account_uuid': identifier, 'home_layout': 'account',
                   'username': 'fileshare', 'is_active': True, 'updated_at': '2026-09-23T00:00:00Z'}
            commands = []
            def artisan(command, *args, **kwargs):
                if command == 'ftp:accounts': return json.dumps([row]).encode()
                if command == 'ftp:inspection-requests': return b'[]'
                if command == 'ftp:secret': return b'Strong!Pass12345'
                if command in ('ftp:provisioned', 'ftp:physical-report', 'ftp:puredb-revoked'): return b''
                if command == 'ftp:finalize-deletions':
                    commands.append(('finalize', args))
                    return b''
                raise AssertionError(command)
            def pure(command, *args, **kwargs):
                commands.append((command, args))
            with patch.object(ftp_admin, 'ROOT', root), \
                 patch.object(ftp_admin, 'PASSWD', str(root / 'pureftpd.passwd')), \
                 patch.object(ftp_admin, 'PUREDB', str(root / 'pureftpd.pdb')), \
                 patch.object(ftp_admin, 'artisan', side_effect=artisan), \
                 patch.object(ftp_admin, 'pure', side_effect=pure), \
                 patch.object(ftp_admin.os, 'chown'):
                ftp_admin.sync_once()
                home = root / 'accounts' / identifier / 'incoming'
                self.assertTrue(home.is_dir())
                self.assertIn(str(home), commands[0][1])
                row['is_active'] = False
                row['deletion_mode'] = 'account'
                commands.clear()
                ftp_admin.sync_once()
                self.assertFalse(any(command == 'useradd' for command, _ in commands))
                self.assertEqual('finalize', commands[-1][0])
                self.assertEqual('3', commands[-1][1][0])
                self.assertEqual('ok', json.loads(__import__('base64').urlsafe_b64decode(commands[-1][1][1]))['status'])

    def test_reconciler_rejects_symlinked_device_directory(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            (root / '12').symlink_to(root, target_is_directory=True)
            row = {'id': 1, 'device_id': 12, 'username': 'bmdev12',
                   'is_active': True, 'updated_at': '2026-09-22T00:00:00Z'}
            with patch.object(ftp_admin, 'ROOT', root), \
                 patch.object(ftp_admin, 'PASSWD', str(root / 'pureftpd.passwd')), \
                 patch.object(ftp_admin, 'PUREDB', str(root / 'pureftpd.pdb')), \
                 patch.object(ftp_admin, 'artisan', side_effect=lambda command, *args, **kwargs: json.dumps([row]).encode() if command == 'ftp:accounts' else b'[]'), \
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
                if command == 'ftp:inspection-requests':
                    return b'[]'
                if command == 'ftp:secret':
                    return b'Strong!Pass12345'
                if command in ('ftp:physical-report', 'ftp:puredb-revoked'):
                    return b''
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
                if command == 'ftp:inspection-requests':
                    return b'[]'
                if command == 'ftp:secret':
                    return state['secret']
                if command in ('ftp:provisioned', 'ftp:physical-report', 'ftp:puredb-revoked'):
                    return b''
                if command == 'ftp:finalize-deletions':
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

class PhysicalDeletionTests(unittest.TestCase):
    UUID = '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'

    def row(self, layout='legacy'):
        return {'id': 19, 'username': 'olt_teste', 'home_layout': layout,
                'device_id': 6 if layout == 'legacy' else None,
                'account_uuid': self.UUID}

    def test_legacy_0700_ftp_owner_and_shared_parent_preserved(self):
        import deletion
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            home = root / '6' / 'incoming'
            home.mkdir(parents=True, mode=0o700)
            os.chmod(home, 0o700)
            try:
                os.chown(home, 65534, 65534)
                ownership = contextlib.nullcontext()
            except OSError:
                # This sandbox denies chown. Present the same UID/GID in lstat
                # while exercising the real 0700 directory and cleanup.
                original_lstat = os.lstat
                def ftp_owned(path, *args, **kwargs):
                    result = original_lstat(path, *args, **kwargs)
                    if Path(path) != home:
                        return result
                    fields = list(result)
                    fields[4], fields[5] = 65534, 65534
                    return os.stat_result(fields)
                ownership = patch.object(deletion.os, 'lstat', side_effect=ftp_owned)
            (root / '6' / 'unexpected-sibling').mkdir()
            with ownership:
                self.assertEqual(65534, os.lstat(home).st_uid)
                self.assertEqual(65534, os.lstat(home).st_gid)
                self.assertEqual(0, deletion.inspect(self.row(), root)['incoming']['files'])
                payload = home / 'old.cfg'
                payload.write_bytes(b'old-data')
                os.utime(payload, (1, 1))
                self.assertEqual(8, deletion.inspect(self.row(), root)['incoming']['bytes'])
                result = deletion.cleanup(self.row(), root, 'ftp_data')
                self.assertEqual({'status': 'ok', 'files_removed': 1, 'bytes_removed': 8}, result)
                self.assertFalse(home.exists())
                self.assertTrue((root / '6' / 'unexpected-sibling').is_dir())
                self.assertEqual(0, deletion.cleanup(self.row(), root, 'ftp_data')['files_removed'])

    def test_uuid_cleanup_keeps_accounts_root(self):
        import deletion
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            home = root / 'accounts' / self.UUID / 'incoming'
            home.mkdir(parents=True)
            payload = home / 'upload.cfg'
            payload.write_bytes(b'data')
            os.utime(payload, (1, 1))
            self.assertEqual(1, deletion.inspect(self.row('account'), root)['incoming']['files'])
            deletion.cleanup(self.row('account'), root, 'all')
            self.assertFalse(home.parent.exists())
            self.assertTrue((root / 'accounts').is_dir())

    def test_invalid_identity_symlink_unexpected_and_permission_errors(self):
        import deletion
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            for row in ({**self.row(), 'device_id': '../6'},
                        {**self.row(), 'device_id': 0},
                        {**self.row('account'), 'account_uuid': '../accounts'},
                        {**self.row(), 'home_layout': 'other'}):
                self.assertEqual('unsafe_path', deletion.inspect(row, root)['blockers'][0])
            (root / '6').symlink_to(root)
            self.assertEqual('symlink_detected', deletion.inspect(self.row(), root)['blockers'][0])
            (root / '6').unlink()
            home = root / '6' / 'incoming'
            home.mkdir(parents=True)
            (home / 'unexpected').mkdir()
            self.assertEqual('unsafe_path', deletion.inspect(self.row(), root)['blockers'][0])
            (home / 'unexpected').rmdir()
            original = deletion.os.scandir
            def denied(path):
                if Path(path) == home:
                    raise PermissionError()
                return original(path)
            with patch.object(deletion.os, 'scandir', side_effect=denied):
                self.assertEqual('filesystem_permission_denied', deletion.inspect(self.row(), root)['blockers'][0])

    def test_processing_claim_blocks_and_quarantine_is_account_scoped(self):
        import deletion
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            (root / '6' / 'incoming').mkdir(parents=True)
            processing = root / 'processing'
            processing.mkdir()
            token = 'a' * 32
            (processing / token).write_bytes(b'data')
            (processing / (token + '.json')).write_text(json.dumps({'account_id': 19, 'device_id': 6}))
            self.assertEqual('active_claim', deletion.inspect(self.row(), root)['blockers'][0])
            with self.assertRaisesRegex(deletion.PhysicalError, 'active_claim'):
                deletion.cleanup(self.row(), root, 'ftp_data')
            (processing / token).unlink()
            (processing / (token + '.json')).unlink()
            quarantine = root / 'quarantine'
            quarantine.mkdir()
            (quarantine / (token + '.quarantine')).write_bytes(b'bad')
            (quarantine / (token + '.json')).write_text(json.dumps({'ftp_account_id': 19}))
            other = 'b' * 32
            (quarantine / (other + '.quarantine')).write_bytes(b'keep')
            (quarantine / (other + '.json')).write_text(json.dumps({'ftp_account_id': 20, 'device_id': 6}))
            deletion.cleanup(self.row(), root, 'ftp_data')
            self.assertFalse((quarantine / (token + '.quarantine')).exists())
            self.assertTrue((quarantine / (other + '.quarantine')).exists())

    def test_recent_upload_and_root_are_blocked(self):
        import deletion
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            home = root / '6' / 'incoming'
            home.mkdir(parents=True)
            (home / 'new.cfg').write_bytes(b'new')
            self.assertEqual('recent_upload', deletion.inspect(self.row(), root)['blockers'][0])
            with self.assertRaisesRegex(deletion.PhysicalError, 'recent_upload'):
                deletion.cleanup(self.row(), root, 'ftp_data')
            self.assertTrue(root.is_dir())
            self.assertTrue(home.is_dir())
