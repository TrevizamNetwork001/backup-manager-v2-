"""Promote a completed A10 SCP upload into the official artifact storage."""

import stat
import time
from pathlib import Path

from a10_scp import ARCHIVE_NAME, MAX_ARCHIVE_BYTES
from errors import BackupError
from storage import store, validate_received_file_integrity


def inbox_path(root_name, filename):
    if not ARCHIVE_NAME.fullmatch(filename or ''):
        raise BackupError('ARTIFACT_INVALID')
    root = Path(root_name)
    inbox = root / 'incoming'
    if root.is_symlink() or inbox.is_symlink() or not inbox.is_dir() or root.resolve() == Path('/'):
        raise BackupError('STORAGE_FAILED')
    if not inbox.resolve().is_relative_to(root.resolve()):
        raise BackupError('STORAGE_FAILED')
    return inbox / filename


def wait_for_archive(root_name, filename, timeout=180, stable_seconds=5, cancel_check=None, poll=0.5):
    path = inbox_path(root_name, filename)
    deadline = time.monotonic() + timeout
    observed = None
    while time.monotonic() < deadline:
        if cancel_check and cancel_check():
            raise BackupError('CANCELLED')
        try:
            info = path.lstat()
        except FileNotFoundError:
            observed = None
            time.sleep(poll)
            continue
        identity = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_nlink, info.st_mode)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= MAX_ARCHIVE_BYTES:
            raise BackupError('ARTIFACT_INVALID')
        now = time.monotonic()
        if observed is not None and observed[0] == identity and now - observed[1] >= stable_seconds and \
                time.time_ns() - info.st_mtime_ns >= stable_seconds * 1_000_000_000:
            try:
                data, digest = validate_received_file_integrity(path, identity, MAX_ARCHIVE_BYTES)
            except BackupError:
                observed = None
                continue
            return path, identity, data, digest
        if observed is None or observed[0] != identity:
            observed = (identity, now)
        time.sleep(poll)
    raise BackupError('A10_RECEIVE_TIMEOUT')


def store_archive(storage_root, relative, data, execution_id):
    if not relative.endswith('.tar.gz') or execution_id < 1:
        raise BackupError('STORAGE_FAILED')
    root = Path(storage_root).resolve(strict=True)
    base = root / relative
    target = base.with_name(base.name[:-7] + f'-exec-{execution_id}.tar.gz')
    if not target.resolve(strict=False).is_relative_to(root):
        raise BackupError('STORAGE_FAILED')
    cursor = root
    for part in Path(relative).parts:
        cursor /= part
        if cursor.is_symlink():
            raise BackupError('STORAGE_FAILED')
    if target.exists() or target.is_symlink():
        existing, _ = validate_received_file_integrity(target, limit=MAX_ARCHIVE_BYTES)
        if existing == data:
            return str(target.relative_to(root))
        raise BackupError('STORAGE_FAILED')
    return store(storage_root, str(target.relative_to(root)), data, 'a10')


def remove_received(path, identity):
    """Remove the inbox copy only after Laravel committed the artifact."""
    try:
        info = path.lstat()
    except FileNotFoundError:
        return
    current = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_nlink, info.st_mode)
    if current == identity:
        path.unlink()
