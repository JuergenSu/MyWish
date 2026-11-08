<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require dirname(__DIR__).'/src/GoogleOidc.php';
use MyWish\App;
use MyWish\Store;
use MyWish\Problem;
use MyWish\GoogleOidc;
use Firebase\JWT\JWT;

session_start();
$checks=0;
function ok(bool $value,string $label): void { global $checks; if (!$value) { throw new RuntimeException($label); } $checks++; }
function denied(callable $fn,int $status): void { try { $fn(); } catch (Problem $e) { ok($e->status===$status,'Unexpected status '.$e->status); return; } throw new RuntimeException('Expected rejection'); }
function b64(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key,$private); $details=openssl_pkey_get_details($key);
$jwks=['keys'=>[['kty'=>'RSA','kid'=>'test-key','use'=>'sig','alg'=>'RS256','n'=>b64($details['rsa']['n']),'e'=>b64($details['rsa']['e'])]]];
$aud='fixture.apps.googleusercontent.com'; $nonce='expected-nonce';
$claims=['iss'=>'https://accounts.google.com','aud'=>$aud,'sub'=>'google-one','email'=>'first@example.com','email_verified'=>true,'nonce'=>$nonce,'iat'=>time(),'exp'=>time()+600,'auth_time'=>time(),'name'=>'Test Person'];
$token=JWT::encode($claims,$private,'RS256','test-key');
$identity=GoogleOidc::verifyToken($token,$jwks,$aud,$nonce);
ok($identity['sub']==='google-one' && $identity['name']==='Test Person','Valid Google identity');
foreach (['iss'=>'https://attacker.example','aud'=>'other-client','nonce'=>'wrong','email_verified'=>false,'sub'=>'','email'=>'not-an-email','exp'=>time()-120,'iat'=>time()+120] as $field=>$value) {
    $invalid=array_replace($claims,[$field=>$value]);
    denied(fn()=>GoogleOidc::verifyToken(JWT::encode($invalid,$private,'RS256','test-key'),$jwks,$aud,$nonce),401);
}
foreach (['exp','iat','nonce','email_verified','sub'] as $field) { $invalid=$claims; unset($invalid[$field]); denied(fn()=>GoogleOidc::verifyToken(JWT::encode($invalid,$private,'RS256','test-key'),$jwks,$aud,$nonce),401); }
denied(fn()=>GoogleOidc::verifyToken(JWT::encode($claims,str_repeat('x',32),'HS256','test-key'),$jwks,$aud,$nonce),401);
denied(fn()=>GoogleOidc::verifyToken('eyJhbGciOiJub25lIn0.'.b64(json_encode($claims)).'.',$jwks,$aud,$nonce),401);
denied(fn()=>GoogleOidc::verifyToken($token,$jwks,$aud,'wrong'),401);
denied(fn()=>GoogleOidc::verifyToken($token,['keys'=>[]],$aud,$nonce),401);
$multi=array_replace($claims,['aud'=>[$aud,'other-client'],'azp'=>$aud]);
ok(GoogleOidc::verifyToken(JWT::encode($multi,$private,'RS256','test-key'),$jwks,$aud,$nonce)['sub']==='google-one','Authorized party in multiple audiences');
$multi['azp']='other-client'; denied(fn()=>GoogleOidc::verifyToken(JWT::encode($multi,$private,'RS256','test-key'),$jwks,$aud,$nonce),401);
$old=array_replace($claims,['auth_time'=>time()-600]); denied(fn()=>GoogleOidc::verifyToken(JWT::encode($old,$private,'RS256','test-key'),$jwks,$aud,$nonce,time()),401);
ok(GoogleOidc::verifyToken($token,$jwks,$aud,$nonce,time())['sub']==='google-one','Fresh Google reauthentication');
$dir=sys_get_temp_dir().'/mywish-google-unit-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
    $store=new Store($dir); $app=new App($store);
    $oidc=new GoogleOidc(['client_id'=>$aud,'client_secret'=>'test-only','redirect_uri'=>'https://example.com/mywish/auth/google/callback.php'],$dir);
    ok($oidc->available(),'Configured Google provider');
    ok(!(new GoogleOidc([],$dir))->available(),'Google disabled by default');
    $url=$oidc->begin('login',null); parse_str(parse_url($url,PHP_URL_QUERY),$params);
    ok($params['scope']==='openid email profile' && $params['code_challenge_method']==='S256','Minimal scopes and PKCE');
    ok($params['code_challenge']===b64(hash('sha256',$_SESSION['googleOauth']['verifier'],true)),'PKCE challenge matches verifier');
    ok(strlen($params['state'])>=43 && strlen($params['nonce'])>=43 && $params['state']!==$params['nonce'],'Random state and nonce');
    $pending=GoogleOidc::consume(['state'=>$params['state'],'code'=>'code']);
    ok($pending['purpose']==='login' && !isset($_SESSION['googleOauth']),'State consumed once');
    denied(fn()=>GoogleOidc::consume(['state'=>$params['state'],'code'=>'code']),403);
    $oidc->begin('login',null); denied(fn()=>GoogleOidc::consume(['state'=>'wrong','code'=>'code']),403);
    $oidc->begin('login',null); $_SESSION['googleOauth']['createdAt']=time()-601; denied(fn()=>GoogleOidc::consume(['state'=>$_SESSION['googleOauth']['state'],'code'=>'code']),403);
    $oidc->begin('login',null); denied(fn()=>GoogleOidc::consume(['state'=>$_SESSION['googleOauth']['state'],'error'=>'access_denied']),400);
    $app->act('setup',['siteName'=>'Test','username'=>'local','name'=>'Local','password'=>'Localpass!12','registration'=>'open']);
    $local=$store->transaction(fn(&$s)=>$s['users'][0]);
    $pending=['userId'=>$local['id'],'version'=>$local['sessionVersion']];
    $app->googleIdentity($identity,'link',$pending);
    $linked=$store->transaction(fn(&$s)=>$s['users'][0]);
    ok($linked['passwordHash']===$local['passwordHash'] && $linked['googleSub']==='google-one','Link retains original password hash');
    $app->act('logout',[]);
    $signedIn=$app->googleIdentity($identity,'login',[]);
    ok($signedIn['id']===$local['id'] && $signedIn['hasLocalPassword'],'Google login keeps original account and lists');
    $app->act('logout',[]);
    $second=['sub'=>'google-two','email'=>'second@example.com','name'=>'Second'];
    $created=$app->googleIdentity($second,'login',[]);
    ok(!$created['hasLocalPassword'] && $created['googleLinked'],'Google-only account');
    $app->act('logout',[]);
    denied(fn()=>$app->act('login',['username'=>'second@example.com','password'=>'password']),401); // Dummy hash must never authenticate Google-only accounts.
    $app->googleIdentity($second,'login',[]);
    denied(fn()=>$app->act('account',['name'=>'Changed','currentPassword'=>'anything']),403);
    $current=$store->transaction(fn(&$s)=>App::user($s));
    $app->googleIdentity($second,'reauthenticate',['userId'=>$current['id'],'version'=>$current['sessionVersion']]);
    $app->act('account',['name'=>'Changed','newPassword'=>'Newpassword!12']);
    $updated=$store->transaction(fn(&$s)=>App::user($s));
    ok(password_verify('Newpassword!12',$updated['passwordHash']),'Google-only user sets local password after reauthentication');
    $app->act('unlinkGoogle',['currentPassword'=>'Newpassword!12']);
    ok(!$app->snapshot()['user']['googleLinked'],'Safe unlink with local password');
    $app->act('logout',[]);
    $app->act('login',['username'=>'local','password'=>'Localpass!12']);
    denied(fn()=>$app->googleIdentity(['sub'=>'google-other','email'=>'third@example.com','name'=>'Third'],'link',['userId'=>$local['id'],'version'=>1]),403);
    // Migration: preserve exact legacy data in a guarded pre-migration backup.
    $legacy=$store->transaction(fn(&$s)=>$s); $legacy['schemaVersion']=1;
    foreach ($legacy['users'] as &$row) { unset($row['googleSub'],$row['googleEmail']); } unset($row);
    $bytes=Store::GUARD.json_encode($legacy); file_put_contents($dir.'/state.php',$bytes);
    $migrated=$store->transaction(fn(&$s)=>$s);
    $backups=glob($dir.'/backup-schema-1-*.php');
    ok(count($backups)===1 && file_get_contents($backups[0])===$bytes,'Exact migration backup');
    ok($migrated['schemaVersion']===Store::VERSION && $migrated['users'][0]['passwordHash']===$legacy['users'][0]['passwordHash'] && $migrated['lists']===$legacy['lists'],'Migration preserves existing data');
    $store->transaction(fn(&$s)=>$s);ok(count(glob($dir.'/backup-schema-1-*.php'))===1,'Migration runs once');
    echo "OK: $checks Google OIDC/account/migration checks\n";
} finally { $_SESSION=[]; session_destroy(); foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir); }
