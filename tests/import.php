<?php
declare(strict_types=1);
// Deterministischer Transport-Doppelgänger: Netzwerkpolitik wird ohne Fremdserver geprüft.
namespace MyWish {
    $replies=[]; $calls=[];
    function gethostbynamel(string $host): array|false { return match($host) { 'www.amazon.de'=>($GLOBALS['amazonPrivate'] ?? false) ? ['10.0.0.1'] : ['93.184.216.34'], 'images.example'=>['93.184.216.34'], 'shop.example'=>['93.184.216.34'], 'private.example'=>['93.184.216.34','10.0.0.1'], default=>false }; }
    function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
    function curl_setopt_array(object $c,array $options): bool { $c->options=$options; return true; }
    function curl_exec(object $c): bool {
        global $replies,$calls; $calls[]=$c;
        $r=array_shift($replies); if (!$r) { throw new \RuntimeException('Unexpected fetch'); }
        $c->status=$r['status'];
        foreach ($r['headers'] ?? [] as $k=>$v) { ($c->options[CURLOPT_HEADERFUNCTION])($c,$k.': '.$v."\r\n"); }
        if ($r['failure'] ?? false) { return false; }
        $body=$r['body'] ?? '';
        return ($c->options[CURLOPT_WRITEFUNCTION])($c,$body)===strlen($body);
    }
    function curl_getinfo(object $c,int $option): int { return $c->status; }
    function curl_close(object $c): void {}
}
namespace {
    require dirname(__DIR__).'/src/Store.php'; require dirname(__DIR__).'/src/App.php'; require dirname(__DIR__).'/src/Metadata.php';
    use MyWish\Metadata;
    use MyWish\Problem;
    $checks=0;
    function verify(bool $condition,string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
    function blocked(callable $fn): void { try { $fn(); } catch (Problem $e) { verify($e->status===422,'Import error status'); return; } throw new RuntimeException('Expected blocked import'); }
    $GLOBALS['replies']=[['status'=>302,'headers'=>['location'=>'/product']],['status'=>200,'body'=>'<title>Shop</title>']];
    $page=Metadata::fetch('https://shop.example/start'); verify($page['url']==='https://shop.example/product','Relative redirect');
    foreach ($GLOBALS['calls'] as $c) {
        verify($c->options[CURLOPT_RESOLVE]===['shop.example:443:93.184.216.34'],'DNS pinning');
        verify($c->options[CURLOPT_PROXY]==='' && $c->options[CURLOPT_FOLLOWLOCATION]===false,'No proxy or automatic redirects');
        verify($c->options[CURLOPT_TIMEOUT_MS]<=10000 && $c->options[CURLOPT_SSL_VERIFYPEER]===true,'Bounded TLS fetch');
    }
    foreach (['http://127.0.0.1','http://private.example','http://shop.example:8080','file:///tmp/a','http://169.254.169.254','http://224.0.0.1'] as $url) { blocked(fn()=>Metadata::fetch($url)); }
    $GLOBALS['replies']=[['status'=>302,'headers'=>['location'=>'http://127.0.0.1/secret']]];
    blocked(fn()=>Metadata::fetch('https://shop.example/product'));
    $GLOBALS['replies']=[['status'=>302,'headers'=>['location'=>'http://private.example/secret']]];
    blocked(fn()=>Metadata::fetch('https://shop.example/product'));
    $GLOBALS['replies']=[['status'=>200,'body'=>str_repeat('x',1025)]];
    blocked(fn()=>Metadata::fetch('https://shop.example/product',1024));
    $GLOBALS['replies']=array_fill(0,4,['status'=>302,'headers'=>['location'=>'/again']]);
    blocked(fn()=>Metadata::fetch('https://shop.example/product'));
    $GLOBALS['replies']=[['status'=>200,'failure'=>true]];
    blocked(fn()=>Metadata::fetch('https://shop.example/product'));
    $GLOBALS['replies']=[['status'=>403]];
    blocked(fn()=>Metadata::fetch('https://shop.example/product'));
    $url='https://www.amazon.de/Orzly-Lenkrader-Kompatibel-Joy-Geschenkbox/dp/B0FLM3M1JJ/?tag=tracking';
    verify(Metadata::canonicalUrl($url)==='https://www.amazon.de/dp/B0FLM3M1JJ','Canonical user example');
    verify(Metadata::canonicalUrl('https://m.amazon.de/gp/aw/d/b0flm3m1jj/ref=test')==='https://www.amazon.de/dp/B0FLM3M1JJ','Mobile Amazon URL');
    verify(Metadata::canonicalUrl('https://www.amazon.de/gp/product/B0FLM3M1JJ')==='https://www.amazon.de/dp/B0FLM3M1JJ','Legacy product URL');
    verify(Metadata::canonicalUrl('https://amazon.de.attacker.example/dp/B0FLM3M1JJ')==='https://amazon.de.attacker.example/dp/B0FLM3M1JJ','Exact Amazon host boundary');
    foreach (['19,99 €'=>'19.99','1.234,56 EUR'=>'1234.56','1,234.56'=>'1234.56','0,00'=>'0.00','29.99'=>'29.99','EUR 1.234'=>'1234','19,99 $'=>'','ab 19,99 €'=>'','9,99 € pro Monat'=>''] as $input=>$expected) { verify(Metadata::price((string)$input)===$expected,'Price '.$input); }
    $html=file_get_contents(__DIR__.'/fixtures/amazon-product.html');
    $parsed=Metadata::parse($html,$url);
    verify($parsed['title']==='Orzly Lenkräder – kompatibel mit Joy-Con, Geschenkbox','Amazon product title instead of generic metadata');
    verify($parsed['price']==='19.99','Current price instead of list/recommendation price');
    verify($parsed['imageUrl']==='https://images.example/product-large.jpg','Largest hero image instead of placeholder/recommendation');
    verify(str_contains($parsed['description'],'Ein Paar Lenkräder & eine Geschenkbox.') && !str_contains($parsed['description'],'Nicht sichtbarer Text'),'Visible feature bullets');
    $classic=Metadata::parse(file_get_contents(__DIR__.'/fixtures/amazon-classic.html'),$url);
    verify($classic['title']==='Älterer Produktaufbau' && $classic['price']==='1234.56' && $classic['imageUrl']==='https://www.amazon.de/images/large.jpg','Legacy Amazon layout');
    $long=Metadata::parse('<span id="productTitle">'.str_repeat('Ä',200).'</span>',$url);
    verify(strlen($long['title'])===200 && preg_match('//u',$long['title'])===1,'Long Unicode title preserved without broken UTF-8');
    verify(Metadata::resolveUrl('https://shop.example/a/b/item','../product?x=1')==='https://shop.example/a/product?x=1','Relative redirect segments');
    blocked(fn()=>Metadata::resolveUrl($url,'file:///etc/passwd'));
    $_SESSION=[];
    $GLOBALS['replies']=[['status'=>200,'body'=>'<!--'.str_repeat('x',1048576).'-->'.$html],['status'=>200,'body'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=')]];
    $preview=Metadata::preview($url);
    verify($preview['title']===$parsed['title'] && $preview['hasImage'] && $preview['warning']==='','Large Amazon page and checked image preview');
    $last=array_slice($GLOBALS['calls'],-2);
    verify($last[0]->url==='https://www.amazon.de/dp/B0FLM3M1JJ','Fetch canonical Amazon URL');
    verify($last[0]->options[CURLOPT_ENCODING]==='' && in_array('Accept-Language: de-DE,de;q=0.9,en;q=0.5',$last[0]->options[CURLOPT_HTTPHEADER],true),'Compression and German response requested');
    $GLOBALS['replies']=[['status'=>503]];
    $fallback=Metadata::preview($url);
    verify(!$fallback['hasImage'] && !isset($_SESSION['importImage']),'Previous image cleared on failed subsequent import');
    verify($fallback['productUrl']==='https://www.amazon.de/dp/B0FLM3M1JJ' && $fallback['price']==='' && $fallback['title']==='Orzly Lenkrader Kompatibel Joy Geschenkbox','503: link and slug proposal, no invented price');
    verify(str_contains($fallback['warning'],'Amazon') && str_contains($fallback['warning'],'nur aus dem Link'),'503 explicitly reported as manual fallback');
    $GLOBALS['replies']=[['status'=>200,'body'=>file_get_contents(__DIR__.'/fixtures/amazon-captcha.html')]];
    $captcha=Metadata::preview($url);
    verify($captcha['title']!=='Amazon.de' && $captcha['warning']!=='','Captcha page is not imported as product');
    $GLOBALS['replies']=[['status'=>302,'headers'=>['location'=>'http://127.0.0.1/private']]];
    blocked(fn()=>Metadata::preview($url));
    $GLOBALS['amazonPrivate']=true; blocked(fn()=>Metadata::preview($url)); $GLOBALS['amazonPrivate']=false;
    blocked(fn()=>Metadata::preview('https://www.amazon.de:8080/dp/B0FLM3M1JJ'));
    $merged=Metadata::mergePreview($preview,['id'=>'','listId'=>'list','title'=>'Mein eigener Titel','description'=>'Eigene Beschreibung','price'=>'0','quantity'=>'2']);
    verify($merged['title']==='Mein eigener Titel' && $merged['description']==='Eigene Beschreibung' && $merged['price']==='0' && $merged['quantity']==='2','Manual values preserved, including zero price');
    verify(Metadata::mergePreview($preview,['title'=>' '])['title']===$parsed['title'],'Blank field receives preview');
    echo "OK: $checks import transport checks\n";
}
