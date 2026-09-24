"""Huawei VRP configuration backup over an interactive SSH shell."""

import re
import socket
import time
import codecs

import paramiko

from drivers.mikrotik_ssh import BackupError, MAX_BYTES, VerifiedHostKeyPolicy, _negotiation_failed
from errors import is_retryable
from driver_base import BackupDriver, ANALYZE, BACKUP, PROBE
from results import AnalysisResult, BackupResult, ProbeResult


PROMPT = re.compile(r'(?m)^(?:<[^>\r\n]+>|\[[^\]\r\n]+\])[ \t]*$')
PAGING = re.compile(r'(?i)(?:-{3,}\s*more\s*-{3,}|\bmore\s*:\s*|press\s+(?:any key|space))')
CLI_ERROR = re.compile(r'(?im)^\s*(?:Error:|%\s*(?:Error|Unrecognized|Unknown)|Unrecognized command|Unknown command|Incomplete command)')


def _read_prompt(channel, timeout, failure_code):
    deadline = time.monotonic() + timeout
    data = bytearray()
    decoder = codecs.getincrementaldecoder('utf-8')('strict')
    decoded_chunks = []
    while time.monotonic() < deadline:
        if channel.recv_ready():
            chunk = channel.recv(min(65536, MAX_BYTES + 1 - len(data)))
            if not chunk:
                raise BackupError(failure_code)
            data.extend(chunk)
            if len(data) > MAX_BYTES:
                raise BackupError('ARTIFACT_INVALID')
            # VRP commonly uses CRLF and can redraw a prompt after an ANSI sequence.
            try:
                decoded_chunks.append(decoder.decode(chunk))
            except UnicodeDecodeError:
                raise BackupError('ARTIFACT_INVALID') from None
            output = re.sub(r'\x1b\[[0-9;]*[A-Za-z]', '', ''.join(decoded_chunks))
            if PAGING.search(output):
                raise BackupError('HUAWEI_PAGING_FAILED')
            normalized = output.replace('\r', '').rstrip('\n')
            prompt = PROMPT.search(normalized)
            if prompt and prompt.end() == len(normalized):
                return normalized
        elif channel.closed or channel.exit_status_ready():
            raise BackupError(failure_code)
        else:
            time.sleep(0.05)
    raise BackupError('SSH_TIMEOUT')


def _run(channel, command, failure_code):
    channel.sendall(command + '\n')
    output = _read_prompt(channel, 30, failure_code)
    lines = output.splitlines()
    while lines and not lines[0].strip():
        lines.pop(0)
    # Remove the echoed command and the final device prompt, never configuration lines.
    if lines and lines[0].strip() == command:
        lines.pop(0)
    if lines and PROMPT.fullmatch(lines[-1]):
        lines.pop()
    return '\n'.join(lines).strip() + '\n'


def export_config(host, port, username, password, algorithm=None, fingerprint=None, observe=None):
    # Keep the same identity policy and error taxonomy as the MikroTik SSH driver.
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
        channel = client.invoke_shell(width=160, height=48)
        channel.settimeout(20)
        try:
            _read_prompt(channel, 30, 'HUAWEI_PROMPT_FAILED')
            paging = _run(channel, 'screen-length 0 temporary', 'HUAWEI_PAGING_FAILED')
            if CLI_ERROR.search(paging):
                # The V1 router profile used this command without screen-length.
                command = 'display current-configuration | no-more'
            else:
                command = 'display current-configuration'
            output = _run(channel, command, 'HUAWEI_EXPORT_FAILED')
            if CLI_ERROR.search(output):
                raise BackupError('HUAWEI_EXPORT_FAILED' if command == 'display current-configuration'
                                  else 'HUAWEI_PAGING_FAILED')
            return output.encode('utf-8')
        finally:
            channel.close()
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
    except BackupError:
        raise
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


def probe_connection(host, port, username, password, algorithm=None, fingerprint=None, observe=None):
    """Read-only connectivity/authentication check — connects, authenticates
    and waits for the initial CLI prompt, never sends a command."""
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
    started = time.monotonic()
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(VerifiedHostKeyPolicy(algorithm, fingerprint, observe))
    try:
        client.connect(host, port=port, username=username, password=password,
                       timeout=10, auth_timeout=10, banner_timeout=10,
                       look_for_keys=False, allow_agent=False)
        channel = client.invoke_shell(width=160, height=48)
        channel.settimeout(20)
        try:
            _read_prompt(channel, 30, 'HUAWEI_PROMPT_FAILED')
        finally:
            channel.close()
        return round((time.monotonic() - started) * 1000, 1)
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
    except BackupError:
        raise
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


def _analyze_vrp_export(data):
    """Best-effort, informational only (see driver_base.BackupDriver.analyze)."""
    if not data:
        return AnalysisResult(status='unknown', vendor='huawei')
    try:
        content = data.decode('utf-8')
    except UnicodeDecodeError:
        return AnalysisResult(status='unknown', vendor='huawei', warnings=['binary_or_unknown_encoding'])
    match = re.search(r'(?mi)^\s*sysname\s+(\S+)', content)
    if match:
        return AnalysisResult(status='recognized', vendor='huawei', platform='network', hostname=match.group(1))
    return AnalysisResult(status='unknown', vendor='huawei', warnings=['sysname_not_found'])


class HuaweiVrpSshDriver(BackupDriver):
    name = 'huawei_vrp_ssh'
    vendor = 'huawei'
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

    def backup(self, context, secret=None, observe=None):
        try:
            payload = export_config(context.host, context.port, context.username, secret,
                                    context.ssh_host_key_algorithm, context.ssh_host_key_fingerprint, observe)
            return BackupResult(success=True, transport=self.transport, payload=payload,
                                size_bytes=len(payload), filename_hint='current-configuration.cfg')
        except BackupError as error:
            return BackupResult(success=False, code=error.code, transport=self.transport,
                                retryable=is_retryable(error.code))

    def analyze(self, payload, context=None):
        return _analyze_vrp_export(payload)
