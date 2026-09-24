"""Engine-side health snapshot — ENGINE-3.

Lesson taken directly from the V1 (backup-manager-local) service_snapshot.py
pattern (see docs/ENGINE_HEALTH.md "Comparação com V1"): the Laravel `app`
container has no access to the Python venv or to /engine at all (see
compose.yml — only `engine`/`scheduler` mount `./engine`, and only `engine`
runs the venv), so Laravel cannot exec a Python health command directly.

Instead, the engine process (already running long-lived, already the only
thing with real knowledge of its own Python/driver state) periodically writes
a small, sanitized JSON snapshot to a location Laravel's storage/ already
reads. Laravel's own health check on this is entirely: does the file exist,
and how old is `generated_at`. A stale/missing snapshot IS the "engine is
down" signal — the collector's own liveness is folded into the same file
instead of needing a second heartbeat-of-the-heartbeat.

This module contains no I/O side effects beyond `write_snapshot()`, so
`build_snapshot()` is trivially unit-testable.
"""

import json
import os
import platform
import tempfile
import time

ENGINE_VERSION = '3.0.0'  # bumped alongside ENGINE-* phases that change this contract


def build_snapshot(registry, worker_id, workspace_root):
    """Never raises for a single driver's sake — one bad driver entry is
    reported as such, not allowed to blank out the whole snapshot."""
    drivers = []
    for (vendor, platform_name, method), driver in sorted(registry.all().items()):
        try:
            drivers.append({
                'vendor': vendor, 'platform': platform_name, 'method': method,
                'name': getattr(driver, 'name', 'unknown'),
                'capabilities': sorted(driver.capabilities),
            })
        except Exception as error:
            drivers.append({'vendor': vendor, 'platform': platform_name, 'method': method,
                            'error': type(error).__name__})

    workspace_writable = _probe_writable(workspace_root) if workspace_root else None

    return {
        'engine_version': ENGINE_VERSION,
        'python_version': platform.python_version(),
        'worker_id': worker_id,
        'pid': os.getpid(),
        'driver_count': len(drivers),
        'drivers': drivers,
        'workspace_writable': workspace_writable,
        'generated_at': None,  # filled by write_snapshot() at the moment of writing
    }


def _probe_writable(root):
    """Create-then-delete a tiny marker file — never leaves anything behind,
    never writes anything but a few bytes, always cleaned up even on error."""
    try:
        fd, path = tempfile.mkstemp(prefix='.health-probe-', dir=root)
        try:
            os.write(fd, b'ok')
        finally:
            os.close(fd)
            os.unlink(path)
        return True
    except OSError:
        return False


def _main():
    """`python3 health_snapshot.py` — prints the snapshot to stdout and exits.
    Used directly by the engine's own periodic write (see backup_engine.py)
    and, standalone, by Laravel's Python-contract integration test (see
    docs/ENGINE_HEALTH.md, item 42) to catch a broken/renamed contract without
    needing real equipment, credentials, or the long-running engine process."""
    import registry_setup  # populates registry_setup.registry as a side effect
    import secrets
    import sys
    import time

    worker_id = secrets.token_hex(16)
    snapshot = build_snapshot(registry_setup.registry, worker_id, os.environ.get('BACKUP_STORAGE_ROOT'))
    snapshot['generated_at'] = int(time.time())
    json.dump(snapshot, sys.stdout)


def write_snapshot(path, data):
    """Atomic: temp file in the same directory, fsync, then rename over the
    target — a reader never observes a partially-written snapshot."""
    payload = dict(data, generated_at=int(time.time()))
    directory = os.path.dirname(path) or '.'
    fd, temp_path = tempfile.mkstemp(prefix='.engine_health.', dir=directory)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(payload, handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_path, 0o644)
        os.replace(temp_path, path)
    finally:
        if os.path.exists(temp_path):
            os.unlink(temp_path)

if __name__ == "__main__":
    _main()
