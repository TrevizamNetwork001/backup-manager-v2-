import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from health_snapshot import build_snapshot, write_snapshot
from registry import DriverRegistry
from driver_base import BackupDriver, PROBE, BACKUP


class FakeDriver(BackupDriver):
    name = 'fake_driver'
    capabilities = frozenset({PROBE, BACKUP})


class BrokenDriver:
    """Deliberately missing `capabilities` to exercise the per-driver guard."""
    name = 'broken'


class BuildSnapshotTests(unittest.TestCase):
    def test_reports_registered_drivers_and_capabilities(self):
        registry = DriverRegistry()
        registry.register(('mikrotik', 'network', 'ssh_pull'), FakeDriver())
        snapshot = build_snapshot(registry, 'worker-abc', None)
        self.assertEqual(1, snapshot['driver_count'])
        self.assertEqual(
            {'vendor': 'mikrotik', 'platform': 'network', 'method': 'ssh_pull',
             'name': 'fake_driver', 'capabilities': ['backup', 'probe']},
            snapshot['drivers'][0])
        self.assertEqual('worker-abc', snapshot['worker_id'])
        self.assertIn('python_version', snapshot)
        self.assertIn('engine_version', snapshot)
        self.assertIsNone(snapshot['workspace_writable'])

    def test_one_broken_driver_does_not_blank_the_whole_snapshot(self):
        registry = DriverRegistry()
        registry.register(('mikrotik', 'network', 'ssh_pull'), FakeDriver())
        registry.register(('huawei', 'network', 'ssh_pull'), BrokenDriver())
        snapshot = build_snapshot(registry, 'worker-abc', None)
        self.assertEqual(2, snapshot['driver_count'])
        broken = next(d for d in snapshot['drivers'] if d['vendor'] == 'huawei')
        self.assertIn('error', broken)
        healthy = next(d for d in snapshot['drivers'] if d['vendor'] == 'mikrotik')
        self.assertNotIn('error', healthy)

    def test_workspace_writable_probes_real_directory_and_cleans_up(self):
        registry = DriverRegistry()
        with tempfile.TemporaryDirectory() as root:
            snapshot = build_snapshot(registry, 'worker-abc', root)
            self.assertTrue(snapshot['workspace_writable'])
            self.assertEqual([], list(Path(root).iterdir()))

    def test_workspace_writable_is_false_for_nonexistent_directory(self):
        registry = DriverRegistry()
        snapshot = build_snapshot(registry, 'worker-abc', '/nonexistent/path/for/sure')
        self.assertFalse(snapshot['workspace_writable'])


class WriteSnapshotTests(unittest.TestCase):
    def test_write_is_atomic_and_adds_generated_at(self):
        with tempfile.TemporaryDirectory() as root:
            path = str(Path(root) / 'snapshot.json')
            write_snapshot(path, {'engine_version': '3.0.0'})
            data = json.loads(Path(path).read_text())
            self.assertEqual('3.0.0', data['engine_version'])
            self.assertIsInstance(data['generated_at'], int)
            # No leftover temp files.
            self.assertEqual(['snapshot.json'], [p.name for p in Path(root).iterdir()])

    def test_write_overwrites_previous_snapshot(self):
        with tempfile.TemporaryDirectory() as root:
            path = str(Path(root) / 'snapshot.json')
            write_snapshot(path, {'engine_version': '1'})
            write_snapshot(path, {'engine_version': '2'})
            data = json.loads(Path(path).read_text())
            self.assertEqual('2', data['engine_version'])
            self.assertEqual(['snapshot.json'], [p.name for p in Path(root).iterdir()])


if __name__ == '__main__':
    unittest.main()
