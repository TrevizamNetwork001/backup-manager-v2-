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
from storage import analyze_content, store, validate_received_file_integrity


TOKEN = re.compile(r'[a-f0-9]{32}\.json\Z')

# STABILIZATION-1 (P1 finding, V1 lesson): discovery previously relied only on
# the size/mtime stability window below — a file that lands with one of these
# well-known "still transferring" suffixes (some FTP clients/servers use them
# for an in-flight upload, then rename atomically on completion) could go
# stable mid-transfer if the transfer stalls for longer than stable_seconds,
# and get claimed/stored as a "succeeded" truncated backup. V1
# (backup_manager/ftp_pipeline.py TEMP_SUFFIXES) filters these at discovery
# time in addition to its own stability window — same defense-in-depth here.
# This is a discovery-time skip, not a rejection: a file with one of these
# suffixes is simply never considered until (if ever) it's renamed away from
# it, exactly like a dotfile is already skipped by RESERVED/safe_name.
IN_PROGRESS_SUFFIXES = ('.part', '.tmp', '.partial', '.filepart', '.upload')


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


# V1 lesson (backup_manager/ftp_importer.py): a file claimed into 'processing'
# with no path back to 'waiting_stable' retries forever if it can never
# succeed (e.g. Laravel unreachable for days, or a permanently malformed
# sidecar) — nothing there ever gives up. Cap it: after this many failed scan
# cycles, quarantine instead of retrying again.
MAX_PROCESSING_RETRIES = 20


def remember_failure(root_name, staged, metadata, record, error, receipt):
    code = error.code if isinstance(error, BackupError) else 'FTP_PROCESSING_RETRY'
    record['retry_count'] = int(record.get('retry_count', 0)) + 1
    record['last_error'] = code
    record['last_attempt_at'] = int(time.time())
    if record['retry_count'] > MAX_PROCESSING_RETRIES:
        quarantine(root_name, staged, 'processing_retry_exhausted', device_id=record.get('device_id'),
                   ftp_account_id=record.get('account_id'), original_filename=record.get('original_filename'))
        metadata.unlink(missing_ok=True)
        logging.error(json.dumps({'event': 'ftp_processing_retry_exhausted', 'claim_token': metadata.stem,
                                  'account_id': record.get('account_id'), 'attempts': record['retry_count'], 'code': code}))
        return
    temporary = metadata.with_name('.' + metadata.name + '.' + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(record, handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, metadata)
        sync_directory(metadata.parent)
    finally:
        temporary.unlink(missing_ok=True)
    if receipt and record.get('purpose') == 'file_server' and record.get('account_id'):
        try:
            receipt(record['account_id'], metadata.stem, record['original_filename'], record['received_at'],
                    'processing', record['identity'][2], '-', '-', code)
        except Exception:
            pass  # The durable sidecar remains the retry record while the database is unavailable.
    logging.error(json.dumps({'event': 'ftp_processing_retry', 'claim_token': metadata.stem,
                              'account_id': record.get('account_id'), 'attempt': record['retry_count'], 'code': code}))


def claim(root_name, path, info, device_id, account_id=None, purpose='backup', account_uuid=None):
    stage = stage_directory(root_name)
    token = uuid.uuid4().hex
    staged = stage / token
    metadata = stage / (token + '.json')
    record = {'device_id': device_id, 'account_id': account_id, 'purpose': purpose, 'account_uuid': account_uuid,
              'original_filename': path.name,
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
        quarantine(root_name, staged, 'changed_during_claim', device_id=device_id, ftp_account_id=account_id,
                   original_filename=path.name)
        metadata.unlink()
        return None
    return staged, metadata, record


def process(root_name, storage_root, staged, metadata, record, receive, complete, fail, receipt=None):
    token = staged.name
    device_id = record['device_id']
    filename = record['original_filename']
    account_id = record.get('account_id')
    if record.get('purpose') == 'file_server':
        process_file_server(root_name, storage_root, staged, metadata, record, receipt)
        return
    response = receive(device_id, token, filename, record['received_at'])
    if response is None or response.get('status') == 'rejected':
        reason = (response or {}).get('error_code', 'invalid_account')
        account_id = (response or {}).get('ftp_account_id') or account_id
        size = record['identity'][2] if identity(staged.lstat()) == tuple(record['identity']) else 0
        quarantine(root_name, staged, reason, device_id=device_id, ftp_account_id=account_id, original_filename=filename)
        if receipt and account_id:
            receipt(account_id, token, filename, record['received_at'], 'quarantined', size, '-', '-', reason)
        metadata.unlink()
        return
    job_id = response['id']
    account_id = response.get('ftp_account_id') or account_id
    if response['status'] == 'succeeded':
        if receipt and account_id:
            data, digest = validate_received_file_integrity(staged, record['identity'])
            receipt(account_id, token, filename, record['received_at'], 'stored', len(data), digest, response['relative_path'], '-')
        staged.unlink()
        metadata.unlink()
        return
    if response['status'] != 'running':
        quarantine(root_name, staged, 'processing_failed', device_id=device_id,
                   ftp_account_id=account_id, original_filename=filename)
        if receipt and account_id:
            receipt(account_id, token, filename, record['received_at'], 'quarantined', record['identity'][2], '-', '-', 'processing_failed')
        metadata.unlink()
        return
    try:
        data, digest = validate_received_file_integrity(staged, record['identity'])
        relative = store(storage_root, response['relative_path'], data, 'ftp', job_id)
        complete(job_id, relative)
        if receipt and account_id:
            receipt(account_id, token, filename, record['received_at'], 'stored', len(data), digest, relative, '-')
    except BackupError as error:
        reason = 'invalid_file' if error.code in ('FTP_FILE_INVALID', 'ARTIFACT_INVALID') else 'processing_failed'
        quarantine(root_name, staged, reason, device_id=device_id,
                   ftp_account_id=account_id, original_filename=filename)
        metadata.unlink()
        fail(job_id, 'FTP_FILE_INVALID' if reason == 'invalid_file' else 'FTP_STORAGE_FAILED')
        if receipt and account_id:
            receipt(account_id, token, filename, record['received_at'], 'quarantined', info.st_size if 'info' in locals() else 0, '-', '-', reason)
        return
    # If DB completion fails, leave the stage and claim token for restart/retry.
    staged.unlink()
    metadata.unlink()
    try:
        analysis = analyze_content(data, 'huawei_olt')
        logging.info(json.dumps({'event': 'ftp_received', 'execution_id': job_id, 'device_id': device_id,
                                 'content_analysis_status': analysis['status'], 'content_analysis_message': analysis['message']}))
    except Exception:
        logging.warning(json.dumps({'event': 'ftp_content_analysis_unavailable', 'execution_id': job_id}))


def process_file_server(root_name, storage_root, staged, metadata, record, receipt):
    if receipt is None or record.get('account_id') is None:
        raise BackupError('FTP_STORAGE_FAILED')
    token = staged.name
    account_id = record['account_id']
    filename = record['original_filename']
    if not re.fullmatch(r'[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}', str(record.get('account_uuid'))):
        raise BackupError('FTP_STORAGE_FAILED')
    receipt(account_id, token, filename, record['received_at'], 'processing', record['identity'][2], '-', '-', '-')
    try:
        data, digest = validate_received_file_integrity(staged, record['identity'])
        root = Path(storage_root).resolve(strict=True)
        target_dir = root / 'ftp-files' / record['account_uuid']
        if target_dir.parent.is_symlink() or target_dir.is_symlink():
            raise BackupError('FTP_STORAGE_FAILED')
        target_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        if not target_dir.resolve().is_relative_to(root):
            raise BackupError('FTP_STORAGE_FAILED')
        relative = 'ftp-files/' + record['account_uuid'] + '/' + token
        target = root / relative
        temporary = target_dir / ('.' + token + '.tmp')
        if target.exists() or target.is_symlink():
            previous = target.lstat()
            if not stat.S_ISREG(previous.st_mode) or previous.st_nlink != 1 or previous.st_size != len(data):
                raise BackupError('FTP_STORAGE_FAILED')
            existing_fd = os.open(target, os.O_RDONLY | os.O_NOFOLLOW)
            try:
                if identity(os.fstat(existing_fd)) != identity(previous) or os.read(existing_fd, len(data) + 1) != data:
                    raise BackupError('FTP_STORAGE_FAILED')
            finally:
                os.close(existing_fd)
        else:
            out = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            try:
                with os.fdopen(out, 'wb') as handle:
                    handle.write(data)
                    handle.flush()
                    os.fsync(handle.fileno())
                os.link(temporary, target, follow_symlinks=False)
            finally:
                temporary.unlink(missing_ok=True)
        receipt(account_id, token, filename, record['received_at'], 'stored', len(data), digest, relative, '-')
    except BackupError as error:
        receipt(account_id, token, filename, record['received_at'], 'quarantined', 0, '-', '-', error.code)
        quarantine(root_name, staged, 'invalid_file' if error.code == 'FTP_FILE_INVALID' else 'processing_failed', ftp_account_id=account_id, original_filename=filename)
        metadata.unlink()
        return
    staged.unlink()
    metadata.unlink()


def scan(root_name, storage_root, stable_seconds, observed, expected, receive, complete, fail, accounts=None, receipt=None):
    root = Path(root_name).resolve(strict=True)
    stage = stage_directory(root_name)
    for metadata in stage.iterdir():
        if not TOKEN.fullmatch(metadata.name) or metadata.is_symlink():
            continue
        staged = stage / metadata.stem
        if not staged.exists() and not staged.is_symlink():
            metadata.unlink()  # Crash before the rename; the source is still in incoming.
            continue
        try:
            record = json.loads(metadata.read_text(encoding='utf-8'))
            process(root_name, storage_root, staged, metadata, record, receive, complete, fail, receipt)
        except Exception as error:
            if 'record' in locals() and isinstance(record, dict):
                remember_failure(root_name, staged, metadata, record, error, receipt)
            else:
                logging.error(json.dumps({'event': 'ftp_metadata_invalid', 'claim_token': metadata.stem}))
        finally:
            record = None
    now = time.monotonic()
    sources = accounts if accounts is not None else [
        {'device_id': int(item.name), 'home_layout': 'legacy', 'home': str(item / 'incoming'), 'purpose': 'backup'}
        for item in root.iterdir() if item.name.isdecimal() and item.is_dir() and not item.is_symlink()]
    for account in sources:
        if not account.get('is_active', True):
            continue
        if account.get('purpose') == 'file_server' and not account.get('ready_for_receive', False):
            continue
        device_id = account.get('device_id')
        home = Path(account['home']) if accounts is not None else directory(root_name, device_id)
        if not home.is_relative_to(root) or home.is_symlink() or home.parent.is_symlink():
            continue
        if account.get('home_layout') == 'account' and (
            not re.fullmatch(r'[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}', str(account.get('account_uuid'))) or
            home != root / 'accounts' / account['account_uuid'] / 'incoming' or (root / 'accounts').is_symlink()):
            continue
        if account.get('home_layout') == 'legacy' and home != root / str(device_id) / 'incoming':
            continue
        if not home.is_dir():
            continue
        for path in home.iterdir():
            key = (str(home), path.name)
            if account.get('purpose') == 'backup' and RESERVED.fullmatch(path.name):
                continue  # Reserved for the manual diagnostic receiver.
            if path.name.lower().endswith(IN_PROGRESS_SUFFIXES):
                continue  # Still transferring — never tracked/claimed by name alone.
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
                quarantine(root_name, path, 'invalid_name', device_id=device_id,
                           ftp_account_id=account.get('id'))
                continue
            try:
                claimed = claim(root_name, path, info, device_id, account.get('id'), account.get('purpose', 'backup'), account.get('account_uuid'))
            except FileNotFoundError:
                continue
            if claimed:
                try:
                    process(root_name, storage_root, *claimed, receive, complete, fail, receipt)
                except Exception as error:
                    remember_failure(root_name, claimed[0], claimed[1], claimed[2], error, receipt)
