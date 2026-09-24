"""Inspect one device's Pure-FTPd incoming directory and promote a stable upload."""

import os
import json
import logging
import re
import stat
import time
import uuid
from pathlib import Path

from drivers.mikrotik_ssh import BackupError
from storage import store, validate, ftp_max_bytes


NAME = re.compile(r'bm-exec-[1-9][0-9]*\.cfg\Z')
RESERVED = re.compile(r'bm-exec-[1-9][0-9]*\.cfg(?:\.[1-9][0-9]*)?\Z')
STARTUP_GRACE_SECONDS = 30


def directory(root_name, device_id):
    root = Path(root_name).resolve(strict=True)
    if root == Path('/') or not isinstance(device_id, int) or device_id < 1:
        raise BackupError('FTP_STORAGE_FAILED')
    device_root = root / str(device_id)
    home = device_root / 'incoming'
    if device_root.is_symlink() or home.is_symlink() or not home.resolve(strict=False).is_relative_to(device_root):
        raise BackupError('FTP_STORAGE_FAILED')
    return home


def quarantine(root_name, path, reason, *, device_id=None, ftp_account_id=None, original_filename=None):
    if reason not in {'invalid_name', 'uncorrelated', 'invalid_file', 'duplicate', 'invalid_account', 'changed_during_claim', 'processing_failed'}:
        raise ValueError('invalid reason')
    root = Path(root_name).resolve(strict=True)
    target_dir = root / 'quarantine'
    target_dir.mkdir(mode=0o700, exist_ok=True)
    if target_dir.is_symlink() or not target_dir.resolve().is_relative_to(root):
        raise BackupError('FTP_STORAGE_FAILED')
    target = target_dir / (uuid.uuid4().hex + '.quarantine')
    os.replace(path, target)
    sidecar = target.with_suffix('.json')
    with sidecar.open('x', encoding='utf-8') as file:
        os.chmod(sidecar, 0o600)
        json.dump({'reason': reason, 'device_id': device_id, 'ftp_account_id': ftp_account_id,
                   'original_filename': original_filename or path.name, 'quarantined_at': time.time()}, file)
        file.flush()
        os.fsync(file.fileno())
    logging.info(json.dumps({'event': 'ftp_quarantine', 'reason': reason, 'device_id': device_id,
                             'ftp_account_id': ftp_account_id, 'original_filename': original_filename or path.name}))
    return reason


def existing_files(root_name):
    """Give uploads present at startup time to reconnect to an active execution."""
    root = Path(root_name).resolve(strict=True)
    preserved = {}
    started = time.monotonic()
    for device_root in root.iterdir():
        if not device_root.name.isdecimal() or device_root.is_symlink() or not device_root.is_dir():
            continue
        home = device_root / 'incoming'
        if home.is_symlink() or not home.is_dir():
            continue
        for path in home.iterdir():
            info = path.lstat()
            identity = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
            preserved[(device_root.name, path.name)] = (identity, started)
    return preserved


def scan_orphans(root_name, expected, stable_seconds, observed, preserved=None):
    """Quarantine stable late uploads even when their execution has ended."""
    root = Path(root_name).resolve(strict=True)
    allowed = {(str(item['device_id']), item['filename']) for item in expected}
    now = time.monotonic()
    for device_root in root.iterdir():
        if not device_root.name.isdecimal() or device_root.is_symlink() or not device_root.is_dir():
            continue
        home = device_root / 'incoming'
        if home.is_symlink() or not home.is_dir():
            continue
        for path in home.iterdir():
            key = (device_root.name, path.name)
            if not RESERVED.fullmatch(path.name):
                continue  # The spontaneous receiver owns all other names.
            if key in allowed:
                observed.pop(key, None)
                continue
            info = path.lstat()
            identity = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
            if preserved is not None:
                original = preserved.get(key)
                if original is not None and original[0] == identity and now - original[1] < STARTUP_GRACE_SECONDS:
                    continue
                preserved.pop(key, None)
            previous = observed.get(key)
            if previous is None or previous[0] != identity:
                observed[key] = (identity, now)
                continue
            if now - previous[1] >= stable_seconds and time.time_ns() - info.st_mtime_ns >= stable_seconds * 1_000_000_000:
                quarantine(root_name, path, 'uncorrelated' if NAME.fullmatch(path.name) else 'invalid_name',
                           device_id=int(device_root.name))
                observed.pop(key, None)


def receive(root_name, device_id, expected, storage_root, relative, timeout, stable_seconds,
            complete, poll=1.0):
    """Wait only in this execution thread; heartbeat and claiming continue elsewhere."""
    if not NAME.fullmatch(expected) or timeout < 1 or stable_seconds < 1:
        raise BackupError('FTP_STORAGE_FAILED')
    home = directory(root_name, device_id)
    home.mkdir(parents=True, exist_ok=True, mode=0o700)
    deadline = time.monotonic() + timeout
    observed = {}
    while time.monotonic() < deadline:
        for path in list(home.iterdir()):
            info = path.lstat()
            identity = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
            now = time.monotonic()
            previous = observed.get(path.name)
            if previous is None or previous[0] != identity:
                observed[path.name] = (identity, now)
                continue
            if now - previous[1] < stable_seconds or time.time_ns() - info.st_mtime_ns < stable_seconds * 1_000_000_000:
                continue
            if path.name != expected:
                quarantine(root_name, path, 'uncorrelated' if NAME.fullmatch(path.name) else 'invalid_name',
                           device_id=device_id)
                observed.pop(path.name, None)
                continue
            limit = ftp_max_bytes()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= limit:
                quarantine(root_name, path, 'invalid_file', device_id=device_id)
                raise BackupError('FTP_FILE_INVALID')
            try:
                with path.open('rb') as handle:
                    opened = os.fstat(handle.fileno())
                    if (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns) != identity:
                        observed.pop(path.name, None)
                        continue
                    data = handle.read(limit + 1)
                validate(data, 'huawei_olt')
                if path.lstat().st_mtime_ns != info.st_mtime_ns or path.lstat().st_size != info.st_size:
                    observed.pop(path.name, None)
                    continue
                final_relative = store(storage_root, relative, data, 'huawei_olt',
                                       int(expected[len('bm-exec-'):-len('.cfg')]))
                complete(final_relative)
                try:
                    path.unlink()
                except OSError:
                    logging.error(json.dumps({'event': 'ftp_incoming_cleanup_failed'}))
                return
            except BackupError as error:
                quarantine(root_name, path, 'invalid_file', device_id=device_id)
                code = 'FTP_FILE_INVALID' if error.code in {'FTP_FILE_INVALID', 'ARTIFACT_INVALID'} else 'FTP_STORAGE_FAILED'
                raise BackupError(code) from None
            except Exception:
                quarantine(root_name, path, 'invalid_file', device_id=device_id)
                raise BackupError('FTP_STORAGE_FAILED') from None
        time.sleep(min(poll, max(0, deadline - time.monotonic())))
    raise BackupError('FTP_RECEIVE_TIMEOUT')
