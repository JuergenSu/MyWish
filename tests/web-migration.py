#!/usr/bin/env python3
"""Real Apache checks: .htaccess, browser-only migration, self removal and copy deploy."""
import concurrent.futures,http.cookiejar,json,os,pathlib,re,shutil,subprocess,tempfile,urllib.request,urllib.error,urllib.parse
from apache_server import ApacheServer
from storage_helpers import data_directory,disk_state
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php');checks=0
def check(ok,label):
 global checks
 if not ok:raise AssertionError(label)
 checks+=1
class Client:
 def __init__(self,base):
  self.base=base;self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def request(self,path='',data=None):
  headers={}
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode();headers['Content-Type']='application/x-www-form-urlencoded'
  try:
   with self.opener.open(urllib.request.Request(self.base+path,data=data,headers=headers),timeout=45) as r:return r.status,r.read()
  except urllib.error.HTTPError as e:return e.code,e.read()
 def form(self):
  code,body=self.request('migrate-storage.php');check(code==200 and b'Migration starten' in body,f'Browser offers explicit migration form: {code} {body[:1000]!r}')
  self.csrf=re.search(rb'name="csrf" value="([a-f0-9]+)"',body)[1].decode();return body
 def login(self):
  code,body=self.request('api/index.php?action=session');check(code==200,'Session endpoint opens migrated dataset');self.csrf=json.loads(body)['csrf']
  code,body=self.request('api/index.php?action=login',{'csrf':self.csrf,'username':'usera','password':'abcdefgh'});check(code==200,'Original credentials still work')
  return json.loads(self.request('api/index.php?action=state')[1])['data']
def install(dest,legacy=True):
 shutil.copytree(ROOT,dest,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git','node_modules','.artifacts','__pycache__','config.php','state.php','layout.php','lock.php'))
 if legacy:
  raw=subprocess.check_output([PHP,'-r',"require $argv[1].'/src/Store.php';require $argv[1].'/tests/fixtures/legacy-state.php';echo MyWish\\Store::GUARD.json_encode(legacyState(3));",str(ROOT)])
  (dest/'storage/state.php').write_bytes(raw);return raw
def guarded(path):return json.loads(path.read_text().split('\n',1)[1])
def no_canaries(doc):return not list(doc.rglob('[0-9a-f]'*48+'.*'))
with tempfile.TemporaryDirectory(prefix='mywish-web-migration-') as temp:
 temp=pathlib.Path(temp);doc=temp/'public';doc.mkdir()
 originals={}
 for folder in ['', 'mywish']:originals[folder]=install(doc/folder)
 server=ApacheServer(temp/'apache',doc)
 try:
  server.start()
  for folder in ['', 'mywish']:
   app=doc/folder;storage=app/'storage';client=Client(server.url('/'+(folder+'/' if folder else '')))
   code,body=client.request();check(code==503 and b'migrate-storage.php' in body,'Legacy installation shows migration link, never auto migrates')
   check((storage/'state.php').read_bytes()==originals[folder] and not (storage/'data-v4').exists(),'Normal page leaves old dataset intact')
   client.form();check(not (storage/'layout.php').exists(),'Opening migration link alone has no migration side effects')
   check(client.request('migrate-storage.php',{'csrf':'wrong'})[0]==403,'Migration requires valid CSRF token')
   code,body=client.request('migrate-storage.php',{'csrf':client.csrf});check(code==200 and b'Migrationsskript wurde gel' in body,'Browser migration completes and confirms self removal')
   check(not (app/'migrate-storage.php').exists() and client.request('migrate-storage.php')[0]==404,'Successful migration removes the callable script')
   data=data_directory(storage);state=disk_state(storage)
   check(guarded(storage/'layout.php')['directory']=='data-v4' and data==storage/'data-v4','Data locator is portable and remains inside the application')
   backups=list(storage.glob('backup-schema-3-*.php'));check(len(backups)==1 and backups[0].read_bytes()==originals[folder],'Byte-for-byte legacy backup is retained')
   check(state['users'][0]['sessionVersion']==3 and state['users'][1]['passwordHash'] is None and len(state['reservations'])==1,'Users, Google-only account, sessions and reservations survive')
   check(len(list((data/'images').iterdir()))==1 and all('"data":' not in p.read_text() for p in (data/'records').glob('*.json')),'Images are deduplicated binaries outside JSON')
   snapshot=client.login();check(len(snapshot['lists'])==2,'Original lists remain visible')
   image=state['wishes'][0]['image'];image_bytes=(data/'images'/image['file']).read_bytes()
   code,body=client.request('media.php?type=wishes&id='+state['wishes'][0]['id']);check(code==200 and body==image_bytes,'Authorized media route streams exact migrated image')
   anon=Client(client.base);check(anon.request('media.php?type=wishes&id='+state['wishes'][0]['id'])[0]==401,'Private media still requires authentication')
   code,body=anon.request('media.php?type=wishes&id='+state['wishes'][0]['id']+'&share='+state['lists'][0]['id']);check(code==200 and body==image_bytes,'Scoped shared image still works without login')
   paths=['manifest.json','.htaccess','transaction.lock','images/'+image['file']]+['records/'+p.name for p in (data/'records').glob('*.json')]
   for path in paths:check(anon.request('storage/data-v4/'+path)[0]==403,'Apache blocks direct raw data: '+path)
   for path in ['layout.php','state.php','protection.php','migration-schema4.php',backups[0].name]:check(anon.request('storage/'+path)[0]==403,'Apache blocks guarded runtime files too')
   check(no_canaries(app),'Protection probes leave no canary files')
   # A copied deployment must work without changing an absolute data path.
   copied=doc/('copied-'+(folder or 'root'));shutil.copytree(app,copied)
   copied_client=Client(server.url('/'+copied.name+'/'));copied_client.login()
   check(guarded(copied/'storage/layout.php')==guarded(storage/'layout.php'),'Copy deploy retains dataset identity and relative locator')
   check(guarded(copied/'storage/protection.php')['binding']['storage']==str(copied/'storage'),'Copied installation rechecks .htaccess at its new path')
   manifest=(data/'manifest.json').read_bytes();done=(storage/'migration-schema4.php').read_bytes()
   shutil.copy2(ROOT/'migrate-storage.php',app/'migrate-storage.php')
   check(client.request('migrate-storage.php')[0]==410 and not (app/'migrate-storage.php').exists(),'Reuploaded migration script disables and deletes itself')
   check((data/'manifest.json').read_bytes()==manifest and (storage/'migration-schema4.php').read_bytes()==done,'Repeat call makes no data or completion marker changes')
   print('PASS real Apache browser migration and copy deploy:',folder or '/')
  # A failed run remains retryable; arbitrary source/target parameters are ignored.
  retry=doc/'retry';raw=install(retry);(retry/'storage/state.php').write_bytes(b'broken')
  client=Client(server.url('/retry/'));client.form()
  check(client.request('migrate-storage.php',{'csrf':client.csrf})[0]==503 and (retry/'migrate-storage.php').exists(),'Failed validation keeps script for retry')
  check(not (retry/'storage/data-v4').exists(),'Invalid source never publishes a dataset')
  (retry/'storage/state.php').write_bytes(raw)
  other=Client(client.base);other.form()
  def migrate(c):return c.request('migrate-storage.php',{'csrf':c.csrf,'data':'/tmp/not-allowed','storage':'/tmp/not-allowed'})[0]
  with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:codes=list(pool.map(migrate,[client,other]))
  check(all(code in [200,404,410] for code in codes) and 200 in codes,'Concurrent migration submits complete exactly one run')
  check(json.loads((retry/'storage/data-v4/manifest.json').read_text())['revision']==1 and len(list((retry/'storage').glob('backup-schema-*.php')))==1,'Concurrency never duplicates or overwrites the migrated dataset')
  # Fresh installation uses the same protected, portable default.
  fresh=doc/'fresh';install(fresh,False);fresh_client=Client(server.url('/fresh/'))
  check(fresh_client.request('api/index.php?action=session')[0]==200 and (fresh/'storage/data-v4/manifest.json').exists(),'Fresh installations initialize the webroot default after a successful deny probe')
  (fresh/'storage/data-v4/records/.htaccess').write_text('Require all granted\n')
  check(fresh_client.request('api/index.php?action=session')[0]==503,'A child access-rule override fails closed even after initial verification')
  # Existing schema-4 installations may still use the previous automatic external default.
  moved=doc/'already-v4';install(moved);external=temp/'previous-private-data'
  subprocess.run([PHP,str(ROOT/'tools/migrate-storage.php'),'--storage='+str(moved/'storage'),'--data='+str(external),'--web-root='+str(doc)],check=True,capture_output=True)
  before=(external/'manifest.json').read_bytes();before_state=disk_state(moved/'storage')
  client=Client(server.url('/already-v4/'));client.form()
  target=moved/'storage/data-v4';target.mkdir();(target/'occupied.txt').write_text('keep')
  check(client.request('migrate-storage.php',{'csrf':client.csrf})[0]==503 and (moved/'migrate-storage.php').exists(),'Occupied relocation target leaves migration retryable')
  check(data_directory(moved/'storage')==external and (external/'manifest.json').read_bytes()==before and (target/'occupied.txt').read_text()=='keep','Failed relocation preserves active source and unrelated target files')
  (target/'occupied.txt').unlink();target.rmdir()
  check(client.request('migrate-storage.php',{'csrf':client.csrf})[0]==200,'Browser migration also relocates the previous external schema-4 default')
  check(data_directory(moved/'storage')==moved/'storage/data-v4' and disk_state(moved/'storage')==before_state,'Schema-4 relocation preserves every metadata record and image reference')
  check((external/'manifest.json').read_bytes()==before and (moved/'storage/data-v4/manifest.json').read_bytes()==before,'Relocation preserves revision and retains original external dataset as backup')
  client.login()
  if os.environ.get('MYWISH_WEB_MIGRATION_BROWSER')=='1':
   install(doc/'visual')
   subprocess.run(['node',str(ROOT/'tests/migration-browser.cjs'),server.url('/visual/')],check=True)
 finally:server.close()
 # Ignored .htaccess must fail closed before any real JSON or images are written.
 unsafe=temp/'unsafe-public';raw=install(unsafe);server=ApacheServer(temp/'unsafe-apache',unsafe,'None')
 try:
  server.start();client=Client(server.url('/'));client.form()
  code,body=client.request('migrate-storage.php',{'csrf':client.csrf})
  check(code==503 and b'AllowOverride' in body,'Ignored .htaccess produces an explicit diagnostic')
  check((unsafe/'storage/state.php').read_bytes()==raw and not (unsafe/'storage/data-v4').exists() and not (unsafe/'storage/layout.php').exists(),'Unprotected host retains legacy data without publishing raw files')
  check((unsafe/'migrate-storage.php').exists() and no_canaries(unsafe),'Failed protection check is retryable and cleans all probes')
 finally:server.close()
 print(f'OK: {checks} real Apache/browser migration checks')
