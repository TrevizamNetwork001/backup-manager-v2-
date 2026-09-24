"""Receive autonomous OLT uploads. The chroot directory, never the filename, identifies the device."""

import json
import logging
import os
import re
import stat
import time
import uuid
from pathlib import Path

from drivers.mikrotik_ssh import BackupError
from ftp_incoming import RESERVED, directory, quarantine
from storage import ftp_max_bytes, store, validate


TOKEN = re.compile(r'[a-f0-9]{32}\.json\Z')


def safe_name(name):
    try:
        return (name not in ('.', '..') and len(name.encode('utf-8')) <= 255 and
                not any(ord(char) < 32 or ord(char) == 127 or char in '/\\' for char in name))
    except UnicodeError:
        return False


def identity(info):
    return info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_nlink, info.st_mode


def stage_directory(root_name):
    root = Path(root_name).resolve(strict=True)
    stage = root / 'processing'
    if stage.is_symlink():
        raise BackupError('FTP_STORAGE_FAILED')
    stage.mkdir(mode=0o700, exist_ok=True)
    if not stage.resolve().is_relative_to(root):
        raise BackupError('FTP_STORAGE_FAILED')
    return stage


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def claim(root_name, path, info, device_id):
    stage = stage_directory(root_name)
    token = uuid.uuid4().hex
    staged = stage / token
    metadata = stage / (token + '.json')
    record = {'device_id': device_id, 'original_filename': path.name,
              'received_at': int(info.st_mtime), 'identity': identity(info)}
    # Metadata precedes the rename, so a restart can recover every claimed file.
    with metadata.open('x', encoding='utf-8') as handle:
        os.chmod(metadata, 0o600)
        json.dump(record, handle)
        handle.flush()
        os.fsync(handle.fileno())
    sync_directory(stage)
    os.replace(path, staged)
    sync_directory(stage)
    sync_directory(path.parent)
    if identity(staged.lstat()) != identity(info):
        quarantine(root_name, staged, 'changed_during_claim', device_id=device_id,
                   original_filename=path.name)
        metadata.unlink()
        return None
    return staged, metadata, record


def process(root_name, storage_root, staged, metadata, record, receive, complete, fail):
    token = staged.name
    device_id = record['device_id']
    filename = record['original_filename']
    response = receive(device_id, token, filename, record['received_at'])
    if response is None:
        quarantine(root_name, staged, 'invalid_account', device_id=device_id, original_filename=filename)
        metadata.unlink()
        return
    job_id = response['id']
    account_id = response.get('ftp_account_id')
    if response['status'] == 'succeeded':
        staged.unlink()
        metadata.unlink()
        return
    if response['status'] != 'running':
        quarantine(root_name, staged, 'processing_failed', device_id=device_id,
                   ftp_account_id=account_id, original_filename=filename)
        metadata.unlink()
        return
    try:
        info = staged.lstat()
        if identity(info) != tuple(record['identity']) or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= ftp_max_bytes():
            raise BackupError('FTP_FILE_INVALID')
        fd = os.open(staged, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            opened = os.fstat(fd)
            if identity(opened) != identity(info):
                raise BackupError('FTP_FILE_INVALID')
            with os.fdopen(fd, 'rb', closefd=False) as handle:
                data = handle.read(ftp_max_bytes() + 1)
            if identity(os.fstat(fd)) != identity(info):
                raise BackupError('FTP_FILE_INVALID')
        finally:
            os.close(fd)
        validate(data, 'huawei_olt')
        relative = store(storage_root, response['relative_path'], data, 'huawei_olt', job_id)
        complete(job_id, relative)
    except BackupError as error:
        reason = 'invalid_file' if error.code in ('FTP_FILE_INVALID', 'ARTIFACT_INVALID') else 'processing_failed'
        quarantine(root_name, staged, reason, device_id=device_id,
                   ftp_account_id=account_id, original_filename=filename)
        metadata.unlink()
        fail(job_id, 'FTP_FILE_INVALID' if reason == 'invalid_file' else 'FTP_STORAGE_FAILED')
        return
    # If DB completion fails, leave the stage and claim token for restart/retry.
    staged.unlink()
    metadata.unlink()
    logging.info(json.dumps({'event': 'ftp_received', 'execution_id': job_id, 'device_id': device_id}))


def scan(root_name, storage_root, stable_seconds, observed, expected, receive, complete, fail):
    root = Path(root_name).resolve(strict=True)
    stage = stage_directory(root_name)
    for metadata in stage.iterdir():
        if not TOKEN.fullmatch(metadata.name) or metadata.is_symlink():
            continue
        staged = stage / metadata.stem
        if not staged.exists() and not staged.is_symlink():
            metadata.unlink()  # Crash before the rename; the source is still in incoming.
            continue
        record = json.loads(metadata.read_text(encoding='utf-8'))
        process(root_name, storage_root, staged, metadata, record, receive, complete, fail)
    now = time.monotonic()
    for device_root in root.iterdir():
        if not device_root.name.isdecimal() or device_root.is_symlink() or not device_root.is_dir():
            continue
        device_id = int(device_root.name)
        home = directory(root_name, device_id)
        if not home.is_dir():
            continue
        for path in home.iterdir():
            key = (device_root.name, path.name)
            if RESERVED.fullmatch(path.name):
                continue  # Reserved for the manual diagnostic receiver.
            try:
                info = path.lstat()
            except FileNotFoundError:
                continue
            current = identity(info)
            previous = observed.get(key)
            if previous is None or previous[0] != current:
                observed[key] = (current, now)
                continue
            if now - previous[1] < stable_seconds or time.time_ns() - info.st_mtime_ns < stable_seconds * 1_000_000_000:
                continue
            observed.pop(key, None)
            if not safe_name(path.name):
                quarantine(root_name, path, 'invalid_name', device_id=device_id)
                continue
            try:
                claimed = claim(root_name, path, info, device_id)
            except FileNotFoundError:
                continue
            if claimed:
                process(root_name, storage_root, *claimed, receive, complete, fail)
