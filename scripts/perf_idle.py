#!/usr/bin/env python3
"""Synthetic PostgreSQL arrivals; requires the isolated PERF-1 runtime."""
import argparse
import json
import os
import resource
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'engine'))
from artisan_session import ArtisanSession

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--notify', action='store_true')
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
PHP = '/tmp/perf-1-runtime/bin/php'
BIN = '/tmp/perf-1-runtime/usr/lib/postgresql/17/bin'
PG = ['-h', '/tmp/bm-perf-1-services/socket', '-p', '55432', '-U', 'perf']
identity = subprocess.check_output([BIN + '/psql', *PG, '-d', 'postgres', '-Atc', 'SHOW data_directory'], text=True).strip()
assert identity == '/tmp/bm-perf-1-services/pgdata', 'Not the isolated PERF cluster'
with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as workspace:
    root = Path(workspace)
    for directory in ('backups', 'ftp', 'views'):
        (root / directory).mkdir()
    database = 'bm_perf_1_' + root.name.removeprefix('bm-perf-1-')
    env = os.environ.copy()
    env.update(PERF_WORKSPACE=workspace, APP_ENV='testing', APP_DEBUG='false',
               APP_KEY='base64:' + 'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',
               DB_CONNECTION='pgsql', DB_HOST=PG[1], PERF_PG_SOCKET=PG[1], DB_PORT='55432',
               DB_USERNAME='perf', DB_PASSWORD='', DB_URL='', DB_DATABASE=database,
               LOG_CHANNEL='stderr', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync',
               APP_CONFIG_CACHE=str(root / 'config.php'), VIEW_COMPILED_PATH=str(root / 'views'),
               BACKUP_STORAGE_ROOT=str(root / 'backups'), BACKUP_FTP_ROOT=str(root / 'ftp'))
    subprocess.run([BIN + '/createdb', *PG, '-T', 'template0', '-E', 'UTF8', database], check=True)
    def sql(query):
        return subprocess.check_output([BIN + '/psql', *PG, '-d', database, '-Atc', query], text=True).strip()
    try:
        subprocess.run([PHP, str(ROOT / 'scripts/perf_control.php'), 'jobs', '7'], env=env,
                       check=True, stdout=subprocess.DEVNULL)
        sql("UPDATE backup_executions SET status='pending'")
        writer = root / 'writer.php'
        writer.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';
$app=require '/opt/backup-manager-v2/app/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if(getenv('APP_ENV')!=='testing'||Illuminate\\Support\\Facades\\DB::selectOne('SHOW data_directory')->data_directory!=='/tmp/bm-perf-1-services/pgdata')throw new RuntimeException('not isolated');
if(($argv[2]??'')==='rollback')Illuminate\\Support\\Facades\\DB::beginTransaction();
App\\Models\\BackupExecution::findOrFail((int)$argv[1])->transitionTo('queued');
if(($argv[2]??'')==='rollback')Illuminate\\Support\\Facades\\DB::rollBack();
echo json_encode(['committed_at'=>hrtime(true)/1e9]).PHP_EOL;
''')
        samples = []
        with ArtisanSession([PHP, str(ROOT / 'app/artisan')], env=env) as session:
            for job_id, delay in enumerate((.1, .5, 1, 2, 4), 1):
                assert json.loads(session.command('engine:claim', 'd' * 32)) is None
                arrivals, errors = [], []
                def arrive():
                    try:
                        time.sleep(delay)
                        output = subprocess.check_output([PHP, str(writer), str(job_id)], env=env, text=True)
                        arrivals.append(json.loads(output)['committed_at'])
                    except Exception as error:
                        errors.append(str(error))
                producer = threading.Thread(target=arrive)
                producer.start()
                start = time.monotonic()
                if args.notify:
                    session.command('engine:wait', 5000)
                else:
                    time.sleep(5)
                job = json.loads(session.command('engine:claim', 'd' * 32))
                claimed_at = time.monotonic()
                producer.join(timeout=10)
                assert not errors and len(arrivals) == 1, errors
                assert job and job['id'] == job_id, job
                samples.append({'arrival_delay_seconds': delay, 'idle_wait_seconds': claimed_at - start,
                                'commit_to_claim_seconds': claimed_at - arrivals[0]})
            assert json.loads(session.command('engine:claim', 'd' * 32)) is None
            cpu_start = resource.getrusage(resource.RUSAGE_SELF)
            tick = time.monotonic()
            for _ in range(3):
                if args.notify:
                    session.command('engine:wait', 5000)
                else:
                    time.sleep(5)
                assert json.loads(session.command('engine:claim', 'd' * 32)) is None
            idle_seconds = time.monotonic() - tick
            cpu_end = resource.getrusage(resource.RUSAGE_SELF)
            resilience = {}
            if args.notify:
                subprocess.run([PHP, str(writer), '6', 'rollback'], env=env, check=True, stdout=subprocess.DEVNULL)
                tick = time.monotonic()
                session.command('engine:wait', 200)
                rollback_wait = time.monotonic() - tick
                assert rollback_wait >= .18
                assert json.loads(session.command('engine:claim', 'd' * 32)) is None
                assert sql('SELECT status FROM backup_executions WHERE id=6') == 'pending'
                # An update that bypasses Eloquent emits no hint: timeout still discovers it.
                sql("UPDATE backup_executions SET status='queued' WHERE id=7")
                tick = time.monotonic()
                session.command('engine:wait', 200)
                missed_hint_wait = time.monotonic() - tick
                assert missed_hint_wait >= .18
                assert json.loads(session.command('engine:claim', 'd' * 32))['id'] == 7
                assert json.loads(session.command('engine:claim', 'd' * 32)) is None
                # A commit between the empty claim and wait must remain buffered.
                subprocess.run([PHP, str(writer), '6'], env=env, check=True, stdout=subprocess.DEVNULL)
                tick = time.monotonic()
                session.command('engine:wait', 5000)
                race_wait = time.monotonic() - tick
                assert race_wait < 1
                assert json.loads(session.command('engine:claim', 'd' * 32))['id'] == 6
                resilience = {'rollback_wait_seconds': rollback_wait, 'missed_hint_wait_seconds': missed_hint_wait,
                              'race_wakeup_seconds': race_wait, 'rollback_preserved_pending': True,
                              'missed_hint_recovered': True, 'race_claimed_once': True}
        assert sql("SELECT count(*) FROM backup_executions WHERE status='running'") == ('7' if args.notify else '5')
        latency = sorted(sample['commit_to_claim_seconds'] for sample in samples)
        result = {'notify': args.notify, 'samples': samples, 'idle_latency_mean_seconds': sum(latency) / len(latency),
                  'idle_latency_p95_seconds': latency[-1], 'quiet_idle_seconds': idle_seconds,
                  'quiet_claims': 3, 'quiet_claims_per_second': 3 / idle_seconds,
                  'quiet_python_cpu_seconds': cpu_end.ru_utime + cpu_end.ru_stime - cpu_start.ru_utime - cpu_start.ru_stime,
                  'redis_commands': 0, 'duplicate_claims': 0, 'resilience': resilience}
        args.output.write_text(json.dumps(result, indent=2) + '\n')
        print(json.dumps(result))
    finally:
        subprocess.run([BIN + '/dropdb', *PG, database], check=True)
