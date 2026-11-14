"""Disposable rootless Apache + PHP-CGI for real .htaccess integration checks."""
import os,pathlib,shutil,socket,subprocess,time,urllib.request,urllib.error,shlex,signal

class ApacheServer:
 def __init__(self,base,document_root,overrides='All'):
  self.base=pathlib.Path(base);self.doc=pathlib.Path(document_root);self.base.mkdir(parents=True,exist_ok=True)
  self.bin=os.environ.get('APACHE_BIN') or shutil.which('apache2') or '/usr/sbin/apache2'
  self.modules=pathlib.Path(os.environ.get('APACHE_MODULES','/usr/lib/apache2/modules'))
  self.cgi=os.environ.get('PHP_CGI_BIN') or shutil.which('php-cgi') or shutil.which('php-cgi8.4') or shutil.which('php-cgi8.2')
  if not pathlib.Path(self.bin).is_file() or not self.cgi:raise RuntimeError('Apache and PHP-CGI are required for the webroot migration test')
  sock=socket.socket();sock.bind(('127.0.0.1',0));self.port=sock.getsockname()[1];sock.close()
  cgi=self.base/'cgi-bin';cgi.mkdir();wrapper=cgi/'php-cgi'
  wrapper.write_text('#!/bin/sh\nexec '+shlex.quote(self.cgi)+' -d upload_max_filesize=8M -d post_max_size=10M "$@"\n');wrapper.chmod(0o755)
  modules=['mpm_prefork','authz_core','authz_host','mime','dir','alias','actions','cgi']
  lines=[f'ServerRoot "{self.base}"',f'DefaultRuntimeDir "{self.base}"',f'PidFile "{self.base}/pid"',f'Listen 127.0.0.1:{self.port}',f'ServerName 127.0.0.1:{self.port}',f'ErrorLog "{self.base}/error.log"','LogLevel warn']
  for name in modules:lines.append(f'LoadModule {name}_module "{self.modules}/mod_{name}.so"')
  lines += ['StartServers 2','MinSpareServers 1','MaxSpareServers 2','ServerLimit 8','MaxRequestWorkers 8','TypesConfig /etc/mime.types',f'DocumentRoot "{self.doc}"',f'<Directory "{self.doc}">',f'AllowOverride {overrides}','Require all granted','Options -Indexes','DirectoryIndex index.php','AddHandler php-script .php','Action php-script /cgi-bin/php-cgi','</Directory>',f'ScriptAlias /cgi-bin/ "{cgi}/"',f'<Directory "{cgi}">','AllowOverride None','Options +ExecCGI','Require all granted','</Directory>']
  if os.getuid()==0:lines+=['User www-data','Group www-data']
  self.config=self.base/'httpd.conf';self.config.write_text('\n'.join(lines)+'\n');self.log=open(self.base/'stdout.log','w')
 def start(self):
  check=subprocess.run([self.bin,'-t','-f',str(self.config)],capture_output=True,text=True)
  if check.returncode:raise RuntimeError(check.stdout+check.stderr)
  self.process=subprocess.Popen([self.bin,'-f',str(self.config),'-DFOREGROUND'],stdout=self.log,stderr=self.log,start_new_session=True)
  for _ in range(100):
   if self.process.poll() is not None:raise RuntimeError((self.base/'stdout.log').read_text()+(self.base/'error.log').read_text())
   try:urllib.request.urlopen(self.url('/'),timeout=1);return self
   except urllib.error.HTTPError:return self
   except Exception:time.sleep(.05)
  raise RuntimeError('Apache did not start: '+(self.base/'error.log').read_text())
 def url(self,path):return f'http://127.0.0.1:{self.port}'+path
 def close(self):
  if hasattr(self,'process') and self.process.poll() is None:os.killpg(self.process.pid,signal.SIGTERM);self.process.wait(timeout=15)
  self.log.close()
