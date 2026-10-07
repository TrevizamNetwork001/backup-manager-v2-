import json
import base64
import logging
import os
import secrets
import subprocess
import threading
import time
from contextlib import ExitStack
from concurrent.futures import ThreadPoolExecutor, wait, FIRST_COMPLETED

from errors import BackupError
from artisan_session import ArtisanSession, ThreadSessions
from contexts import BackupContext
from driver_base import RECEIVED_PAYLOAD
from registry_setup import registry
from drivers.huawei_olt_ftp import collect_config as collect_huawei_olt_config
from ftp_incoming import existing_files, scan_orphans
from ftp_spontaneous import scan as scan_spontaneous
from a10_incoming import inbox_path, remove_received, store_archive, wait_for_archive
from a10_scp_server import password_for
from storage import store, validate_ssh_command_output
from health_snapshot import build_snapshot, write_snapshot


logging.basicConfig(level=logging.INFO, format='%(message)s')
logging.getLogger('paramiko').setLevel(logging.WARNING)
ARTISAN = ['php', '/var/www/html/artisan']
WORKER_ID = secrets.token_hex(16)
HEARTBEAT_SECONDS = max(1, int(os.environ.get('BACKUP_ENGINE_HEARTBEAT_SECONDS', '30')))
WORKERS = min(4, max(1, int(os.environ.get('BACKUP_ENGINE_WORKERS', '4'))))
WORKER_SESSION = threading.local()
FTP_SCAN_WORKERS = min(2, max(1, int(os.environ.get('BACKUP_FTP_SCAN_WORKERS', '2'))))
# ENGINE-3: see docs/ENGINE_HEALTH.md — Laravel's `app` container has no
# access to this process (no shared venv, /engine not mounted), so it can
# only learn the engine's state by reading this file, atomically refreshed
# here. An empty/unset path disables the feature entirely (never crashes).
HEALTH_SNAPSHOT_PATH = os.environ.get('BACKUP_ENGINE_HEALTH_SNAPSHOT_PATH', '')
HEALTH_SNAPSHOT_SECONDS = max(5, int(os.environ.get('BACKUP_ENGINE_HEALTH_SNAPSHOT_SECONDS', '30')))


def command(*args, env=None, pass_fds=()):
    session = getattr(WORKER_SESSION, 'session', None)
    if session is not None and env is None and not pass_fds and args[0] in (
            'engine:complete', 'engine:fail', 'engine:observe-host-key', 'engine:cancel-ack'):
        return session.command(*args)
    result = subprocess.run(ARTISAN + list(map(str, args)), capture_output=True,
                            env=env, pass_fds=pass_fds, timeout=45, check=False)
    if result.returncode:
        raise BackupError('ENGINE_FAILED')
    return result.stdout


def secret_for(job_id):
    read_fd, write_fd = os.pipe()
    try:
        env = os.environ.copy()
        env['ENGINE_SECRET_FD'] = str(write_fd)
        command('engine:secret', job_id, WORKER_ID, env=env, pass_fds=(write_fd,))
        os.close(write_fd)
        write_fd = -1
        return os.read(read_fd, 65536).decode('utf-8')
    finally:
        os.close(read_fd)
        if write_fd >= 0:
            os.close(write_fd)


def execute(job):
    job_id = job['id']
    start = time.monotonic()
    status = 'failed'
    code = None
    stop = threading.Event()
    cancelled = threading.Event()
    def heartbeat():
        while not stop.wait(HEARTBEAT_SECONDS):
            try:
                response = json.loads(command('engine:heartbeat', job_id, WORKER_ID))
                if response.get('updated') is False:
                    cancelled.set()
                    logging.error(json.dumps({'execution_id': job_id, 'status': 'worker_lease_lost'}))
                    return
                if response.get('cancel_requested'):
                    cancelled.set()
            except Exception:
                logging.error(json.dumps({'execution_id': job_id, 'status': 'heartbeat_failed'}))
                # A transient database/control-plane failure must not disable
                # heartbeat monitoring for the rest of a long device session.
                continue
    monitor = threading.Thread(target=heartbeat, daemon=True)
    monitor.start()
    try:
        vendor = job['vendor'].strip().casefold()
        driver = registry.resolve(vendor, job['platform'], job['method'])
        received = RECEIVED_PAYLOAD in driver.capabilities
        a10_system = job['method'] == 'a10_system'
        if a10_system:
            if os.environ.get('BACKUP_A10_ENABLED', 'false').lower() != 'true' or job['artifact_mode'] != 'binary':
                raise BackupError('UNSUPPORTED_POLICY')
        elif received:
            # OLT FTP Push is claimed for the manual diagnostic path only —
            # the real spontaneous auto-backup bypasses engine:claim entirely
            # (see ftp_spontaneous.scan, driven straight from main()).
            if not (job.get('schedule_type') == 'manual' and job.get('origin') == 'manual') \
                    or job['artifact_mode'] != 'config':
                raise BackupError('UNSUPPORTED_POLICY')
            if not job['ftp_account_available'] or not job['ftp_host']:
                raise BackupError('FTP_ACCOUNT_UNAVAILABLE')
        elif job['artifact_mode'] != 'config':
            raise BackupError('UNSUPPORTED_POLICY')
        if not job['eligible']:
            raise BackupError('CREDENTIAL_INVALID')
        if a10_system:
            transport = os.environ.get('BACKUP_A10_TRANSPORT', 'scp').lower()
            root = os.environ.get('BACKUP_A10_SFTP_ROOT', '') if transport == 'sftp' else os.environ.get('BACKUP_A10_SCP_ROOT', '')
            host = os.environ.get('BACKUP_A10_TRANSFER_HOST', '') or os.environ.get('BACKUP_A10_SCP_HOST', '')
            interface = job.get('a10_transfer_interface')
            if transport not in ('scp', 'sftp') or not root or not host or interface not in ('management', 'data'):
                raise BackupError('A10_RECEIVER_UNAVAILABLE')
            if transport == 'scp':
                key_path = os.path.join(root, 'auth.key')
                if not os.path.exists(key_path):
                    raise BackupError('A10_RECEIVER_UNAVAILABLE')
                key_info = os.lstat(key_path)
                if key_info.st_mode & 0o077 or not os.path.isfile(key_path) or os.path.islink(key_path):
                    raise BackupError('A10_RECEIVER_UNAVAILABLE')
                with open(key_path, 'rb') as key_file:
                    key = key_file.read()
                if len(key) < 32:
                    raise BackupError('A10_RECEIVER_UNAVAILABLE')
                transfer_user = f'bmexec{job_id}'
                transfer_password = password_for(key, job_id)
            else:
                transfer_user = os.environ.get('BACKUP_A10_SFTP_USER', '')
                password_path = os.path.join(root, 'transfer.password')
                if not transfer_user or not os.path.exists(password_path):
                    raise BackupError('A10_RECEIVER_UNAVAILABLE')
                password_info = os.lstat(password_path)
                if password_info.st_mode & 0o077 or not os.path.isfile(password_path) or os.path.islink(password_path):
                    raise BackupError('A10_RECEIVER_UNAVAILABLE')
                with open(password_path, 'rb') as password_file:
                    password_bytes = password_file.read(129).strip()
                if not 24 <= len(password_bytes) <= 128 or any(byte < 33 or byte > 126 for byte in password_bytes):
                    raise BackupError('A10_RECEIVER_UNAVAILABLE')
                transfer_password = password_bytes.decode('ascii')
            expected = json.loads(command('a10:expected', job_id)).get('filename')
            if not expected:
                raise BackupError('CREDENTIAL_INVALID')
            inbox = inbox_path(root, expected)
            if not inbox.exists():
                ssh_secret = secret_for(job_id)
                observe = lambda algorithm, fingerprint: command('engine:observe-host-key', job_id,
                                                                 WORKER_ID, job['host'], algorithm, fingerprint)
                context = BackupContext(execution_id=job_id, device_id=job['device_id'], host=job['host'],
                                        port=job['port'], username=job['username'], vendor=vendor,
                                        platform=job['platform'], method=job['method'], policy_id=job['policy_id'],
                                        ssh_host_key_algorithm=job.get('ssh_host_key_algorithm'),
                                        ssh_host_key_fingerprint=job.get('ssh_host_key_fingerprint'))
                options = {'method': transport, 'host': host, 'username': transfer_user,
                           'filename': expected, 'interface': interface}
                if transport == 'sftp':
                    options['directory'] = 'incoming'
                context.metadata['command_options'] = options
                try:
                    result = driver.backup(context, secret=(ssh_secret, transfer_password),
                                           observe=observe, cancel_check=cancelled.is_set)
                    if not result.success:
                        raise BackupError(result.code)
                finally:
                    del ssh_secret
            path, identity, data, _ = wait_for_archive(root, expected, cancel_check=cancelled.is_set)
            if cancelled.is_set():
                raise BackupError('CANCELLED')
            relative = store_archive(os.environ['BACKUP_STORAGE_ROOT'], job['relative_path'], data, job_id)
            command('engine:complete', job_id, relative, WORKER_ID)
            try:
                remove_received(path, identity)
            except OSError:
                logging.warning(json.dumps({'event': 'a10_inbox_cleanup_failed', 'execution_id': job_id}))
            try:
                analysis = driver.analyze(data)
                logging.info(json.dumps({'event': 'a10_content_analysis', 'execution_id': job_id,
                                         'status': analysis.status, 'warnings': analysis.warnings,
                                         'entries': analysis.metadata.get('entries')}))
            except Exception:
                logging.warning(json.dumps({'event': 'a10_content_analysis_failed', 'execution_id': job_id}))
        elif received:
            collect_huawei_olt_config(job,
                                      lambda relative: command('engine:complete', job_id, relative, WORKER_ID))
        else:
            password = secret_for(job_id)
            observe = lambda algorithm, fingerprint: command('engine:observe-host-key', job_id,
                                                             WORKER_ID, job['host'], algorithm, fingerprint)
            context = BackupContext(execution_id=job_id, device_id=job['device_id'], host=job['host'],
                                    port=job['port'], username=job['username'], vendor=vendor,
                                    platform=job['platform'], method=job['method'], policy_id=job['policy_id'],
                                    ssh_host_key_algorithm=job.get('ssh_host_key_algorithm'),
                                    ssh_host_key_fingerprint=job.get('ssh_host_key_fingerprint'))
            result = driver.backup(context, secret=password, observe=observe, cancel_check=cancelled.is_set)
            del password
            # A cancellation observed right as the driver returns wins over a
            # late success: the operator asked to stop, so the payload (even
            # if fully collected) is discarded rather than stored. See
            # docs/ENGINE_QUEUE.md.
            if cancelled.is_set():
                raise BackupError('CANCELLED')
            if not result.success:
                raise BackupError(result.code)
            validate_ssh_command_output(result.payload, vendor)
            relative = job['relative_path']
            relative = store(os.environ['BACKUP_STORAGE_ROOT'], relative, result.payload, vendor, job_id)
            command('engine:complete', job_id, relative, WORKER_ID)
        status = 'succeeded'
    except BackupError as error:
        code = error.code
    except Exception:
        code = 'ENGINE_FAILED'
    finally:
        stop.set()
        monitor.join()
    if code == 'CANCELLED':
        try:
            command('engine:cancel-ack', job_id, WORKER_ID)
            status = 'cancelled'
        except Exception:
            logging.error(json.dumps({'execution_id': job_id, 'status': 'cancel_ack_failed'}))
    elif code:
        try:
            command('engine:fail', job_id, code, WORKER_ID)
        except Exception:
            logging.error(json.dumps({'execution_id': job_id, 'status': 'report_failed'}))
    logging.info(json.dumps({'execution_id': job_id, 'device_id': job['device_id'],
                             'policy_id': job['policy_id'], 'status': status,
                             'error_code': code, 'duration_seconds': round(time.monotonic() - start, 2)}))


def scan_ftp(spontaneous_observed, orphan_observed, preserved_uploads):
    try:
        with ThreadSessions(ARTISAN) as session:
            expected = json.loads(session.command('ftp:expected'))
            accounts = json.loads(session.command('ftp:accounts'))
            scan_spontaneous(os.environ['BACKUP_FTP_ROOT'], os.environ['BACKUP_STORAGE_ROOT'],
                             int(os.environ.get('BACKUP_FTP_STABLE_SECONDS', '5')),
                             spontaneous_observed, expected,
                             lambda device, token, filename, received: json.loads(session.command(
                                 'ftp:receive', device, token,
                                 'n' + base64.urlsafe_b64encode(filename.encode('utf-8')).decode('ascii'),
                                 received, WORKER_ID)),
                             lambda job_id, relative: session.command('engine:complete', job_id, relative, WORKER_ID),
                             lambda job_id, code: session.command('engine:fail', job_id, code, WORKER_ID),
                             accounts,
                             lambda account, token, filename, received, status, size, digest, path, error: session.command(
                                 'ftp:receipt', account, token,
                                 'n' + base64.urlsafe_b64encode(filename.encode('utf-8')).decode('ascii'),
                                 received, status, size, digest, path, error), workers=FTP_SCAN_WORKERS)
            scan_orphans(os.environ['BACKUP_FTP_ROOT'], expected,
                         int(os.environ.get('BACKUP_FTP_STABLE_SECONDS', '5')), orphan_observed,
                         preserved_uploads, accounts)
    except Exception:
        logging.error(json.dumps({'status': 'ftp_orphan_scan_failed'}))


def execute_with_session(job, stack, session_lock):
    if not hasattr(WORKER_SESSION, 'session'):
        with session_lock:
            WORKER_SESSION.session = stack.enter_context(ArtisanSession(ARTISAN))
    execute(job)


def main():
    session_lock = threading.Lock()
    with ExitStack() as stack, ThreadPoolExecutor(max_workers=WORKERS) as pool, \
            ThreadPoolExecutor(max_workers=1) as scanner, ArtisanSession(ARTISAN) as dispatcher:
        active = set()
        orphan_observed = {}
        spontaneous_observed = {}
        ftp_root = os.environ.get('BACKUP_FTP_ROOT')
        preserved_uploads = existing_files(ftp_root) if ftp_root else {}
        scan_future = None
        last_orphan_scan = 0
        last_health_snapshot = 0
        while True:
            if HEALTH_SNAPSHOT_PATH and time.monotonic() - last_health_snapshot >= HEALTH_SNAPSHOT_SECONDS:
                last_health_snapshot = time.monotonic()
                try:
                    snapshot = build_snapshot(registry, WORKER_ID, os.environ.get('BACKUP_STORAGE_ROOT'))
                    write_snapshot(HEALTH_SNAPSHOT_PATH, snapshot)
                except Exception:
                    logging.error(json.dumps({'status': 'health_snapshot_failed'}))
            if time.monotonic() - last_orphan_scan >= 5 and os.environ.get('BACKUP_FTP_ROOT') and (scan_future is None or scan_future.done()):
                last_orphan_scan = time.monotonic()
                scan_future = scanner.submit(scan_ftp, spontaneous_observed, orphan_observed, preserved_uploads)
            active = {future for future in active if not future.done()}
            if len(active) >= WORKERS:
                wait(active, timeout=1, return_when=FIRST_COMPLETED)
                continue
            try:
                job = json.loads(dispatcher.command('engine:claim', WORKER_ID))
                if job:
                    active.add(pool.submit(execute_with_session, job, stack, session_lock))
                else:
                    if active:
                        wait(active, timeout=1, return_when=FIRST_COMPLETED)
                    else:
                        dispatcher.command('engine:wait', 5000)
            except Exception:
                logging.error(json.dumps({'status': 'claim_failed'}))
                time.sleep(5)


if __name__ == '__main__':
    main()
