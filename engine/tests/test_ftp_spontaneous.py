import json
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from ftp_incoming import directory, scan_orphans
from ftp_spontaneous import scan


FIXTURE = Path(__file__).parent / 'fixtures/ma5800_ftp.cfg'
RELATIVE = 'Backup Manager/LAB/OLT/23-09-2026/OLT_20260923120000.cfg'


class SpontaneousTests(unittest.TestCase):
    def setUp(self):
        self.ftp = tempfile.TemporaryDirectory()
        self.storage = tempfile.TemporaryDirectory()
        self.addCleanup(self.ftp.cleanup)
        self.addCleanup(self.storage.cleanup)
        self.home = directory(self.ftp.name, 7)
        self.home.mkdir(parents=True)
        self.observed = {}
        self.receipts = {}
        self.completed = []
        self.failed = []

    def receive(self, device, token, name, received):
        self.assertEqual(7, device)
        if name == 'no-account.cfg':
            return None
        if token not in self.receipts:
            self.receipts[token] = {'id': len(self.receipts) + 1, 'status': 'running',
                                    'relative_path': RELATIVE, 'name': name, 'received': received}
        return self.receipts[token]

    def complete(self, job, relative):
        self.completed.append((job, relative))
        self.receipts[next(k for k, v in self.receipts.items() if v['id'] == job)]['status'] = 'succeeded'

    def scan(self, stable=1):
        scan(self.ftp.name, self.storage.name, stable, self.observed, [],
             self.receive, self.complete, lambda *args: self.failed.append(args))

    def settle(self):
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
            path = self.home / key[1]
            os.utime(path, (time.time() - 3, time.time() - 3), follow_symlinks=False)
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        self.scan()

    def sidecars(self):
        return [json.loads(p.read_text()) for p in Path(self.ftp.name, 'quarantine').glob('*.json')]

    def test_arbitrary_name_and_repeated_uploads_create_separate_events(self):
        for name in ('backup.cfg', 'backup.cfg'):
            (self.home / name).write_bytes(FIXTURE.read_bytes())
            self.settle()
        self.assertEqual(2, len(self.receipts))
        self.assertEqual(2, len(self.completed))
        self.assertEqual('backup.cfg', next(iter(self.receipts.values()))['name'])
        self.assertEqual(2, len(list(Path(self.storage.name).rglob('*.cfg'))))
        self.assertEqual([], self.sidecars())

    def test_invalid_file_and_missing_account_are_quarantined(self):
        (self.home / 'broken.cfg').write_bytes(b'<html>error</html>')
        (self.home / 'no-account.cfg').write_bytes(FIXTURE.read_bytes())
        self.settle()
        self.assertEqual([(1, 'FTP_FILE_INVALID')], self.failed)
        self.assertEqual({'invalid_file', 'invalid_account'}, {item['reason'] for item in self.sidecars()})
        self.assertEqual([], self.completed)

    def test_symlink_hardlink_empty_and_oversize(self):
        source = self.home / 'source.cfg'
        source.write_bytes(FIXTURE.read_bytes())
        os.link(source, self.home / 'hard.cfg')
        external = Path(self.storage.name, 'external.cfg')
        external.write_bytes(FIXTURE.read_bytes())
        (self.home / 'link.cfg').symlink_to(external)
        (self.home / 'empty.cfg').touch()
        (self.home / 'large.cfg').write_bytes(b'x' * 65)
        with patch.dict(os.environ, {'BACKUP_FTP_MAX_BYTES': '64'}):
            self.settle()
        self.assertEqual(5, len(self.failed), self.sidecars())
        self.assertEqual({'invalid_file'}, {item['reason'] for item in self.sidecars()})
        self.assertEqual([], self.completed)

    def test_restart_recovers_claim_after_completion_failure(self):
        (self.home / 'restart.cfg').write_bytes(FIXTURE.read_bytes())
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(self.home / 'restart.cfg', (time.time() - 3, time.time() - 3))
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        with self.assertRaises(RuntimeError):
            scan(self.ftp.name, self.storage.name, 1, self.observed, [], self.receive,
                 lambda *_: (_ for _ in ()).throw(RuntimeError('db offline')),
                 lambda *args: self.failed.append(args))
        self.assertEqual(1, len(self.receipts))
        self.scan()  # New process starts with an empty observation cache.
        self.assertEqual(1, len(self.receipts))
        self.assertEqual(1, len(self.completed))
        self.assertEqual([], list(Path(self.ftp.name, 'processing').iterdir()))

    def test_manual_name_and_autorename_variant_stay_out_of_spontaneous_flow(self):
        first = self.home / 'bm-exec-9.cfg'
        second = self.home / 'bm-exec-9.cfg.1'
        first.write_bytes(FIXTURE.read_bytes())
        second.write_bytes(FIXTURE.read_bytes())
        self.settle()
        self.assertEqual({}, self.receipts)
        observed = {}
        scan_orphans(self.ftp.name, [{'device_id': 7, 'filename': first.name}], 1, observed)
        for key, (identity, seen) in observed.items():
            observed[key] = (identity, seen - 2)
        os.utime(second, (time.time() - 3, time.time() - 3))
        scan_orphans(self.ftp.name, [{'device_id': 7, 'filename': first.name}], 1, observed)
        for key, (identity, seen) in observed.items():
            observed[key] = (identity, seen - 2)
        scan_orphans(self.ftp.name, [{'device_id': 7, 'filename': first.name}], 1, observed)
        self.assertTrue(first.exists())
        self.assertFalse(second.exists())


if __name__ == '__main__':
    unittest.main()
