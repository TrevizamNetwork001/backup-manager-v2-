import json
import logging
import os
import secrets
import subprocess
import threading
import time
from concurrent.futures import ThreadPoolExecutor

from drivers.mikrotik_ssh import BackupError, export_config
from drivers.huawei_vrp_ssh import export_config as export_huawei_config
from drivers.huawei_olt_ftp import collect_config as collect_huawei_olt_config
from ftp_incoming import existing_files, scan_orphans
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
        drivers = {'mikrotik': export_config, 'huawei': export_huawei_config}
        vendor = job['vendor'].strip().casefold()
        driver = drivers.get(vendor)
        if driver is None:
            raise BackupError('UNSUPPORTED_VENDOR')
        olt = (vendor == 'huawei' and job['platform'] == 'olt' and job['method'] == 'ftp_push'
               and job.get('schedule_type') == 'manual' and job.get('origin') == 'manual')
        pull = job['platform'] == 'network' and job['method'] == 'ssh_pull'
        if not (olt or pull) or job['artifact_mode'] != 'config':
            raise BackupError('UNSUPPORTED_POLICY')
        if olt and (not job['ftp_account_available'] or not job['ftp_host']):
            raise BackupError('FTP_ACCOUNT_UNAVAILABLE')
        if not job['eligible']:
            raise BackupError('CREDENTIAL_INVALID')
        if olt:
            collect_huawei_olt_config(job,
                                      lambda relative: command('engine:complete', job_id, relative, WORKER_ID))
        else:
            password = secret_for(job_id)
            observe = lambda algorithm, fingerprint: command('engine:observe-host-key', job_id,
                                                             WORKER_ID, job['host'], algorithm, fingerprint)
            data = driver(job['host'], job['port'], job['username'], password,
                          job.get('ssh_host_key_algorithm'), job.get('ssh_host_key_fingerprint'), observe)
            del password
            relative = job['relative_path']
            relative = store(os.environ['BACKUP_STORAGE_ROOT'], relative, data, vendor, job_id)
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
    with ThreadPoolExecutor(max_workers=4) as pool:
        active = set()
        orphan_observed = {}
        ftp_root = os.environ.get('BACKUP_FTP_ROOT')
        preserved_uploads = existing_files(ftp_root) if ftp_root else {}
        last_orphan_scan = 0
        while True:
            if time.monotonic() - last_orphan_scan >= 5 and os.environ.get('BACKUP_FTP_ROOT'):
                last_orphan_scan = time.monotonic()
                try:
                    expected = json.loads(command('ftp:expected'))
                    scan_orphans(os.environ['BACKUP_FTP_ROOT'], expected,
                                 int(os.environ.get('BACKUP_FTP_STABLE_SECONDS', '5')), orphan_observed,
                                 preserved_uploads)
                except Exception:
                    logging.error(json.dumps({'status': 'ftp_orphan_scan_failed'}))
            active = {future for future in active if not future.done()}
            if len(active) >= 4:
                time.sleep(1)
                continue
            try:
                job = json.loads(command('engine:claim', WORKER_ID))
                if job:
                    active.add(pool.submit(execute, job))
                else:
                    time.sleep(5)
            except Exception:
                logging.error(json.dumps({'status': 'claim_failed'}))
                time.sleep(5)


if __name__ == '__main__':
    main()
