"""Constrained physical FTP inspection and deletion. No caller supplied paths."""
import json
import os
import re
import stat
import time
import uuid
from pathlib import Path


class PhysicalError(Exception):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def identity(row, root):
    if not isinstance(row.get('id'), int) or row['id'] < 1:
        raise PhysicalError('unsafe_path')
    layout = row.get('home_layout') or 'legacy'
    if layout == 'legacy':
        value = row.get('device_id')
        if not isinstance(value, int) or isinstance(value, bool) or value < 1:
            raise PhysicalError('unsafe_path')
        return root / str(value), layout
    if layout == 'account':
        value = row.get('account_uuid')
        try:
            parsed = str(uuid.UUID(value))
        except (ValueError, TypeError, AttributeError):
            raise PhysicalError('unsafe_path') from None
        if value != parsed:
            raise PhysicalError('unsafe_path')
        return root / 'accounts' / parsed, layout
    raise PhysicalError('unsafe_path')


def checked(path, root, expected=None):
    try:
        relative = path.relative_to(root)
        if not relative.parts or any(part in ('', '.', '..') for part in relative.parts):
            raise PhysicalError('unsafe_path')
        current = root
        for part in relative.parts:
            current = current / part
            try:
                info = os.lstat(current)
            except FileNotFoundError:
                return None
            if stat.S_ISLNK(info.st_mode):
                raise PhysicalError('symlink_detected')
            if current != path and not stat.S_ISDIR(info.st_mode):
                raise PhysicalError('unsafe_path')
        if expected == 'dir' and not stat.S_ISDIR(info.st_mode):
            raise PhysicalError('unsafe_path')
        if expected == 'file' and (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1):
            raise PhysicalError('unsafe_path')
        return info
    except PermissionError:
        raise PhysicalError('filesystem_permission_denied') from None


def entries(directory, root):
    if checked(directory, root, 'dir') is None:
        return []
    try:
        with os.scandir(directory) as scan:
            return sorted((Path(entry.path) for entry in scan), key=str)
    except PermissionError:
        raise PhysicalError('filesystem_permission_denied') from None


def plan(row, root):
    root = Path(root)
    try:
        root_info = os.lstat(root)
    except PermissionError:
        raise PhysicalError('filesystem_permission_denied') from None
    except OSError:
        raise PhysicalError('ftp_admin_unavailable') from None
    if not stat.S_ISDIR(root_info.st_mode):
        raise PhysicalError('unsafe_path')
    parent, layout = identity(row, root)
    home = parent / 'incoming'
    checked(parent, root, 'dir')
    if layout == 'account' and checked(parent, root, 'dir') is not None:
        if any(path.name != 'incoming' for path in entries(parent, root)):
            raise PhysicalError('unsafe_path')
    files = entries(home, root)
    for path in files:
        checked(path, root, 'file')
    sidecars = {'processing': [], 'quarantine': []}
    for area in sidecars:
        directory = root / area
        for metadata in entries(directory, root):
            if not re.fullmatch(r'[a-f0-9]{32}\.json', metadata.name):
                continue
            info = checked(metadata, root, 'file')
            if info is None:
                continue
            if info.st_size > 65536:
                raise PhysicalError('unsafe_path')
            try:
                descriptor = os.open(metadata, os.O_RDONLY | os.O_NOFOLLOW)
                try:
                    opened = os.fstat(descriptor)
                    if opened.st_ino != info.st_ino or opened.st_dev != info.st_dev:
                        raise PhysicalError('unsafe_path')
                    data = json.loads(os.read(descriptor, 65537))
                finally:
                    os.close(descriptor)
            except PermissionError:
                raise PhysicalError('filesystem_permission_denied') from None
            except (OSError, ValueError):
                raise PhysicalError('unsafe_path') from None
            if not isinstance(data, dict):
                raise PhysicalError('unsafe_path')
            linked = data.get('ftp_account_id', data.get('account_id')) == row['id']
            legacy = layout == 'legacy' and data.get('account_id', data.get('ftp_account_id')) is None and data.get('device_id') == row['device_id']
            if not (linked or legacy):
                continue
            payload = directory / (metadata.stem + ('.quarantine' if area == 'quarantine' else ''))
            checked(payload, root, 'file')
            sidecars[area].extend([metadata, payload] if payload != metadata else [metadata])
    now = time.time()
    recent = any(info is not None and info.st_mtime > now - 60
                 for info in (checked(path, root, 'file') for path in files))
    groups = {}
    for area, paths in [('incoming', files), *sidecars.items()]:
        infos = [checked(path, root, 'file') for path in paths]
        groups[area] = {'files': sum(info is not None for info in infos),
                        'bytes': sum(info.st_size for info in infos if info is not None)}
    blockers = []
    if sidecars['processing']:
        blockers.append('active_claim')
    if recent:
        blockers.append('recent_upload')
    return {'status': 'ok', 'safe': not blockers, 'home_exists': checked(home, root, 'dir') is not None,
            'incoming': groups['incoming'], 'processing': groups['processing'],
            'quarantine': groups['quarantine'], 'blockers': blockers}, files, sidecars, parent, home, layout


def inspect(row, root):
    try:
        return plan(row, root)[0]
    except PhysicalError as error:
        return {'status': 'error', 'safe': False, 'blockers': [error.code]}


def cleanup(row, root, mode):
    if mode not in ('account', 'ftp_data', 'all'):
        raise PhysicalError('unsafe_path')
    report, files, sidecars, parent, home, layout = plan(row, root)
    if report['blockers']:
        raise PhysicalError(report['blockers'][0])
    removed = 0
    amount = 0
    root = Path(root)
    try:
        if mode != 'account':
            targets = files + sidecars['quarantine']
            for path in targets:
                info = checked(path, root, 'file')
                if info is None:
                    continue
                # Reject replacement between validation and unlink.
                directory_info = checked(path.parent, root, 'dir')
                if directory_info is None:
                    continue
                try:
                    descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                except FileNotFoundError:
                    continue
                try:
                    opened = os.fstat(descriptor)
                    if opened.st_ino != directory_info.st_ino or opened.st_dev != directory_info.st_dev:
                        raise PhysicalError('unsafe_path')
                    try:
                        current = os.stat(path.name, dir_fd=descriptor, follow_symlinks=False)
                    except FileNotFoundError:
                        continue
                    if current.st_ino != info.st_ino or current.st_dev != info.st_dev or not stat.S_ISREG(current.st_mode):
                        raise PhysicalError('unsafe_path')
                    try:
                        os.unlink(path.name, dir_fd=descriptor)
                    except FileNotFoundError:
                        continue
                finally:
                    os.close(descriptor)
                removed += 1
                amount += info.st_size
        if checked(home, root, 'dir') is not None and not entries(home, root):
            try:
                os.rmdir(home)
            except FileNotFoundError:
                pass
        if layout == 'account' and checked(parent, root, 'dir') is not None and not entries(parent, root):
            try:
                os.rmdir(parent)
            except FileNotFoundError:
                pass
    except FileNotFoundError:
        raise PhysicalError('filesystem_cleanup_failed') from None
    except PermissionError:
        raise PhysicalError('filesystem_permission_denied') from None
    except OSError:
        raise PhysicalError('filesystem_cleanup_failed') from None
    return {'status': 'ok', 'files_removed': removed, 'bytes_removed': amount}
