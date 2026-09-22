import json
import logging
import os
import subprocess
import time

from drivers.mikrotik_ssh import BackupError, export_config
from storage import store


logging.basicConfig(level=logging.INFO, format='%(message)s')
ARTISAN = ['php', '/var/www/html/artisan']


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
        command('engine:secret', job_id, env=env, pass_fds=(write_fd,))
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
    try:
        if job['method'] != 'ssh_pull' or job['artifact_mode'] != 'config':
            raise BackupError('UNSUPPORTED_POLICY')
        if job['vendor'].strip().casefold() != 'mikrotik':
            raise BackupError('UNSUPPORTED_VENDOR')
        if not job['eligible']:
            raise BackupError('CREDENTIAL_INVALID')
        password = secret_for(job_id)
        data = export_config(job['host'], job['port'], job['username'], password)
        del password
        relative = job['relative_path']
        store(os.environ['BACKUP_STORAGE_ROOT'], relative, data)
        command('engine:complete', job_id, relative)
        status = 'succeeded'
    except BackupError as error:
        code = error.code
    except Exception:
        code = 'ENGINE_FAILED'
    if code:
        try:
            command('engine:fail', job_id, code)
        except Exception:
            logging.error(json.dumps({'execution_id': job_id, 'status': 'report_failed'}))
    logging.info(json.dumps({'execution_id': job_id, 'device_id': job['device_id'],
                             'policy_id': job['policy_id'], 'status': status,
                             'error_code': code, 'duration_seconds': round(time.monotonic() - start, 2)}))


def main():
    while True:
        try:
            job = json.loads(command('engine:claim'))
            if job:
                execute(job)
            else:
                time.sleep(5)
        except Exception:
            logging.error(json.dumps({'status': 'claim_failed'}))
            time.sleep(5)


if __name__ == '__main__':
    main()
