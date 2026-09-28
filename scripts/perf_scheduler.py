import os,sys,json,subprocess,tempfile,time
from pathlib import Path
ROOT=Path('/opt/backup-manager-v2'); PHP='/tmp/perf-1-runtime/bin/php'; BIN='/tmp/perf-1-runtime/usr/lib/postgresql/17/bin'; LIB='/tmp/perf-1-runtime/usr/lib/x86_64-linux-gnu'; SOCKET='/tmp/bm-perf-1-services/socket'
PG=['-h',SOCKET,'-p','55432','-U','perf']; REDIS='/tmp/perf-1-runtime/usr/bin/redis-cli'; out={}
import argparse,time
parser=argparse.ArgumentParser(description='Isolated scheduler with Redis unavailable')
parser.add_argument('--output',type=Path,required=True)
args=parser.parse_args()
with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as name:
 r=Path(name)
 for d in ('backups','ftp','views'):(r/d).mkdir()
 db='bm_perf_1_'+r.name.removeprefix('bm-perf-1-');env=os.environ.copy();env.update(PERF_WORKSPACE=name,APP_ENV='testing',APP_DEBUG='false',APP_KEY='base64:'+'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',DB_CONNECTION='pgsql',DB_HOST=SOCKET,PERF_PG_SOCKET=SOCKET,DB_PORT='55432',DB_USERNAME='perf',DB_PASSWORD='',DB_URL='',DB_DATABASE=db,LOG_CHANNEL='stderr',CACHE_STORE='redis',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',APP_CONFIG_CACHE=str(r/'config.php'),VIEW_COMPILED_PATH=str(r/'views'),BACKUP_STORAGE_ROOT=str(r/'backups'),BACKUP_FTP_ROOT=str(r/'ftp'),REDIS_HOST='127.0.0.1',REDIS_PORT='56379',REDIS_PASSWORD='null',REDIS_PREFIX='bm_perf_1_probe_',CACHE_PREFIX='bm_perf_1_probe_cache_',BACKUP_ENGINE_HEALTH_SNAPSHOT_PATH='')
 subprocess.run([BIN+'/createdb',*PG,'-T','template0','-E','UTF8',db],check=True)
 def sql(q):return subprocess.check_output([BIN+'/psql',*PG,'-d',db,'-Atc',q],text=True).strip()
 php=r/'probe.php';php.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';
$app = require '/opt/backup-manager-v2/app/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB; use Illuminate\\Support\\Facades\\Cache; use Illuminate\\Support\\Facades\\Artisan;
if (getenv('APP_ENV')!=='testing' || config('database.connections.pgsql.host')!=='/tmp/bm-perf-1-services/socket') throw new RuntimeException('not isolated');
$mode=$argv[1];$engine=app(App\\Services\\EngineJobService::class);
if ($mode==='claim') {
 $start=hrtime(true); $job=$engine->claim(str_repeat('a',32));echo json_encode(['id'=>$job?->id,'seconds'=>(hrtime(true)-$start)/1e9]).PHP_EOL;
} elseif ($mode==='hold') {
 DB::transaction(function () {DB::select('SELECT id FROM backup_executions WHERE id=1 FOR UPDATE'); echo "held\\n";flush();usleep(600000);});
} elseif ($mode==='lifecycle') {
 $job=$engine->claim(str_repeat('b',32));$engine->fail($job->id,'SSH_TIMEOUT',str_repeat('b',32));echo json_encode(['job'=>$job->id,'status'=>$job->fresh()->status]).PHP_EOL;
} elseif ($mode==='mutex') {
 $event=collect(app(Illuminate\\Console\\Scheduling\\Schedule::class)->events())->first(fn($e)=>str_contains($e->command ?? '', 'backups:schedule'));
 $first=$event->mutex->create($event);$second=$event->mutex->create($event);$exists=$event->mutex->exists($event);$event->mutex->forget($event);
 echo json_encode(compact('first','second','exists')).PHP_EOL;
} elseif ($mode==='scheduler') {
 Artisan::call('backups:schedule');echo json_encode(['created'=>(int)trim(Artisan::output()),'total'=>App\\Models\\BackupExecution::count()]).PHP_EOL;
} elseif ($mode==='due') {
 DB::table('backup_executions')->delete();DB::table('backup_policies')->update(['schedule_type'=>'daily','schedule_time'=>now(app(App\\Services\\InstanceTimezone::class)->get())->format('H:i')]); echo "ready\\n";
} elseif ($mode==='scheduled') {
 foreach(app(Illuminate\\Console\\Scheduling\\Schedule::class)->events() as $event) { if ($event->command) $event->command=str_replace(PHP_BINARY, '/tmp/perf-1-runtime/bin/php', $event->command); }
 try {Artisan::call('schedule:run'); echo json_encode(['ran'=>true,'total'=>App\\Models\\BackupExecution::count()]).PHP_EOL;} catch (Throwable $e) {echo json_encode(['exception'=>get_class($e),'total'=>App\\Models\\BackupExecution::count()]).PHP_EOL;}
}
''')
 def call(mode,check=True):
  p=subprocess.run([PHP,str(php),mode],env=env,text=True,capture_output=True,timeout=25)
  if check and p.returncode:raise RuntimeError(p.stderr[-1500:])
  try:return json.loads(p.stdout.strip().splitlines()[-1])
  except Exception:return {'returncode':p.returncode,'error':p.stdout[-500:]}
 try:
  subprocess.run([PHP,str(ROOT/'scripts/perf_control.php'),'jobs','2'],env=env,check=True,stdout=subprocess.DEVNULL)
  call('due')
  tick=time.monotonic();out['scheduled_redis_offline']=call('scheduled');out['seconds']=time.monotonic()-tick
  out['repeat']=call('scheduled')
  print(json.dumps(out),flush=True)
 finally:subprocess.run([BIN+'/dropdb',*PG,db],check=True)
args.output.write_text(json.dumps(out,indent=2)+'\n')

