#!/usr/bin/env python3
"""Isolated OpenAI image-search HTTP tests with synthetic provider replies only."""
import http.cookiejar,json,os,pathlib,shutil,signal,socket,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse
from html.parser import HTMLParser
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php'); checks=0
def check(ok,label):
 global checks
 if not ok: raise AssertionError(label)
 checks+=1
class Inputs(HTMLParser):
 def __init__(self,html): super().__init__();self.values={};self.feed(html)
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag=='input' and 'name' in a:self.values[a['name']]=a.get('value','')
class Client:
 def __init__(self,base):
  self.base=base;self.csrf='';self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.get('session')
 def request(self,path,data=None,headers=None):
  try:
   with self.opener.open(urllib.request.Request(self.base+path,data=data,headers=headers or {}),timeout=35) as r:return r.status,r.read(),r.headers
  except urllib.error.HTTPError as e:return e.code,e.read(),e.headers
 def get(self,action):
  code,body,headers=self.request('api/index.php?action='+action);data=json.loads(body);self.csrf=data.get('csrf',self.csrf);return code,data
 def post(self,action,data,expected=200,token=None):
  code,body,_=self.request('api/index.php?action='+action,json.dumps(data).encode(),{'Content-Type':'application/json','X-CSRF-Token':self.csrf if token is None else token});r=json.loads(body);self.csrf=r.get('csrf',self.csrf)
  check(code==expected and r['ok']==(expected<400),f'{action}: {code}: {r}');return r.get('data',r.get('error'))
def config(dest,key='sk-test-server-key',limit=100):
 (dest/'config.php').write_text("<?php return ['openai'=>['api_key'=>"+json.dumps(key)+",'model'=>'test-model','daily_limit'=>"+str(limit)+"]];")
def used(dest):
 p=dest/'storage/openai-search.php'
 return json.loads(p.read_text().split('\n',1)[1])['used'] if p.exists() else 0
def scenario(base,dest):
 owner=Client(base);anon=Client(base)
 owner.post('setup',{'name':'Owner','username':'owner','password':'abcdefgh','siteName':'Images','registration':'open'},201)
 lid=owner.post('saveList',{'name':'Wishes','occasion':'Anderes'},201)['id']
 guest=Client(base);guest.post('register',{'name':'Other','username':'other','password':'abcdefgh'},201)
 payload={'listId':lid,'title':'Cuisinart RBT2200E','productUrl':'https://www.amazon.de/Toaster/dp/B0G1586BZG?tracking=secret','api_key':'ignored-client-key'}
 anon.post('searchImage',payload,401);guest.post('searchImage',payload,403)
 owner.post('searchImage',payload,403,token='wrong');owner.post('searchImage',{**payload,'title':''},422)
 owner.post('searchImage',{**payload,'productUrl':'file:///etc/passwd'},422)
 check(used(dest)==0,'Unauthorized, CSRF and invalid-input requests never charge OpenAI')
 code,html,_=owner.request('index.php?page=wish&listId='+lid)
 check(code==200 and b'Bild mit OpenAI suchen' in html and b'data-image-auto="no"' in html,'Configured UI offers explicit search without searching on normal form load')
 check(b'sk-test-server-key' not in html and b'test-model' not in html,'Credentials and model configuration stay on server')
 result=owner.post('searchImage',payload)
 check(result['hasImage'] and result['imageSourceUrl']=='https://shop.example/product' and result['productUrl']==payload['productUrl'],'API gives checked image/source and preserves original product URL')
 check(used(dest)==1 and 'invented.jpg' not in json.dumps(result),'Exactly one search; free assistant text never used')
 code,body,headers=owner.request('media.php?type=preview')
 check(code==200 and body.startswith(b'\x89PNG') and headers['Content-Type']=='image/png','Preview image is served locally')
 wish=owner.post('saveWish',{'listId':lid,'title':'Toaster','productUrl':payload['productUrl'],'useImportedImage':'yes'},201)['id']
 check(owner.get('state')[1]['data']['wishes'][0]['imageSourceUrl']=='https://shop.example/product','Source survives save and API snapshot')
 code,html,_=guest.request('index.php?page=list&id='+lid)
 check(code==200 and b'Bildquelle' in html and b'href="https://shop.example/product"' in html,'Other users see clickable image attribution')
 check(b'sk-test-server-key' not in html and b'invented.jpg' not in html,'No provider text or API credentials in rendered wishlist')
 owner.post('saveWish',{'id':wish,'listId':lid,'title':'Renamed'})
 check(owner.get('state')[1]['data']['wishes'][0]['imageSourceUrl']=='https://shop.example/product','Editing wish retains image credit')
 owner.post('searchImage',payload);check(used(dest)==1,'Cached query has no additional paid call')
 config(dest,limit=1);owner.post('searchImage',{**payload,'title':'Another product'},429)
 check(used(dest)==1,'Daily budget enforced before calling provider');config(dest)
 # Native POST works without JavaScript and preserves manually entered fields.
 form={'action':'searchImage','csrf':owner.csrf,**payload,'title':'Native product','price':'0','quantity':'2','description':'Keep my description'}
 code,body,_=owner.request('index.php?page=wish&listId='+lid,urllib.parse.urlencode(form).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 html=body.decode();f=Inputs(html).values
 check(code==200 and f.get('useImportedImage')=='yes' and f['title']=='Native product' and f['price']=='0' and f['quantity']=='2','Native image search keeps manual values and checked preview')
 check('Keep my description' in html and 'Bildquelle' in html and 'data-image-auto="no"' in html,'Native search includes credit and never loops automatically')
 form.update(title='provider-error',useImportedImage='yes')
 code,body,_=owner.request('index.php?page=wish&listId='+lid,urllib.parse.urlencode(form).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 check(code==503 and b'HTTP 401' in body and b'sk-test-server-key' not in body and 'useImportedImage' not in Inputs(body.decode()).values,'Provider error preserves form without key exposure or stale image')
 result=owner.post('searchImage',{**payload,'title':'no-match'})
 check(not result['hasImage'] and 'kein passendes' in result['warning'],'No-match search allows manual continuation')
 # Automatic fallback is a second request after failed normal import.
 form={'action':'import','csrf':owner.csrf,'listId':lid,'productUrl':payload['productUrl'],'title':'Cuisinart RBT2200E'}
 before=used(dest)
 code,body,_=owner.request('index.php?page=wish&listId='+lid,urllib.parse.urlencode(form).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 check(code==200 and b'data-image-auto="yes"' in body and used(dest)==before,'Failed import schedules browser fallback without blocking normal preview on an API request')
 config(dest,key='')
 code,body,_=owner.request('index.php?page=wish&listId='+lid)
 check(b'openai-image-search' not in body,'Empty configured key hides optional search')
 owner.post('searchImage',payload,503);check(used(dest)==before,'Disabled search never charges provider');config(dest)
 for path in ['config.php','storage/openai-search.php']:
  code,body,_=anon.request(path)
  check(b'sk-test-server-key' not in body and b'"cache"' not in body,'Configuration and guarded cache do not disclose contents')
 check(owner.request('api/index.php?action=searchImage')[0]==405,'Image search requires POST')
 print('PASS OpenAI image search HTTP:',base)
with tempfile.TemporaryDirectory(prefix='mywish-openai-http-') as temp:
 doc=pathlib.Path(temp)
 for dest in [doc,doc/'mywish']:
  shutil.copytree(ROOT,dest,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git','config.php','state.php','lock.php','backup-schema-*.php','openai-search*.php','node_modules','.artifacts','__pycache__'));config(dest)
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 log=open(doc/'server.log','w')
 process=subprocess.Popen([PHP,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d','auto_prepend_file='+str(ROOT/'tests/fixtures/openai-transport.php'),'-S',f'127.0.0.1:{port}','-t',str(doc)],stdout=log,stderr=log,start_new_session=True)
 try:
  for _ in range(100):
   try:urllib.request.urlopen(f'http://127.0.0.1:{port}/api/index.php?action=session',timeout=1);break
   except Exception:time.sleep(.05)
  for path,dest in [('/',doc),('/mywish/',doc/'mywish')]:scenario(f'http://127.0.0.1:{port}'+path,dest)
  print(f'OK: {checks} OpenAI HTTP checks')
 finally:os.killpg(process.pid,signal.SIGTERM);process.wait();log.close()
