#!/usr/bin/env python3
"""Complete local OIDC callbacks with a fake transport; no Google credentials or network."""
import base64, http.cookiejar, json, os, pathlib, shutil, signal, socket, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
AUD='fixture.apps.googleusercontent.com'
checks=0

def check(value,label):
 global checks
 if not value: raise AssertionError(label)
 checks+=1

class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args): return None

class Client:
 def __init__(self,base):
  self.base=base; self.csrf=''; self.jar=http.cookiejar.CookieJar()
  self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
  self.api('session')
 def request(self,path,data=None,headers=None):
  req=urllib.request.Request(self.base+path,data=data,headers=headers or {})
  try:
   with self.opener.open(req,timeout=20) as r:return r.code,r.read(),r.headers
  except urllib.error.HTTPError as e:return e.code,e.read(),e.headers
 def api(self,action,data=None,status=200):
  code,body,_=self.request('api/index.php?action='+action,None if data is None else json.dumps(data).encode(),{} if data is None else {'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  result=json.loads(body);self.csrf=result.get('csrf',self.csrf)
  check(code==status,f'{action}: {code} {result}')
  return result.get('data',result.get('error'))
 def start(self,purpose='login',password='',status=303):
  self.api('session')
  code,body,headers=self.request('auth/google/start.php',urllib.parse.urlencode({'csrf':self.csrf,'purpose':purpose,'currentPassword':password}).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
  check(code==status,f'OAuth start {purpose}: {code} {body[:200]}')
  if code!=303:return None
  target=headers['Location'];check(target.startswith('https://accounts.google.com/o/oauth2/v2/auth?'),'Fixed Google authorization endpoint')
  params=dict(urllib.parse.parse_qsl(urllib.parse.urlparse(target).query))
  check(params['redirect_uri']==self.base+'auth/google/callback.php','Correct callback under root/subdir')
  check(params['scope']=='openid email profile' and params['code_challenge_method']=='S256','Minimal scopes and PKCE')
  return params
 def callback(self,params,sub,email,overrides=None,state=None):
  claims={'iss':'https://accounts.google.com','aud':AUD,'sub':sub,'email':email,'email_verified':True,'name':'Google Name','nonce':params['nonce'],'iat':int(time.time()),'exp':int(time.time())+600,'auth_time':int(time.time()),'_challenge':params['code_challenge'],**(overrides or {})}
  code='fixture:'+base64.b64encode(json.dumps(claims).encode()).decode()
  response,body,headers=self.request('auth/google/callback.php?'+urllib.parse.urlencode({'state':params['state'] if state is None else state,'code':code}))
  check(response==303,'Callback redirects cleanly')
  check(urllib.parse.urljoin(self.base,headers['Location']).startswith(self.base+'index.php'),'Callback stays within installation')
  self.api('session')
  return headers['Location']

with tempfile.TemporaryDirectory(prefix='mywish-google-http-') as temp:
 temp=pathlib.Path(temp);doc=temp/'public';doc.mkdir()
 for dest in [doc,doc/'mywish']:
  shutil.copytree(ROOT,dest,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git','config.php','state.php','lock.php','backup-schema-*.php','google-jwks*.php','node_modules','.artifacts','__pycache__'))
 key_script="""$k=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($k,$private);$d=openssl_pkey_get_details($k);$enc=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');echo json_encode(['private'=>$private,'jwks'=>['keys'=>[['kty'=>'RSA','kid'=>'test-key','alg'=>'RS256','use'=>'sig','n'=>$enc($d['rsa']['n']),'e'=>$enc($d['rsa']['e'])]]]]);"""
 keys=temp/'keys.json';keys.write_bytes(subprocess.check_output([PHP,'-r',key_script]))
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 def config(folder,registration=None,enabled=True):
  values="'google'=>['client_id'=>'fixture.apps.googleusercontent.com','client_secret'=>'test-secret-only','redirect_uri'=>'http://127.0.0.1:%s%s/auth/google/callback.php']"%(port,folder) if enabled else "'google'=>[]"
  if registration:values+=",'registration'=>'"+registration+"'"
  (doc/folder.lstrip('/')/'config.php').write_text('<?php return ['+values+'];')
 for folder in ['', '/mywish']:config(folder)
 log=open(temp/'server.log','w')
 server=subprocess.Popen([PHP,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d','auto_prepend_file='+str(ROOT/'tests/fixtures/google-transport.php'),'-S',f'127.0.0.1:{port}','-t',str(doc)],env=dict(os.environ,MYWISH_GOOGLE_TEST_KEYS=str(keys)),stdout=log,stderr=log,start_new_session=True)
 try:
  for _ in range(100):
   try:urllib.request.urlopen(f'http://127.0.0.1:{port}/api/index.php?action=session',timeout=1);break
   except Exception:time.sleep(.05)
  for folder in ['', '/mywish']:
   base=f'http://127.0.0.1:{port}{folder}/'
   owner=Client(base);owner.api('setup',{'siteName':'Test','username':'existing@example.com','name':'Existing','password':'Localpass!12','registration':'open'},201)
   local_id=owner.api('state')['user']['id']
   owner.api('saveList',{'name':'Keep my list','occasion':'Geburtstag'},201)
   guest=Client(base)
   check(guest.request('auth/google/start.php')[0]==405,'OAuth starts with POST only')
   code,body,_=guest.request('auth/google/start.php',b'purpose=login&csrf=wrong',{'Content-Type':'application/x-www-form-urlencoded'});check(code==403,'Start requires CSRF')
   check(b'Mit Google anmelden' in guest.request('index.php?page=login')[1],'Google button displayed when configured')
   # Invalid state/nonce and replay do not establish a session.
   pending=guest.start();guest.callback(pending,'bad','bad@example.com',state='wrong');guest.api('state',status=401)
   pending=guest.start();guest.callback(pending,'bad','bad@example.com',{'nonce':'wrong'});guest.api('state',status=401)
   pending=guest.start();guest.callback(pending,'bad','bad@example.com',{'email_verified':False});guest.api('state',status=401)
   pending=guest.start();guest.callback(pending,'bad','bad@example.com',{'_token_error':True});guest.api('state',status=401)
   # Verified email alone must not merge into an existing local account.
   pending=guest.start();guest.callback(pending,'google-local','existing@example.com');guest.api('state',status=401)
   check(len(json.loads((doc/folder.lstrip('/')/'storage/state.php').read_text().split('\n',1)[1])['users'])==1,'No implicit email-based linking')
   owner.start('link',password='wrong',status=403)
   pending=owner.start('link',password='Localpass!12');owner.callback(pending,'google-local','existing@example.com')
   check(owner.api('state')['user']['id']==local_id,'Explicit link preserves original account')
   owner.api('logout',{})
   pending=guest.start();guest.callback(pending,'google-local','existing@example.com')
   data=guest.api('state');check(data['user']['id']==local_id and len(data['lists'])==1,'Google login retains existing lists')
   check('googleSub' not in json.dumps(data) and 'passwordHash' not in json.dumps(data),'No provider subject or password hash in public snapshot')
   guest.api('logout',{})
   pending=guest.start();guest.callback(pending,'google-new','new@example.com')
   data=guest.api('state');new_id=data['user']['id'];check(not data['user']['hasLocalPassword'] and data['user']['googleLinked'],'New Google-only account')
   # Reusing the same callback state cannot log in again.
   guest.api('logout',{});guest.callback(pending,'google-new','new@example.com');guest.api('state',status=401)
   guest.api('login',{'username':'new@example.com','password':'password'},401)
   pending=guest.start();guest.callback(pending,'google-new','new@example.com')
   guest.api('account',{'name':'Updated','newPassword':''},403)
   pending=guest.start('reauthenticate');check(pending['max_age']=='0','Fresh provider authentication requested')
   guest.callback(pending,'other-user','other@example.com');guest.api('account',{'name':'Updated'},403)
   pending=guest.start('reauthenticate');guest.callback(pending,'google-new','new@example.com',{'auth_time':int(time.time())-600});guest.api('account',{'name':'Updated'},403)
   pending=guest.start('reauthenticate');guest.callback(pending,'google-new','new@example.com')
   guest.api('account',{'name':'Updated','newPassword':'Newpassword!12'})
   check(guest.api('state')['user']['hasLocalPassword'],'Set a local password after Google reauthentication')
   guest.api('unlinkGoogle',{'currentPassword':'Newpassword!12'})
   check(not guest.api('state')['user']['googleLinked'],'Unlink keeps usable local sign-in')
   guest.api('logout',{});guest.api('login',{'username':'new@example.com','password':'Newpassword!12'})
   guest.api('logout',{})
   # Closed registration still permits linked existing users, never new Google users.
   config(folder,'closed')
   pending=guest.start();guest.callback(pending,'google-blocked','blocked@example.com');guest.api('state',status=401)
   pending=guest.start();guest.callback(pending,'google-local','existing@example.com');check(guest.api('state')['user']['id']==local_id,'Closed registration allows existing linked login')
   guest.api('logout',{});config(folder,'open')
   # Google-only deletion requires fresh confirmation too.
   pending=guest.start();guest.callback(pending,'google-delete','delete@example.com')
   guest.api('deleteAccount',{'confirm':'yes'},403)
   pending=guest.start('reauthenticate');guest.callback(pending,'google-delete','delete@example.com');guest.api('deleteAccount',{'confirm':'yes'});guest.api('state',status=401)
   check(guest.request('storage/google-jwks.php')[0]==404,'JWKS cache has executable guard')
   config(folder,enabled=False)
   check(b'Mit Google anmelden' not in guest.request('index.php?page=login')[1],'Google optional and hidden when disabled')
   guest.start(status=503)
   config(folder)
   print('PASS Google callbacks, linking, reauthentication and permissions:',folder or '/')
  print(f'OK: {checks} Google HTTP/OIDC checks')
 finally:
  os.killpg(server.pid,signal.SIGTERM);server.wait();log.close()
