import json
import logging
import os
import secrets
import subprocess
import threading
import time

from drivers.mikrotik_ssh import BackupError, export_config
from storage import store


logging.basicConfig(level=logging.INFO, format='%(message)s')
logging.getLogger('paramiko').setLevel(logging.WARNING)
ARTISAN = ['php', '/var/www/html/artisan']
WORKER_ID = secrets.token_hex(16)
HEARTBEAT_SECONDS = max(1, int(os.environ.get('BACKUP_ENGINE_HEARTBEAT_SECONDS', '30')))


def command(*args, env=None, pass_fds=()):
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
    def heartbeat():
        while not stop.wait(HEARTBEAT_SECONDS):
            try:
                command('engine:heartbeat', job_id, WORKER_ID)
            except Exception:
                logging.error(json.dumps({'execution_id': job_id, 'status': 'heartbeat_failed'}))
                break
    monitor = threading.Thread(target=heartbeat, daemon=True)
    monitor.start()
    try:
        if job['method'] != 'ssh_pull' or job['artifact_mode'] != 'config':
            raise BackupError('UNSUPPORTED_POLICY')
        if job['vendor'].strip().casefold() != 'mikrotik':
            raise BackupError('UNSUPPORTED_VENDOR')
        if not job['eligible']:
            raise BackupError('CREDENTIAL_INVALID')
        password = secret_for(job_id)
        data = export_config(job['host'], job['port'], job['username'], password,
                             job.get('ssh_host_key_algorithm'), job.get('ssh_host_key_fingerprint'),
                             lambda algorithm, fingerprint: command('engine:observe-host-key', job_id,
                                                                    WORKER_ID, job['host'], algorithm, fingerprint))
        del password
        relative = job['relative_path']
        store(os.environ['BACKUP_STORAGE_ROOT'], relative, data)
        command('engine:complete', job_id, relative, WORKER_ID)
        status = 'succeeded'
    except BackupError as error:
        code = error.code
    except Exception:
        code = 'ENGINE_FAILED'
    finally:
        stop.set()
        monitor.join()
    if code:
        try:
            command('engine:fail', job_id, code, WORKER_ID)
        except Exception:
            logging.error(json.dumps({'execution_id': job_id, 'status': 'report_failed'}))
    logging.info(json.dumps({'execution_id': job_id, 'device_id': job['device_id'],
                             'policy_id': job['policy_id'], 'status': status,
                             'error_code': code, 'duration_seconds': round(time.monotonic() - start, 2)}))


def main():
    while True:
        try:
            job = json.loads(command('engine:claim', WORKER_ID))
            if job:
                execute(job)
            else:
                time.sleep(5)
        except Exception:
            logging.error(json.dumps({'status': 'claim_failed'}))
            time.sleep(5)


if __name__ == '__main__':
    main()
