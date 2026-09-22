"""Huawei VRP configuration backup over an interactive SSH shell."""

import re
import socket
import time
import codecs

import paramiko

from drivers.mikrotik_ssh import BackupError, MAX_BYTES, VerifiedHostKeyPolicy, _negotiation_failed


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
