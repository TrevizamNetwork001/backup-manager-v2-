import os,sys,json,subprocess,tempfile,time,ssl,ftplib,io,hashlib,resource,base64,math
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'engine'))
from ftp_spontaneous import scan
from artisan_session import ArtisanSession, ArtisanTransportError, ThreadSessions
import argparse
parser=argparse.ArgumentParser(description='Synthetic FTPS/receiver/PostgreSQL PERF comparison; isolated PERF-1 runtime required.')
parser.add_argument('--session',action='store_true')
parser.add_argument('--local-files',action='store_true')
parser.add_argument('--uploads',type=int,default=40)
parser.add_argument('--file-server-only',action='store_true')
parser.add_argument('--workers',type=int,choices=[1,2],default=1)
parser.add_argument('--output',type=Path,required=True)
args=parser.parse_args()
if not 2 <= args.uploads <= 1000: parser.error('uploads must be between 2 and 1000')
backup_uploads=0 if args.file_server_only else args.uploads//2
PHP='/tmp/perf-1-runtime/bin/php';BIN='/tmp/perf-1-runtime/usr/lib/postgresql/17/bin';PG=['-h','/tmp/bm-perf-1-services/socket','-p','55432','-U','perf'];worker='c'*32;out={}
with tempfile.TemporaryDirectory(prefix='bm-perf-1-') as name:
 r=Path(name);r.chmod(0o755)
 for d in ('backups','ftp','views'):(r/d).mkdir()
 db='bm_perf_1_'+r.name.removeprefix('bm-perf-1-');env=os.environ.copy();env.update(PERF_WORKSPACE=name,APP_ENV='testing',APP_DEBUG='false',APP_KEY='base64:'+'MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',DB_CONNECTION='pgsql',DB_HOST=PG[1],PERF_PG_SOCKET=PG[1],DB_PORT='55432',DB_USERNAME='perf',DB_PASSWORD='',DB_URL='',DB_DATABASE=db,LOG_CHANNEL='stderr',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',APP_CONFIG_CACHE=str(r/'config.php'),VIEW_COMPILED_PATH=str(r/'views'),BACKUP_STORAGE_ROOT=str(r/'backups'),BACKUP_FTP_ROOT=str(r/'ftp'),BACKUP_FTP_MAX_BYTES=str(64*1024*1024))
 subprocess.run([BIN+'/createdb',*PG,'-T','template0','-E','UTF8',db],check=True)
 def sql(q):return subprocess.check_output([BIN+'/psql',*PG,'-d',db,'-Atc',q],text=True).strip()
 def cmd(*args):return subprocess.check_output([PHP,str(ROOT/'app/artisan'),*[str(a) for a in args]],env=env,text=True,stderr=subprocess.DEVNULL,timeout=45)
 script=r/'setup.php';script.write_text('''<?php
require '/opt/backup-manager-v2/app/vendor/autoload.php';$app=require '/opt/backup-manager-v2/app/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;use Illuminate\\Support\\Facades\\Artisan;
if(getenv('APP_ENV')!=='testing'||DB::selectOne('SHOW data_directory')->data_directory!=='/tmp/bm-perf-1-services/pgdata')throw new RuntimeException('not isolated');
Artisan::call('migrate',['--force'=>true]);
$site=App\\Models\\Site::create(['name'=>'FTPS LAB','is_active'=>true]);$device=App\\Models\\Device::create(['site_id'=>$site->id,'name'=>'OLT','management_ip'=>'198.18.0.1','vendor'=>'Huawei','platform'=>'olt','is_active'=>true]);
$policy=App\\Models\\BackupPolicy::create(['name'=>'FTP','method'=>'ftp_push','artifact_mode'=>'config','schedule_type'=>'manual','is_active'=>true]);
App\\Models\\DeviceBackupPolicy::create(['device_id'=>$device->id,'backup_policy_id'=>$policy->id,'credential_id'=>null,'is_active'=>true]);
foreach(['backup','file_server'] as $purpose) {$a=new App\\Models\\FtpAccount(['device_id'=>$purpose==='backup'?$device->id:null,'account_uuid'=>(string)Illuminate\\Support\\Str::uuid(),'purpose'=>$purpose,'home_layout'=>'account','username'=>'perf_'.$purpose,'is_active'=>true]);$a->secret='synthetic-only';$a->save();DB::table('ftp_accounts')->where('id',$a->id)->update(['provisioned_at'=>now(),'sync_error'=>null]);}
Artisan::call('ftp:accounts');echo Artisan::output();
''')
 server=None
 try:
  accounts=json.loads(subprocess.check_output([PHP,str(script)],env=env,text=True));assert len(accounts)==2
  password=os.urandom(24).hex();passwd=str(r/'pw');puredb=str(r/'pw.pdb')
  for a in accounts:
   home=Path(a['home']);home.mkdir(parents=True)
   for parent in (r/'ftp',r/'ftp/accounts',home.parent):parent.chmod(0o755)
   os.chown(home,65534,65534);home.chmod(0o700)
   subprocess.run(['/usr/bin/pure-pw','useradd','perf_'+a['purpose'],'-u','65534','-g','65534','-d',str(home),'-f',passwd],input=(password+'\n'+password+'\n').encode(),check=True,stdout=subprocess.DEVNULL)
  subprocess.run(['/usr/bin/pure-pw','mkdb',puredb,'-f',passwd],check=True)
  cert=r/'cert';key=r/'key';subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=IP:127.0.0.1','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  ctx=ssl.create_default_context(cafile=str(cert));server=subprocess.Popen(['/usr/sbin/pure-ftpd','-l','puredb:'+puredb,'-E','-A','-R','-K','-G','-r','-u','1','-S','127.0.0.1,52121','-p','53000:53009','-c','20','-C','5','-Y','3','-2',str(cert)+','+str(key),'-g',str(r/'pid')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  time.sleep(.3);assert server.poll() is None
  payload=b'# synthetic config\n'+b'x'*4077;lat=[];upload_epoch=time.monotonic()
  def upload(i):
   purpose='backup' if i<backup_uploads else 'file_server';start=time.monotonic()
   if args.local_files:
    home=Path(next(a['home'] for a in accounts if a['purpose']==purpose))
    (home/('upload-'+str(i)+'.cfg')).write_bytes(payload);lat.append(time.monotonic()-start);return
   with ftplib.FTP_TLS(context=ctx) as c:
    c.connect('127.0.0.1',52121,timeout=20);c.login('perf_'+purpose,password);c.prot_p();c.storbinary('STOR upload-'+str(i)+'.cfg',io.BytesIO(payload))
   lat.append(time.monotonic()-start)
  with ThreadPoolExecutor(max_workers=4) as pool:list(pool.map(upload,range(args.uploads)))
  upload_seconds=time.monotonic()-upload_epoch
  encode=lambda s:'n'+base64.urlsafe_b64encode(s.encode()).decode()
  receive=lambda device,token,filename,received:json.loads(cmd('ftp:receive',device,token,encode(filename),received,worker))
  complete=lambda job,relative:cmd('engine:complete',job,relative,worker)
  fail=lambda job,code:cmd('engine:fail',job,code,worker)
  receipt_lat=[];receipt_ack=[]
  def receipt(a,token,filename,received,status,size,digest,path,error):
   start=time.monotonic();cmd('ftp:receipt',a,token,encode(filename),received,status,size,digest,path,error);receipt_lat.append(time.monotonic()-start);receipt_ack.append(time.monotonic()-upload_epoch)
  observed={}
  monitor=subprocess.Popen([PHP,str(ROOT/'scripts/perf_monitor.php')],env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  deadline=time.monotonic()+10
  while not (r/'monitor-ready').exists():
   if monitor.poll() is not None or time.monotonic()>deadline: raise RuntimeError('monitor did not start')
   time.sleep(.01)
  original_popen=subprocess.Popen;receiver_spawns=[]
  def counted_popen(command,*positional,**kwargs):
   if command[:2]==[PHP,str(ROOT/'app/artisan')]:receiver_spawns.append(command[2])
   return original_popen(command,*positional,**kwargs)
  subprocess.Popen=counted_popen
  tick=time.monotonic()
  session=(ThreadSessions if args.workers>1 else ArtisanSession)([PHP,str(ROOT/'app/artisan')],env=env) if args.session else None
  if session:
   cmd=lambda *a:session.command(*a).decode()
  for _ in range(3):scan(str(r/'ftp'),str(r/'backups'),0,observed,[],receive,complete,fail,accounts,receipt,workers=args.workers)
  scan_seconds=time.monotonic()-tick
  if session:session.close()
  subprocess.Popen=original_popen
  (r/'monitor-stop').touch();monitor_output,monitor_errors=monitor.communicate(timeout=10)
  assert monitor.returncode==0,monitor_errors
  out['database_activity']=json.loads(monitor_output)
  out.update(session=args.session,receiver_subprocesses=len(receiver_spawns),scanner_workers=args.workers,transport="synthetic filesystem" if args.local_files else "FTPS",uploads=args.uploads,backup_uploads=backup_uploads,file_server_uploads=args.uploads-backup_uploads,bytes_each=len(payload),sessions=4,upload_seconds=upload_seconds,upload_files_per_second=args.uploads/upload_seconds,scan_seconds=scan_seconds,scan_files_per_second=args.uploads/scan_seconds,end_to_end_seconds=time.monotonic()-upload_epoch,end_to_end_files_per_second=args.uploads/(time.monotonic()-upload_epoch),upload_session_p95_seconds=sorted(lat)[math.ceil(len(lat)*.95)-1],receipt_command_p95_seconds=sorted(receipt_lat)[math.ceil(len(receipt_lat)*.95)-1],batch_receipt_p95_seconds=sorted(receipt_ack)[math.ceil(len(receipt_ack)*.95)-1],batch_receipt_mean_seconds=sum(receipt_ack)/len(receipt_ack))
  out['db_counts']={'executions':int(sql('select count(*) from backup_executions')),'succeeded':int(sql("select count(*) from backup_executions where status='succeeded'")),'artifacts':int(sql('select count(*) from backup_artifacts')),'receipts':int(sql('select count(*) from ftp_received_files'))}
  assert out['db_counts']=={'executions':backup_uploads,'succeeded':backup_uploads,'artifacts':backup_uploads,'receipts':args.uploads}
  rows=sql("select relative_path||'|'||sha256||'|'||size_bytes from ftp_received_files where status='stored'").splitlines()
  for row in rows:
   path,digest,size=row.rsplit('|',2);p=r/'backups'/path;assert p.stat().st_size==int(size) and hashlib.sha256(p.read_bytes()).hexdigest()==digest
  out['unique_tokens']=int(sql('select count(distinct claim_token) from ftp_received_files'));out['processing_files']=len(list((r/'ftp/processing').iterdir()));assert out['unique_tokens']==args.uploads and out['processing_files']==0
  out['deadlocks']=int(sql('select deadlocks from pg_stat_database where datname=current_database()'))
  print(json.dumps(out),flush=True)
  # A real database outage after publication must retain the durable sidecar.
  with ftplib.FTP_TLS(context=ctx) as c:
   c.connect('127.0.0.1',52121,timeout=20);c.login('perf_backup',password);c.prot_p();c.storbinary('STOR gap.cfg',io.BytesIO(payload))
  outage=[]
  def pgctl(action):
   args=['runuser','-u','nobody','--','env','LD_LIBRARY_PATH=/tmp/perf-1-runtime/usr/lib/x86_64-linux-gnu',BIN+'/pg_ctl','-D','/tmp/bm-perf-1-services/pgdata']
   if action=='stop':args+=['-m','fast','stop']
   else:args+=['-l','/tmp/bm-perf-1-services/postgres.log','-o','-k '+PG[1]+' -h "" -p 55432 -c max_connections=40 -c log_lock_waits=on -c deadlock_timeout=100ms','start']
   subprocess.run(args,check=True,stdout=subprocess.DEVNULL)
  def interrupted_complete(job,path):
   pgctl('stop')
   try:
    try:complete(job,path)
    except (subprocess.CalledProcessError, ArtisanTransportError):
     outage.append(True);raise
    else:raise AssertionError('offline completion accepted')
   finally:pgctl('start')
  scan(str(r/'ftp'),str(r/'backups'),0,observed,[],receive,complete,fail,accounts,receipt)
  scan(str(r/'ftp'),str(r/'backups'),0,observed,[],receive,interrupted_complete,fail,accounts,receipt)
  assert outage==[True]
  after_outage_files=sum(p.is_file() for p in (r/'backups').rglob('*'))
  assert after_outage_files==args.uploads+1
  assert int(sql('select count(*) from backup_artifacts'))==backup_uploads
  assert any((r/'ftp/processing').iterdir())
  scan(str(r/'ftp'),str(r/'backups'),0,{},[],receive,complete,fail,accounts,receipt)
  assert int(sql('select count(*) from backup_artifacts'))==backup_uploads+1
  assert int(sql('select count(*) from ftp_received_files'))==args.uploads+1
  assert sum(p.is_file() for p in (r/'backups').rglob('*'))==args.uploads+1
  assert not any((r/'ftp/processing').iterdir())
  out['publication_postgres_outage']={'offline_completion_rejected':True,'files_after_outage':args.uploads+1,'files_after_restart':args.uploads+1,'artifacts_after_restart':backup_uploads+1,'unique_receipts_after_restart':args.uploads+1,'processing_after_restart':0}
 finally:
  if 'session' in locals() and session:session.close()
  if server:server.terminate();server.wait(timeout=10)
  subprocess.run([BIN+'/dropdb',*PG,db],check=True)
args.output.write_text(json.dumps(out,indent=2)+'\n')
