import json
import hashlib
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

    def test_unknown_content_is_stored_and_missing_account_is_quarantined(self):
        (self.home / 'broken.cfg').write_bytes(b'<html>error</html>')
        (self.home / 'no-account.cfg').write_bytes(FIXTURE.read_bytes())
        self.settle()
        self.assertEqual([], self.failed)
        self.assertEqual({'invalid_account'}, {item['reason'] for item in self.sidecars()})
        self.assertEqual(1, len(self.completed))
        self.assertEqual(b'<html>error</html>', next(Path(self.storage.name).rglob('*.cfg')).read_bytes())

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

    def test_processing_retry_is_quarantined_after_exhausting_max_attempts(self):
        # V1 lesson (backup_manager/ftp_importer.py): nothing there ever gave
        # up on a stuck 'processing' row — it retried forever. Here it must
        # eventually quarantine instead of retrying indefinitely. `receive`
        # (not `complete`) is the one that fails on every attempt: failing
        # `complete` instead would re-run `store()` for the same execution id
        # on each retry, which has its own (unrelated, pre-existing) collision
        # rule after a couple of attempts — orthogonal to what's under test.
        from ftp_spontaneous import MAX_PROCESSING_RETRIES
        failing_receive = lambda *_: (_ for _ in ()).throw(RuntimeError('db offline'))
        (self.home / 'stuck.cfg').write_bytes(FIXTURE.read_bytes())
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(self.home / 'stuck.cfg', (time.time() - 3, time.time() - 3))
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        for attempt in range(1, MAX_PROCESSING_RETRIES + 1):
            scan(self.ftp.name, self.storage.name, 1, self.observed, [], failing_receive, self.complete,
                 lambda *args: self.failed.append(args))
            metadata = next(Path(self.ftp.name, 'processing').glob('*.json'))
            self.assertEqual(attempt, json.loads(metadata.read_text())['retry_count'])
        scan(self.ftp.name, self.storage.name, 1, self.observed, [], failing_receive, self.complete,
             lambda *args: self.failed.append(args))
        self.assertEqual([], list(Path(self.ftp.name, 'processing').iterdir()))
        self.assertEqual({'processing_retry_exhausted'}, {item['reason'] for item in self.sidecars()})
        self.assertEqual([], self.completed)

    def test_in_progress_suffix_is_never_claimed_even_once_stable(self):
        # STABILIZATION-1 (P1, V1 lesson): a well-known "still transferring"
        # suffix must be skipped at discovery, independent of the stability
        # window — otherwise a stalled transfer could go stable mid-upload.
        for suffix in ('.part', '.tmp', '.partial', '.filepart', '.upload'):
            (self.home / ('stuck' + suffix)).write_bytes(FIXTURE.read_bytes())
        self.settle()
        self.assertEqual([], self.completed)
        self.assertEqual([], self.failed)
        self.assertEqual({}, self.receipts)
        self.assertEqual(5, len(list(self.home.iterdir())))
        self.assertEqual([], list(Path(self.ftp.name, 'processing').iterdir()))

    def test_restart_recovers_claim_after_completion_failure(self):
        (self.home / 'restart.cfg').write_bytes(FIXTURE.read_bytes())
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(self.home / 'restart.cfg', (time.time() - 3, time.time() - 3))
        self.scan()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        scan(self.ftp.name, self.storage.name, 1, self.observed, [], self.receive,
             lambda *_: (_ for _ in ()).throw(RuntimeError('db offline')),
             lambda *args: self.failed.append(args))
        metadata = next(Path(self.ftp.name, 'processing').glob('*.json'))
        self.assertEqual(1, json.loads(metadata.read_text())['retry_count'])
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

    def test_file_server_receives_without_backup_execution(self):
        account_uuid = '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'
        home = Path(self.ftp.name, 'accounts', account_uuid, 'incoming')
        home.mkdir(parents=True)
        (home / 'firmware.bin').write_bytes(b'generic data')
        events = []
        account = {'id': 11, 'device_id': None, 'account_uuid': account_uuid, 'home_layout': 'account',
                   'home': str(home), 'purpose': 'file_server', 'is_active': True, 'ready_for_receive': True}
        def receive(*args):
            self.fail('standalone account must not create a backup execution')
        def scan_once():
            scan(self.ftp.name, self.storage.name, 1, self.observed, [], receive,
                 self.complete, lambda *args: self.failed.append(args), [account],
                 lambda *args: events.append(args))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(home / 'firmware.bin', (time.time() - 3, time.time() - 3))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        scan_once()
        self.assertEqual(['processing', 'stored'], [item[4] for item in events])
        self.assertEqual(11, events[0][0])
        self.assertEqual(b'generic data', (Path(self.storage.name) / events[-1][7]).read_bytes())
        self.assertEqual([], self.completed)

    def test_new_backup_home_keeps_huawei_processing(self):
        account_uuid = '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'
        home = Path(self.ftp.name, 'accounts', account_uuid, 'incoming')
        home.mkdir(parents=True)
        (home / 'olt.cfg').write_bytes(FIXTURE.read_bytes())
        account = {'id': 12, 'device_id': 7, 'account_uuid': account_uuid,
                   'home_layout': 'account', 'home': str(home), 'purpose': 'backup', 'is_active': True}
        events = []
        def scan_once():
            def receive(*args):
                return {**self.receive(*args), 'ftp_account_id': 12}
            scan(self.ftp.name, self.storage.name, 1, self.observed, [], receive,
                 self.complete, lambda *args: self.failed.append(args), [account], lambda *args: events.append(args))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(home / 'olt.cfg', (time.time() - 3, time.time() - 3))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        scan_once()
        self.assertEqual(1, len(self.completed))
        self.assertEqual('stored', events[0][4])
        self.assertEqual(12, events[0][0])
        self.assertEqual(hashlib.sha256(FIXTURE.read_bytes()).hexdigest(), events[0][6])
        self.assertEqual(len(FIXTURE.read_bytes()), events[0][5])
        self.assertEqual('-', events[0][8])

    def test_r19_without_sysname_is_stored(self):
        data = FIXTURE.read_bytes().replace(b'R021C10B066', b'R019C11B072').replace(b' sysname OLT-LAB', b' no-sysname OLT-LAB')
        (self.home / 'r19.cfg').write_bytes(data)
        self.settle()
        self.assertEqual([], self.failed)
        self.assertEqual(1, len(self.completed))
        self.assertEqual(data, next(Path(self.storage.name).rglob('*.cfg')).read_bytes())
        self.assertEqual([], self.sidecars())

    def test_missing_policy_quarantines_with_account_and_physical_size(self):
        account_uuid = '7312a362-0b55-44bc-b639-cfae6766ff7c'
        home = Path(self.ftp.name, 'accounts', account_uuid, 'incoming')
        home.mkdir(parents=True)
        payload = FIXTURE.read_bytes()
        (home / 'dados6.zip').write_bytes(payload)
        account = {'id': 12, 'device_id': 7, 'account_uuid': account_uuid,
                   'home_layout': 'account', 'home': str(home), 'purpose': 'backup', 'is_active': True}
        events = []
        def scan_once():
            scan(self.ftp.name, self.storage.name, 1, self.observed, [],
                 lambda *_: {'status': 'rejected', 'error_code': 'missing_backup_policy', 'ftp_account_id': 12},
                 self.complete, lambda *args: self.failed.append(args), [account],
                 lambda *args: events.append(args))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(home / 'dados6.zip', (time.time() - 3, time.time() - 3))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        scan_once()
        self.assertEqual(12, self.sidecars()[0]['ftp_account_id'])
        self.assertEqual('missing_backup_policy', self.sidecars()[0]['reason'])
        self.assertEqual(len(payload), events[0][5])
        self.assertEqual('missing_backup_policy', events[0][8])

    def test_file_server_waits_for_readiness_and_retries_claim_with_context(self):
        account_uuid = '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'
        home = Path(self.ftp.name, 'accounts', account_uuid, 'incoming')
        home.mkdir(parents=True)
        (home / 'pending.bin').write_bytes(b'preserve this upload')
        account = {'id': 11, 'device_id': None, 'account_uuid': account_uuid, 'home_layout': 'account',
                   'home': str(home), 'purpose': 'file_server', 'is_active': True, 'ready_for_receive': False}
        online = False
        events = []
        def receipt(*args):
            if not online:
                raise OSError('database unavailable')
            events.append(args)
        def scan_once():
            scan(self.ftp.name, self.storage.name, 1, self.observed, [],
                 lambda *_: self.fail('no backup execution'), self.complete,
                 lambda *args: self.failed.append(args), [account], receipt)
        scan_once()
        self.assertTrue((home / 'pending.bin').exists())
        self.assertEqual({}, self.observed)
        account['ready_for_receive'] = True
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        os.utime(home / 'pending.bin', (time.time() - 3, time.time() - 3))
        scan_once()
        for key, (identity, seen) in self.observed.items():
            self.observed[key] = (identity, seen - 2)
        scan_once()
        sidecars = list(Path(self.ftp.name, 'processing').glob('*.json'))
        self.assertEqual(1, len(sidecars))
        self.assertEqual(1, json.loads(sidecars[0].read_text())['retry_count'])
        self.assertFalse((home / 'pending.bin').exists())
        account['is_active'] = False  # A claim already accepted must still finish after a state change.
        online = True
        scan_once()
        self.assertEqual(['processing', 'stored'], [item[4] for item in events])
        self.assertEqual([], list(Path(self.ftp.name, 'processing').glob('*.json')))
        self.assertEqual(b'preserve this upload', (Path(self.storage.name) / events[-1][7]).read_bytes())


if __name__ == '__main__':
    unittest.main()
