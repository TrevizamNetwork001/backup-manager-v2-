import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from a10_incoming import remove_received, store_archive, wait_for_archive
from errors import BackupError


NAME = 'CGNAT-A10_20261002110000-exec-177.tar.gz'
RELATIVE = 'Backup Manager/POP/CGNAT-A10/02-10-2026/CGNAT-A10_20261002110000.tar.gz'


class A10IncomingTests(unittest.TestCase):
    def test_complete_archive_is_stored_once_and_inbox_copy_removed_after_commit(self):
        with tempfile.TemporaryDirectory() as root:
            inbox = Path(root) / 'scp' / 'incoming'
            storage = Path(root) / 'backups'
            inbox.mkdir(parents=True)
            storage.mkdir()
            os.chmod(inbox, 0o700)
            upload = inbox / NAME
            upload.write_bytes(b'opaque archive')
            path, identity, data, digest = wait_for_archive(inbox.parent, NAME, timeout=1,
                                                            stable_seconds=0, poll=0.01)
            self.assertEqual(b'opaque archive', data)
            self.assertEqual(64, len(digest))
            relative = store_archive(storage, RELATIVE, data, 177)
            self.assertTrue(relative.endswith('-exec-177.tar.gz'))
            self.assertEqual(relative, store_archive(storage, RELATIVE, data, 177))
            self.assertEqual(data, (storage / relative).read_bytes())
            remove_received(path, identity)
            self.assertFalse(upload.exists())

    def test_rejects_changed_existing_artifact_and_symlink_upload(self):
        with tempfile.TemporaryDirectory() as root:
            inbox = Path(root) / 'scp' / 'incoming'
            storage = Path(root) / 'backups'
            inbox.mkdir(parents=True)
            storage.mkdir()
            os.chmod(inbox, 0o700)
            store_archive(storage, RELATIVE, b'first', 177)
            with self.assertRaises(BackupError):
                store_archive(storage, RELATIVE, b'second', 177)
            (inbox / NAME).symlink_to(storage / 'outside')
            with self.assertRaises(BackupError):
                wait_for_archive(inbox.parent, NAME, timeout=1, stable_seconds=0, poll=0.01)

    def test_missing_upload_times_out_without_artifact(self):
        with tempfile.TemporaryDirectory() as root:
            (Path(root) / 'incoming').mkdir()
            with self.assertRaises(BackupError) as failure:
                wait_for_archive(root, NAME, timeout=0.02, stable_seconds=0, poll=0.01)
            self.assertEqual('A10_RECEIVE_TIMEOUT', failure.exception.code)

    def test_storage_path_cannot_escape_backup_root(self):
        with tempfile.TemporaryDirectory() as root:
            storage = Path(root) / 'backups'
            storage.mkdir()
            with self.assertRaises(BackupError) as failure:
                store_archive(storage, '../outside.tar.gz', b'data', 177)
            self.assertEqual('STORAGE_FAILED', failure.exception.code)
            self.assertFalse((Path(root) / 'outside-exec-177.tar.gz').exists())
