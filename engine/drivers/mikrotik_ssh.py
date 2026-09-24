import errno
import base64
import hashlib
import ipaddress
import re
import socket
import time

import paramiko

from errors import BackupError, is_retryable
from driver_base import BackupDriver, ANALYZE, BACKUP, PROBE
from results import AnalysisResult, BackupResult, ProbeResult

# Re-exported for backward compatibility: existing code across the engine
# (huawei_vrp_ssh.py, storage.py, ftp_incoming.py, ftp_spontaneous.py,
# backup_engine.py) imports BackupError from this module. The class itself
# now lives in errors.py — see docs/ENGINE_DRIVERS.md.
__all__ = ['BackupError', 'MAX_BYTES', 'VerifiedHostKeyPolicy', 'export_config',
           'probe_connection', 'MikroTikRouterOsSshDriver']


MAX_BYTES = 8 * 1024 * 1024


class VerifiedHostKeyPolicy(paramiko.MissingHostKeyPolicy):
    def __init__(self, algorithm, fingerprint, observe):
        self.algorithm = algorithm
        self.fingerprint = fingerprint
        self.observe = observe

    def missing_host_key(self, client, hostname, key):
        observed_algorithm = key.get_name()
        observed_fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(key.asbytes()).digest()).decode('ascii').rstrip('=')
        self.observe(observed_algorithm, observed_fingerprint)
        if not self.algorithm or not self.fingerprint:
            raise BackupError('SSH_HOST_KEY_UNKNOWN')
        if self.algorithm != observed_algorithm or self.fingerprint != observed_fingerprint:
            raise BackupError('SSH_HOST_KEY_MISMATCH')


def _mikrotik_transport(sock, **kwargs):
    transport = paramiko.Transport(sock, **kwargs)
    options = transport.get_security_options()
    # RouterOS legado pode oferecer somente ssh-rsa como algoritmo de host key.
    # A alteração pertence apenas a este transporte, sem modificar outros algoritmos.
    if 'ssh-rsa' not in options.key_types:
        options.key_types = tuple(options.key_types) + ('ssh-rsa',)
    return transport


def _negotiation_failed(error):
    # Paramiko 4 sinaliza incompatibilidade de algoritmos com SSHException.
    # A mensagem é usada somente para classificação; nunca é enviada ou logada.
    message = str(error).lower()
    return 'incompatible ssh peer' in message or 'no acceptable ' in message


def export_config(host, port, username, password, algorithm=None, fingerprint=None, observe=None):
    try:
        ipaddress.ip_address(host)
        port = int(port)
        if not 1 <= port <= 65535 or not username:
            raise ValueError()
    except (ValueError, TypeError):
        raise BackupError('SSH_CONNECT_FAILED') from None

    if observe is None:
        raise BackupError('ENGINE_FAILED')
    client = paramiko.SSHClient()
    # Do not load system host keys: every device must pass the persistent check.
    client.set_missing_host_key_policy(VerifiedHostKeyPolicy(algorithm, fingerprint, observe))
    try:
        client.connect(host, port=port, username=username, password=password,
                       timeout=10, auth_timeout=10, banner_timeout=10,
                       look_for_keys=False, allow_agent=False,
                       transport_factory=_mikrotik_transport)
        channel = client.get_transport().open_session(timeout=10)
        channel.settimeout(20)
        channel.exec_command('/export terse')
        chunks = []
        size = 0
        deadline = time.monotonic() + 30
        while True:
            if time.monotonic() > deadline:
                raise BackupError('SSH_TIMEOUT')
            if channel.recv_ready():
                chunk = channel.recv(min(65536, MAX_BYTES + 1 - size))
                size += len(chunk)
                if size > MAX_BYTES:
                    raise BackupError('ARTIFACT_INVALID')
                chunks.append(chunk)
            elif channel.exit_status_ready():
                break
            else:
                time.sleep(0.05)
        if channel.recv_exit_status() != 0:
            raise BackupError('EXPORT_FAILED')
        return b''.join(chunks)
    except paramiko.AuthenticationException:
        raise BackupError('SSH_AUTH_FAILED') from None
    except (socket.timeout, TimeoutError):
        raise BackupError('SSH_TIMEOUT') from None
    except paramiko.ssh_exception.NoValidConnectionsError as error:
        if error.errors and all(exc.errno == errno.ECONNREFUSED for exc in error.errors.values()):
            raise BackupError('SSH_CONNECTION_REFUSED') from None
        raise BackupError('SSH_CONNECT_FAILED') from None
    except ConnectionRefusedError:
        raise BackupError('SSH_CONNECTION_REFUSED') from None
    except paramiko.SSHException as error:
        code = 'SSH_NEGOTIATION_FAILED' if _negotiation_failed(error) else 'SSH_CONNECT_FAILED'
        raise BackupError(code) from None
    except BackupError:
        raise
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


def probe_connection(host, port, username, password, algorithm=None, fingerprint=None, observe=None):
    """Read-only connectivity/authentication check — connects and
    authenticates, never runs a command, never changes the device."""
    try:
        ipaddress.ip_address(host)
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
                       look_for_keys=False, allow_agent=False,
                       transport_factory=_mikrotik_transport)
        return round((time.monotonic() - started) * 1000, 1)
    except paramiko.AuthenticationException:
        raise BackupError('SSH_AUTH_FAILED') from None
    except (socket.timeout, TimeoutError):
        raise BackupError('SSH_TIMEOUT') from None
    except paramiko.ssh_exception.NoValidConnectionsError as error:
        if error.errors and all(exc.errno == errno.ECONNREFUSED for exc in error.errors.values()):
            raise BackupError('SSH_CONNECTION_REFUSED') from None
        raise BackupError('SSH_CONNECT_FAILED') from None
    except ConnectionRefusedError:
        raise BackupError('SSH_CONNECTION_REFUSED') from None
    except paramiko.SSHException as error:
        code = 'SSH_NEGOTIATION_FAILED' if _negotiation_failed(error) else 'SSH_CONNECT_FAILED'
        raise BackupError(code) from None
    except BackupError:
        raise
    except (OSError, EOFError):
        raise BackupError('SSH_CONNECT_FAILED') from None
    finally:
        client.close()


# Lesson carried over from the V1 (backup-manager-local) RouterOS content
# validator: a `/export` can legitimately embed plaintext secrets (user
# passwords, full-access API keys, private keys) or commands that would be
# dangerous to ever replay unreviewed. This is informational only — it never
# blocks storage of a transport-valid backup, it only surfaces a warning for
# an operator to notice.
_SENSITIVE_EXPORT_MARKERS = (
    (re.compile(rb'(?i)/user\s+(?:add|set)\b.*password='), 'contains_user_password'),
    (re.compile(rb'(?i)/tool\s+fetch\b'), 'contains_tool_fetch'),
    (re.compile(rb'(?i)/system\s+(?:script|scheduler)\s+add\b'), 'contains_script_or_scheduler'),
    (re.compile(rb'-----BEGIN [A-Z ]*PRIVATE KEY-----'), 'contains_private_key'),
)


def _analyze_routeros_export(data):
    """Best-effort, informational only (see driver_base.BackupDriver.analyze).
    RouterOS `/export` output always starts with a `#` comment header; there
    is no deeper vendor parser for MikroTik today, unlike Huawei OLT."""
    if not data or not data.lstrip().startswith(b'#'):
        return AnalysisResult(status='unknown', vendor='mikrotik')
    warnings = [label for pattern, label in _SENSITIVE_EXPORT_MARKERS if pattern.search(data)]
    return AnalysisResult(status='recognized', vendor='mikrotik', platform='network', warnings=warnings)


class MikroTikRouterOsSshDriver(BackupDriver):
    name = 'mikrotik_routeros_ssh'
    vendor = 'mikrotik'
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
                                size_bytes=len(payload), filename_hint='export.rsc')
        except BackupError as error:
            return BackupResult(success=False, code=error.code, transport=self.transport,
                                retryable=is_retryable(error.code))

    def analyze(self, payload, context=None):
        return _analyze_routeros_export(payload)
