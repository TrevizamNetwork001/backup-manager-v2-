import os
import re
import stat
import tempfile
import hashlib
from pathlib import Path

from a10_scp import MAX_ARCHIVE_BYTES
from drivers.mikrotik_ssh import BackupError, MAX_BYTES


def ftp_max_bytes():
    return max(1, min(64 * 1024 * 1024, int(os.environ.get('BACKUP_FTP_MAX_BYTES', str(MAX_BYTES)))))


def validate_received_file_integrity(path, expected_identity=None, limit=None):
    """Read one stable regular file without following links; return bytes and SHA256."""
    path = Path(path)
    limit = ftp_max_bytes() if limit is None else limit
    try:
        before = path.lstat()
        identity = lambda info: (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns,
                                 info.st_nlink, info.st_mode)
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or
                not 0 < before.st_size <= limit or
                (expected_identity is not None and identity(before)[:len(expected_identity)] != tuple(expected_identity))):
            raise BackupError('FTP_FILE_INVALID')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            if identity(os.fstat(fd)) != identity(before):
                raise BackupError('FTP_FILE_INVALID')
            with os.fdopen(fd, 'rb', closefd=False) as handle:
                data = handle.read(limit + 1)
            if len(data) != before.st_size or identity(os.fstat(fd)) != identity(before) or identity(path.lstat()) != identity(before):
                raise BackupError('FTP_FILE_INVALID')
        finally:
            os.close(fd)
    except (OSError, ValueError):
        raise BackupError('FTP_FILE_INVALID') from None
    return data, hashlib.sha256(data).hexdigest()


def analyze_content(data, vendor, platform=None):
    """Informational only. Never use this result to decide whether to retain a file."""
    if vendor == 'huawei_olt' or (vendor == 'huawei' and platform == 'olt'):
        try:
            content = data.decode('utf-8')
        except UnicodeDecodeError:
            return {'status': 'unknown', 'message': 'binary_or_unknown_encoding'}
        markers = ('[!Software Version MA5800', '[Saving time:', '[global-config]', '<global-config>')
        if all(marker in content for marker in markers):
            return {'status': 'recognized' if re.search(r'(?mi)^\s*sysname\s+\S+', content) else 'warning',
                    'message': 'ma5800_config' if re.search(r'(?mi)^\s*sysname\s+\S+', content) else 'ma5800_without_sysname'}
    return {'status': 'unknown', 'message': 'unrecognized_format'}


def validate_ssh_command_output(data, vendor='mikrotik'):
    """Reject failed SSH command output, independently of vendor format recognition."""
    limit = MAX_BYTES
    if not data or len(data) > limit or b'\x00' in data:
        raise BackupError('ARTIFACT_INVALID')
    content = data.decode('utf-8', errors='replace')
    if re.search(r'(?im)^\s*(?:error:|%\s*(?:error|unrecognized|unknown)|authentication failed|backing up files is fail|unrecognized command|unknown command|incomplete command|<!doctype html\b|<html\b)', content) or re.search(r'(?i)(?:-{3,}\s*more\s*-{3,}|\bmore\s*:\s*|press\s+(?:any key|space))', content):
        raise BackupError('ARTIFACT_INVALID')


def validate(data, vendor='mikrotik'):
    """Compatibility alias for SSH command validation."""
    validate_ssh_command_output(data, vendor)


def store(root_name, relative, data, vendor='mikrotik', execution_id=None):
    limit = MAX_ARCHIVE_BYTES if vendor == 'a10' else (ftp_max_bytes() if vendor in ('ftp', 'huawei_olt') else MAX_BYTES)
    if not data or len(data) > limit:
        raise BackupError('ARTIFACT_INVALID')
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
        candidates = [target]
        if isinstance(execution_id, int) and execution_id > 0:
            candidates.append(target.with_name(f'{target.stem}-exec-{execution_id}{target.suffix}'))
        for candidate in candidates:
            try:
                # link(2) publishes exclusively. replace(2) could overwrite a concurrent upload.
                os.link(temporary, candidate, follow_symlinks=False)
            except FileExistsError:
                continue
            temporary.unlink()
            directory_fd = os.open(candidate.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory_fd)
            finally:
                os.close(directory_fd)
            return str(candidate.relative_to(root))
        raise BackupError('STORAGE_FAILED')
    except OSError:
        raise BackupError('STORAGE_FAILED') from None
    finally:
        if temporary and temporary.exists():
            temporary.unlink()
