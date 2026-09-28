#!/usr/bin/env python3
"""PERF-1: real engine + isolated Artisan/database and synthetic local uploads.

Never imports production credentials, opens equipment sockets, or uses Docker.
Each scenario has a fresh /tmp workspace; PostgreSQL requires a temporary socket.
Usage: PYTHONPATH=engine python3 scripts/perf_baseline.py --php /path/to/php --output /tmp/results.json
"""

import argparse
import collections
import hashlib
import json
import logging
import math
import os
import resource
import statistics
import subprocess
import sys
import tempfile
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from pathlib import Path
from unittest.mock import patch

PROJECT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PROJECT / 'engine'))
import backup_engine
import ftp_spontaneous
import storage
from drivers import mikrotik_ssh


class Finished(BaseException):
    pass


def distribution(values):
    values = sorted(values)
    return {'mean_seconds': statistics.mean(values) if values else None,
            'p95_seconds': values[math.ceil(len(values) * .95) - 1] if values else None}


def io_counters():
    return {key: int(value) for key, value in
            (line.split(':') for line in Path('/proc/self/io').read_text().splitlines())}


@contextmanager
def measured(result):
    start = time.monotonic()
    usage = resource.getrusage(resource.RUSAGE_SELF)
    children = resource.getrusage(resource.RUSAGE_CHILDREN)
    before = io_counters()
    yield
    after = resource.getrusage(resource.RUSAGE_SELF)
    child_after = resource.getrusage(resource.RUSAGE_CHILDREN)
    result.update(seconds=time.monotonic() - start, cpu_seconds=after.ru_utime + after.ru_stime - usage.ru_utime - usage.ru_stime,
                  child_cpu_seconds=child_after.ru_utime + child_after.ru_stime - children.ru_utime - children.ru_stime,
                  peak_rss_kib=after.ru_maxrss, child_peak_rss_kib=child_after.ru_maxrss,
                  io_delta={key: value - before[key] for key, value in io_counters().items()})


def control(php, mode, count):
    if mode == 'enqueue':
        with backup_engine.ArtisanSession([php, str(PROJECT / 'app/artisan')]) as listener:
            listener.command('engine:wait', 1)
            output = subprocess.check_output([php, str(PROJECT / 'scripts/perf_control.php'), mode, str(count)])
        return json.loads(output)
    output = subprocess.check_output([php, str(PROJECT / 'scripts/perf_control.php'), mode, str(count)])
    return json.loads(output)


def engine_load(php, count, delay, same_device=False, workers=4, scanner_files=0):
    control(php, 'same_device' if same_device else 'jobs', count)
    scanner_files = scanner_files if count >= 100 else 0
    if scanner_files:
        root = Path(os.environ['PERF_WORKSPACE'])
        probe = root / 'scanner.php'
        probe.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';$app=require '/opt/backup-manager-v2/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;use Illuminate\\Support\\Facades\\Artisan;
if(getenv('APP_ENV')!=='testing'||DB::selectOne('SHOW data_directory')->data_directory!=='/tmp/bm-perf-1-services/pgdata')throw new RuntimeException('not isolated');
if($argv[1]==='seed') {
 $a=new App\\Models\\FtpAccount(['device_id'=>null,'account_uuid'=>(string)Illuminate\\Support\\Str::uuid(),'purpose'=>'file_server','home_layout'=>'account','username'=>'perf_scanner','is_active'=>true]);$a->secret='synthetic-only';$a->save();DB::table('ftp_accounts')->where('id',$a->id)->update(['provisioned_at'=>now(),'sync_error'=>null]);
 Artisan::call('ftp:accounts');echo Artisan::output();
} else {echo json_encode(DB::table('ftp_received_files')->get(['claim_token','relative_path','size_bytes','sha256','status']));}
''')
        accounts = json.loads(subprocess.check_output([php, str(probe), 'seed']))
        home = Path(accounts[0]['home'])
        assert home.is_relative_to(root / 'ftp')
        home.mkdir(parents=True)
        for index in range(scanner_files):
            (home / f'upload-{index}.cfg').write_bytes(b'x' * 4096)
    result = {'scenario': 'engine_same_device' if same_device else 'engine_queue', 'jobs': count,
              'driver_delay_seconds': delay, 'control_plane': 'real Artisan/' + os.environ['DB_CONNECTION'], 'workers': workers,
              'scanner_files': scanner_files}
    started, ended, durations, queues = {}, {}, [], []
    operations = collections.Counter()
    commands = []
    subprocess_errors = collections.Counter()
    processes = collections.Counter()
    active = set()
    peak = 0
    overlap = 0
    lock = threading.Lock()
    monitor = None
    workspace = Path(os.environ['PERF_WORKSPACE'])
    if os.environ['DB_CONNECTION'] == 'pgsql':
        monitor = subprocess.Popen([php, str(PROJECT / 'scripts/perf_monitor.php')],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        deadline = time.monotonic() + 10
        while not (workspace / 'monitor-ready').exists():
            if monitor.poll() is not None or time.monotonic() > deadline:
                monitor.kill()
                raise RuntimeError('Isolated PostgreSQL sampler did not start')
            time.sleep(.01)
    original = backup_engine.command
    original_run = subprocess.run
    original_popen = subprocess.Popen
    original_session_command = backup_engine.ArtisanSession.command
    epoch = time.monotonic()

    def command(*args, **kwargs):
        if args[0] == 'engine:claim' and (len(ended) >= count or
                (len(started) >= count and not active) or time.monotonic() - epoch > 180):
            raise Finished()
        tick = time.monotonic()
        value = original(*args, **kwargs)
        with lock:
            operations[args[0]] += 1
            commands.append(time.monotonic() - tick)
            if args[0] in ('engine:complete', 'engine:fail', 'engine:cancel-ack'):
                ended[args[1]] = time.monotonic() - epoch
        return value

    def execute(job):
        nonlocal peak, overlap
        tick = time.monotonic()
        with lock:
            if job['device_id'] in active:
                overlap += 1
            active.add(job['device_id'])
            peak = max(peak, len(active))
            started[job['id']] = tick - epoch
            queues.append(tick - epoch)
        try:
            original_execute(job)
        finally:
            with lock:
                active.remove(job['device_id'])
                durations.append(time.monotonic() - tick)

    def export(*args, **kwargs):
        time.sleep(delay)
        return b'# synthetic RouterOS\n/interface bridge\nadd name=perf\n'

    original_execute = backup_engine.execute
    def popen(args, *positional, **kwargs):
        if isinstance(args, list) and args[:2] == [php, str(PROJECT / 'app/artisan')]:
            processes[args[2]] += 1
        return original_popen(args, *positional, **kwargs)

    def session_command(session, *args):
        if args[0] == 'engine:claim' and (len(ended) >= count or
                (len(started) >= count and not active) or time.monotonic() - epoch > 180):
            raise Finished()
        tick = time.monotonic()
        value = original_session_command(session, *args)
        with lock:
            if args[0] in ('engine:claim', 'ftp:expected', 'ftp:accounts'):
                operations[args[0]] += 1
                commands.append(time.monotonic() - tick)
        return value

    def run(*args, **kwargs):
        value = original_run(*args, **kwargs)
        if value.returncode:
            text = (value.stderr or b'') + (value.stdout or b'')
            subprocess_errors['database_locked' if b'database is locked' in text else 'other'] += 1
        return value
    with patch.object(backup_engine, 'ARTISAN', [php, str(PROJECT / 'app/artisan')]), \
            patch.object(backup_engine, 'WORKERS', workers, create=True), \
            patch.object(backup_engine, 'command', side_effect=command), \
            patch.object(backup_engine, 'execute', side_effect=execute), \
            patch.object(backup_engine.ArtisanSession, 'command', session_command), \
            patch.object(subprocess, 'Popen', side_effect=popen), \
            patch.object(backup_engine.subprocess, 'run', side_effect=run), \
            patch.object(mikrotik_ssh, 'export_config', side_effect=export), measured(result):
        try:
            backup_engine.main()
        except Finished:
            pass
        finally:
            if monitor:
                (workspace / 'monitor-stop').touch()
                output, errors = monitor.communicate(timeout=10)
                if monitor.returncode:
                    raise RuntimeError('Isolated PostgreSQL sampler failed')
                result['database_activity'] = json.loads(output)
    status = control(php, 'status', count)
    if scanner_files:
        receipts = json.loads(subprocess.check_output([php, str(probe), 'verify']))
        assert len(receipts) == scanner_files
        assert len({receipt['claim_token'] for receipt in receipts}) == scanner_files
        for receipt in receipts:
            assert receipt['status'] == 'stored'
            path = root / 'backups' / receipt['relative_path']
            assert path.stat().st_size == receipt['size_bytes']
            assert hashlib.sha256(path.read_bytes()).hexdigest() == receipt['sha256']
        assert not list((root / 'ftp/processing').iterdir())
        result['scanner_receipts_verified'] = len(receipts)
    result.update(throughput_jobs_min=60 * status['statuses'].get('succeeded', 0) / result['seconds'], latency=distribution(list(ended.values())),
                  execution=distribution(durations), queue=distribution(queues), command=distribution(commands),
                  artisan_operations=dict(operations), subprocess_count=sum(processes.values()),
                  subprocess_commands=dict(processes), peak_active_devices=peak, device_overlap=overlap,
                  failure_rate=1 - status['statuses'].get('succeeded', 0) / count, database_result=status,
                  subprocess_errors=dict(subprocess_errors))
    result['attempted_jobs'] = len(started)
    result['unreported_jobs'] = len(set(started) - set(ended))
    assert overlap == 0, result
    assert sum(status['statuses'].values()) == count, result
    return result


def uploads(count, size, purpose, callback_delay=0):
    root, backups = os.environ['BACKUP_FTP_ROOT'], os.environ['BACKUP_STORAGE_ROOT']
    home = Path(root, '7/incoming')
    account = {'id': 11, 'device_id': 7, 'home_layout': 'legacy', 'home': str(home), 'purpose': purpose,
               'is_active': True, 'ready_for_receive': True, 'account_uuid': '4a923771-0d3d-4cd2-b3af-bcdbf2036d20'}
    home.mkdir(parents=True)
    result = {'scenario': purpose + '_uploads', 'uploads': count, 'bytes_each': size, 'producer_threads': 8,
              'transport': 'synthetic filesystem, not FTP wire', 'callback_delay_seconds': callback_delay}
    receipts, jobs, completed, failed = {}, {}, [], []
    hashes, publications, latency = [], [], []
    payload = b'x' * size
    observed = {}
    original_validate = ftp_spontaneous.validate_received_file_integrity
    original_store = ftp_spontaneous.store

    def timed_validate(*args, **kwargs):
        start = time.monotonic()
        value = original_validate(*args, **kwargs)
        hashes.append(time.monotonic() - start)
        return value

    def timed_store(*args, **kwargs):
        start = time.monotonic()
        value = original_store(*args, **kwargs)
        publications.append(time.monotonic() - start)
        return value

    def receive(device, token, filename, received):
        time.sleep(callback_delay)
        jobs.setdefault(token, {'id': len(jobs) + 1, 'status': 'running', 'ftp_account_id': 11,
                               'relative_path': f'Backup Manager/LAB/OLT/28-09-2026/OLT_{len(jobs)+1:014d}.cfg'})
        return jobs[token]

    def receipt(*args):
        time.sleep(callback_delay)
        receipts[args[1]] = args
        if args[4] == 'stored':
            latency.append(time.monotonic() - epoch)

    def scan():
        ftp_spontaneous.scan(root, backups, 0, observed, [], receive,
                             lambda *args: completed.append(args), lambda *args: failed.append(args), [account], receipt)

    epoch = time.monotonic()
    with measured(result), patch.object(ftp_spontaneous, 'validate_received_file_integrity', side_effect=timed_validate), \
            patch.object(ftp_spontaneous, 'store', side_effect=timed_store):
        with ThreadPoolExecutor(max_workers=8) as producers:
            list(producers.map(lambda i: (home / f'upload-{i}.bin').write_bytes(payload), range(count)))
        scan()
        scan()
        scan()  # No repeated receipt or publication after completion.
    stored = [item for item in receipts.values() if item[4] == 'stored']
    assert len(stored) == count and not failed and not list(Path(root, 'processing').iterdir()), result
    for item in stored:
        path = Path(backups, item[7])
        assert path.stat().st_size == size and hashlib.sha256(path.read_bytes()).hexdigest() == item[6]
    result.update(throughput_uploads_min=60 * count / result['seconds'], latency=distribution(latency),
                  integrity_read_and_hash=distribution(hashes), artifact_publication=distribution(publications),
                  unique_receipts=len(receipts), executions=len(jobs), failure_rate=len(failed) / count)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--only', choices=['engine', 'control', 'ftp', 'secret', 'enqueue'])
    parser.add_argument('--engine-cases', choices=['all', 'remaining', 'quick'], default='all')
    parser.add_argument('--workers', type=int, choices=[1, 2, 4], default=4)
    parser.add_argument('--scanner-files', type=int, choices=[0, 1000], default=0)
    parser.add_argument('--execution-model', type=Path)
    parser.add_argument('--pg-socket', type=Path)
    parser.add_argument('--pg-bin', type=Path)
    args = parser.parse_args()
    if args.execution_model and (args.only != 'enqueue' or not str(args.execution_model).startswith('/tmp/perf-3-')):
        parser.error('--execution-model is a PERF-3 copy for enqueue only')
    if args.pg_socket and (not args.pg_bin or args.pg_socket.name != 'socket'
            or args.pg_socket.parent.parent != Path('/tmp')
            or not args.pg_socket.parent.name.startswith('bm-perf-1-')):
        parser.error('PostgreSQL requires --pg-bin and /tmp/bm-perf-1-*/socket')
    logging.getLogger().setLevel(logging.CRITICAL)
    if args.scanner_files and not args.pg_socket:
        parser.error('--scanner-files requires isolated PostgreSQL')
    results = []
    scenarios = []
    if args.only == 'secret':
        scenarios += [('control', 'secret', 100)]
    if args.only == 'enqueue':
        scenarios += [('control', 'enqueue', 1000)]
    if args.only in (None, 'engine'):
        counts = (20,) if args.engine_cases == 'quick' else ((100,) if args.engine_cases == 'remaining' else (20, 50, 100))
        scenarios += [('engine', n, .02, False, args.workers, args.scanner_files) for n in counts]
        if args.engine_cases != 'quick':
            scenarios += [('engine', 20, 2., False, args.workers, 0), ('engine', 5, .1, True, args.workers, 0)]
    if args.only in (None, 'control'):
        scenarios += [('control', mode, n) for mode, n in [('scheduler', 100), ('scheduler', 1000),
                      ('retention', 1000), ('retention', 10000), ('stale', 1000), ('retry', 100), ('cancel', 100)]]
    if args.only in (None, 'ftp'):
        scenarios += [('ftp', n, size, purpose, delay) for n, size, purpose, delay in
                      [(100, 4096, 'backup', 0), (1000, 4096, 'backup', 0), (100, 4096, 'file_server', 0),
                       (1000, 4096, 'file_server', 0), (4, 8*1024*1024, 'backup', 0),
                       (4, 64*1024*1024, 'file_server', 0), (100, 4096, 'backup', .01)]]
    for scenario in scenarios:
        with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as workspace:
            root = Path(workspace)
            (root / 'backups').mkdir()
            (root / 'ftp').mkdir()
            (root / 'views').mkdir()
            (root / 'database.sqlite').touch()
            environment = {'PERF_WORKSPACE': workspace, 'APP_ENV': 'testing', 'APP_DEBUG': 'false',
                           'APP_KEY': 'base64:' + 'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',
                           'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(root / 'database.sqlite'), 'DB_URL': '',
                           'LOG_CHANNEL': 'stderr', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
                           'QUEUE_CONNECTION': 'sync', 'VIEW_COMPILED_PATH': str(root / 'views'),
                           'APP_CONFIG_CACHE': str(root / 'config.php'),
                           'BACKUP_STORAGE_ROOT': str(root / 'backups'), 'BACKUP_FTP_ROOT': str(root / 'ftp'),
                           'BACKUP_FTP_MAX_BYTES': str(64*1024*1024), 'BACKUP_ENGINE_HEALTH_SNAPSHOT_PATH': '',
                           'BACKUP_FTP_STABLE_SECONDS': '0'}
            environment['PERF_EXECUTION_MODEL_FILE'] = str(args.execution_model) if args.execution_model else ''
            database = 'bm_perf_1_' + root.name.removeprefix('bm-perf-1-')
            pg_args = ['-h', str(args.pg_socket), '-p', '55432', '-U', 'perf']
            if args.pg_socket:
                # Read-only identity check precedes database creation.
                directory = subprocess.check_output([str(args.pg_bin / 'psql'), *pg_args,
                    '-d', 'postgres', '-Atc', 'SHOW data_directory']).decode().strip()
                if directory != str(args.pg_socket.parent / 'pgdata'):
                    raise RuntimeError('Not the isolated PERF cluster')
                subprocess.run([str(args.pg_bin / 'createdb'), *pg_args, '-T', 'template0', '-E', 'UTF8', database], check=True)
                environment.update(DB_CONNECTION='pgsql', DB_DATABASE=database,
                    DB_HOST=str(args.pg_socket), DB_PORT='55432', DB_USERNAME='perf', DB_PASSWORD='',
                    PERF_PG_SOCKET=str(args.pg_socket))
            try:
                with patch.dict(os.environ, environment):
                    if scenario[0] == 'engine':
                        result = engine_load(args.php, *scenario[1:])
                    elif scenario[0] == 'ftp':
                        result = uploads(*scenario[1:])
                    else:
                        result = control(args.php, *scenario[1:])
                    result['database_backend'] = environment['DB_CONNECTION']
            finally:
                if args.pg_socket:
                    subprocess.run([str(args.pg_bin / 'dropdb'), *pg_args, database], check=True)
            results.append(result)
            args.output.write_text(json.dumps(results, indent=2) + '\n')
            print(json.dumps({key: value for key, value in result.items() if key not in ('job_ids', 'slowest')}), flush=True)


if __name__ == '__main__':
    main()
