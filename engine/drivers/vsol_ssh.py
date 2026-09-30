"""VSOL OLT configuration backup over an interactive SSH shell.

Chosen over FTP/TFTP push after homologation: this OLT's `copy
startup-config` only accepts a `tftp://` destination, not `ftp://` — and
TFTP (no authentication, UDP) isn't something this system provisions
infrastructure for. Pulling `show running-config` directly over SSH avoids
the file-transfer protocol question entirely and matches the existing
ssh_pull pattern already used for MikroTik/Huawei VRP.

Prompt/pager handling here is ported from the V1 Telnet implementation
(backup_manager/vsol_olt.py::collect_running_config, validated against real
V1600GT hardware) onto an authenticated SSH channel instead of a raw Telnet
socket — the device-side CLI behavior (prompts, `--More--` paging) is the
same either way; only the transport changed. Unlike the Telnet version, no
login/password handshake happens at the shell level: paramiko already
authenticated over the SSH protocol before invoke_shell() runs, so the
channel opens straight at the operational CLI prompt.
"""

import re
import socket
import time

import paramiko

from drivers.mikrotik_ssh import BackupError, MAX_BYTES, VerifiedHostKeyPolicy, _negotiation_failed
from errors import is_retryable
from driver_base import BackupDriver, ANALYZE, BACKUP, PROBE
from results import AnalysisResult, BackupResult, ProbeResult


PROMPT = re.compile(r'[>#]\s*$')
CLI_ERROR = re.compile(r'(?i)unknown command|invalid input|command error')


def _clean(data):
    # Strip common three-byte Telnet/ANSI control sequences some VSOL CLIs
    # still emit even over SSH, and backspace characters from line editing.
    data = re.sub(rb'\xff[\xfb-\xfe].', b'', data)
    text = data.decode('iso-8859-1', errors='replace')
    text = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', text)
    return text.replace('\x08', '')


def _receive(channel, timeout):
    chunks = bytearray()
    deadline = time.monotonic() + timeout
    quiet_deadline = None
    handled_pagers = 0
    while time.monotonic() < deadline:
        if channel.recv_ready():
            chunk = channel.recv(65535)
            if not chunk:
                break
            if len(chunks) + len(chunk) > MAX_BYTES:
                raise BackupError('ARTIFACT_INVALID')
            chunks.extend(chunk)
            text = _clean(bytes(chunks))
            pager_count = text.count('--More--') + text.count('---- More ----')
            if pager_count > handled_pagers:
                channel.sendall(b' ' * (pager_count - handled_pagers))
                handled_pagers = pager_count
                quiet_deadline = None
                continue
            quiet_deadline = time.monotonic() + 0.35
            if PROMPT.search(text):
                break
        elif channel.closed or channel.exit_status_ready():
            break
        elif quiet_deadline is not None and time.monotonic() >= quiet_deadline:
            break
        else:
            time.sleep(0.05)
    return _clean(bytes(chunks))


def _send(channel, value, timeout=30):
    channel.sendall((value + '\n').encode('iso-8859-1'))
    return _receive(channel, timeout)


def _connect(host, port, username, password, algorithm, fingerprint, observe):
    from ipaddress import ip_address
    try:
        ip_address(host)
        port = int(port)
        if not 1 <= port <= 65535 or not username:
            raise ValueError()
    except (ValueError, TypeError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    if observe is None:
        raise BackupError('ENGINE_FAILED')
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(VerifiedHostKeyPolicy(algorithm, fingerprint, observe))
    try:
        client.connect(host, port=port, username=username, password=password,
                       timeout=10, auth_timeout=10, banner_timeout=10,
                       look_for_keys=False, allow_agent=False)
        return client
    except paramiko.AuthenticationException:
        raise BackupError('SSH_AUTH_FAILED') from None
    except (socket.timeout, TimeoutError):
        raise BackupError('SSH_TIMEOUT') from None
    except paramiko.ssh_exception.NoValidConnectionsError as error:
        import errno
        if error.errors and all(exc.errno == errno.ECONNREFUSED for exc in error.errors.values()):
            raise BackupError('SSH_CONNECTION_REFUSED') from None
        raise BackupError('SSH_CONNECT_FAILED') from None
    except ConnectionRefusedError:
        raise BackupError('SSH_CONNECTION_REFUSED') from None
    except paramiko.SSHException as error:
        raise BackupError('SSH_NEGOTIATION_FAILED' if _negotiation_failed(error)
                          else 'SSH_CONNECT_FAILED') from None
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None


def export_config(host, port, username, password, algorithm=None, fingerprint=None, observe=None):
    client = _connect(host, port, username, password, algorithm, fingerprint, observe)
    try:
        channel = client.invoke_shell(width=160, height=48)
        channel.settimeout(20)
        try:
            response = _receive(channel, 30)
            if not PROMPT.search(response):
                raise BackupError('VSOL_PROMPT_FAILED')
            if not response.rstrip().endswith('#'):
                response = _send(channel, 'enable')
                if 'password' in response.casefold():
                    response = _send(channel, password)
                if not response.rstrip().endswith('#'):
                    raise BackupError('VSOL_PRIVILEGED_MODE_FAILED')
            output = _send(channel, 'show running-config', timeout=60)
            if CLI_ERROR.search(output):
                raise BackupError('VSOL_EXPORT_FAILED')
            lines = [line.rstrip() for line in output.replace('\r', '').split('\n')]
            lines = [line.replace('--More--', '').replace('---- More ----', '') for line in lines]
            lines = [line for line in lines
                     if line.strip() != 'show running-config' and not re.fullmatch(r'\S+[>#]', line.strip())]
            while lines and not lines[0].strip():
                lines.pop(0)
            result = '\n'.join(lines).strip() + '\n'
            if len(result.encode('utf-8')) < 100:
                raise BackupError('ARTIFACT_INVALID')
            return result.encode('utf-8')
        finally:
            channel.close()
    except BackupError:
        raise
    except (socket.timeout, TimeoutError):
        raise BackupError('SSH_TIMEOUT') from None
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


def probe_connection(host, port, username, password, algorithm=None, fingerprint=None, observe=None):
    """Read-only connectivity/authentication check — connects, authenticates
    and waits for the initial CLI prompt, never sends a command."""
    started = time.monotonic()
    client = _connect(host, port, username, password, algorithm, fingerprint, observe)
    try:
        channel = client.invoke_shell(width=160, height=48)
        channel.settimeout(20)
        try:
            response = _receive(channel, 30)
            if not PROMPT.search(response):
                raise BackupError('VSOL_PROMPT_FAILED')
        finally:
            channel.close()
        return round((time.monotonic() - started) * 1000, 1)
    except BackupError:
        raise
    except (socket.timeout, TimeoutError):
        raise BackupError('SSH_TIMEOUT') from None
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


def _analyze_vsol_export(data):
    """Best-effort, informational only (see driver_base.BackupDriver.analyze)."""
    if not data:
        return AnalysisResult(status='unknown', vendor='vsol')
    try:
        content = data.decode('utf-8')
    except UnicodeDecodeError:
        return AnalysisResult(status='unknown', vendor='vsol', warnings=['binary_or_unknown_encoding'])
    match = re.search(r'(?mi)^\s*hostname\s+(\S+)', content)
    if match:
        return AnalysisResult(status='recognized', vendor='vsol', platform='olt', hostname=match.group(1))
    return AnalysisResult(status='unknown', vendor='vsol', warnings=['hostname_not_found'])


class VsolOltSshDriver(BackupDriver):
    name = 'vsol_olt_ssh'
    vendor = 'vsol'
    transport = 'ssh'
    capabilities = frozenset({PROBE, BACKUP, ANALYZE})

    def probe(self, context):
        started = time.monotonic()
        try:
            latency_ms = probe_connection(
                context.host, context.port, context.username, context.metadata.get('__secret'),
                context.ssh_host_key_algorithm, context.ssh_host_key_fingerprint,
                context.metadata.get('__observe'))
            return ProbeResult(success=True, latency_ms=latency_ms)
        except BackupError as error:
            return ProbeResult(success=False, code=error.code,
                               latency_ms=round((time.monotonic() - started) * 1000, 1))

    def backup(self, context, secret=None, observe=None, cancel_check=None):
        # cancel_check accepted for interface uniformity (see
        # huawei_vrp_ssh.py's identical note) but not consulted: the
        # interactive-shell paging loop has no safe mid-read checkpoint.
        try:
            payload = export_config(context.host, context.port, context.username, secret,
                                    context.ssh_host_key_algorithm, context.ssh_host_key_fingerprint, observe)
            return BackupResult(success=True, transport=self.transport, payload=payload,
                                size_bytes=len(payload), filename_hint='running-config.cfg')
        except BackupError as error:
            return BackupResult(success=False, code=error.code, transport=self.transport,
                                retryable=is_retryable(error.code))

    def analyze(self, payload, context=None):
        return _analyze_vsol_export(payload)
