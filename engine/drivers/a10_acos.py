"""A10 ACOS identity, SFTP export trigger and archive analysis."""

import gzip
import io
import re
import tarfile
import zlib
import socket
import time
from datetime import datetime, timezone
from ipaddress import ip_address

import paramiko

from driver_base import ANALYZE, BACKUP, PROBE, BackupDriver
from drivers.mikrotik_ssh import VerifiedHostKeyPolicy, _negotiation_failed
from drivers.huawei_olt_ssh_probe import probe_authentication
from errors import BackupError, is_retryable
from results import AnalysisResult, BackupResult


VERSION = re.compile(r'(?im)^\s*(?:ACOS:\s*|64-bit Advanced Core OS \(ACOS\) version\s+)(4\.1\.4-GR1-P14),\s*build\s+(\d+)\b')
ANALYSIS_LIMIT = 64 * 1024 * 1024
MEMBER_LIMIT = 4096
NAME = re.compile(r'[A-Z0-9-]+_[0-9]{14}-exec-[1-9][0-9]*\.tar\.gz\Z')
USER = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,39}\Z')
HOST_LABEL = re.compile(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\Z')
CLI_PROMPT = re.compile(r'(?m)^[A-Za-z0-9_.-]+(?:\([^\r\n)]+\))?[#>][ \t]*\Z')
PASSWORD_PROMPT = re.compile(r'(?i)(?:^|\n)\s*(?:password|passphrase)(?:\s+for\s+[^\r\n:]{1,60})?\s*(?:\[\])?\s*[:?]\s*\Z')
FILENAME_PROMPT = re.compile(r'(?i)(?:^|\n)\s*file\s+name\s*\[([^\]\r\n]{1,240})\]\s*[:?]?\s*\Z')
PROFILE_PROMPT = re.compile(r'(?i)(?:^|\n)[^\r\n]{0,180}save\s+the\s+remote\s+host\s+information\s+to\s+a\s+profile[^\r\n]{0,80}\[yes/no\]\s*[:?]?\s*\Z')
CLI_ERROR = re.compile(r'(?im)^\s*(?:error:|%\s*(?:error|unrecognized command|incomplete command)|invalid command|invalid input detected|unknown command|backup failed|authentication failed)')


def parse_acos_version(output):
    """Return a bounded identity, never a raw CLI banner or secret."""
    match = VERSION.search(output[:8192])
    return f'{match.group(1)}, build {match.group(2)}' if match else None


def expected_filename(device_name, created_at, execution_id):
    """Stable correlation name across retries; final storage path is Laravel's."""
    if not isinstance(execution_id, int) or execution_id < 1:
        raise ValueError('invalid execution id')
    if not isinstance(created_at, datetime) or created_at.tzinfo is None:
        raise ValueError('timestamp must have timezone')
    slug = re.sub(r'[^A-Za-z0-9]+', '-', device_name).strip('-').upper()[:40].rstrip('-')
    if not slug:
        raise ValueError('invalid device name')
    stamp = created_at.astimezone(timezone.utc).strftime('%Y%m%d%H%M%S')
    return f'{slug}_{stamp}-exec-{execution_id}.tar.gz'


def backup_system_command(version, method, host, username, filename, interface='data', port=None, directory=None):
    """Build only the P14 CLI syntax observed by the operator, without a secret.

    Protocol selection is explicit. This does not execute the command or
    assert that a corresponding server is available.
    """
    if not re.fullmatch(r'4\.1\.4-GR1-P14, build [0-9]+', version or ''):
        raise ValueError('unsupported ACOS version')
    if method not in {'scp', 'sftp', 'ftp'}:
        raise ValueError('unsupported transfer method')
    if interface not in {'management', 'data'}:
        raise ValueError('unsupported transfer interface')
    if not USER.fullmatch(username or '') or not NAME.fullmatch(filename or ''):
        raise ValueError('invalid transfer identity')
    try:
        if ip_address(host).version != 4:
            raise ValueError('IPv6 URL syntax not confirmed')
    except ValueError:
        if (not host or len(host) > 253 or any(not HOST_LABEL.fullmatch(label)
                                              for label in host.split('.'))):
            raise ValueError('invalid transfer host') from None
    if port is not None:
        if method != 'ftp' or type(port) is not int or not 1 <= port <= 65535:
            raise ValueError('invalid transfer port')
    if directory is not None and (method != 'sftp' or directory != 'incoming'):
        raise ValueError('invalid transfer directory')
    authority = f'{username}@{host}' + (f':{port}' if port is not None else '')
    prefix = 'backup system use-mgmt-port' if interface == 'management' else 'backup system'
    remote_path = f'{directory}/{filename}' if directory else filename
    return f'{prefix} {method}://{authority}/{remote_path}'


def _read_cli(channel, expected, timeout):
    deadline = time.monotonic() + timeout
    output = bytearray()
    while time.monotonic() < deadline:
        if channel.recv_ready():
            chunk = channel.recv(4096)
            if not chunk:
                raise BackupError('A10_TRANSFER_FAILED')
            output.extend(chunk)
            if len(output) > 65536:
                raise BackupError('A10_TRANSFER_FAILED')
            text = re.sub(r'\x1b\[[0-9;]*[A-Za-z]', '', output.decode('utf-8', 'replace')).replace('\r', '')
            if CLI_ERROR.search(text):
                raise BackupError('A10_TRANSFER_FAILED')
            stripped = text.rstrip('\n')
            if expected == 'password' and PASSWORD_PROMPT.search(stripped):
                return text
            if expected != 'password' and PASSWORD_PROMPT.search(stripped):
                raise BackupError('A10_TRANSFER_FAILED')
            if expected == 'backup' and (FILENAME_PROMPT.search(stripped) or PROFILE_PROMPT.search(stripped)):
                return text
            if CLI_PROMPT.search(stripped):
                if expected in ('prompt', 'backup'):
                    return text
                raise BackupError('A10_TRANSFER_FAILED')
        elif channel.closed or channel.exit_status_ready():
            raise BackupError('A10_TRANSFER_FAILED')
        else:
            time.sleep(0.05)
    raise BackupError('SSH_TIMEOUT')


def trigger_system_backup(context, ssh_secret, command_options, transfer_password, observe):
    """Send a P14 command only after reading its version and expected prompts.

    The transfer password is sent only to a recognized interactive password
    prompt. Neither CLI output nor either secret is logged or returned.
    """
    if observe is None or not transfer_password:
        raise BackupError('ENGINE_FAILED')
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(VerifiedHostKeyPolicy(
        context.ssh_host_key_algorithm, context.ssh_host_key_fingerprint, observe))
    try:
        client.connect(context.host, port=context.port, username=context.username, password=ssh_secret,
                       timeout=10, auth_timeout=10, banner_timeout=10, look_for_keys=False, allow_agent=False)
        channel = client.invoke_shell(width=160, height=48)
        channel.settimeout(20)
        try:
            initial = _read_cli(channel, 'prompt', 30)
            if initial.rstrip().endswith('>'):
                channel.sendall('enable\n')
                _read_cli(channel, 'password', 30)
                channel.sendall('\n')  # Observed blank enable password on this P14 account.
                enabled = _read_cli(channel, 'prompt', 30)
                if not enabled.rstrip().endswith('#'):
                    raise BackupError('A10_TRANSFER_FAILED')
            channel.sendall('show version | inc ACOS\n')
            version = parse_acos_version(_read_cli(channel, 'prompt', 30))
            if version is None:
                raise BackupError('A10_VERSION_UNSUPPORTED')
            channel.sendall('configure\n')
            configured = _read_cli(channel, 'prompt', 30)
            if not re.search(r'\(config[^\r\n)]*\)#[ \t]*\Z', configured):
                raise BackupError('A10_TRANSFER_FAILED')
            command = backup_system_command(version, **command_options)
            channel.sendall(command + '\n')
            _read_cli(channel, 'password', 30)
            channel.sendall(transfer_password + '\n')
            for _ in range(4):
                response = _read_cli(channel, 'backup', 180)
                stripped = response.rstrip('\n')
                filename = FILENAME_PROMPT.search(stripped)
                if filename:
                    default_path = filename.group(1)
                    remote_path = '/'.join(filter(None, (command_options.get('directory'), command_options['filename'])))
                    if default_path not in (command_options['filename'], remote_path, '/' + remote_path):
                        raise BackupError('A10_TRANSFER_FAILED')
                    channel.sendall('\n')
                elif PROFILE_PROMPT.search(stripped):
                    channel.sendall('no\n')
                elif CLI_PROMPT.search(stripped):
                    break
                else:
                    raise BackupError('A10_TRANSFER_FAILED')
            else:
                raise BackupError('A10_TRANSFER_FAILED')
        finally:
            channel.close()
    except paramiko.AuthenticationException:
        raise BackupError('SSH_AUTH_FAILED') from None
    except (socket.timeout, TimeoutError):
        raise BackupError('SSH_TIMEOUT') from None
    except paramiko.ssh_exception.NoValidConnectionsError:
        raise BackupError('SSH_CONNECT_FAILED') from None
    except paramiko.SSHException as error:
        raise BackupError('SSH_NEGOTIATION_FAILED' if _negotiation_failed(error) else 'SSH_CONNECT_FAILED') from None
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


def analyze_system_archive(payload):
    """Best-effort archive recognition; it never extracts or gates retention."""
    if not payload or len(payload) < 3 or payload[:2] != b'\x1f\x8b':
        return AnalysisResult(status='unknown', vendor='a10 networks', platform='network',
                              warnings=['unknown_archive_format'])
    try:
        with gzip.GzipFile(fileobj=io.BytesIO(payload)) as compressed:
            data = compressed.read(ANALYSIS_LIMIT + 1)
        if len(data) > ANALYSIS_LIMIT:
            return AnalysisResult(status='warning', vendor='a10 networks', platform='network',
                                  warnings=['archive_too_large_to_analyze'])
        with tarfile.open(fileobj=io.BytesIO(data), mode='r:') as archive:
            count = 0
            for _ in archive:
                count += 1
                if count > MEMBER_LIMIT:
                    return AnalysisResult(status='warning', vendor='a10 networks', platform='network',
                                          warnings=['archive_too_many_entries'])
            if count == 0:
                raise tarfile.TarError('empty archive')
            return AnalysisResult(status='recognized', vendor='a10 networks', platform='network',
                                  metadata={'format': 'tar.gz', 'entries': count})
    except (tarfile.TarError, OSError, EOFError, zlib.error):
        return AnalysisResult(status='warning', vendor='a10 networks', platform='network',
                              warnings=['archive_structure_unreadable'])


class A10AcosDriver(BackupDriver):
    name = 'a10_acos'
    vendor = 'a10 networks'
    transport = 'ssh'
    capabilities = frozenset({PROBE, BACKUP, ANALYZE})

    def probe(self, context):
        return probe_authentication(context)

    def backup(self, context, secret=None, observe=None, cancel_check=None):
        options = context.metadata.get('command_options')
        transport = options.get('method', 'scp') if isinstance(options, dict) else 'scp'
        if not isinstance(secret, tuple) or len(secret) != 2 or not isinstance(options, dict):
            return BackupResult(success=False, code='UNSUPPORTED_POLICY', transport=transport)
        if cancel_check and cancel_check():
            return BackupResult(success=False, code='CANCELLED', transport=transport)
        try:
            trigger_system_backup(context, secret[0], options, secret[1], observe)
            return BackupResult(success=True, transport=transport, filename_hint=options['filename'])
        except BackupError as error:
            return BackupResult(success=False, code=error.code, transport=transport, retryable=is_retryable(error.code))
        except ValueError:
            return BackupResult(success=False, code='A10_RECEIVER_UNAVAILABLE', transport=transport)

    def analyze(self, payload, context=None):
        return analyze_system_archive(payload)
