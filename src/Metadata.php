<?php
declare(strict_types=1);
namespace MyWish;

final class Metadata
{
    public const MAX_HTML_BYTES = 12 * 1024 * 1024;
    private const MAX_IMAGE_ATTEMPTS = 5;

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
        $basePath=$p['path'] ?? '/';
        $directory=str_ends_with($basePath,'/') ? $basePath : dirname($basePath).'/';
        $path=str_starts_with($value,'/') ? $value : $directory.$value;
        $parts=[];
        // Dot-Segmente nur im Pfad normalisieren, nicht in Query-/Fragmentwerten.
        preg_match('/^([^?#]*)(.*)$/s',$path,$m);
        foreach (explode('/',$m[1]) as $part) {
            if ($part==='..') { array_pop($parts); }
            elseif ($part!=='.' && $part!=='') { $parts[]=$part; }
        }
        $normalized=implode('/',$parts);
        if ($normalized!=='' && (str_ends_with($m[1],'/') || preg_match('~/(?:\.|\.\.)$~',$m[1]))) { $normalized.='/'; }
        return $origin.'/'.$normalized.$m[2];
    }

    private static function unavailable(string $message): Problem
    {
        return new Problem(422,$message,'shop_unavailable');
    }

    public static function fetch(string $url, int $max = self::MAX_HTML_BYTES, ?float $deadline = null): array
    {
        if (!function_exists('curl_init')) { throw self::unavailable('Automatischer Import ist hier nicht verfügbar. Bitte manuell ausfüllen.'); }
        $deadline=min($deadline ?? INF,microtime(true)+10);
        for ($redirect=0;$redirect<=3;$redirect++) {
            App::webUrl($url); $p=parse_url($url); $host=strtolower($p['host']);
            if (isset($p['port']) && !in_array($p['port'],[80,443],true)) { throw new Problem(422,'Nur Standard-Webports sind erlaubt.'); }
            $ips=filter_var($host,FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
            if (!$ips) { throw self::unavailable('Shop ist nicht erreichbar. Bitte manuell ausfüllen.'); }
            foreach ($ips as $ip) { if (!self::publicAddress($ip)) { throw new Problem(422,'Lokale und private Netzwerkadressen sind für den Import gesperrt.'); } }
            $remaining=(int)(($deadline-microtime(true))*1000);
            if ($remaining<1) { throw self::unavailable('Import hat zu lange gedauert. Bitte manuell ausfüllen.'); }
            $port=$p['port'] ?? ($p['scheme']==='https' ? 443 : 80);
            $body=''; $headers=[]; $tooLarge=false; $c=curl_init($url);
            curl_setopt_array($c,[
                CURLOPT_PROXY=>'', CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining), CURLOPT_TIMEOUT_MS=>$remaining,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ips[0]], CURLOPT_ENCODING=>'',
                CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.5','Accept-Language: de-DE,de;q=0.9,en;q=0.5'],
                CURLOPT_HEADERFUNCTION=>function($c,$line) use (&$headers) {
                    if (str_starts_with($line,'HTTP/')) { $headers=[]; }
                    if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $headers[strtolower(trim($k))]=trim($v); }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>function($c,$part) use (&$body,&$tooLarge,$max) {
                    // Grenze gilt auch für bereits dekomprimierte Antworten.
                    if (strlen($body)+strlen($part)>$max) { $tooLarge=true; return 0; }
                    $body.=$part; return strlen($part);
                },
            ]);
            $ok=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
            if ($tooLarge) { throw self::unavailable('Die Shop-Antwort überschreitet die Importgrenze von '.($max/1024/1024).' MB. Bitte manuell ergänzen.'); }
            if ($ok===false) { throw self::unavailable('Shop-Antwort nicht lesbar. Bitte manuell ausfüllen.'); }
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

    private static function schemaType(array $node,string $type): bool
    {
        foreach ((array)($node['@type'] ?? []) as $value) {
            if (is_string($value) && preg_replace('~^https?://schema\.org/~','',$value)===$type) { return true; }
        }
        return false;
    }

    private static function compactHtml(string $html): string
    {
        // Große Hydration-/JavaScript-Blöcke nicht in den DOM kopieren. Produkt-JSON
        // wird ohnehin nur bis 2 MB ausgewertet; libxml begrenzt einzelne Textknoten.
        $offset=0; $copied=0; $parts=[];
        while (preg_match('~<script\b[^>]*>~i',$html,$match,PREG_OFFSET_CAPTURE,$offset)) {
            $start=$match[0][1]; $content=$start+strlen($match[0][0]);
            $end=stripos($html,'</script',$content);
            if ($end===false || ($close=strpos($html,'>',$end))===false) { break; }
            if ($end-$content>2*1024*1024) { $parts[]=substr($html,$copied,$start-$copied); $copied=$close+1; }
            $offset=$close+1;
        }
        if (!$parts) { return $html; }
        $parts[]=substr($html,$copied);
        return implode('',$parts);
    }

    private static function structuredProduct(\DOMDocument $doc,string $url): array
    {
        $products=[]; $index=[]; $visited=0;
        $page=preg_replace('/[?#].*$/','',self::canonicalUrl($url));
        $walk=function(array $node,int $depth=0,int $score=0) use (&$walk,&$products,&$index,&$visited,$page,$url): void {
            if ($depth>32 || ++$visited>4096) { return; }
            if (is_string($node['@id'] ?? null)) { $index[$node['@id']]=$node; }
            if (self::schemaType($node,'Product') || self::schemaType($node,'ProductGroup')) {
                foreach (['url','@id'] as $key) {
                    if (!is_string($node[$key] ?? null)) { continue; }
                    try {
                        $candidate=preg_replace('/[?#].*$/','',self::canonicalUrl(self::resolveUrl($url,$node[$key])));
                        if ($candidate===$page) { $score+=20; }
                    } catch (Problem) {}
                }
                $products[]=['node'=>$node,'score'=>$score];
                // Unterprodukte/Empfehlungen nicht versehentlich als Hauptartikel wählen.
                return;
            }
            foreach ($node as $key=>$child) {
                if (!is_array($child) || in_array($key,['itemListElement','isRelatedTo','isSimilarTo','hasVariant'],true)) { continue; }
                $walk($child,$depth+1,$score+($key==='mainEntity' ? 40 : 0));
            }
        };
        foreach ($doc->getElementsByTagName('script') as $script) {
            $type=strtolower(trim(explode(';',$script->getAttribute('type'))[0]));
            $id=$script->getAttribute('id');
            $productJson=$script->hasAttribute('data-product-json') || preg_match('/^product[-_]?json(?:[-_]|$)/i',$id);
            if ($type!=='application/ld+json' && !($type==='application/json' && ($productJson || $id==='__NEXT_DATA__'))) { continue; }
            if (strlen($script->textContent)>2*1024*1024) { continue; }
            $data=json_decode($script->textContent,true,64);
            if (!is_array($data)) { continue; }
            if ($type==='application/ld+json') { $walk($data); continue; }
            $node=$id==='__NEXT_DATA__' ? ($data['props']['pageProps']['product'] ?? []) : ($data['product'] ?? $data);
            if (is_array($node) && is_string($node['title'] ?? $node['name'] ?? null)) {
                // Nur ausdrücklich markierte Produktdaten; keine beliebige App-Konfiguration.
                $products[]=['score'=>-10,'node'=>[
                    '@type'=>'Product','name'=>$node['title'] ?? $node['name'],
                    'description'=>is_string($node['description'] ?? null) ? $node['description'] : '',
                    'image'=>$node['images'] ?? $node['image'] ?? $node['featured_image'] ?? [],
                ]];
            }
        }
        usort($products,fn($a,$b)=>$b['score']<=>$a['score']);
        $product=$products[0]['node'] ?? [];
        // ImageObject-Referenzen in @graph auflösen; Zyklen bleiben tiefenbegrenzt.
        $expand=function(mixed $value,int $depth=0) use (&$expand,$index): mixed {
            if ($depth>8 || !is_array($value)) { return $depth>8 ? [] : $value; }
            if (count($value)===1 && is_string($value['@id'] ?? null) && isset($index[$value['@id']])) { return $expand($index[$value['@id']],$depth+1); }
            foreach ($value as $key=>$child) { if (is_array($child)) { $value[$key]=$expand($child,$depth+1); } }
            return $value;
        };
        return $expand($product);
    }

    private static function imageValues(mixed $value,int $depth=0): array
    {
        if ($depth>8) { return []; }
        if (is_string($value)) { return [$value]; }
        if (!is_array($value)) { return []; }
        $images=[];
        foreach (array_is_list($value) ? $value : array_intersect_key($value,array_flip(['contentUrl','url','src','originalSrc','thumbnailUrl','image'])) as $child) {
            $images=array_merge($images,self::imageValues($child,$depth+1));
            if (count($images)>=24) { break; }
        }
        return array_slice($images,0,24);
    }

    private static function srcset(string $value): array
    {
        $images=[];
        preg_match_all('~(?:^|,\s*)(\S+)\s+(\d+(?:\.\d+)?)(w|x)(?=\s*(?:,|$))~',$value,$matches,PREG_SET_ORDER);
        foreach ($matches as $match) { $images[]=['url'=>$match[1],'size'=>(float)$match[2]]; }
        usort($images,fn($a,$b)=>$b['size']<=>$a['size']);
        return array_column($images,'url');
    }

    private static function imageCandidates(\DOMXPath $xp,string $url,array $product,string $hero): array
    {
        $images=[];
        $add=function(string $value) use (&$images,$url): void {
            if (count($images)>=24 || in_array(strtolower(trim($value)),['','false','true','null','undefined'],true)) { return; }
            try { $src=self::resolveUrl($url,$value); } catch (Problem) { return; }
            $path=parse_url($src,PHP_URL_PATH) ?: '';
            if (preg_match('~(?:^|[/_.-])(?:placeholder|spacer|transparent(?:-pixel)?|grey-pixel|blank|pixel|loader|loading)(?:[/_.-]|$)|\.(?:svg|gif)(?:$)~i',$path)) { return; }
            $images[$src]=true;
        };
        if (self::amazon($url)) { $add($hero); }
        foreach (self::imageValues($product['image'] ?? []) as $src) { $add($src); }
        // Mehrere Metadaten behalten: ein defektes og:image darf nicht alle Alternativen verbergen.
        foreach (['og:image:secure_url','og:image','og:image:url','twitter:image','twitter:image:src'] as $key) {
            foreach ($xp->query('//meta') as $meta) {
                if (strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'))===$key) { $add($meta->getAttribute('content')); }
            }
        }
        foreach ($xp->query('//link') as $link) {
            if (in_array('image_src',preg_split('/\s+/',strtolower($link->getAttribute('rel'))),true)) { $add($link->getAttribute('href')); }
        }
        $nodes=[];
        foreach ($xp->query('//*[@itemprop]') as $node) {
            if (in_array('image',preg_split('/\s+/',strtolower($node->getAttribute('itemprop'))),true)) {
                $nodes[]=$node;
                foreach ($xp->query('.//img | .//*[@itemprop="contentUrl" or @itemprop="url"]',$node) as $child) { $nodes[]=$child; }
            }
        }
        // Nur klar erkennbare Produkt-/Hauptbilder, keine beliebigen Werbebanner oder Logos.
        foreach ($xp->query('//img') as $node) {
            if ($node->hasAttribute('width') && $node->hasAttribute('height') && (int)$node->getAttribute('width')<=32 && (int)$node->getAttribute('height')<=32) { continue; }
            $context=''; $parent=$node;
            for ($i=0;$i<8 && $parent instanceof \DOMElement;$i++,$parent=$parent->parentNode) { $context.=' '.$parent->getAttribute('id').' '.$parent->getAttribute('class').' '.$parent->getAttribute('data-testid'); }
            if (preg_match('/recommend|related|similar|upsell|cross.?sell|carousel-reco/i',$context)) { continue; }
            if (preg_match('/product.{0,24}(?:image|gallery|photo)|(?:image|gallery|photo).{0,24}product|pdp.{0,16}(?:image|gallery)|hero[-_ ]?image|landingImage|imgBlkFront/i',$context)) { $nodes[]=$node; }
        }
        foreach ($nodes as $node) {
            $picture=$xp->query('ancestor::picture[1]',$node)->item(0);
            if ($picture) {
                foreach ($picture->getElementsByTagName('source') as $source) {
                    // Die lokal unterstützten Formate gegenüber AVIF bevorzugen.
                    if (in_array(strtolower($source->getAttribute('type')),['','image/jpeg','image/png','image/webp'],true)) {
                        foreach (['data-srcset','srcset'] as $attr) { foreach (self::srcset($source->getAttribute($attr)) as $src) { $add($src); } }
                    }
                }
            }
            foreach (['content','href','data-old-hires','data-a-hires','data-zoom-image','data-large-image'] as $attr) { $add($node->getAttribute($attr)); }
            foreach (['data-srcset','data-lazy-srcset','srcset'] as $attr) { foreach (self::srcset($node->getAttribute($attr)) as $src) { $add($src); } }
            $dynamic=json_decode($node->getAttribute('data-a-dynamic-image'),true,8);
            if (is_array($dynamic)) {
                $sizes=[];
                foreach ($dynamic as $src=>$dimensions) {
                    if (is_string($src) && is_numeric($dimensions[0] ?? null) && is_numeric($dimensions[1] ?? null)) { $sizes[$src]=(float)$dimensions[0]*(float)$dimensions[1]; }
                }
                arsort($sizes); foreach (array_keys($sizes) as $src) { $add($src); }
            }
            foreach (['data-original','data-src','data-lazy-src','data-image'] as $attr) { $add($node->getAttribute($attr)); }
            $add($node->getAttribute('src'));
        }
        return array_keys($images);
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
        try {
            if (!$doc->loadHTML('<?xml encoding="utf-8" ?>'.self::compactHtml($html),LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) {
                throw self::unavailable('Die Shopseite konnte nicht vollständig gelesen werden. Bitte manuell ergänzen.');
            }
        }
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
            $field=['og:title'=>'title','twitter:title'=>'title','og:description'=>'description','description'=>'description','twitter:description'=>'description','og:image'=>'imageUrl','product:price:amount'=>'price','og:price:amount'=>'price'][$key] ?? null;
            if ($field && $result[$field]==='') { $result[$field]=$value; }
            if (in_array($key,['product:price:currency','og:price:currency'],true)) { $currency=strtoupper(trim($value)); }
        }
        if ($currency!=='' && $currency!=='EUR') { $result['price']=''; }
        $product=self::structuredProduct($doc,$url);
        self::product($product,$result);
        if (self::amazon($url)) { self::amazonFields($xp,$result); }
        $imageBase=$url;
        $base=$xp->query('//base[@href]')->item(0);
        if ($base) { try { $imageBase=self::resolveUrl($url,$base->getAttribute('href')); } catch (Problem) {} }
        $result['imageUrls']=self::imageCandidates($xp,$imageBase,$product,$result['imageUrl']);
        $result['imageUrl']=$result['imageUrls'][0] ?? '';
        if ($result['title']==='') { $result['title']=self::nodeText($xp,'//h1') ?: $documentTitle; }
        if (self::amazon($url)) {
            $result['title']=preg_replace('/\s*:\s*Amazon\.de\b.*$/iu','',$result['title']) ?? '';
            if (preg_match('/^(?:Amazon(?:\.de)?|Amazon\s+(?:Sign[- ]?In|Anmelden))$/i',trim($result['title']))) { $result['title']=''; }
        }
        foreach (['title'=>200,'description'=>4000] as $key=>$limit) {
            $value=$key==='title' ? self::clean($result[$key]) : trim($result[$key]);
            $result[$key]=self::truncate($value,$limit);
        }
        $result['price']=self::price($result['price']);
        return $result;
    }

    private static function product(array $node,array &$out): void
    {
        if (self::schemaType($node,'Product') || self::schemaType($node,'ProductGroup')) {
            foreach (['name'=>'title','description'=>'description'] as $key=>$field) {
                if ($out[$field]==='' && is_string($node[$key] ?? null)) { $out[$field]=strip_tags($node[$key]); }
            }
            $offers=$node['offers'] ?? [];
            if (is_array($offers)) {
                if (!array_is_list($offers)) { $offers=[$offers]; }
                foreach ($offers as $offer) {
                    if (is_array($offer) && strtoupper((string)($offer['priceCurrency'] ?? 'EUR'))==='EUR' && is_scalar($offer['price'] ?? null) && $out['price']==='') { $out['price']=(string)$offer['price']; }
                }
            }
        }
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
        $target=self::canonicalUrl($url); $warning=''; $deadline=microtime(true)+25;
        try {
            $page=self::fetch($target,self::MAX_HTML_BYTES,$deadline); $result=self::parse($page['body'],$page['url']);
            unset($page);
            if ($result['title']==='') { $warning='Keine verlässlichen Produktdaten erkannt. Bitte Titel, Preis und Bild selbst ergänzen.'; }
        } catch (Problem $error) {
            // Sicherheits-/Validierungsfehler bleiben Fehler. Nur Shop-Ausfälle erlauben manuelle Vorschau.
            if ($error->reason!=='shop_unavailable') { throw $error; }
            $result=self::fallback($url);
            $warning=(self::amazon($target) ? 'Amazon liefert gerade keine auslesbaren Produktdaten. ' : $error->getMessage().' ')
                .'Der Link wurde übernommen. Bitte Titel, Preis und Bild selbst ergänzen bzw. prüfen.';
            if ($result['title']!=='') { $warning.=' Der vorgeschlagene Titel stammt nur aus dem Link.'; }
        }
        $images=array_slice($result['imageUrls'] ?? [],0,self::MAX_IMAGE_ATTEMPTS);
        foreach ($images as $imageUrl) {
            if (microtime(true)>=$deadline) { break; }
            try {
                $img=self::fetch($imageUrl,2*1024*1024,$deadline);
                $_SESSION['importImage']=['at'=>time(),'image'=>App::imageBytes($img['body'])];
                break;
            } catch (Problem) { /* Weiter mit dem nächsten Bild derselben Produktseite. */ }
        }
        if ($images && !isset($_SESSION['importImage'])) {
            $warning=trim($warning.' Die gefundenen Produktbilder konnten nicht übernommen werden. Bitte eine Bildadresse oder ein eigenes Bild ergänzen.');
        } elseif (!$images && $warning==='') {
            $warning='Produktdaten übernommen, aber kein Produktbild gefunden. Bitte eine Bildadresse oder ein eigenes Bild ergänzen.';
        }
        $result['hasImage']=isset($_SESSION['importImage']); $result['warning']=$warning;
        unset($result['imageUrl'],$result['imageUrls']); return $result;
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

    public static function googleImagesUrl(string $title,string $productUrl=''): string
    {
        $query=self::clean($title);
        if (self::amazon($productUrl) && preg_match('~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:/|$)~i',parse_url($productUrl,PHP_URL_PATH) ?: '',$m)) {
            $query=trim($query.' '.strtoupper($m[1]));
        }
        return $query==='' ? 'https://www.google.com/imghp?hl=de' : 'https://www.google.com/search?'.http_build_query(['tbm'=>'isch','hl'=>'de','q'=>$query],'','&',PHP_QUERY_RFC3986);
    }

    public static function imagePreview(string $url): array
    {
        unset($_SESSION['importImage']);
        App::webUrl($url);
        if ($url==='') { throw new Problem(422,'Bitte die direkte Bildadresse einfügen.'); }
        $response=self::fetch($url,2*1024*1024);
        $_SESSION['importImage']=['at'=>time(),'image'=>App::imageBytes($response['body'])];
        return ['hasImage'=>true,'warning'=>'','imageUrl'=>$url];
    }
}
