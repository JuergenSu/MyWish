<?php
declare(strict_types=1);
namespace MyWish;

final class Metadata
{
    public const MAX_HTML_BYTES = 4 * 1024 * 1024;

    public static function publicAddress(string $ip): bool
    {
        // Nur öffentliche IPv4-Ziele; keine IPv6-/Übergangstunnel.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE) !== false
            && (int)explode('.', $ip)[0]<224
            && !str_starts_with($ip,'169.254.') && !str_starts_with($ip,'100.64.')
            && !(ip2long($ip)>=ip2long('100.64.0.0') && ip2long($ip)<=ip2long('100.127.255.255'));
    }

    private static function amazon(string $url): bool
    {
        return in_array(strtolower(parse_url($url, PHP_URL_HOST) ?: ''), ['amazon.de','www.amazon.de','m.amazon.de','smile.amazon.de'], true);
    }

    public static function canonicalUrl(string $url): string
    {
        App::webUrl($url);
        $p=parse_url($url);
        if (self::amazon($url) && (!isset($p['port']) || in_array($p['port'], [80,443], true))
            && preg_match('~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:/|$)~i', $p['path'] ?? '', $m)) {
            return 'https://www.amazon.de/dp/'.strtoupper($m[1]);
        }
        return $url;
    }

    public static function resolveUrl(string $base, string $value): string
    {
        $value=trim(html_entity_decode($value, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) { return App::webUrl($value); }
        $p=parse_url($base);
        $origin=$p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($value,'//')) { return App::webUrl($p['scheme'].':'.$value); }
        if ($value==='') { return $base; }
        if ($value[0]==='#') { return preg_replace('/#.*$/','',$base).$value; }
        if ($value[0]==='?') { return $origin.($p['path'] ?? '/').$value; }
        $path=str_starts_with($value,'/') ? $value : rtrim(dirname($p['path'] ?? '/'),'/').'/'.$value;
        $parts=[];
        // Dot-Segmente nur im Pfad normalisieren, nicht in Query-/Fragmentwerten.
        preg_match('/^([^?#]*)(.*)$/s',$path,$m);
        foreach (explode('/',$m[1]) as $part) {
            if ($part==='..') { array_pop($parts); }
            elseif ($part!=='.' && $part!=='') { $parts[]=$part; }
        }
        return $origin.'/'.implode('/',$parts).$m[2];
    }

    private static function unavailable(string $message): Problem
    {
        return new Problem(422,$message,'shop_unavailable');
    }

    public static function fetch(string $url, int $max = self::MAX_HTML_BYTES): array
    {
        if (!function_exists('curl_init')) { throw self::unavailable('Automatischer Import ist hier nicht verfügbar. Bitte manuell ausfüllen.'); }
        $deadline=microtime(true)+10;
        for ($redirect=0;$redirect<=3;$redirect++) {
            App::webUrl($url); $p=parse_url($url); $host=strtolower($p['host']);
            if (isset($p['port']) && !in_array($p['port'],[80,443],true)) { throw new Problem(422,'Nur Standard-Webports sind erlaubt.'); }
            $ips=filter_var($host,FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
            if (!$ips) { throw self::unavailable('Shop ist nicht erreichbar. Bitte manuell ausfüllen.'); }
            foreach ($ips as $ip) { if (!self::publicAddress($ip)) { throw new Problem(422,'Lokale und private Netzwerkadressen sind für den Import gesperrt.'); } }
            $remaining=(int)(($deadline-microtime(true))*1000);
            if ($remaining<1) { throw self::unavailable('Import hat zu lange gedauert. Bitte manuell ausfüllen.'); }
            $port=$p['port'] ?? ($p['scheme']==='https' ? 443 : 80);
            $body=''; $headers=[]; $c=curl_init($url);
            curl_setopt_array($c,[
                CURLOPT_PROXY=>'', CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining), CURLOPT_TIMEOUT_MS=>$remaining,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ips[0]], CURLOPT_ENCODING=>'',
                CURLOPT_USERAGENT=>'MyWish/1.0 metadata preview',
                CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,image/*;q=0.8,*/*;q=0.5','Accept-Language: de-DE,de;q=0.9,en;q=0.5'],
                CURLOPT_HEADERFUNCTION=>function($c,$line) use (&$headers) {
                    if (str_starts_with($line,'HTTP/')) { $headers=[]; }
                    if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $headers[strtolower(trim($k))]=trim($v); }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>function($c,$part) use (&$body,$max) {
                    // Grenze gilt auch für bereits dekomprimierte Antworten.
                    if (strlen($body)+strlen($part)>$max) { return 0; }
                    $body.=$part; return strlen($part);
                },
            ]);
            $ok=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
            if ($ok===false) { throw self::unavailable('Shop-Antwort nicht lesbar oder zu groß. Bitte manuell ausfüllen.'); }
            if ($status>=300 && $status<400 && isset($headers['location'])) { $url=self::resolveUrl($url,$headers['location']); continue; }
            if ($status!==200) { throw self::unavailable('Der Shop erlaubt gerade keinen Import. Bitte manuell ausfüllen.'); }
            return ['body'=>$body,'type'=>$headers['content-type'] ?? '', 'url'=>$url];
        }
        throw self::unavailable('Zu viele Weiterleitungen. Bitte manuell ausfüllen.');
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/[\s\x{00a0}\x{202f}]+/u',' ',$value) ?? '');
    }

    private static function truncate(string $value,int $bytes): string
    {
        if (!preg_match('//u',$value)) { return ''; }
        if (strlen($value)<=$bytes) { return $value; }
        // Keine mbstring-Pflicht, keine abgeschnittenen UTF-8-Zeichen.
        $value=substr($value,0,$bytes);
        while ($value!=='' && !preg_match('//u',$value)) { $value=substr($value,0,-1); }
        return rtrim($value);
    }

    public static function price(string $value): string
    {
        $value=self::clean($value);
        if (preg_match('/(?:USD|GBP|\$|£|pro\s+Monat|mtl\.?)/iu',$value)) { return ''; }
        $value=preg_replace('/(?:EUR|€|[\s\x{00a0}\x{202f}])/iu','',$value) ?? '';
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/D',$value)) { $value=str_replace('.','',$value); }
        elseif (preg_match('/^\d{1,3}(?:,\d{3})+\.\d{1,2}$/D',$value)) { $value=str_replace(',','',$value); }
        $value=str_replace(',','.',$value);
        try { App::money($value); return $value; } catch (Problem) { return ''; }
    }

    private static function nodeText(\DOMXPath $xp,string $query): string
    {
        foreach ($xp->query($query) as $node) {
            $text=self::clean($node->textContent);
            if ($text!=='') { return $text; }
        }
        return '';
    }

    private static function className(string $name): string
    {
        return "contains(concat(' ',normalize-space(@class),' '),' ".$name." ')";
    }

    private static function amazonFields(\DOMXPath $xp,array &$out): void
    {
        $title=self::nodeText($xp,"//*[@id='productTitle' or @id='title_feature_div']//span[@id='productTitle'] | //*[@id='productTitle']");
        if ($title!=='') { $out['title']=$title; }
        $bullets=[];
        foreach ($xp->query("//*[@id='feature-bullets']//li[not(".self::className('aok-hidden').")]") as $li) {
            $text=self::clean($li->textContent);
            if ($text!=='' && !str_contains($text,'Weitere Produktdetails')) { $bullets[]=$text; }
        }
        if ($bullets) { $out['description']=implode("\n",$bullets); }
        elseif ($description=self::nodeText($xp,"//*[@id='productDescription']")) { $out['description']=$description; }

        $notListPrice="not(ancestor::*[".self::className('a-text-price')."] )";
        $scopes=["//*[@id='corePriceDisplay_desktop_feature_div']", "//*[@id='corePrice_feature_div']", "//*[@id='apex_desktop']", "//*[@id='apex_mobile']"];
        $queries=[];
        foreach ($scopes as $scope) { $queries[]=$scope."//*[".self::className('a-price')."]//*[".self::className('a-offscreen')." and ".$notListPrice."]"; }
        $queries[]="//*[@id='priceblock_ourprice' or @id='priceblock_dealprice' or @id='priceblock_saleprice' or @id='price_inside_buybox' or @id='newBuyBoxPrice']";
        foreach ($queries as $query) {
            foreach ($xp->query($query) as $node) {
                $price=self::price($node->textContent);
                if ($price!=='') { $out['price']=$price; break 2; }
            }
        }
        foreach ($xp->query("//*[@id='landingImage' or @id='imgBlkFront' or @id='ebooksImgBlkFront']") as $image) {
            $candidates=[$image->getAttribute('data-old-hires'),$image->getAttribute('data-a-hires')];
            $dynamic=json_decode($image->getAttribute('data-a-dynamic-image'),true,8);
            if (is_array($dynamic)) {
                $sizes=[];
                foreach ($dynamic as $src=>$dimensions) {
                    if (is_string($src) && is_array($dimensions) && is_numeric($dimensions[0] ?? null) && is_numeric($dimensions[1] ?? null)) { $sizes[$src]=(float)$dimensions[0]*(float)$dimensions[1]; }
                }
                arsort($sizes); $candidates=array_merge($candidates,array_keys($sizes));
            }
            $candidates[]=$image->getAttribute('src');
            foreach ($candidates as $src) {
                if ($src!=='' && !preg_match('~^(?:data|javascript):|(?:transparent-pixel|grey-pixel|loading-)~i',$src)) { $out['imageUrl']=$src; break 2; }
            }
        }
    }

    public static function parse(string $html,string $url): array
    {
        $result=['title'=>'','description'=>'','price'=>'','productUrl'=>self::canonicalUrl($url),'imageUrl'=>''];
        if (!class_exists('DOMDocument')) { return $result; }
        $doc=new \DOMDocument(); $prev=libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
        $xp=new \DOMXPath($doc);
        $documentTitle=self::nodeText($xp,'//title');
        if (self::amazon($url) && ($xp->query("//form[contains(@action,'validateCaptcha')] | //*[@id='captchacharacters']")->length>0
            || preg_match('/^(?:Robot Check|Amazon Captcha|[45]\d\d\s*-\s*Amazon\.de)$/i',$documentTitle))) {
            throw self::unavailable('Amazon hat statt Produktdaten eine Prüf- oder Fehlerseite geliefert.');
        }
        $currency='';
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $key=strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
            $value=$meta->getAttribute('content');
            $field=['og:title'=>'title','og:description'=>'description','og:image'=>'imageUrl','product:price:amount'=>'price','og:price:amount'=>'price'][$key] ?? null;
            if ($field && $result[$field]==='') { $result[$field]=$value; }
            if (in_array($key,['product:price:currency','og:price:currency'],true)) { $currency=strtoupper(trim($value)); }
        }
        if ($currency!=='' && $currency!=='EUR') { $result['price']=''; }
        foreach ($doc->getElementsByTagName('script') as $script) {
            if (strtolower(trim($script->getAttribute('type')))!=='application/ld+json') { continue; }
            $data=json_decode($script->textContent,true,32);
            if (is_array($data)) { self::product($data,$result); }
        }
        if (self::amazon($url)) { self::amazonFields($xp,$result); }
        if ($result['title']==='') { $result['title']=$documentTitle; }
        if (self::amazon($url)) {
            $result['title']=preg_replace('/\s*:\s*Amazon\.de\b.*$/iu','',$result['title']) ?? '';
            if (preg_match('/^(?:Amazon(?:\.de)?|Amazon\s+(?:Sign[- ]?In|Anmelden))$/i',trim($result['title']))) { $result['title']=''; }
        }
        foreach (['title'=>200,'description'=>4000] as $key=>$limit) {
            $value=$key==='title' ? self::clean($result[$key]) : trim($result[$key]);
            $result[$key]=self::truncate($value,$limit);
        }
        $result['price']=self::price($result['price']);
        if ($result['imageUrl']!=='') {
            try { $result['imageUrl']=self::resolveUrl($url,$result['imageUrl']); }
            catch (Problem) { $result['imageUrl']=''; }
        }
        return $result;
    }

    private static function product(array $node,array &$out): void
    {
        if (in_array('Product',(array)($node['@type'] ?? []),true)) {
            foreach (['name'=>'title','description'=>'description'] as $key=>$field) {
                if ($out[$field]==='' && is_string($node[$key] ?? null)) { $out[$field]=strip_tags($node[$key]); }
            }
            $image=$node['image'] ?? '';
            if (is_array($image)) { $image=$image['url'] ?? $image[0] ?? ''; }
            if ($out['imageUrl']==='' && is_string($image)) { $out['imageUrl']=$image; }
            $offers=$node['offers'] ?? [];
            if (is_array($offers)) {
                if (!array_is_list($offers)) { $offers=[$offers]; }
                foreach ($offers as $offer) {
                    if (is_array($offer) && strtoupper((string)($offer['priceCurrency'] ?? 'EUR'))==='EUR' && is_scalar($offer['price'] ?? null) && $out['price']==='') { $out['price']=(string)$offer['price']; }
                }
            }
        }
        foreach ($node as $child) { if (is_array($child)) { self::product($child,$out); } }
    }

    private static function fallback(string $url): array
    {
        $title='';
        if (self::amazon($url) && preg_match('~^/([^/]+)/(?:dp|gp/product)/~i',parse_url($url,PHP_URL_PATH) ?: '',$m)) {
            $title=self::truncate(self::clean(str_replace('-',' ',rawurldecode($m[1]))),200);
        }
        return ['title'=>$title,'description'=>'','price'=>'','productUrl'=>self::canonicalUrl($url),'imageUrl'=>''];
    }

    public static function preview(string $url): array
    {
        unset($_SESSION['importImage']);
        $target=self::canonicalUrl($url); $warning='';
        try {
            $page=self::fetch($target); $result=self::parse($page['body'],$page['url']);
            if ($result['title']==='') { $warning='Keine verlässlichen Produktdaten erkannt. Bitte Titel, Preis und Bild selbst ergänzen.'; }
        } catch (Problem $error) {
            // Sicherheits-/Validierungsfehler bleiben Fehler. Nur Shop-Ausfälle erlauben manuelle Vorschau.
            if ($error->reason!=='shop_unavailable') { throw $error; }
            $result=self::fallback($url);
            $warning=(self::amazon($target) ? 'Amazon liefert gerade keine auslesbaren Produktdaten. ' : 'Der Shop ist gerade nicht auslesbar. ')
                .'Der Link wurde übernommen. Bitte Titel, Preis und Bild selbst ergänzen bzw. prüfen.';
            if ($result['title']!=='') { $warning.=' Der vorgeschlagene Titel stammt nur aus dem Link.'; }
        }
        if ($result['imageUrl']!=='') {
            try { $img=self::fetch($result['imageUrl'],2*1024*1024); $_SESSION['importImage']=['at'=>time(),'image'=>App::imageBytes($img['body'])]; }
            catch (Problem) { $warning=trim($warning.' Das Produktbild konnte nicht übernommen werden; ein eigenes Bild ist weiterhin möglich.'); }
        }
        $result['hasImage']=isset($_SESSION['importImage']); $result['warning']=$warning;
        unset($result['imageUrl']); return $result;
    }

    public static function mergePreview(array $preview,array $input): array
    {
        foreach (['id'=>32,'listId'=>32,'title'=>200,'description'=>4000,'price'=>12,'quantity'=>2] as $key=>$max) {
            if (array_key_exists($key,$input)) {
                $value=App::text($input,$key,$max);
                if ($value!=='' || in_array($key,['id','listId'],true)) { $preview[$key]=$value; }
            }
        }
        return $preview;
    }
}
