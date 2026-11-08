<?php
declare(strict_types=1);
namespace MyWish;

require_once dirname(__DIR__).'/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

final class GoogleOidc
{
    private const AUTHORIZE='https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN='https://oauth2.googleapis.com/token';
    private const KEYS='https://www.googleapis.com/oauth2/v3/certs';

    public function __construct(private array $config,private string $cacheDirectory) {}

    public function available(): bool
    {
        foreach (['client_id','client_secret','redirect_uri'] as $key) {
            if (!is_string($this->config[$key] ?? null) || trim($this->config[$key])==='') { return false; }
        }
        return function_exists('curl_init') && extension_loaded('openssl');
    }

    private function assertConfiguration(): void
    {
        if (!$this->available()) { throw new Problem(503,'Google-Anmeldung ist auf dieser Instanz nicht eingerichtet.'); }
        $uri=$this->config['redirect_uri']; $parts=parse_url($uri);
        $local=in_array($parts['host'] ?? '',['localhost','127.0.0.1','[::1]'],true);
        if (!filter_var($uri,FILTER_VALIDATE_URL) || (($parts['scheme'] ?? '')!=='https' && !(($parts['scheme'] ?? '')==='http' && $local))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !str_ends_with($parts['path'] ?? '', '/auth/google/callback.php')
            || !preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D',$this->config['client_id'])) {
            throw new Problem(503,'Google-Konfiguration prüfen: gültige Client-ID und exakte HTTPS-Callback-Adresse erforderlich.');
        }
    }

    private static function random(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }

    public function begin(string $purpose,?array $user): string
    {
        $this->assertConfiguration();
        if (!in_array($purpose,['login','link','reauthenticate'],true) || ($purpose!=='login' && !$user)) { throw new Problem(403,'Diese Google-Aktion ist nicht erlaubt.'); }
        $pending=['state'=>self::random(),'nonce'=>self::random(),'verifier'=>self::random(),'createdAt'=>time(),'purpose'=>$purpose,'userId'=>$user['id'] ?? null,'version'=>$user['sessionVersion'] ?? null];
        $_SESSION['googleOauth']=$pending;
        $query=['client_id'=>$this->config['client_id'],'redirect_uri'=>$this->config['redirect_uri'],'response_type'=>'code','scope'=>'openid email profile','state'=>$pending['state'],'nonce'=>$pending['nonce'],
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$pending['verifier'],true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account'];
        if ($purpose==='reauthenticate') { $query['max_age']='0'; }
        return self::AUTHORIZE.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }

    public static function consume(array $query): array
    {
        $pending=$_SESSION['googleOauth'] ?? null;
        unset($_SESSION['googleOauth']);
        $state=$query['state'] ?? null;
        if (!is_array($pending) || !is_string($state) || !hash_equals($pending['state'],$state) || $pending['createdAt']<time()-600 || $pending['createdAt']>time()+60) {
            throw new Problem(403,'Die Google-Anmeldung ist abgelaufen oder gehört nicht zu dieser Sitzung. Bitte erneut beginnen.');
        }
        if (isset($query['error'])) { throw new Problem(400,'Die Google-Anmeldung wurde abgebrochen.'); }
        if (!is_string($query['code'] ?? null) || $query['code']==='' || strlen($query['code'])>4096) { throw new Problem(400,'Google hat keinen gültigen Anmeldecode geliefert.'); }
        return $pending;
    }

    public function complete(string $code,array $pending): array
    {
        $this->assertConfiguration();
        $response=self::request(self::TOKEN,[
            'code'=>$code,'client_id'=>$this->config['client_id'],'client_secret'=>$this->config['client_secret'],
            'redirect_uri'=>$this->config['redirect_uri'],'grant_type'=>'authorization_code','code_verifier'=>$pending['verifier'],
        ]);
        $token=$response['id_token'] ?? null;
        if (!is_string($token) || strlen($token)>16384) { throw new Problem(401,'Google hat keine gültige Identitätsbestätigung geliefert.'); }
        $keys=$this->keys(false);
        // Ungeprüfter Header dient nur zum Cache-Abgleich, nie als Schlüssel-/Algorithmusquelle.
        $header=json_decode(JWT::urlsafeB64Decode(explode('.',$token)[0]),true);
        $kid=is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : '';
        $known=array_column($keys['keys'] ?? [],'kid');
        if ($kid!=='' && strlen($kid)<=255 && !in_array($kid,$known,true)) { $keys=$this->keys(true); }
        return self::verifyToken($token,$keys,$this->config['client_id'],$pending['nonce'],$pending['purpose']==='reauthenticate' ? $pending['createdAt'] : null);
    }

    public static function verifyToken(string $token,array $keys,string $audience,string $nonce,?int $reauthenticationStarted=null): array
    {
        $leeway=JWT::$leeway; JWT::$leeway=60;
        try {
            if (strlen($token)>16384 || substr_count($token,'.')!==2) { throw new \UnexpectedValueException(); }
            // Nur RSA-Signaturschlüssel für RS256 zulassen; kein vom Token gewählter Algorithmus/Schlüsselserver.
            $keys['keys']=array_values(array_filter($keys['keys'] ?? [],fn($k)=>is_array($k) && ($k['kty'] ?? '')==='RSA' && ($k['alg'] ?? 'RS256')==='RS256' && ($k['use'] ?? 'sig')==='sig'));
            $claims=(array)JWT::decode($token,JWK::parseKeySet($keys,'RS256'));
            if (!in_array($claims['iss'] ?? '',['https://accounts.google.com','accounts.google.com'],true)
                || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null) || $claims['exp']<=time()-60 || $claims['iat']>time()+60
                || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce,$claims['nonce'])
                || !is_string($claims['sub'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$claims['sub'])
                || ($claims['email_verified'] ?? false)!==true || !is_string($claims['email'] ?? null) || strlen($claims['email'])>254 || !filter_var($claims['email'],FILTER_VALIDATE_EMAIL)) { throw new \UnexpectedValueException(); }
            $aud=$claims['aud'] ?? null;
            if ($aud!==$audience && !(is_array($aud) && in_array($audience,$aud,true) && ($claims['azp'] ?? null)===$audience)) { throw new \UnexpectedValueException(); }
            if (isset($claims['azp']) && $claims['azp']!==$audience) { throw new \UnexpectedValueException(); }
            if ($reauthenticationStarted!==null && (!is_int($claims['auth_time'] ?? null) || $claims['auth_time']<$reauthenticationStarted-60 || $claims['auth_time']>time()+60)) { throw new \UnexpectedValueException(); }
            $name=is_string($claims['name'] ?? null) ? trim($claims['name']) : '';
            if ($name==='') { $name=explode('@',$claims['email'])[0]; }
            $name=substr($name,0,100);
            while ($name!=='' && !preg_match('//u',$name)) { $name=substr($name,0,-1); }
            $name=App::text(['name'=>$name],'name',100,true);
            return ['sub'=>$claims['sub'],'email'=>strtolower($claims['email']),'name'=>$name];
        } catch (\Throwable) { throw new Problem(401,'Die Google-Identität konnte nicht sicher bestätigt werden. Bitte erneut anmelden.'); }
        finally { JWT::$leeway=$leeway; }
    }

    private function keys(bool $refresh): array
    {
        $file=$this->cacheDirectory.'/google-jwks.php';
        if (!$refresh && is_file($file)) {
            $raw=@file_get_contents($file);
            if (is_string($raw) && str_starts_with($raw,Store::GUARD)) {
                $cache=json_decode(substr($raw,strlen(Store::GUARD)),true);
                if (is_array($cache) && is_int($cache['expiresAt'] ?? null) && $cache['expiresAt']>time() && is_array($cache['jwks']['keys'] ?? null)) { return $cache['jwks']; }
            }
        }
        $keys=self::request(self::KEYS);
        if (!is_array($keys['keys'] ?? null)) { throw new Problem(503,'Googles Signaturschlüssel sind gerade nicht verfügbar. Bitte später erneut versuchen.'); }
        $temp=$this->cacheDirectory.'/google-jwks-'.bin2hex(random_bytes(8)).'.php';
        try {
            $data=Store::GUARD.json_encode(['expiresAt'=>time()+3600,'jwks'=>$keys],JSON_THROW_ON_ERROR);
            if (@file_put_contents($temp,$data,LOCK_EX)===strlen($data)) { @chmod($temp,0600); @rename($temp,$file); }
        } finally { if (is_file($temp)) { @unlink($temp); } }
        return $keys;
    }

    private static function request(string $url,?array $form=null): array
    {
        $body=''; $c=curl_init($url);
        $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($c,$chunk) use (&$body) { if (strlen($body)+strlen($chunk)>131072) { return 0; } $body.=$chunk; return strlen($chunk); }];
        if ($form!==null) { $options[CURLOPT_POST]=true; $options[CURLOPT_POSTFIELDS]=http_build_query($form,'','&',PHP_QUERY_RFC3986); $options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded'; }
        curl_setopt_array($c,$options);
        $ok=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
        if ($ok===false || $status!==200) { throw new Problem(503,'Google-Anmeldung ist gerade nicht möglich. Bitte erneut versuchen.'); }
        try { $data=json_decode($body,true,32,JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new Problem(503,'Die Google-Antwort konnte nicht verarbeitet werden.'); }
        if (!is_array($data)) { throw new Problem(503,'Die Google-Antwort konnte nicht verarbeitet werden.'); }
        return $data;
    }
}
