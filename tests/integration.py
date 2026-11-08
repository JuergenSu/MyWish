#!/usr/bin/env python3
"""Isolated copy-deployment, HTTP contract, privacy and concurrency tests. No dependencies."""
import concurrent.futures, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse
from html.parser import HTMLParser
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
 for invalid in ['abcdef!', 'abcdefg', 'äöüßéa!']:
  owner.post('setup',{'name':'Antonia','username':'antonia','password':invalid,'siteName':'MyWish Test','registration':'open'},422)
 owner.post('setup',{'name':'Antonia','username':'antonia','password':' Abcdefgh ','siteName':'MyWish Test','registration':'open'},201)
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
 code,public_before,headers=guest.request('index.php?page=share&id='+a)
 check(code==200 and b'Buch &lt;img' in public_before and b'Weihnachten' not in public_before,'Public link shows exactly the chosen list and escapes stored content')
 check(headers['X-Robots-Tag']=='noindex, nofollow' and 'no-store' in headers['Cache-Control'],'Public view avoids search indexing and shared caches')
 check(b'reservation-form' not in public_before and b'available' not in public_before and b'reservedByMe' not in public_before and b'Wunsch bearbeiten' not in public_before,'Public view has no mutations or reservation state')
 check(('page=login&amp;returnList='+a).encode() in public_before,'Public reserve links lead to login with a scoped return target')
 for bad in ['', 'not-an-id', 'f'*32, ['array']]:
  query=urllib.parse.urlencode({'page':'share','id':bad},doseq=True)
  check(guest.request('index.php?'+query)[0]==404,'Missing and invalid shared lists return 404')
 check(guest.get('state')[0]==401,'Sharing does not unlock the full snapshot')
 guest.post('reserve',{'id':wish},401)
 code,body,headers=owner.request('index.php?page=list&id='+a)
 check(code==200 and b'&lt;script&gt;' in body and b'<script>alert' not in body,'Stored XSS escaped')
 check('frame-ancestors' in headers['Content-Security-Policy'],'CSP present')
 for invalid in ['abcdef!', 'abcdefg', 'äöüßéa!']:
  guest.post('register',{'name':'Invalid','username':'invalid','password':invalid},422)
 users=[]
 for i in range(10):
  u=Client(base); u.post('register',{'name':'Schenkender '+str(i),'username':'giver'+str(i),'password':'abcdefgh'},201); users.append(u)
 # Import form exercised against deterministic Amazon HTML, never a live shop.
 class Inputs(HTMLParser):
  def __init__(self,html): super().__init__(); self.values={}; self.feed(html)
  def handle_starttag(self,tag,attrs):
   attrs=dict(attrs)
   if tag=='input' and 'name' in attrs:self.values[attrs['name']]=attrs.get('value','')
 def import_form(product,**values):
  payload={'action':'import','csrf':owner.csrf,'listId':a,'id':'','productUrl':product,**values}
  code,body,_=owner.request('import-test.php?page=wish&listId='+a,urllib.parse.urlencode(payload).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
  check(code==200,'Import form HTTP status')
  return body.decode(),Inputs(body.decode()).values
 html,form=import_form('https://www.amazon.de/Orzly-Lenkrader-Kompatibel-Joy-Geschenkbox/dp/B0FLM3M1JJ/')
 check(form['title']=='Orzly Lenkräder – kompatibel mit Joy-Con, Geschenkbox' and form['price']=='19.99','Amazon fields populate the actual form')
 check(form['useImportedImage']=='yes' and 'Vorschau geladen' in html,'Actual form offers checked image preview')
 html,form=import_form('https://www.amazon.de/dp/B0FLM3M1JJ',title='Mein eigener Titel',price='0',quantity='2')
 check(form['title']=='Mein eigener Titel' and form['price']=='0' and form['quantity']=='2','Form preserves manual overrides')
 # Google search remains an explicit external link; chosen images are fetched safely by PHP.
 check('google-image-search' not in html,'Search hint hidden when an imported image exists')
 payload={'action':'importImage','csrf':owner.csrf,'listId':a,'id':'','productUrl':'https://www.amazon.de/dp/B0FLM3M1JJ','title':'Eigenes Bild','price':'0','imageUrl':'https://images.example/manual.png'}
 code,body,_=owner.request('import-test.php?page=wish&listId='+a,urllib.parse.urlencode(payload).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 chosen=Inputs(body.decode()).values
 check(code==200 and chosen['useImportedImage']=='yes' and chosen['title']=='Eigenes Bild' and chosen['price']=='0','Manual image address preserves wish fields')
 image_wish=owner.post('saveWish',{'listId':a,'title':chosen['title'],'price':chosen['price'],'productUrl':chosen['productUrl'],'useImportedImage':'yes'},201)['id']
 code,bytes,headers=owner.request('media.php?type=wishes&id='+image_wish)
 check(code==200 and bytes.startswith(b'\x89PNG') and headers['Content-Type']=='image/png','Chosen image is served locally after save')
 owner.post('deleteWish',{'id':image_wish,'confirm':'yes'})
 payload['imageUrl']='https://images.example/invalid.svg';payload['useImportedImage']='yes'
 code,body,_=owner.request('import-test.php?page=wish&listId='+a,urllib.parse.urlencode(payload).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 check(code==422 and 'useImportedImage' not in Inputs(body.decode()).values,'Unsafe image rejected without stale preview')
 owner.post('importImage',{'imageUrl':'http://127.0.0.1/private.png'},422)
 guest.post('importImage',{'imageUrl':'https://images.example/manual.png'},401)
 html,form=import_form('https://shop.example/product-large')
 check(form['title']=='Große Shopseite' and form.get('useImportedImage')=='yes','Page above 4 MB with broken first image and lazy fallback populates the actual form')
 recovered=owner.post('saveWish',{'listId':a,'title':form['title'],'productUrl':form['productUrl'],'useImportedImage':'yes'},201)['id']
 code,bytes,headers=owner.request('media.php?type=wishes&id='+recovered)
 check(code==200 and bytes.startswith(b'\x89PNG') and headers['Content-Type']=='image/png','Recovered image persists and is served locally')
 owner.post('deleteWish',{'id':recovered,'confirm':'yes'})
 html,form=import_form('https://www.amazon.de/SONOFF-SNZB-02LD-Temperatursensor/dp/B0FCG8N5NC?ref=test')
 check(form['title']=='SONOFF SNZB 02LD Temperatursensor' and form['price']=='','Unavailable Amazon page preserves title proposal without inventing a price')
 check(form.get('useImportedImage')=='yes' and 'Amazon-Artikelnummer' in html and 'Amazon liefert gerade keine auslesbaren Produktdaten' in html,'Direct ASIN image appears with partial-import and verification notice')
 check('m.media-amazon.com' not in html and 'google-image-search' not in html,'ASIN fallback uses local preview without browser hotlinking or missing-image hint')
 asin_wish=owner.post('saveWish',{'listId':a,'title':form['title'],'productUrl':form['productUrl'],'useImportedImage':'yes'},201)['id']
 code,bytes,headers=owner.request('media.php?type=wishes&id='+asin_wish)
 check(code==200 and bytes.startswith(b'\x89PNG') and headers['Content-Type']=='image/png','ASIN image survives explicit save and is served locally')
 check(owner.get('state')[1]['data']['wishes'][-1]['productUrl']=='https://www.amazon.de/dp/B0FCG8N5NC','ASIN fallback preserves canonical product link')
 owner.post('deleteWish',{'id':asin_wish,'confirm':'yes'})
 html,form=import_form('https://www.amazon.de/Cuisinart-Farb-Touchscreen-Toaster/dp/B0G1586BZG/?th=1')
 check('Platzhalter (1 × 1 Pixel)' in html and 'useImportedImage' not in form,'Reported toaster shows actual placeholder reason instead of a successful or stale image preview')
 check('google-image-search' not in html and form['title']=='Cuisinart Farb Touchscreen Toaster','Placeholder result retains title proposal without Google search')
 html,form=import_form('https://www.amazon.de/Manuell-speichern/dp/B000000503/')
 check('Amazon liefert gerade keine auslesbaren Produktdaten' in html and 'Vorschau geladen' not in html,'503 fallback is not claimed as a successful import')
 check('useImportedImage' not in form and form['title']=='Manuell speichern','Failed import never reuses old image')
 fallback_wish=owner.post('saveWish',{'listId':a,'title':form['title'],'productUrl':form['productUrl']},201)['id']
 check(owner.get('state')[1]['data']['wishes'][-1]['productUrl']=='https://www.amazon.de/dp/B000000503','Failed import remains manually saveable')
 owner.post('deleteWish',{'id':fallback_wish,'confirm':'yes'})
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
 check(guest.request('index.php?page=share&id='+a)[1]==public_before,'Anonymous view stays byte-identical after reserving: no surprise leaked')
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
 def upload(content, filename, mime, expected, extra=None):
  boundary='mywish-boundary-123456'
  fields={'action':'saveWish','listId':a,'title':'Bildtest','csrf':owner.csrf,**(extra or {})}
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
 image=upload(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC'),'image.png','application/octet-stream',201)['data']['id']
 code,body,headers=guest.request('media.php?type=wishes&id='+image+'&share='+a)
 check(code==200 and headers['Content-Type']=='image/png','Shared wish image is readable without login')
 check(guest.request('media.php?type=wishes&id='+image+'&share='+b)[0]==404,'Other list IDs do not unlock an image')
 check(guest.request('media.php?type=preview&share='+a)[0]==401,'Public link never unlocks session previews')
 upload(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC'),'cover.png','image/png',200,{'action':'saveList','id':b,'name':'Weihnachten','occasion':'Weihnachten'})
 check(guest.request('media.php?type=lists&id='+b+'&share='+b)[0]==200,'Shared list cover is readable without login')
 check(guest.request('media.php?type=lists&id='+b+'&share='+a)[0]==404,'Public cover access is scoped to its list')
 import struct,zlib
 def png_chunk(kind,data): return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
 screenshot=b'\x89PNG\r\n\x1a\n'+png_chunk(b'IHDR',struct.pack('>IIBBBBB',1080,2400,8,2,0,0,0))+png_chunk(b'IDAT',zlib.compress((b'\0'+b'\x80\x40\xff'*1080)*2400))+png_chunk(b'IEND',b'')
 screenshot+=b'x'*(8*1024*1024-len(screenshot))
 screenshot_id=upload(screenshot,'Handy-Screenshot.png','image/png',201)['data']['id']
 _,resized,_=owner.request('media.php?type=wishes&id='+screenshot_id)
 check(struct.unpack('>II',resized[16:24])==(540,1200) and len(resized)<2097152,'Real 8 MB multipart upload is stored as a small preview')
 upload(screenshot+b'x','zu-gross.png','image/png',422)
 owner.post('deleteWish',{'id':screenshot_id,'confirm':'yes'})
 code,body,headers=users[0].request('media.php?type=wishes&id='+image)
 check(code==200 and headers['Content-Type']=='image/png' and body.startswith(b'\x89PNG'),'Authorized fixed MIME media')
 owner.post('saveWish',{'id':image,'listId':a,'title':'Unchanged image','_image':{'tmp_name':'/etc/passwd','error':0,'size':100}})

 owner.post('account',{'name':'Antonia Neu','currentPassword':'wrong'},403)
 other=Client(base); other.post('login',{'username':'antonia','password':' Abcdefgh '})
 for invalid in ['abcdef!', 'abcdefg', 'äöüßéa!']:
  owner.post('account',{'name':'Antonia Neu','currentPassword':' Abcdefgh ','newPassword':invalid},422)
 check(other.get('state')[0]==200,'Rejected password changes preserve active sessions')
 owner.post('account',{'name':'Antonia Neu','currentPassword':' Abcdefgh ','newPassword':''})
 other.post('login',{'username':'antonia','password':' Abcdefgh '})
 owner.post('account',{'name':'Antonia Neu','currentPassword':' Abcdefgh ','newPassword':'Changed1'})
 check(other.get('state')[0]==401,'Password change invalidates other sessions')
 returning=Client(base)
 returning.request('index.php?page=login&returnList='+a)
 failed={'action':'login','csrf':returning.csrf,'username':'giver0','password':'wrong','returnList':a}
 check(returning.request('index.php?page=login',urllib.parse.urlencode(failed).encode(),{'Content-Type':'application/x-www-form-urlencoded'})[0]==401,'Failed password login retains the login page')
 failed['password']='abcdefgh'
 code,body,_=returning.request('index.php?page=login',urllib.parse.urlencode(failed).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 check(code==200 and b'Buch &lt;img' in body and b'reservation-form' in body,'Successful form login returns to the shared list')
 registered=Client(base)
 code,body,_=registered.request('index.php?page=register&returnList='+a,urllib.parse.urlencode({'action':'register','csrf':registered.csrf,'returnList':a,'name':'Neuer Gast','username':'shared-new','password':'abcdefgh'}).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
 check(code==200 and b'Buch &lt;img' in body,'New registrations also return to the shared list')
 # Persistent login across a browser restart and simulated days of inactivity.
 persistent=Client(base);persistent.post('login',{'username':'giver1','password':'abcdefgh'})
 cookie=next(c for c in persistent.jar if c.name.startswith('mywish_'))
 check(not cookie.discard and 14*86400-5<=cookie.expires-time.time()<=14*86400,'Login cookie persists for 14 days')
 check(cookie.path==urllib.parse.urlparse(base).path and cookie.has_nonstandard_attr('HttpOnly') and cookie.get_nonstandard_attr('SameSite')=='Lax','Cookie path, HttpOnly and SameSite remain protected')
 jar_path=doc/'browser-restart.cookies';saved=http.cookiejar.LWPCookieJar(str(jar_path))
 for entry in persistent.jar:saved.set_cookie(entry)
 saved.save(ignore_discard=False,ignore_expires=False)
 reopened=Client(base);reopened.jar=http.cookiejar.LWPCookieJar(str(jar_path));reopened.jar.load(ignore_discard=False,ignore_expires=False)
 reopened.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(reopened.jar))
 check(reopened.get('state')[0]==200,'Restarted browser keeps the authenticated session')
 install=doc/urllib.parse.urlparse(base).path.strip('/')
 session_dir=install/'storage/sessions';session_file=session_dir/('session-'+cookie.value+'.php')
 code,body,_=guest.request('storage/sessions/'+session_file.name)
 check(code==404 and b'csrf' not in body and b'userId' not in body,'Session file guard blocks direct web access')
 def age_session(seconds):
  script="require $argv[1].'/src/Store.php';require $argv[1].'/src/SessionStore.php';$h=new MyWish\\SessionStore($argv[2]);session_set_save_handler($h,true);session_id($argv[3]);session_start();$_SESSION['seen']=time()-(int)$argv[4];session_write_close();touch($argv[2].'/session-'.$argv[3].'.php',time()-(int)$argv[4]);"
  subprocess.run([PHP,'-r',script,str(install),str(session_dir),cookie.value,str(seconds)],check=True,capture_output=True)
 age_session(13*86400)
 check(reopened.get('state')[0]==200,'13 inactive days do not require a new login')
 check(session_file.stat().st_mtime>=time.time()-3,'Using the app renews the server-side session lifetime')
 age_session(14*86400)
 check(reopened.get('state')[0]==401,'14 inactive days require a new login')
 persistent.get('session');persistent.post('login',{'username':'giver1','password':'abcdefgh'})
 old_cookie=next(c for c in persistent.jar if c.name.startswith('mywish_'))
 persistent.post('logout',{})
 check(persistent.get('state')[0]==401,'Logout invalidates a persistent session immediately')
 replay=Client(base);replay.jar.clear();replay.jar.set_cookie(old_cookie)
 check(replay.get('state')[0]==401,'Replaying the old login cookie cannot undo logout')
 # Login rate limiting; repeated wrong attempts share an IP as in production.
 for i in range(10): guest.post('login',{'username':'missing','password':'wrong'},401)
 guest.post('login',{'username':'missing','password':'wrong'},429)
 owner.post('deleteList',{'id':a,'confirm':'yes'})
 check(guest.request('index.php?page=share&id='+a)[0]==404,'Deleting a list invalidates its public link')
 owner.post('deleteAccount',{'currentPassword':'Changed1','confirm':'yes'})
 check(owner.get('state')[0]==401,'Account deletion invalidates session')

with tempfile.TemporaryDirectory(prefix='mywish-http-') as temp:
 doc=pathlib.Path(temp)
 for dest in [doc,doc/'mywish']:
  shutil.copytree(ROOT,dest,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git','state.php','lock.php','tmp-*.php','config.php','.artifacts','__pycache__'))
 for dest in [doc,doc/'mywish']:
  (dest/'import-test.php').write_text("<?php require __DIR__.'/tests/fixtures/import-transport.php'; require __DIR__.'/index.php';")
 # Distinct stores for fresh root/subdirectory installs.
 sock=socket.socket(); sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]; sock.close()
 env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4')
 log=open(doc/'server.log','w')
 process=subprocess.Popen([PHP,'-d','upload_max_filesize=8M','-d','post_max_size=10M','-S',f'127.0.0.1:{port}','-t',str(doc)],env=env,stdout=log,stderr=log,start_new_session=True)
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
   payload={'name':'First','username':'first','password':'Setup123','siteName':'Race','registration':'open'}
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
  legacy.post('account',{'name':'First updated','currentPassword':'Previously valid passphrase','newPassword':'abcdefg'},422)
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
