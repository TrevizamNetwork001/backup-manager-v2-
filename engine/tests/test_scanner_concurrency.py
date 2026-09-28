import hashlib
import tempfile
import threading
import unittest
import time
from collections import Counter
from pathlib import Path
from unittest.mock import patch

from ftp_spontaneous import scan


class ScannerConcurrencyTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.root = Path(self.workspace.name)
        self.ftp = self.root / 'ftp'
        self.storage = self.root / 'backups'
        self.home = self.ftp / '7/incoming'
        self.home.mkdir(parents=True)
        self.storage.mkdir()
        self.account = {'id': 11, 'device_id': 7, 'home_layout': 'legacy', 'home': str(self.home),
                        'purpose': 'file_server', 'is_active': True, 'ready_for_receive': True,
                        'account_uuid': '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'}
        self.observed = {}
        self.payload = b'# synthetic scanner payload\n'

    def run_scan(self, receipt, observed=None):
        scan(str(self.ftp), str(self.storage), 0, self.observed if observed is None else observed, [],
             lambda *args: self.fail('file_server called receive'),
             lambda *args: self.fail('file_server called complete'),
             lambda *args: self.fail('file_server called fail'), [self.account], receipt, workers=2)

    def test_large_batch_has_two_workers_unique_tokens_and_no_reprocessing(self):
        for index in range(256):
            (self.home / f'upload-{index}.cfg').write_bytes(self.payload)
        barrier = threading.Barrier(2)
        lock = threading.Lock()
        active, peak = 0, 0
        stored = {}
        processing = Counter()

        def receipt(*args):
            nonlocal active, peak
            if args[4] == 'processing':
                with lock:
                    processing[args[1]] += 1
                    active += 1
                    peak = max(peak, active)
                barrier.wait(timeout=5)
            if args[4] == 'stored':
                with lock:
                    active -= 1
                    stored[args[1]] = args

        self.run_scan(receipt)
        self.run_scan(receipt)
        self.run_scan(receipt)
        self.assertEqual(2, peak)
        self.assertEqual(0, active)
        self.assertEqual(256, len(stored))
        self.assertEqual({1}, set(processing.values()))
        self.assertEqual([], list((self.ftp / 'processing').iterdir()))
        for args in stored.values():
            self.assertEqual(hashlib.sha256(self.payload).hexdigest(), args[6])
            self.assertEqual(self.payload, (self.storage / args[7]).read_bytes())

    def test_overlapping_scan_cannot_reprocess_a_pending_token(self):
        (self.home / 'upload.cfg').write_bytes(self.payload)
        entered, release = threading.Event(), threading.Event()
        calls = []

        def receipt(*args):
            calls.append(args[4])
            if args[4] == 'processing':
                entered.set()
                if not release.wait(timeout=5):
                    raise RuntimeError('test gate timed out')

        self.run_scan(receipt)
        worker = threading.Thread(target=self.run_scan, args=(receipt,))
        worker.start()
        try:
            self.assertTrue(entered.wait(timeout=2))
            self.run_scan(receipt, observed={})
            self.assertEqual(['processing'], calls)
        finally:
            release.set()
            worker.join(timeout=5)
        self.assertFalse(worker.is_alive())
        self.run_scan(receipt)
        self.assertEqual(['processing', 'stored'], calls)

    def test_parallel_scanner_keeps_one_processing_task_per_backup_device(self):
        self.assert_backup_tasks_serial('backup')

    def test_legacy_null_purpose_keeps_backup_device_serial(self):
        self.assert_backup_tasks_serial(None)

    def assert_backup_tasks_serial(self, purpose):
        account = dict(self.account, purpose=purpose)
        other_home = self.ftp / '8/incoming'
        other_home.mkdir(parents=True)
        other = dict(account, id=12, device_id=8, home=str(other_home))
        for index in range(256):
            (self.home / f'upload-{index}.cfg').write_bytes(self.payload)
        active, peak = 0, 0
        lock = threading.Lock()

        def process(root, storage, staged, metadata, record, *callbacks):
            nonlocal active, peak
            with lock:
                active += 1
                peak = max(peak, active)
            time.sleep(.001)
            staged.unlink()
            metadata.unlink()
            with lock:
                active -= 1

        with patch('ftp_spontaneous.process', side_effect=process) as processor:
            for _ in range(3):
                scan(str(self.ftp), str(self.storage), 0, self.observed, [], None, None, None,
                     [account, other], workers=2)
        self.assertEqual(256, processor.call_count)
        self.assertEqual(1, peak)
        self.assertEqual([], list((self.ftp / 'processing').iterdir()))
