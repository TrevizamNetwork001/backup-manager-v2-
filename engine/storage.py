import os
import re
import tempfile
from pathlib import Path

from drivers.mikrotik_ssh import BackupError, MAX_BYTES


def relative_path(job):
    # The path is derived only from immutable numeric IDs and the engine's UTC date.
    from datetime import datetime, timezone
    date = datetime.now(timezone.utc).strftime('%Y/%m/%d')
    return f"{int(job['device_id'])}/{date}/execution-{int(job['id'])}-config.rsc"


def validate(data):
    if not data or len(data) > MAX_BYTES:
        raise BackupError('ARTIFACT_INVALID')
    try:
        preview = data[:4096].decode('utf-8')
        data.decode('utf-8')
    except UnicodeDecodeError:
        raise BackupError('ARTIFACT_INVALID') from None
    if b'\x00' in data or not re.search(r'(?mi)^/[a-z]', preview):
        raise BackupError('ARTIFACT_INVALID')


def store(root_name, relative, data):
    validate(data)
    root = Path(root_name).resolve(strict=True)
    if root == Path('/'):
        raise BackupError('STORAGE_FAILED')
    target = root / relative
    if not target.resolve(strict=False).is_relative_to(root) or target.is_symlink():
        raise BackupError('STORAGE_FAILED')
    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if not target.parent.resolve(strict=True).is_relative_to(root):
        raise BackupError('STORAGE_FAILED')
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=target.parent, prefix='.partial-', delete=False) as file:
            temporary = Path(file.name)
            os.chmod(file.name, 0o600)
            file.write(data)
            file.flush()
            os.fsync(file.fileno())
        os.replace(temporary, target)
        os.chmod(target, 0o600)
        return relative
    except OSError:
        raise BackupError('STORAGE_FAILED') from None
    finally:
        if temporary and temporary.exists():
            temporary.unlink()
