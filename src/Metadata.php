<?php
declare(strict_types=1);
namespace MyWish;

final class Metadata
{
    public static function publicAddress(string $ip): bool
    {
        // V1 verwendet ausschließlich öffentliche IPv4-Ziele, keine IPv6-/Übergangstunnel.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE) !== false
            && (int)explode('.', $ip)[0]<224
            && !str_starts_with($ip,'169.254.') && !str_starts_with($ip,'100.64.')
            && !(ip2long($ip)>=ip2long('100.64.0.0') && ip2long($ip)<=ip2long('100.127.255.255'));
    }
    public static function resolveUrl(string $base, string $value): string
    {
        $value=trim(html_entity_decode($value, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
        if (preg_match('~^https?://~i',$value)) { return $value; }
        $p=parse_url($base); $origin=$p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($value,'//')) { return $p['scheme'].':'.$value; }
        if (str_starts_with($value,'/')) { return $origin.$value; }
        return $origin.rtrim(dirname($p['path'] ?? '/'),'/').'/'.$value;
    }
    public static function fetch(string $url, int $max = 1048576): array
    {
        if (!function_exists('curl_init')) { throw new Problem(422,'Automatischer Import ist hier nicht verfügbar. Bitte manuell ausfüllen.'); }
        $deadline=microtime(true)+10;
        for ($redirect=0;$redirect<=3;$redirect++) {
            App::webUrl($url); $p=parse_url($url); $host=strtolower($p['host']);
            if (isset($p['port']) && !in_array($p['port'],[80,443],true)) { throw new Problem(422,'Nur Standard-Webports sind erlaubt.'); }
            if (filter_var($host,FILTER_VALIDATE_IP)) { $ips=[$host]; }
            else { $ips=gethostbynamel($host) ?: []; }
            if (!$ips) { throw new Problem(422,'Shop ist nicht erreichbar. Bitte manuell ausfüllen.'); }
            foreach ($ips as $ip) { if (!self::publicAddress($ip)) { throw new Problem(422,'Lokale und private Netzwerkadressen sind für den Import gesperrt.'); } }
            $remaining=(int)(($deadline-microtime(true))*1000);
            if ($remaining<1) { throw new Problem(422,'Import hat zu lange gedauert. Bitte manuell ausfüllen.'); }
            $port=$p['port'] ?? ($p['scheme']==='https' ? 443 : 80);
            $body=''; $headers=[]; $c=curl_init($url);
            curl_setopt_array($c,[CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining),CURLOPT_TIMEOUT_MS=>$remaining,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ips[0]],CURLOPT_USERAGENT=>'MyWish/1.0 metadata preview',CURLOPT_HEADERFUNCTION=>function($c,$line) use (&$headers) { if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $headers[strtolower(trim($k))]=trim($v); } return strlen($line); },CURLOPT_WRITEFUNCTION=>function($c,$part) use (&$body,$max) { if (strlen($body)+strlen($part)>$max) { return 0; } $body.=$part; return strlen($part); }]);
            $ok=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
            if ($ok===false) { throw new Problem(422,'Shop-Antwort nicht lesbar oder zu groß. Bitte manuell ausfüllen.'); }
            if ($status>=300 && $status<400 && isset($headers['location'])) { $url=self::resolveUrl($url,$headers['location']); continue; }
            if ($status!==200) { throw new Problem(422,'Der Shop erlaubt keinen Import. Bitte manuell ausfüllen.'); }
            return ['body'=>$body,'type'=>$headers['content-type'] ?? '', 'url'=>$url];
        }
        throw new Problem(422,'Zu viele Weiterleitungen. Bitte manuell ausfüllen.');
    }
    public static function parse(string $html,string $url): array
    {
        $result=['title'=>'','description'=>'','price'=>'','productUrl'=>$url,'imageUrl'=>''];
        if (!class_exists('DOMDocument')) { return $result; }
        $doc=new \DOMDocument(); $prev=libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); libxml_clear_errors(); libxml_use_internal_errors($prev);
        foreach ($doc->getElementsByTagName('meta') as $m) {
            $key=strtolower($m->getAttribute('property') ?: $m->getAttribute('name'));
            $value=trim($m->getAttribute('content'));
            $field=['og:title'=>'title','og:description'=>'description','og:image'=>'imageUrl','product:price:amount'=>'price','og:price:amount'=>'price'][$key] ?? null;
            if ($field) { $result[$field]=$value; }
        }
        foreach ($doc->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type')!=='application/ld+json') { continue; }
            $data=json_decode($script->textContent,true,32);
            if (is_array($data)) { self::product($data,$result); }
        }
        if (!$result['title']) { $result['title']=trim($doc->getElementsByTagName('title')->item(0)?->textContent ?? ''); }
        if ($result['imageUrl']) { $result['imageUrl']=self::resolveUrl($url,$result['imageUrl']); }
        foreach (['title'=>200,'description'=>4000,'price'=>12] as $key=>$limit) { $result[$key]=function_exists('mb_strcut') ? mb_strcut($result[$key],0,$limit,'UTF-8') : (strlen($result[$key])<=$limit ? $result[$key] : ''); }
        try { App::money($result['price']); } catch (Problem) { $result['price']=''; }
        return $result;
    }
    private static function product(array $node,array &$out): void
    {
        $types=(array)($node['@type'] ?? []);
        if (in_array('Product',$types,true)) {
            foreach (['name'=>'title','description'=>'description'] as $k=>$field) { if (!$out[$field] && is_string($node[$k] ?? null)) { $out[$field]=strip_tags($node[$k]); } }
            $image=$node['image'] ?? ''; if (is_array($image)) { $image=$image['url'] ?? $image[0] ?? ''; }
            if (!$out['imageUrl'] && is_string($image)) { $out['imageUrl']=$image; }
            $offer=$node['offers'] ?? []; if (isset($offer[0])) { $offer=$offer[0]; }
            if (!$out['price'] && is_array($offer) && ($offer['priceCurrency'] ?? 'EUR')==='EUR' && is_scalar($offer['price'] ?? null)) { $out['price']=(string)$offer['price']; }
        }
        foreach ($node as $child) { if (is_array($child)) { self::product($child,$out); } }
    }
    public static function preview(string $url): array
    {
        $page=self::fetch($url); $result=self::parse($page['body'],$page['url']);
        unset($_SESSION['importImage']);
        if ($result['imageUrl']) {
            try { $img=self::fetch($result['imageUrl'],2*1024*1024); $_SESSION['importImage']=['at'=>time(),'image'=>App::imageBytes($img['body'])]; }
            catch (\Throwable) { /* Bild ist optional; niemals extern im Browser nachladen. */ }
        }
        $result['hasImage']=isset($_SESSION['importImage']); unset($result['imageUrl']);
        return $result;
    }
}
