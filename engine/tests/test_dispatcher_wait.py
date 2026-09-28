import os
import threading
import tempfile
import unittest
from unittest.mock import patch

import backup_engine


class Finished(BaseException):
    pass


class DispatcherWaitTest(unittest.TestCase):
    def test_slow_scanner_does_not_block_claim_or_spawn_overlapping_scans(self):
        started, release, finished = threading.Event(), threading.Event(), threading.Event()
        observations = []

        def scan(*args, **kwargs):
            started.set()
            release.wait(timeout=2)
            finished.set()

        def command(*args):
            if args[0] == 'engine:claim':
                observations.append(started.wait(timeout=2) and not finished.is_set())
                release.set()
                raise Finished()
            return b'[]'

        with tempfile.TemporaryDirectory() as root, \
                patch.dict(os.environ, {'BACKUP_FTP_ROOT': root, 'BACKUP_STORAGE_ROOT': root}), \
                patch.object(backup_engine, 'HEALTH_SNAPSHOT_PATH', ''), \
                patch.object(backup_engine.ArtisanSession, 'command', side_effect=command), \
                patch.object(backup_engine, 'scan_spontaneous', side_effect=scan) as scanner, \
                patch.object(backup_engine, 'scan_orphans'):
            with self.assertRaises(Finished):
                backup_engine.main()
        self.assertEqual([True], observations)
        self.assertTrue(finished.is_set())
        scanner.assert_called_once()

    def test_busy_device_wait_wakes_on_completion_instead_of_idle_sleep(self):
        gate = threading.Event()
        calls = []
        completed = []

        def command(*args):
            calls.append(args[0])
            if len(calls) == 1:
                return b'{"id":1}'
            if len(calls) == 2:
                gate.set()
                return b'null'
            if len(calls) == 3:
                self.assertIn(1, completed)
                return b'{"id":2}'
            raise Finished()

        def execute(job):
            if job['id'] == 1:
                self.assertTrue(gate.wait(timeout=2))
            completed.append(job['id'])

        with patch.dict(os.environ, {'BACKUP_FTP_ROOT': ''}), \
                patch.object(backup_engine, 'HEALTH_SNAPSHOT_PATH', ''), \
                patch.object(backup_engine, 'WORKERS', 4), \
                patch.object(backup_engine.ArtisanSession, 'command', side_effect=command), \
                patch.object(backup_engine, 'execute', side_effect=execute), \
                patch.object(backup_engine.time, 'sleep') as sleep:
            with self.assertRaises(Finished):
                backup_engine.main()
            sleep.assert_not_called()
        self.assertEqual([1, 2], completed)
