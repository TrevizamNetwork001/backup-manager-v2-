import sys, os, subprocess, tempfile, json
from pathlib import Path
sys.path.insert(0,'/opt/backup-manager-v2/scripts')
from perf_baseline import control
ROOT=Path(__file__).resolve().parents[1]
import argparse
parser=argparse.ArgumentParser(description='Synthetic two-process PostgreSQL claim barrier')
parser.add_argument('--other-device',action='store_true')
parser.add_argument('--output',type=Path,required=True)
args=parser.parse_args()
PHP='/tmp/perf-1-runtime/bin/php'
BIN='/tmp/perf-1-runtime/usr/lib/postgresql/17/bin'
PG=['-h','/tmp/bm-perf-1-services/socket','-p','55432','-U','perf']
with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as workspace:
 r=Path(workspace)
 for d in ('backups','ftp','views'): (r/d).mkdir()
 db='bm_perf_1_'+r.name.removeprefix('bm-perf-1-')
 env=os.environ.copy(); env.update(PERF_WORKSPACE=workspace,APP_ENV='testing',APP_DEBUG='false',APP_KEY='base64:'+ 'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',DB_CONNECTION='pgsql',DB_HOST=PG[1],PERF_PG_SOCKET=PG[1],DB_PORT='55432',DB_USERNAME='perf',DB_PASSWORD='',DB_URL='',DB_DATABASE=db,LOG_CHANNEL='stderr',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',APP_CONFIG_CACHE=str(r/'config.php'),VIEW_COMPILED_PATH=str(r/'views'),BACKUP_STORAGE_ROOT=str(r/'backups'),BACKUP_FTP_ROOT=str(r/'ftp'))
 subprocess.run([BIN+'/createdb',*PG,'-T','template0','-E','UTF8',db],check=True)
 try:
  subprocess.run([PHP,str(ROOT/'scripts/perf_control.php'),'jobs' if args.other_device else 'same_device','3' if args.other_device else '2'],env=env,check=True,stdout=subprocess.DEVNULL)
  if args.other_device:
   subprocess.run([BIN+'/psql',*PG,'-d',db,'-Atc','UPDATE backup_executions SET device_id=1,device_backup_policy_id=1,credential_id=1 WHERE id=2'],check=True,stdout=subprocess.DEVNULL)
  script=r/'probe.php'; script.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';
$app = require '/opt/backup-manager-v2/app/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || Illuminate\\Support\\Facades\\DB::selectOne('SHOW data_directory')->data_directory !== '/tmp/bm-perf-1-services/pgdata') throw new RuntimeException('not isolated');
Illuminate\\Support\\Facades\\DB::listen(function ($event) {
 if (str_contains($event->sql, 'SKIP LOCKED') && str_contains($event->sql, 'backup_executions')) {
  touch(getenv('PERF_WORKSPACE').'/ready-'.$GLOBALS['argv'][1]);
  $until=microtime(true)+10;
  while(count(glob(getenv('PERF_WORKSPACE').'/ready-*'))<2) { if(microtime(true)>$until) throw new RuntimeException('barrier timeout'); usleep(1000); }
 }
});
$start=hrtime(true);
try { $job=app(App\\Services\\EngineJobService::class)->claim(str_repeat($argv[1],32));
echo json_encode(['claimed'=>$job?->id,'seconds'=>(hrtime(true)-$start)/1e9]).PHP_EOL;
} catch(Throwable $e) {echo json_encode(['error'=>$e->getCode(),'seconds'=>(hrtime(true)-$start)/1e9]).PHP_EOL;exit(1);}
''')
  workers=[subprocess.Popen([PHP,str(script),x],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for x in ('a','b')]
  results=[p.communicate(timeout=20) for p in workers]
  out={'workers':results,'returncodes':[p.returncode for p in workers]}
  query=subprocess.check_output([BIN+'/psql',*PG,'-d',db,'-Atc',"select count(*) from backup_executions where status='running'"]).decode().strip()
  out['running_total']=int(query);out['other_device']=args.other_device;print(json.dumps(out));args.output.write_text(json.dumps(out,indent=2)+'\n')
 finally: subprocess.run([BIN+'/dropdb',*PG,db],check=True)

