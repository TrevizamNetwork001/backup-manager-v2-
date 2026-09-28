import os,subprocess,tempfile,time,json
from pathlib import Path
import argparse
parser=argparse.ArgumentParser(description='Isolated synthetic retention/lock comparison')
parser.add_argument('--output',type=Path,required=True)
args=parser.parse_args()
PHP='/tmp/perf-1-runtime/bin/php';BIN='/tmp/perf-1-runtime/usr/lib/postgresql/17/bin';PG=['-h','/tmp/bm-perf-1-services/socket','-p','55432','-U','perf'];out={}
with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as name:
 r=Path(name)
 for d in ('backups','ftp','views'):(r/d).mkdir()
 db='bm_perf_1_'+r.name.removeprefix('bm-perf-1-');env=os.environ.copy();env.update(PERF_WORKSPACE=name,APP_ENV='testing',APP_DEBUG='false',APP_KEY='base64:'+'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',DB_CONNECTION='pgsql',DB_HOST=PG[1],PERF_PG_SOCKET=PG[1],DB_PORT='55432',DB_USERNAME='perf',DB_PASSWORD='',DB_URL='',DB_DATABASE=db,LOG_CHANNEL='stderr',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',APP_CONFIG_CACHE=str(r/'config.php'),VIEW_COMPILED_PATH=str(r/'views'),BACKUP_STORAGE_ROOT=str(r/'backups'),BACKUP_FTP_ROOT=str(r/'ftp'))
 subprocess.run([BIN+'/createdb',*PG,'-T','template0','-E','UTF8',db],check=True)
 def sql(q):return subprocess.check_output([BIN+'/psql',*PG,'-d',db,'-Atc',q],text=True).strip()
 try:
  seed=subprocess.check_output([PHP,'/opt/backup-manager-v2/scripts/perf_control.php','retention','10000'],env=env,text=True);out['initial_dry_run']={k:v for k,v in json.loads(seed).items() if k not in ('job_ids','slowest')};print('seeded',flush=True)
  probe=r/'probe.php';probe.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';$app=require '/opt/backup-manager-v2/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
if(getenv('APP_ENV')!=='testing'||DB::selectOne('SHOW data_directory')->data_directory!=='/tmp/bm-perf-1-services/pgdata')throw new RuntimeException('not isolated');
$count=0;$ms=0;$locked=null;
DB::listen(function($event) use (&$count,&$ms,&$locked) { $count++;$ms+=$event->time;if($locked===null && str_contains($event->sql,'backup_artifacts')&&str_starts_with($event->sql,'select')) { $locked=hrtime(true);touch(getenv('PERF_WORKSPACE').'/locked'); } });
$start=hrtime(true);$result=app(App\\Services\\BackupRetention::class)->run($argv[1]==='apply',Carbon\\CarbonImmutable::parse('2026-09-28 12:00:00','UTC'));
echo json_encode(['seconds'=>(hrtime(true)-$start)/1e9,'locked_seconds'=>$locked ? (hrtime(true)-$locked)/1e9 : null,'peak_php_bytes'=>memory_get_peak_usage(true),'query_count'=>$count,'query_ms'=>$ms,'result'=>$result]).PHP_EOL;
''')
  log=open('/tmp/perf-2-retention-worker.log','w');worker=subprocess.Popen([PHP,str(probe),'dry'],env=env,text=True,stdout=subprocess.PIPE,stderr=log)
  deadline=time.monotonic()+30
  while not (r/'locked').exists():
   if worker.poll() is not None:raise RuntimeError('retention finished before lock probe')
   if time.monotonic()>deadline:raise RuntimeError('lock signal timeout')
   time.sleep(.002)
  tick=time.monotonic();contender_env=os.environ.copy();contender_env['PGAPPNAME']='bm_perf_retention_contender'
  contender=subprocess.Popen([BIN+'/psql',*PG,'-d',db,'-Atc','UPDATE backup_artifacts SET updated_at=updated_at WHERE id=1'],env=contender_env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  observed=[]
  while contender.poll() is None:
   sample=sql("select coalesce(wait_event_type,'')||':'||coalesce(wait_event,'')||':'||cardinality(pg_blocking_pids(pid)) from pg_stat_activity where application_name='bm_perf_retention_contender'")
   if sample:observed.append(sample)
   time.sleep(.015)
  result,errors=contender.communicate(timeout=5);out['contender_wall_seconds']=time.monotonic()-tick;assert contender.returncode==0,errors
  result,_=worker.communicate(timeout=30);assert worker.returncode==0;out['dry_run_lock_probe']=json.loads(result);out['wait_events']=sorted(set(observed));out['dry_run_blocked']=any(x.startswith('Lock:') for x in observed)
  result=subprocess.check_output([PHP,str(probe),'apply'],env=env,text=True,stderr=log);out['apply']=json.loads(result)
  assert out['apply']['result']['deleted']==9990
  out['database_statuses']=sql("select status,count(*) from backup_artifacts group by status order by status");out['remaining_files']=sum(p.is_file() for p in (r/'backups').rglob('*'));assert out['remaining_files']==10
  out['deadlocks']=int(sql('select deadlocks from pg_stat_database where datname=current_database()'))
  log.close()
 finally:subprocess.run([BIN+'/dropdb',*PG,db],check=True)
args.output.write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out))

