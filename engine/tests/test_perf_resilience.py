"""Synthetic crash/outage regressions: no equipment or application database."""

import errno
import json
import multiprocessing
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from ftp_spontaneous import claim, process, process_file_server, scan, store_claimed
from storage import store
from errors import BackupError


class PublicationRecoveryTests(unittest.TestCase):
    def test_restart_after_link_publication_cleans_only_matching_temp_inode(self):
        for purpose in ('backup', 'file_server'):
            with self.subTest(purpose=purpose), tempfile.TemporaryDirectory() as ftp, tempfile.TemporaryDirectory() as storage:
                home = Path(ftp, '7', 'incoming')
                home.mkdir(parents=True)
                source = home / 'upload.bin'
                source.write_bytes(b'synthetic payload')
                staged, metadata, record = claim(ftp, source, source.stat(), 7, 11, purpose,
                    '4a923771-0d3d-4cd2-b3af-bcdbf2036d20')
                response = {'id': 9, 'status': 'running', 'ftp_account_id': 11,
                    'relative_path': 'Backup Manager/LAB/OLT/28-09-2026/OLT_20260928120000.cfg'}
                original_link = os.link
                def crash():
                    def publish(*args, **kwargs):
                        original_link(*args, **kwargs)
                        os._exit(92)
                    with patch('ftp_spontaneous.os.link', side_effect=publish):
                        process(ftp, storage, staged, metadata, record, lambda *_: response,
                                lambda *_: None, lambda *_: None, lambda *_: None)
                worker = multiprocessing.get_context('fork').Process(target=crash)
                worker.start()
                worker.join(5)
                self.assertFalse(worker.is_alive())
                self.assertEqual(92, worker.exitcode)
                published = next(path for path in Path(storage).rglob('*')
                                 if path.is_file() and not path.name.startswith('.'))
                unrelated = published.parent / '.partial-unrelated'
                unrelated.write_bytes(b'preserve unrelated temp')
                events = []
                process(ftp, storage, staged, metadata, json.loads(metadata.read_text()), lambda *_: response,
                        lambda *_: None, lambda *_: self.fail('unexpected failure'), lambda *args: events.append(args))
                self.assertEqual('stored', events[-1][4])
                self.assertEqual(1, Path(storage, events[-1][7]).stat().st_nlink)
                self.assertEqual(b'synthetic payload', Path(storage, events[-1][7]).read_bytes())
                self.assertEqual(b'preserve unrelated temp', unrelated.read_bytes())

    def test_file_server_restart_after_crash_before_atomic_publish(self):
        with tempfile.TemporaryDirectory() as ftp, tempfile.TemporaryDirectory() as storage:
            home = Path(ftp, '7', 'incoming')
            home.mkdir(parents=True)
            source = home / 'firmware.bin'
            source.write_bytes(b'synthetic firmware')
            staged, metadata, record = claim(ftp, source, source.stat(), None, 11, 'file_server',
                '4a923771-0d3d-4cd2-b3af-bcdbf2036d20')
            def crash():
                with patch('ftp_spontaneous.os.link', side_effect=lambda *_args, **_kwargs: os._exit(91)):
                    process_file_server(ftp, storage, staged, metadata, record, lambda *_: None)
            worker = multiprocessing.get_context('fork').Process(target=crash)
            worker.start()
            worker.join(5)
            self.assertFalse(worker.is_alive())
            self.assertEqual(91, worker.exitcode)
            events = []
            process_file_server(ftp, storage, staged, metadata, json.loads(metadata.read_text()),
                                lambda *args: events.append(args))
            self.assertEqual('stored', events[-1][4])
            self.assertEqual(b'synthetic firmware', Path(storage, events[-1][7]).read_bytes())
            self.assertEqual([], list(Path(storage).rglob('*.tmp')))
            self.assertEqual([], list(Path(ftp, 'processing').iterdir()))

    def test_publication_crash_gap_reuses_only_own_unchanged_file(self):
        with tempfile.TemporaryDirectory() as storage:
            base = 'Backup Manager/LAB/OLT/28-09-2026/OLT_20260928120000.cfg'
            data = b'synthetic configuration'
            first = store_claimed(storage, base, data, 9)
            inode = Path(storage, first).stat().st_ino
            self.assertEqual(first, store_claimed(storage, base, data, 9))
            self.assertEqual(inode, Path(storage, first).stat().st_ino)
            self.assertNotEqual(first, store_claimed(storage, base, data, 10))
            with self.assertRaises(BackupError):
                store_claimed(storage, base, b'changed', 9)
            self.assertEqual(data, Path(storage, first).read_bytes())
            Path(storage, first).unlink()
            Path(storage, first).symlink_to(Path(storage, base.replace('.cfg', '-exec-10.cfg')))
            with self.assertRaises(BackupError):
                store_claimed(storage, base, data, 9)

    def test_repeated_completion_outage_recovers_one_file_and_one_receipt(self):
        with tempfile.TemporaryDirectory() as ftp, tempfile.TemporaryDirectory() as storage:
            home = Path(ftp, '7', 'incoming')
            home.mkdir(parents=True)
            source = home / 'backup.cfg'
            source.write_bytes(b'synthetic configuration')
            staged, metadata, record = claim(ftp, source, source.stat(), 7, 11)
            response = {'id': 9, 'status': 'running', 'ftp_account_id': 11,
                        'relative_path': 'Backup Manager/LAB/OLT/28-09-2026/OLT_20260928120000.cfg'}
            receipts = {}
            failures = []
            def receipt(*args):
                receipts[args[1]] = args
            def offline(*args):
                raise RuntimeError('synthetic completion outage')
            for _ in range(5):
                with self.assertRaises(RuntimeError):
                    process(ftp, storage, staged, metadata, record, lambda *_: response,
                            offline, lambda *args: failures.append(args), receipt)
                self.assertEqual(1, len(list(Path(storage).rglob('*.cfg'))))
                self.assertTrue(staged.exists())
            completed = []
            def complete(job, relative):
                completed.append((job, relative))
                response['status'] = 'succeeded'
            # Restart discards in-memory context, but retains the durable sidecar.
            record = json.loads(metadata.read_text())
            process(ftp, storage, staged, metadata, record, lambda *_: response,
                    complete, lambda *args: failures.append(args), receipt)
            self.assertEqual(1, len(completed))
            self.assertEqual(1, len(receipts))
            self.assertEqual(completed[0][1], next(iter(receipts.values()))[7])
            self.assertEqual([], failures)
            self.assertEqual([], list(Path(ftp, 'processing').iterdir()))

    def test_receipt_outage_after_commit_recovers_actual_published_path(self):
        with tempfile.TemporaryDirectory() as ftp, tempfile.TemporaryDirectory() as storage:
            home = Path(ftp, '7', 'incoming')
            home.mkdir(parents=True)
            source = home / 'backup.cfg'
            source.write_bytes(b'synthetic configuration')
            staged, metadata, record = claim(ftp, source, source.stat(), 7, 11)
            response = {'id': 9, 'status': 'running', 'ftp_account_id': 11,
                        'relative_path': 'Backup Manager/LAB/OLT/28-09-2026/OLT_20260928120000.cfg'}
            store(storage, response['relative_path'], b'another execution')
            committed = []
            def complete(job, relative):
                committed.append(relative)
                response['status'] = 'succeeded'
            with self.assertRaises(RuntimeError):
                process(ftp, storage, staged, metadata, record, lambda *_: response,
                        complete, lambda *_: self.fail('unexpected failure'),
                        lambda *_: (_ for _ in ()).throw(RuntimeError('receipt outage')))
            events = []
            scan(ftp, storage, 0, {}, [], lambda *_: response, complete,
                 lambda *_: self.fail('unexpected failure'), receipt=lambda *args: events.append(args))
            self.assertEqual(1, len(committed))
            self.assertEqual(committed[0], events[0][7])
            self.assertEqual(b'synthetic configuration', Path(storage, events[0][7]).read_bytes())

    def test_storage_enospc_cleans_partial_and_preserves_existing(self):
        with tempfile.TemporaryDirectory() as root:
            target = Path(root, 'existing.cfg')
            target.write_bytes(b'preserve')
            with patch('storage.os.fsync', side_effect=OSError(errno.ENOSPC, 'synthetic full disk')):
                with self.assertRaises(BackupError) as failure:
                    store(root, 'new.cfg', b'new')
            self.assertEqual('STORAGE_FAILED', failure.exception.code)
            self.assertEqual(b'preserve', target.read_bytes())
            self.assertFalse(Path(root, 'new.cfg').exists())
            self.assertEqual([], list(Path(root).glob('.partial-*')))
