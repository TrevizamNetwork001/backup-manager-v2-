"""SSH authentication check for Huawei OLTs whose backups arrive by FTP."""

import errno
from ipaddress import ip_address
import socket
import time

import paramiko

from drivers.mikrotik_ssh import VerifiedHostKeyPolicy, _negotiation_failed
from errors import BackupError
from results import ProbeResult


def probe_authentication(context):
    started = time.monotonic()
    try:
        ip_address(context.host)
        port = int(context.port)
        if not 1 <= port <= 65535 or not context.username:
            raise ValueError()
    except (ValueError, TypeError):
        return ProbeResult(success=False, code='SSH_CONNECT_FAILED')

    observe = context.metadata.get('__observe')
    if observe is None:
        return ProbeResult(success=False, code='ENGINE_FAILED')

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(VerifiedHostKeyPolicy(
        context.ssh_host_key_algorithm, context.ssh_host_key_fingerprint, observe))
    code = None
    try:
        client.connect(context.host, port=port, username=context.username,
                       password=context.metadata.get('__secret'), timeout=10,
                       auth_timeout=10, banner_timeout=10,
                       look_for_keys=False, allow_agent=False)
    except paramiko.AuthenticationException:
        code = 'SSH_AUTH_FAILED'
    except (socket.timeout, TimeoutError):
        code = 'SSH_TIMEOUT'
    except paramiko.ssh_exception.NoValidConnectionsError as error:
        code = ('SSH_CONNECTION_REFUSED' if error.errors and
                all(exc.errno == errno.ECONNREFUSED for exc in error.errors.values())
                else 'SSH_CONNECT_FAILED')
    except ConnectionRefusedError:
        code = 'SSH_CONNECTION_REFUSED'
    except paramiko.SSHException as error:
        code = 'SSH_NEGOTIATION_FAILED' if _negotiation_failed(error) else 'SSH_CONNECT_FAILED'
    except BackupError as error:
        code = error.code
    except (OSError, EOFError):
        code = 'SSH_CONNECT_FAILED'
    finally:
        client.close()

    return ProbeResult(success=code is None, code=code,
                       latency_ms=round((time.monotonic() - started) * 1000, 1))
