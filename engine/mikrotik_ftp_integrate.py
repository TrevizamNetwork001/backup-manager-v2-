"""Install one managed RouterOS FTP export routine and run one upload probe.

Called by the Laravel app with JSON on stdin. Secrets never appear on stdout.
"""

import ipaddress
import hashlib
import json
import re
import sys
import time
import uuid
from datetime import datetime
from zoneinfo import ZoneInfo

import paramiko

from drivers.mikrotik_ssh import VerifiedHostKeyPolicy, _mikrotik_transport
from errors import BackupError


SCRIPT_NAME = 'backup-manager-v2-ftp'
MARKER = '# backup-manager-v2-ftp device='
NAMING_MARKER = '# backup-manager-v2-ftp naming=2'


def quote(value):
    if any(character in value for character in '\r\n\x00'):
        raise ValueError('invalid_value')
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'


def source_quote(value):
    if '\x00' in value:
        raise ValueError('invalid_value')
    escaped = value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$')
    return '"' + escaped.replace('\r\n', '\\r\\n').replace('\r', '\\r\\n').replace('\n', '\\r\\n') + '"'


def config_marker(data):
    material = '\x00'.join((data['ftp_host'], data['ftp_username'], data['ftp_secret'], data['schedule_time']))
    return '# backup-manager-v2-ftp config=' + hashlib.sha256(material.encode('utf-8')).hexdigest()[:16]


def device_label(data):
    label = re.sub(r'[^A-Za-z0-9]+', '-', data['device_name']).strip('-').upper()[:40].rstrip('-')
    return label or f'MIKROTIK-{data["device_id"]}'


def run(client, command, timeout=25, *, secret=False, content_output=False):
    channel = client.get_transport().open_session(timeout=10)
    channel.settimeout(timeout)
    channel.exec_command(command)
    deadline = time.monotonic() + timeout
    output = bytearray()
    errors = bytearray()
    while not channel.exit_status_ready() or channel.recv_ready() or channel.recv_stderr_ready():
        if time.monotonic() > deadline:
            channel.close()
            raise RuntimeError('SSH_TIMEOUT')
        if channel.recv_ready():
            output.extend(channel.recv(65536))
        if channel.recv_stderr_ready():
            errors.extend(channel.recv_stderr(65536))
        if len(output) + len(errors) > 262144:
            channel.close()
            raise RuntimeError('ROUTEROS_OUTPUT_TOO_LARGE')
        time.sleep(0.05)
    if channel.recv_exit_status() != 0 or errors or (not content_output and any(
        marker in output.decode('utf-8', errors='replace').lower()
        for marker in ('syntax error', 'expected closing', 'failure', 'invalid',
                       'no such item', 'bad command', 'expected end of command')
    )):
        raise RuntimeError('ROUTEROS_COMMAND_FAILED' if secret else 'ROUTEROS_PREFLIGHT_FAILED')
    return output.decode('utf-8', errors='replace').strip()


def count(client, menu, name):
    result = run(client, f'/{menu} print count-only where name={quote(name)}')
    try:
        return int(result)
    except ValueError as error:
        raise RuntimeError('ROUTEROS_PREFLIGHT_FAILED') from error


def script_source(data, major):
    export = '/export compact file=$bmBase' if major == 6 else '/export file=$bmBase'
    fetch = ('/tool fetch mode=ftp upload=yes address=$bmHost port=21 user=$bmUser '
             'password=$bmPassword src-path=$bmFile dst-path=$bmFile keep-result=no')
    if major == 7:
        fetch += ' duration=2m idle-timeout=15s'
    return '\n'.join([
        f'{MARKER}{data["device_id"]}',
        config_marker(data),
        NAMING_MARKER,
        ':global backupManagerV2FtpRunning',
        ':global backupManagerV2FtpSequence',
        ':if ($backupManagerV2FtpRunning = true) do={ :error "backup-manager-v2 already running" }',
        ':set backupManagerV2FtpRunning true',
        ':if ([:typeof $backupManagerV2FtpSequence] != "num") do={ :set backupManagerV2FtpSequence 0 }',
        ':set backupManagerV2FtpSequence ($backupManagerV2FtpSequence + 1)',
        f':local bmHost {quote(data["ftp_host"])}',
        f':local bmUser {quote(data["ftp_username"])}',
        f':local bmPassword {quote(data["ftp_secret"])}',
        ':local bmDate [/system clock get date]',
        ':local bmDateSafe $bmDate',
        ':local bmSlashOne [:find $bmDate "/"]',
        ':if ([:typeof $bmSlashOne] = "num") do={',
        '  :local bmSlashTwo [:find $bmDate "/" ($bmSlashOne + 1)]',
        '  :set bmDateSafe ([:pick $bmDate ($bmSlashTwo + 1) [:len $bmDate]] . "-" . [:pick $bmDate 0 $bmSlashOne] . "-" . [:pick $bmDate ($bmSlashOne + 1) $bmSlashTwo])',
        '}',
        ':local bmTime [/system clock get time]',
        ':local bmTimeSafe ([:pick $bmTime 0 2] . [:pick $bmTime 3 5] . [:pick $bmTime 6 8])',
        f':local bmBase ({quote(device_label(data) + "-backup-")} . $bmDateSafe . "-" . $bmTimeSafe . "-" . $backupManagerV2FtpSequence)',
        ':local bmFile ($bmBase . ".rsc")',
        ':do {',
        f'  {export}',
        '  :delay 2s',
        '  :local bmFileIds [/file find where name=$bmFile]',
        '  :if ([:len $bmFileIds] != 1) do={ :error "export file unavailable" }',
        '  :if ([/file get $bmFileIds size] <= 0) do={ :error "export file empty" }',
        f'  {fetch}',
        '  /file remove $bmFileIds',
        '} on-error={',
        '  :local bmOriginalError $message',
        '  :local bmCleanup [/file find where name=$bmFile]',
        '  :if ([:len $bmCleanup] > 0) do={ /file remove $bmCleanup }',
        '  :set bmPassword ""',
        '  :set backupManagerV2FtpRunning false',
        '  :error ("backup-manager-v2 ftp failed: " . $bmOriginalError)',
        '}',
        ':set bmPassword ""',
        ':set backupManagerV2FtpRunning false',
    ])


def test_source(data, major, base):
    export = '/export compact file=$bmBase' if major == 6 else '/export file=$bmBase'
    fetch = ('/tool fetch mode=ftp upload=yes address=$bmHost port=21 user=$bmUser '
             'password=$bmPassword src-path=$bmFile dst-path=$bmFile keep-result=no')
    if major == 7:
        fetch += ' duration=2m idle-timeout=15s'
    return '\n'.join([
        f':local bmHost {quote(data["ftp_host"])}',
        f':local bmUser {quote(data["ftp_username"])}',
        f':local bmPassword {quote(data["ftp_secret"])}',
        f':local bmBase {quote(base)}',
        ':local bmFile ($bmBase . ".rsc")',
        ':if ([:len [/file find where name=$bmFile]] > 0) do={ :error "test file already exists" }',
        ':do {',
        f'  {export}',
        '  :delay 2s',
        '  :local bmFileIds [/file find where name=$bmFile]',
        '  :if ([:len $bmFileIds] != 1) do={ :error "test file unavailable" }',
        '  :if ([/file get $bmFileIds size] <= 0) do={ :error "test file empty" }',
        f'  {fetch}',
        '  /file remove $bmFileIds',
        '} on-error={',
        '  :local bmOriginalError $message',
        '  :local bmCleanup [/file find where name=$bmFile]',
        '  :if ([:len $bmCleanup] > 0) do={ /file remove $bmCleanup }',
        '  :set bmPassword ""',
        '  :error ("backup-manager-v2 ftp test failed: " . $bmOriginalError)',
        '}',
        ':set bmPassword ""',
    ])


def import_source(client, content):
    filename = f'bm-v2-{uuid.uuid4().hex}.rsc'
    try:
        sftp = client.open_sftp()
        with sftp.file('/' + filename, 'wb') as remote:
            remote.write(content.encode('utf-8'))
        sftp.close()
        if count(client, 'file', filename) != 1:
            raise RuntimeError('SFTP_TRANSFER_FAILED')
        run(client, f'/import file-name={quote(filename)} verbose=no', 35, secret=True)
    finally:
        for path in (filename, 'disk/' + filename):
            try:
                run(client, f'/file remove [find where name={quote(path)}]', 10)
            except Exception:
                pass


def connect(data):
    ipaddress.ip_address(data['host'])
    port = int(data['port'])
    if not 1 <= port <= 65535:
        raise ValueError('invalid_port')
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(VerifiedHostKeyPolicy(
        data['ssh_host_key_algorithm'], data['ssh_host_key_fingerprint'], lambda *_: None))
    client.connect(data['host'], port=port, username=data['ssh_username'], password=data['ssh_secret'],
                   timeout=10, auth_timeout=10, banner_timeout=10, look_for_keys=False, allow_agent=False,
                   transport_factory=_mikrotik_transport)
    return client


def install_and_test(client, data):
    version = run(client, ':put [/system resource get version]')
    match = re.match(r'^([67])\.(\d+)', version)
    if not match or (match.group(1) == '6' and int(match.group(2)) < 43):
        raise RuntimeError('ROUTEROS_VERSION_UNSUPPORTED')
    major = int(match.group(1))
    if count(client, 'system script', 'backup-manager') or count(client, 'system scheduler', 'backup-manager'):
        raise RuntimeError('LEGACY_INTEGRATION_PRESENT')
    scripts = count(client, 'system script', SCRIPT_NAME)
    schedulers = count(client, 'system scheduler', SCRIPT_NAME)
    if scripts > 1 or schedulers > 1 or (schedulers and not scripts):
        raise RuntimeError('MANAGED_OBJECT_CONFLICT')
    if scripts:
        source = run(client, f':put [/system script get [find where name={quote(SCRIPT_NAME)}] source]',
                     secret=True, content_output=True)
        if f'{MARKER}{data["device_id"]}' not in source:
            raise RuntimeError('MANAGED_OBJECT_CONFLICT')
        if not schedulers:
            import_source(client, f'/system scheduler add name={quote(SCRIPT_NAME)} interval=1d '
                                 f'start-time={data["schedule_time"]}:00 '
                                 f'on-event={quote("/system script run " + SCRIPT_NAME)} '
                                 'disabled=yes policy=ftp,read,write,policy,test,sensitive')
        scheduler = run(client, f'/system scheduler print detail where name={quote(SCRIPT_NAME)}')
        if SCRIPT_NAME not in scheduler:
            raise RuntimeError('MANAGED_OBJECT_CONFLICT')
        run(client, f'/system scheduler set [find where name={quote(SCRIPT_NAME)}] disabled=yes', secret=True)
        if config_marker(data) not in source or NAMING_MARKER not in source or f'start-time={data["schedule_time"]}:00' not in scheduler:
            refreshed = script_source(data, major)
            import_source(client, '\n'.join([
                f'/system script set [find where name={quote(SCRIPT_NAME)}] source={source_quote(refreshed)}',
                f'/system scheduler set [find where name={quote(SCRIPT_NAME)}] start-time={data["schedule_time"]}:00 disabled=yes',
            ]))
    else:
        source = script_source(data, major)
        installation = '\n'.join([
            f'/system script add name={quote(SCRIPT_NAME)} policy=ftp,read,write,policy,test,sensitive source={source_quote(source)}',
            f'/system scheduler add name={quote(SCRIPT_NAME)} interval=1d start-time={data["schedule_time"]}:00 '
            f'on-event={quote("/system script run " + SCRIPT_NAME)} disabled=yes policy=ftp,read,write,policy,test,sensitive',
        ])
        import_source(client, installation)
        if count(client, 'system script', SCRIPT_NAME) != 1 or count(client, 'system scheduler', SCRIPT_NAME) != 1:
            raise RuntimeError('INSTALL_VERIFY_FAILED')
    timestamp = datetime.now(ZoneInfo('America/Sao_Paulo')).strftime('%Y-%m-%d_%H%M%S')
    base = f'{device_label(data)}-teste-{timestamp}-{uuid.uuid4().hex[:6]}'
    import_source(client, test_source(data, major, base))
    return {'success': True, 'code': 'waiting_receipt', 'filename': base + '.rsc', 'routeros_version': version}


def enable_scheduler(client, data):
    if count(client, 'system script', SCRIPT_NAME) != 1 or count(client, 'system scheduler', SCRIPT_NAME) != 1:
        raise RuntimeError('MANAGED_OBJECT_MISSING')
    source = run(client, f':put [/system script get [find where name={quote(SCRIPT_NAME)}] source]',
                 secret=True, content_output=True)
    if f'{MARKER}{data["device_id"]}' not in source:
        raise RuntimeError('MANAGED_OBJECT_CONFLICT')
    run(client, f'/system scheduler set [find where name={quote(SCRIPT_NAME)}] disabled=no', secret=True)
    scheduler = run(client, f'/system scheduler print detail where name={quote(SCRIPT_NAME)}')
    if 'disabled=yes' in scheduler or re.search(r'(?m)^\s*\d+\s+X\b', scheduler):
        raise RuntimeError('SCHEDULER_ENABLE_FAILED')
    return {'success': True, 'code': 'enabled'}


def update_naming(client, data):
    if count(client, 'system script', SCRIPT_NAME) != 1 or count(client, 'system scheduler', SCRIPT_NAME) != 1:
        raise RuntimeError('MANAGED_OBJECT_MISSING')
    version = run(client, ':put [/system resource get version]')
    match = re.match(r'^([67])\.', version)
    if not match:
        raise RuntimeError('ROUTEROS_VERSION_UNSUPPORTED')
    source = run(client, f':put [/system script get [find where name={quote(SCRIPT_NAME)}] source]',
                 secret=True, content_output=True)
    if f'{MARKER}{data["device_id"]}' not in source or config_marker(data) not in source:
        raise RuntimeError('MANAGED_OBJECT_CONFLICT')
    scheduler = run(client, f'/system scheduler print detail where name={quote(SCRIPT_NAME)}')
    if f'start-time={data["schedule_time"]}:00' not in scheduler:
        raise RuntimeError('MANAGED_OBJECT_CONFLICT')
    if NAMING_MARKER not in source:
        refreshed = script_source(data, int(match.group(1)))
        import_source(client, f'/system script set [find where name={quote(SCRIPT_NAME)}] source={source_quote(refreshed)}')
    source = run(client, f':put [/system script get [find where name={quote(SCRIPT_NAME)}] source]',
                 secret=True, content_output=True)
    if NAMING_MARKER not in source:
        raise RuntimeError('INSTALL_VERIFY_FAILED')
    return {'success': True, 'code': 'naming_updated'}


def run_managed_test(client, data):
    if count(client, 'system script', SCRIPT_NAME) != 1:
        raise RuntimeError('MANAGED_OBJECT_MISSING')
    source = run(client, f':put [/system script get [find where name={quote(SCRIPT_NAME)}] source]',
                 secret=True, content_output=True)
    if f'{MARKER}{data["device_id"]}' not in source or NAMING_MARKER not in source:
        raise RuntimeError('MANAGED_OBJECT_CONFLICT')
    run(client, f'/system script run {quote(SCRIPT_NAME)}', 45, secret=True)
    return {'success': True, 'code': 'managed_test_sent'}


def inspect(client):
    result = {
        'success': True,
        'code': 'inspected',
        'routeros_version': run(client, ':put [/system resource get version]'),
        'legacy_scripts': count(client, 'system script', 'backup-manager'),
        'legacy_schedulers': count(client, 'system scheduler', 'backup-manager'),
        'managed_scripts': count(client, 'system script', SCRIPT_NAME),
        'managed_schedulers': count(client, 'system scheduler', SCRIPT_NAME),
    }
    if result['managed_schedulers'] == 1:
        scheduler = f'[/system scheduler find where name={quote(SCRIPT_NAME)}]'
        result['scheduler_disabled'] = run(client, f':put [/system scheduler get {scheduler} disabled]') == 'true'
        result['scheduler_start_time'] = run(client, f':put [/system scheduler get {scheduler} start-time]')
        result['scheduler_interval'] = run(client, f':put [/system scheduler get {scheduler} interval]')
    return result


def main():
    client = None
    try:
        data = json.load(sys.stdin)
        client = connect(data)
        if data['action'] == 'install_test':
            result = install_and_test(client, data)
        elif data['action'] == 'enable':
            result = enable_scheduler(client, data)
        elif data['action'] == 'inspect':
            result = inspect(client)
        elif data['action'] == 'update_naming':
            result = update_naming(client, data)
        elif data['action'] == 'run_managed_test':
            result = run_managed_test(client, data)
        else:
            raise ValueError('invalid_action')
    except paramiko.AuthenticationException:
        result = {'success': False, 'code': 'SSH_AUTH_FAILED'}
    except BackupError as error:
        result = {'success': False, 'code': error.code}
    except (paramiko.SSHException, OSError):
        result = {'success': False, 'code': 'SSH_CONNECT_FAILED'}
    except (RuntimeError, ValueError, KeyError) as error:
        code = str(error)
        result = {'success': False, 'code': code if re.fullmatch(r'[A-Z_]{3,60}', code) else 'INTEGRATION_FAILED'}
    except Exception:
        result = {'success': False, 'code': 'INTEGRATION_FAILED'}
    finally:
        if client:
            client.close()
    sys.stdout.write(json.dumps(result))


if __name__ == '__main__':
    main()
