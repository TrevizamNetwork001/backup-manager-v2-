import errno
import base64
import hashlib
import ipaddress
import socket
import time

import paramiko


MAX_BYTES = 8 * 1024 * 1024


class BackupError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


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
