<?php
declare(strict_types=1);
namespace MyWish;

/** A deny rule is mandatory for copy deployments; verify it before raw files exist. */
final class StorageProtection
{
    public const RULE = "Require all denied\n";
    private static array $contexts=[];
    private static array $force=[];

    public static function configure(string $storage,string $base,array $server): void
    {
        $storage=realpath($storage) ?: $storage;
        self::$contexts[$storage]=['base'=>$base,'server'=>$server];
    }
    public static function forMigration(string $storage): void { self::$force[realpath($storage) ?: $storage]=true; }
    private static function rules(string $storage): string
    {
        $rules=@file_get_contents($storage.'/.htaccess');
        if ($rules===false || trim($rules)!==trim(self::RULE)) { throw new Problem(503,'Bitte storage/.htaccess mit „Require all denied“ mitkopieren. Der Datenspeicher wurde nicht freigegeben.'); }
        return hash('sha256',$rules);
    }
    private static function binding(string $storage,?string $webRoot): array
    {
        return ['storage'=>$storage,'webRoot'=>$webRoot!==null ? (realpath($webRoot) ?: $webRoot) : null,'rules'=>self::rules($storage)];
    }
    private static function receipt(string $storage): ?array
    {
        $raw=@file_get_contents($storage.'/protection.php');
        if ($raw===false || !str_starts_with($raw,Store::GUARD)) { return null; }
        try { $value=json_decode(substr($raw,strlen(Store::GUARD)),true,16,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        return is_array($value) ? $value : null;
    }
    public static function requireProtected(string $storage,string $target,?string $webRoot): void
    {
        if ($target===$storage || !str_starts_with($target,$storage.'/')) { throw new Problem(503,'Im Webroot müssen JSON und Bilder im geschützten storage-Unterordner liegen.'); }
        $binding=self::binding($storage,$webRoot);$context=self::$contexts[$storage] ?? null;
        $receipt=self::receipt($storage);
        $matches=$receipt!==null && ($receipt['binding'] ?? null)===$binding;
        if ($matches && $context!==null) {
            $matches=($receipt['host'] ?? null)===($context['server']['HTTP_HOST'] ?? '') && ($receipt['address'] ?? null)===($context['server']['SERVER_ADDR'] ?? '');
        }
        if (!$matches || (self::$force[$storage] ?? false)) {
            if ($context===null) { throw new Problem(503,'Der .htaccess-Schutz wurde noch nicht geprüft. Bitte migrate-storage.php im Browser öffnen.'); }
            self::verify($storage,$webRoot);self::$force[$storage]=false;
        }
        // A child directory must not override the inherited deny rule.
        for ($path=$target;$path!==$storage;$path=dirname($path)) {
            if (is_file($path.'/.htaccess') && trim((string)file_get_contents($path.'/.htaccess'))!==trim(self::RULE)) { throw new Problem(503,'Eine abweichende .htaccess im Datenordner könnte den Zugriff erlauben. Bitte den Deny-Schutz wiederherstellen.'); }
        }
        foreach (['records','images'] as $folder) { if (is_file($target.'/'.$folder.'/.htaccess') && trim((string)file_get_contents($target.'/'.$folder.'/.htaccess'))!==trim(self::RULE)) { throw new Problem(503,'Der Schutz eines Daten-Unterordners wurde verändert.'); } }
    }
    public static function verify(string $storage,?string $webRoot): void
    {
        $context=self::$contexts[$storage] ?? null;
        if ($context===null || !function_exists('curl_init')) { throw new Problem(503,'Für die .htaccess-Prüfung bitte die Migration im Browser aufrufen und PHP-cURL aktivieren.'); }
        $binding=self::binding($storage,$webRoot);$server=$context['server'];
        $host=$server['HTTP_HOST'] ?? '';$address=$server['SERVER_ADDR'] ?? '';$port=(int)($server['SERVER_PORT'] ?? 0);
        if (!is_string($host) || !preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD',$host) || !filter_var($address,FILTER_VALIDATE_IP) || $port<1 || $port>65535) { throw new Problem(503,'Die lokale Webserver-Adresse für die Schutzprüfung fehlt. Bitte die Hosting-Einstellungen prüfen.'); }
        $name=parse_url('http://'.$host,PHP_URL_HOST);$name=trim($name,'[]');
        $tls=$port===443 || ($port!==80 && !empty($server['HTTPS']) && $server['HTTPS']!=='off');
        $url=($tls ? 'https://' : 'http://').(str_contains($name,':') ? '['.$name.']' : $name).':'.$port;
        $app=realpath(dirname(__DIR__));$root=$webRoot!==null ? realpath($webRoot) : false;
        if (!$app || !$root || !str_starts_with($storage,$root.'/')) { throw new Problem(503,'Der veröffentlichte Storage-Pfad kann nicht für die Schutzprüfung ermittelt werden.'); }
        $storageUrl='/'.implode('/',array_map('rawurlencode',explode('/',substr($storage,strlen($root)+1))));
        $nonce=bin2hex(random_bytes(24));$secret=bin2hex(random_bytes(32));$public=$app.'/'.$nonce.'.txt';$created=[];
        try {
            if (file_put_contents($public,$secret,LOCK_EX)===false) { throw new Problem(503,'Für die einmalige Schutzprüfung muss PHP im App-Ordner eine Testdatei anlegen und entfernen können.'); }
            @chmod($public,0644);$created[]=$public;
            $request=function(string $path) use ($url,$host,$name,$address,$port,$server): array {
                $curl=\curl_init($url.$path);$body='';
                $resolve=$name.':'.$port.':'.(str_contains($address,':') ? '['.$address.']' : $address);
                \curl_setopt_array($curl,[CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$resolve],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Host: '.$host,'Cache-Control: no-cache'],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int { $body.=substr($chunk,0,max(0,16384-strlen($body)));return strlen($chunk); }]);
                if (isset($server['PHP_AUTH_USER'],$server['PHP_AUTH_PW'])) { \curl_setopt($curl,CURLOPT_USERPWD,$server['PHP_AUTH_USER'].':'.$server['PHP_AUTH_PW']); }
                $ok=\curl_exec($curl);$status=\curl_getinfo($curl,CURLINFO_RESPONSE_CODE);\curl_close($curl);return [$ok!==false,$status,$body];
            };
            [$ok,$status,$body]=$request($context['base'].'/'.$nonce.'.txt');
            if (!$ok || $status!==200 || !hash_equals($secret,$body)) { throw new Problem(503,'Der Webserver konnte sich zur .htaccess-Prüfung nicht eindeutig selbst erreichen. Es wurden keine JSON- oder Bilddaten veröffentlicht.'); }
            foreach (['json','png','jpg','webp','txt'] as $extension) {
                $file=$storage.'/'.$nonce.'.'.$extension;
                if (file_put_contents($file,$secret,LOCK_EX)===false) { throw new Problem(503,'Die Schutz-Testdatei kann nicht geschrieben werden.'); }
                @chmod($file,0644);$created[]=$file;
                [$ok,$status,$body]=$request($storageUrl.'/'.$nonce.'.'.$extension);
                if (!$ok || $status!==403 || str_contains($body,$secret)) { throw new Problem(503,'Der Webserver sperrt storage nicht zuverlässig mit HTTP 403. Bitte .htaccess und AllowOverride prüfen. Die bisherigen Daten bleiben unverändert.'); }
            }
            Store::writeFile($storage.'/protection.php',Store::GUARD.json_encode(['binding'=>$binding,'host'=>$host,'address'=>$address,'checkedAt'=>time()],JSON_THROW_ON_ERROR));
        } finally { foreach ($created as $file) { @unlink($file); } }
    }
}
