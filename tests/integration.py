#!/usr/bin/env python3
"""Isolated copy-deployment, HTTP contract, privacy and concurrency tests. No dependencies."""
import concurrent.futures, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
checks=0

def check(condition,label):
 global checks
 if not condition: raise AssertionError(label)
 checks+=1

class Client:
 def __init__(self,base):
  self.base=base; self.csrf=''; self.jar=http.cookiejar.CookieJar(); self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
  self.get('session')
 def request(self,path,data=None,headers=None):
  req=urllib.request.Request(self.base+path,data=data,headers=headers or {})
  try:
   with self.opener.open(req,timeout=30) as response: return response.status,response.read(),response.headers
  except urllib.error.HTTPError as error: return error.code,error.read(),error.headers
 def get(self,action):
  code,body,headers=self.request('api/index.php?action='+action)
  data=json.loads(body); self.csrf=data.get('csrf',self.csrf); return code,data
 def post(self,action,payload=None,expected=200,token=None):
  code,body,headers=self.request('api/index.php?action='+action,json.dumps(payload or {}).encode(),{'Content-Type':'application/json','X-CSRF-Token':self.csrf if token is None else token})
  try: data=json.loads(body)
  except Exception: raise AssertionError((code,body.decode(errors='replace')[:1000]))
  self.csrf=data.get('csrf',self.csrf)
  check(code==expected,f'{action}: expected {expected}, got {code}: {data}')
  check(data['ok']==(expected<400),f'{action} envelope')
  return data.get('data',data.get('error'))

def scenario(base):
 owner=Client(base); guest=Client(base)
 guest.post('saveList',{},401)
 for invalid in ['abcdef!', 'abcdefgh', 'äöüßéa!']:
  owner.post('setup',{'name':'Antonia','username':'antonia','password':invalid,'siteName':'MyWish Test','registration':'open'},422)
 owner.post('setup',{'name':'Antonia','username':'antonia','password':' Abcdefg! ','siteName':'MyWish Test','registration':'open'},201)
 owner.post('setup',{'name':'Intruder'},409)
 owner.post('saveList',{},403,token='wrong')
 owner.post('saveList',{'name':'','occasion':'Geburtstag'},422)
 owner.post('saveList',{'name':'Bad date','occasion':'Geburtstag','eventDate':'2026-02-30'},422)
 a=owner.post('saveList',{'name':'Geburtstag <script>alert(1)</script>','occasion':'Geburtstag','eventDate':'2026-12-12','description':'Alles, was Freude macht.'},201)['id']
 b=owner.post('saveList',{'name':'Weihnachten','occasion':'Weihnachten'},201)['id']
 wish=owner.post('saveWish',{'listId':a,'title':'Buch <img src=x onerror=alert(1)>','price':'39,99','quantity':1},201)['id']
 multi=owner.post('saveWish',{'listId':a,'title':'Drei Tassen','quantity':3,'price':'0'},201)['id']
 owner.post('saveWish',{'listId':a,'title':'bad','price':'-1'},422)
 owner.post('saveWish',{'listId':a,'title':'bad','productUrl':'file:///etc/passwd'},422)
 owner.post('reserve',{'id':wish},403)
 for bad in ['http://127.0.0.1','http://169.254.169.254','file:///etc/passwd','http://10.1.1.1']:
  owner.post('import',{'productUrl':bad},422)
 code,body,headers=guest.request('index.php?page=list&id='+a)
 check(code==200 and b'Anmelden' in body and b'Buch &lt;' not in body,'Guest cannot view lists')
 code,body,headers=owner.request('index.php?page=list&id='+a)
 check(code==200 and b'&lt;script&gt;' in body and b'<script>alert' not in body,'Stored XSS escaped')
 check('frame-ancestors' in headers['Content-Security-Policy'],'CSP present')
 for invalid in ['abcdef!', 'abcdefgh', 'äöüßéa!']:
  guest.post('register',{'name':'Invalid','username':'invalid','password':invalid},422)
 users=[]
 for i in range(10):
  u=Client(base); u.post('register',{'name':'Schenkender '+str(i),'username':'giver'+str(i),'password':'abcdefg!'},201); users.append(u)
 code,state=users[0].get('state'); check(len(state['data']['lists'])==2,'All lists visible to signed-in user')
 users[0].post('saveList',{'id':a,'name':'Hacked','occasion':'Anderes'},403)
 users[0].post('saveWish',{'id':wish,'listId':a,'title':'Hacked'},403)
 users[0].post('deleteList',{'id':a,'confirm':'yes'},403)
 users[0].post('unreserve',{'id':wish},403)
 # Independent sessions ensure PHP session locking cannot serialize these requests.
 def reserve(u,w):
  code,body,_=u.request('api/index.php?action=reserve',json.dumps({'id':w}).encode(),{'Content-Type':'application/json','X-CSRF-Token':u.csrf})
  check(json.loads(body)['ok']==(code==200),'Concurrent envelope'); return code
 with concurrent.futures.ThreadPoolExecutor(max_workers=10) as pool: statuses=list(pool.map(lambda u:reserve(u,wish),users))
 check(statuses.count(200)==1 and statuses.count(409)==9,'Exactly one winner among 10 requests: '+str(statuses))
 winner=users[statuses.index(200)]; winner.post('reserve',{'id':wish},409)
 before=owner.get('state')[1]['data']
 check(all('available' not in w and 'reservedByMe' not in w for w in before['wishes']),'Owner API has no reservation fields')
 check('reservations' not in before and 'passwordHash' not in json.dumps(before),'No private table or hash exposure')
 with concurrent.futures.ThreadPoolExecutor(max_workers=10) as pool: statuses=list(pool.map(lambda u:reserve(u,multi),users))
 check(statuses.count(200)==3 and statuses.count(409)==7,'Quantity three has exactly three winners')
 winner.post('unreserve',{'id':wish}); check(owner.get('state')[1]['data']==before,'Owner representation identical before/after reservation change')
 users[0].post('reserve',{'id':wish}); users[0].post('unreserve',{'id':wish})
 owner.post('deleteWish',{'id':multi},422)
 owner.post('deleteWish',{'id':multi,'confirm':'yes'})
 for u in users:
  data=u.get('state')[1]['data']; check(all(w['id']!=multi for w in data['wishes']),'Deleted wish disappears')
 code,body,_=guest.request('storage/state.php'); check(code==404 and b'passwordHash' not in body and b'Antonia' not in body,'Guarded storage not downloadable')
 code,body,_=guest.request('api/index.php?action=reserve&id='+wish); check(code==405,'No mutating GET')
 code,body,_=owner.request('api/index.php?action=saveWish',b'{oops',{'Content-Type':'application/json','X-CSRF-Token':owner.csrf}); check(code==400 and not json.loads(body)['ok'],'Malformed JSON')
 code,body,_=guest.request('media.php?type=wishes&id='+wish); check(code==401,'Media requires login')
 # Multipart signatures are authoritative, not filenames or browser MIME headers.
 def upload(content, filename, mime, expected):
  boundary='mywish-boundary-123456'
  fields={'action':'saveWish','listId':a,'title':'Bildtest','csrf':owner.csrf}
  body=b''
  for key,value in fields.items():
   body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
  body+=f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode()
  code,result,_=owner.request('api/index.php',body,{'Content-Type':'multipart/form-data; boundary='+boundary})
  check(code==expected,f'Upload signature: {code} {result[:200]}')
  return json.loads(result)
 upload(b'<?php echo "hack";','image.png','image/png',422)
 upload(b'<svg onload="alert(1)"></svg>','image.png','image/png',422)
 import base64
 image=upload(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII='),'image.png','application/octet-stream',201)['data']['id']
 code,body,headers=users[0].request('media.php?type=wishes&id='+image)
 check(code==200 and headers['Content-Type']=='image/png' and body.startswith(b'\x89PNG'),'Authorized fixed MIME media')
 owner.post('saveWish',{'id':image,'listId':a,'title':'Unchanged image','_image':{'tmp_name':'/etc/passwd','error':0,'size':100}})

 owner.post('account',{'name':'Antonia Neu','currentPassword':'wrong'},403)
 other=Client(base); other.post('login',{'username':'antonia','password':' Abcdefg! '})
 for invalid in ['abcdef!', 'abcdefgh', 'äöüßéa!']:
  owner.post('account',{'name':'Antonia Neu','currentPassword':' Abcdefg! ','newPassword':invalid},422)
 check(other.get('state')[0]==200,'Rejected password changes preserve active sessions')
 owner.post('account',{'name':'Antonia Neu','currentPassword':' Abcdefg! ','newPassword':''})
 other.post('login',{'username':'antonia','password':' Abcdefg! '})
 owner.post('account',{'name':'Antonia Neu','currentPassword':' Abcdefg! ','newPassword':'Changed!'})
 check(other.get('state')[0]==401,'Password change invalidates other sessions')
 # Login rate limiting; repeated wrong attempts share an IP as in production.
 for i in range(10): guest.post('login',{'username':'missing','password':'wrong'},401)
 guest.post('login',{'username':'missing','password':'wrong'},429)
 owner.post('deleteList',{'id':a,'confirm':'yes'})
 owner.post('deleteAccount',{'currentPassword':'Changed!','confirm':'yes'})
 check(owner.get('state')[0]==401,'Account deletion invalidates session')

with tempfile.TemporaryDirectory(prefix='mywish-http-') as temp:
 doc=pathlib.Path(temp)
 for dest in [doc,doc/'mywish']:
  shutil.copytree(ROOT,dest,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git','state.php','lock.php','tmp-*.php','config.php','.artifacts','__pycache__'))
 # Distinct stores for fresh root/subdirectory installs.
 sock=socket.socket(); sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]; sock.close()
 env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4')
 log=open(doc/'server.log','w')
 process=subprocess.Popen([PHP,'-d','upload_max_filesize=2M','-d','post_max_size=4M','-S',f'127.0.0.1:{port}','-t',str(doc)],env=env,stdout=log,stderr=log,start_new_session=True)
 try:
  for i in range(100):
   try:
    urllib.request.urlopen(f'http://127.0.0.1:{port}/api/index.php?action=session',timeout=1); break
   except Exception: time.sleep(.05)
  for path in ['/','/mywish/']:
   scenario(f'http://127.0.0.1:{port}'+path)
   print('PASS copy deployment, permissions, privacy and races:',path)
  # Operator registration override is enforced server-side.
  (doc/'config.php').write_text("<?php return ['registration'=>'closed'];")
  closed=Client(f'http://127.0.0.1:{port}/')
  closed.post('register',{'name':'Blocked','username':'blocked','password':'Closed!1'},403)
  (doc/'config.php').unlink()
  # Bad storage permissions produce a JSON startup diagnostic, never a fatal page.
  (doc/'storage').chmod(0o500)
  code,body,_=closed.request('api/index.php?action=state')
  check(code==503 and not json.loads(body)['ok'],'Read-only storage diagnostic')
  (doc/'storage').chmod(0o700)
  # Two first-setup requests cannot create two bootstrap accounts.
  race=doc/'setup-race'; shutil.copytree(ROOT,race,ignore=shutil.ignore_patterns('.git','state.php','lock.php','config.php','.artifacts','__pycache__'))
  starters=[Client(f'http://127.0.0.1:{port}/setup-race/') for _ in range(2)]
  def initialize(client):
   payload={'name':'First','username':'first','password':'Setup!12','siteName':'Race','registration':'open'}
   return client.request('api/index.php?action=setup',json.dumps(payload).encode(),{'Content-Type':'application/json','X-CSRF-Token':client.csrf})[0]
  with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: codes=list(pool.map(initialize,starters))
  check(sorted(codes)==[201,409],'Atomic first setup: '+str(codes))
  # A hash from the previous policy remains valid without a special character.
  legacy_file=race/'storage/state.php'
  guard,raw=legacy_file.read_text().split('\n',1)
  legacy_state=json.loads(raw)
  legacy_state['users'][0]['passwordHash']=subprocess.check_output([PHP,'-r','echo password_hash($argv[1], PASSWORD_DEFAULT);','Previously valid passphrase'],text=True)
  legacy_file.write_text(guard+'\n'+json.dumps(legacy_state))
  legacy=Client(f'http://127.0.0.1:{port}/setup-race/')
  legacy.post('login',{'username':'first','password':'Previously valid passphrase'})
  legacy.post('account',{'name':'First updated','currentPassword':'Previously valid passphrase','newPassword':''})
  legacy.post('account',{'name':'First updated','currentPassword':'Previously valid passphrase','newPassword':'abcdefgh'},422)
  # Validate portable backup and roundtrip on the second instance.
  original=(doc/'storage/state.php').read_bytes()
  (doc/'mywish/storage/state.php').write_bytes(original)
  check(subprocess.run([PHP,str(ROOT/'tools/check-backup.php'),str(doc/'mywish/storage/state.php')],capture_output=True).returncode==0,'Backup roundtrip validates')
  (doc/'mywish/storage/state.php').write_text('<?php broken')
  code,body,_=Client(f'http://127.0.0.1:{port}/').request('mywish/api/index.php?action=state')
  check(code==503 and not json.loads(body)['ok'],'Corrupt storage gives controlled JSON error')
  print(f'OK: {checks} HTTP/integration checks')
 finally:
  import signal
  os.killpg(process.pid,signal.SIGTERM); process.wait(); log.close()
