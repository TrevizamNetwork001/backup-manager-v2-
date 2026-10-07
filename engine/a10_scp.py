"""Strict legacy SCP sink for one A10 system-backup archive.

The SSH server must authenticate the sender and supply the exact filename
allowed for its execution. This module never trusts a filename from SCP data.
"""

import os
import re
import stat
import tempfile
from pathlib import Path


ARCHIVE_NAME = re.compile(r'[A-Z0-9-]+_[0-9]{14}-exec-[1-9][0-9]*\.tar\.gz\Z')
COMMAND = re.compile(r'scp -t /?([A-Z0-9-]+_[0-9]{14}-exec-[1-9][0-9]*\.tar\.gz)\Z')
MAX_ARCHIVE_BYTES = 64 * 1024 * 1024


class ScpRejected(Exception):
    """The sender used an unsupported command or invalid transfer frame."""


def _read_exact(channel, size):
    chunks = []
    while size:
        chunk = channel.read(size)
        if not chunk:
            raise ScpRejected('incomplete transfer')
        chunks.append(chunk)
        size -= len(chunk)
    return b''.join(chunks)


def _read_line(channel):
    line = bytearray()
    while len(line) <= 300:
        char = _read_exact(channel, 1)
        if char == b'\n':
            return bytes(line)
        line.extend(char)
    raise ScpRejected('oversized header')


def requested_filename(command):
    """Accept only a single target archive, never a directory or shell syntax."""
    match = COMMAND.fullmatch(command or '')
    if not match:
        raise ScpRejected('unsupported command')
    return match.group(1)


def receive_archive(channel, root_name, expected_name, max_bytes=MAX_ARCHIVE_BYTES):
    """Receive one regular file atomically and exclusively into a private inbox.

    A caller must map the authenticated SSH identity to ``expected_name``.
    The final file is published only after the complete payload and SCP trailer.
    """
    if not ARCHIVE_NAME.fullmatch(expected_name or '') or not 0 < max_bytes <= MAX_ARCHIVE_BYTES:
        raise ScpRejected('invalid receiver configuration')
    root = Path(root_name)
    if root.is_symlink() or not root.is_dir() or root.resolve() == Path('/'):
        raise ScpRejected('invalid inbox')
    if stat.S_IMODE(root.stat().st_mode) & 0o077:
        raise ScpRejected('inbox is not private')
    target = root / expected_name
    if target.exists() or target.is_symlink():
        raise ScpRejected('archive already received')

    channel.write(b'\0')
    header = _read_line(channel)
    match = re.fullmatch(rb'C(0[0-7]{3}) ([1-9][0-9]*) ([^/\x00\r\n]+)', header)
    try:
        received_name = match.group(3).decode('ascii') if match else None
    except UnicodeDecodeError:
        raise ScpRejected('invalid file header') from None
    if received_name != expected_name:
        raise ScpRejected('invalid file header')
    mode = int(match.group(1), 8)
    size = int(match.group(2))
    if mode & 0o111 or size > max_bytes:
        raise ScpRejected('invalid file mode or size')

    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=root, prefix='.partial-', delete=False) as handle:
            temporary = Path(handle.name)
            os.chmod(temporary, 0o600)
            channel.write(b'\0')
            remaining = size
            while remaining:
                chunk = _read_exact(channel, min(65536, remaining))
                handle.write(chunk)
                remaining -= len(chunk)
            if _read_exact(channel, 1) != b'\0':
                raise ScpRejected('invalid transfer trailer')
            handle.flush()
            os.fsync(handle.fileno())
        os.link(temporary, target, follow_symlinks=False)
        temporary.unlink()
        temporary = None
        directory_fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        channel.write(b'\0')
        return target
    except FileExistsError:
        raise ScpRejected('archive already received') from None
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
