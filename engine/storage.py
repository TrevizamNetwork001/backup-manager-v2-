import os
import re
import tempfile
from pathlib import Path

from drivers.mikrotik_ssh import BackupError, MAX_BYTES


def validate(data, vendor='mikrotik'):
    if not data or len(data) > MAX_BYTES:
        raise BackupError('ARTIFACT_INVALID')
    try:
        content = data.decode('utf-8')
        preview = content[:4096]
    except UnicodeDecodeError:
        raise BackupError('ARTIFACT_INVALID') from None
    if b'\x00' in data:
        raise BackupError('ARTIFACT_INVALID')
    if vendor == 'mikrotik' and not re.search(r'(?mi)^/[a-z]', preview):
        raise BackupError('ARTIFACT_INVALID')
    if vendor == 'huawei' and (len(data) < 32 or
            re.search(r'(?im)^\s*(?:Error:|%\s*(?:Error|Unrecognized|Unknown)|Unrecognized command|Unknown command|Incomplete command)', content) or
            re.search(r'(?i)(?:-{3,}\s*more\s*-{3,}|\bmore\s*:\s*|press\s+(?:any key|space))', content) or
            not re.search(r'(?m)^#\s*$', preview) or
            not re.search(r'(?mi)^(?:sysname|interface|vlan(?: batch)?|ip route-static|aaa|user-interface|stelnet server|snmp-agent)\b', preview)):
        raise BackupError('ARTIFACT_INVALID')
    if vendor not in ('mikrotik', 'huawei'):
        raise BackupError('ARTIFACT_INVALID')


def store(root_name, relative, data, vendor='mikrotik'):
    validate(data, vendor)
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
