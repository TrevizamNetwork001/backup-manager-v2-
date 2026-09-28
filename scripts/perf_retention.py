import os,subprocess,tempfile,time,json
from pathlib import Path
import argparse
parser=argparse.ArgumentParser(description='Isolated synthetic retention/lock comparison')
parser.add_argument('--output',type=Path,required=True)
parser.add_argument('--count',type=int,default=10000)
parser.add_argument('--service-file',type=Path)
parser.add_argument('--fk-contender',action='store_true')
args=parser.parse_args()
if args.service_file and not str(args.service_file).startswith('/tmp/perf-3-'): parser.error('service-file must be a PERF-3 copy in /tmp')
if args.count < 11: parser.error('count must be at least 11')
PHP='/tmp/perf-1-runtime/bin/php';BIN='/tmp/perf-1-runtime/usr/lib/postgresql/17/bin';PG=['-h','/tmp/bm-perf-1-services/socket','-p','55432','-U','perf'];out={}
with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as name:
 r=Path(name)
 for d in ('backups','ftp','views'):(r/d).mkdir()
 db='bm_perf_1_'+r.name.removeprefix('bm-perf-1-');env=os.environ.copy();env.update(PERF_WORKSPACE=name,APP_ENV='testing',APP_DEBUG='false',APP_KEY='base64:'+'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',DB_CONNECTION='pgsql',DB_HOST=PG[1],PERF_PG_SOCKET=PG[1],DB_PORT='55432',DB_USERNAME='perf',DB_PASSWORD='',DB_URL='',DB_DATABASE=db,LOG_CHANNEL='stderr',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',APP_CONFIG_CACHE=str(r/'config.php'),VIEW_COMPILED_PATH=str(r/'views'),BACKUP_STORAGE_ROOT=str(r/'backups'),BACKUP_FTP_ROOT=str(r/'ftp'))
 subprocess.run([BIN+'/createdb',*PG,'-T','template0','-E','UTF8',db],check=True)
 def sql(q):return subprocess.check_output([BIN+'/psql',*PG,'-d',db,'-Atc',q],text=True).strip()
 try:
  seed=subprocess.check_output([PHP,'/opt/backup-manager-v2/scripts/perf_control.php','retention',str(args.count)],env=env,text=True);out['initial_dry_run']={k:v for k,v in json.loads(seed).items() if k not in ('job_ids','slowest')};print('seeded',flush=True)
  peer_source=None
  if args.fk_contender:
   peer_setup=r/'peer.php';peer_setup.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';$app=require '/opt/backup-manager-v2/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if(getenv('APP_ENV')!=='testing'||Illuminate\\Support\\Facades\\DB::selectOne('SHOW data_directory')->data_directory!=='/tmp/bm-perf-1-services/pgdata')throw new RuntimeException('not isolated');
$device=App\\Models\\Device::create(['site_id'=>1,'name'=>'PEER','management_ip'=>'198.18.0.2','vendor'=>'MikroTik','is_active'=>true]);
$credential=new App\\Models\\Credential(['device_id'=>$device->id,'name'=>'PERF','type'=>'ssh','username'=>'synthetic','is_active'=>true]);$credential->secret='synthetic-only';$credential->save();
$source=App\\Models\\DeviceBackupPolicy::create(['device_id'=>$device->id,'backup_policy_id'=>1,'credential_id'=>$credential->id,'is_active'=>true]);echo $source->id;
''')
   peer_source=int(subprocess.check_output([PHP,str(peer_setup)],env=env,text=True))
  if args.service_file: env['PERF_RETENTION_SERVICE_FILE']=str(args.service_file)
  probe=r/'probe.php';probe.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';if(getenv('PERF_RETENTION_SERVICE_FILE'))require getenv('PERF_RETENTION_SERVICE_FILE');$app=require '/opt/backup-manager-v2/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
if(getenv('APP_ENV')!=='testing'||DB::selectOne('SHOW data_directory')->data_directory!=='/tmp/bm-perf-1-services/pgdata')throw new RuntimeException('not isolated');
$count=0;$ms=0;$locked=null;$transactions=[];$txStart=null;
$app['events']->listen(Illuminate\\Database\\Events\\TransactionBeginning::class,function() use (&$txStart) {$txStart=hrtime(true);});
$app['events']->listen(Illuminate\\Database\\Events\\TransactionCommitted::class,function() use (&$txStart,&$transactions) {if($txStart!==null) {$transactions[]=(hrtime(true)-$txStart)/1e9;$txStart=null;}});
$storage=new class extends App\\Services\\ArtifactStorage {
 public float $verifySeconds=0; public float $removeSeconds=0;
 public function verify(App\\Models\\BackupArtifact $artifact): array {$start=hrtime(true);try{return parent::verify($artifact);}finally{$this->verifySeconds+=(hrtime(true)-$start)/1e9;}}
 public function remove(App\\Models\\BackupArtifact $artifact,?int $expectedInode=null): array {$start=hrtime(true);try{return parent::remove($artifact,$expectedInode);}finally{$this->removeSeconds+=(hrtime(true)-$start)/1e9;}}
};
$app->instance(App\\Services\\ArtifactStorage::class,$storage);
DB::listen(function($event) use (&$count,&$ms,&$locked) { $count++;$ms+=$event->time;if(str_contains($event->sql,'backup_artifacts')&&str_contains($event->sql,'for update'))touch(getenv('PERF_WORKSPACE').'/apply-locked');if($locked===null && str_contains($event->sql,'backup_artifacts')&&str_starts_with($event->sql,'select')) { $locked=hrtime(true);touch(getenv('PERF_WORKSPACE').'/locked'); } });
$start=hrtime(true);$result=app(App\\Services\\BackupRetention::class)->run($argv[1]==='apply',Carbon\\CarbonImmutable::parse('2026-09-28 12:00:00','UTC'));
echo json_encode(['seconds'=>(hrtime(true)-$start)/1e9,'locked_seconds'=>$locked ? (hrtime(true)-$locked)/1e9 : null,'peak_php_bytes'=>memory_get_peak_usage(true),'query_count'=>$count,'query_ms'=>$ms,'result'=>$result,'verify_seconds'=>$storage->verifySeconds,'remove_seconds'=>$storage->removeSeconds,'transaction_seconds'=>$transactions]).PHP_EOL;
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
  worker=subprocess.Popen([PHP,str(probe),'apply'],env=env,text=True,stdout=subprocess.PIPE,stderr=log)
  deadline=time.monotonic()+30
  while not (r/'apply-locked').exists():
   if worker.poll() is not None:raise RuntimeError('apply finished before lock probe')
   if time.monotonic()>deadline:raise RuntimeError('apply lock signal timeout')
   time.sleep(.002)
  tick=time.monotonic()
  contender_sql=f'UPDATE backup_artifacts SET updated_at=updated_at WHERE id={args.count}'
  if peer_source:
   contender_sql=f"INSERT INTO backup_executions (device_backup_policy_id,backup_policy_id,device_id,credential_id,origin,status,attempt,max_attempts,created_at,updated_at) SELECT id,backup_policy_id,device_id,credential_id,'manual','pending',1,3,now(),now() FROM device_backup_policies WHERE id={peer_source} RETURNING id"
  contender=subprocess.Popen([BIN+'/psql',*PG,'-d',db,'-Atc',contender_sql],env=contender_env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  apply_events=[]
  while contender.poll() is None:
   sample=sql("select coalesce(wait_event_type,'')||':'||coalesce(wait_event,'')||':'||cardinality(pg_blocking_pids(pid)) from pg_stat_activity where application_name='bm_perf_retention_contender'")
   if sample:apply_events.append(sample)
   time.sleep(.015)
  result,errors=contender.communicate(timeout=5);assert contender.returncode==0,errors
  out['contender_kind']='other_source_foreign_key' if peer_source else 'artifact_update'
  out['apply_contender_wall_seconds']=time.monotonic()-tick;out['apply_wait_events']=sorted(set(apply_events))
  result,_=worker.communicate(timeout=30);assert worker.returncode==0;out['apply']=json.loads(result)
  assert out['apply']['result']['deleted']==args.count-10
  out['database_statuses']=sql("select status,count(*) from backup_artifacts group by status order by status");out['remaining_files']=sum(p.is_file() for p in (r/'backups').rglob('*'));assert out['remaining_files']==10
  out['deadlocks']=int(sql('select deadlocks from pg_stat_database where datname=current_database()'))
  log.close()
 finally:subprocess.run([BIN+'/dropdb',*PG,db],check=True)
args.output.write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out))

