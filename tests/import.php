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
    verify(Metadata::resolveUrl('https://shop.example/catalog/','picture.jpg')==='https://shop.example/catalog/picture.jpg','Relative image below a directory URL');
    verify(Metadata::resolveUrl('https://shop.example/catalog/item','../new/')==='https://shop.example/new/','Redirect preserves trailing directory slash');
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
    $structured=Metadata::parse(file_get_contents(__DIR__.'/fixtures/shop-structured.html'),'https://shop.example/product');
    verify($structured['title']==='Wunschprodukt' && $structured['price']==='39.95','Select mainEntity product instead of preceding unrelated product');
    verify($structured['imageUrls']===['https://images.example/hero.jpg','https://shop.example/second.webp','https://shop.example/fallback.jpg'],'JSON-LD graph, referenced ImageObject, image array and metadata fallback');
    $gallery=Metadata::parse(file_get_contents(__DIR__.'/fixtures/shop-gallery.html'),'https://shop.example/product');
    verify($gallery['title']==='Galerieprodukt','HTML heading fallback');
    verify($gallery['imageUrls']===['https://images.example/large.webp','https://images.example/small.webp','https://shop.example/catalog/photo-900.jpg','https://shop.example/catalog/photo-400.jpg','https://shop.example/catalog/photo.jpg'],'Picture, lazy images, srcset sizes and HTML base; exclude AVIF, placeholders, logos, related products and tracker');
    foreach ([
        '<meta name="twitter:image" content="/twitter.jpg">'=>'https://shop.example/twitter.jpg',
        '<link rel="image_src" href="/linked.jpg">'=>'https://shop.example/linked.jpg',
        '<meta itemprop="image" content="/micro.jpg">'=>'https://shop.example/micro.jpg',
        '<div itemprop="image" itemscope itemtype="https://schema.org/ImageObject"><meta itemprop="contentUrl" content="/object.jpg"></div>'=>'https://shop.example/object.jpg',
        '<div class="product-gallery"><img data-src="/lazy.jpg" src="data:image/png;base64,AAAA"></div>'=>'https://shop.example/lazy.jpg',
        '<script type="application/ld+json">{"@type":"Product","image":[{"url":"/array.jpg"}]}</script>'=>'https://shop.example/array.jpg',
        '<script type="application/json" id="ProductJson-main">{"title":"Shopprodukt","images":[{"src":"/shopify.jpg"}]}</script>'=>'https://shop.example/shopify.jpg',
        '<script type="application/json" id="__NEXT_DATA__">{"props":{"pageProps":{"product":{"name":"Shopprodukt","images":[{"url":"/next.jpg"}]}}}}</script>'=>'https://shop.example/next.jpg',
    ] as $fragment=>$expected) {
        verify(Metadata::parse($fragment,'https://shop.example/product')['imageUrl']===$expected,'Additional image source: '.$expected);
    }
    $bad=Metadata::parse('<meta property="og:image" content="javascript:alert(1)"><meta name="twitter:image" content="file:///etc/passwd"><img class="product-image" data-src="false" src="/placeholder.jpg"><script type="application/ld+json">{invalid}</script>','https://shop.example/product');
    verify($bad['imageUrl']==='','Invalid metadata, placeholders and unsupported URL schemes are skipped');
    $related=Metadata::parse('<script type="application/ld+json">{"@type":"ItemList","itemListElement":[{"@type":"Product","image":"/wrong.jpg"}]}</script>','https://shop.example/product');
    verify($related['imageUrl']==='','Product lists are not mistaken for the main product');
    $GLOBALS['replies']=[['status'=>200,'body'=>str_repeat(' ',5*1024*1024).file_get_contents(__DIR__.'/fixtures/shop-structured.html')]];
    $large=Metadata::fetch('https://shop.example/product');
    verify(strlen($large['body'])>4*1024*1024 && Metadata::parse($large['body'],$large['url'])['title']==='Wunschprodukt','Page above former 4 MB boundary, with product metadata at the end');
    $scriptHeavy='<script>'.str_repeat('x',11*1024*1024).'</script><title>Späte Produktdaten</title><meta property="og:image" content="/late.jpg">';
    $late=Metadata::parse($scriptHeavy,'https://shop.example/product'); unset($scriptHeavy);
    verify($late['title']==='Späte Produktdaten' && $late['imageUrl']==='https://shop.example/late.jpg','Oversized script block does not hide later metadata or require relaxed XML limits');
    $GLOBALS['replies']=[['status'=>200,'body'=>str_repeat('x',Metadata::MAX_HTML_BYTES+1)]];
    try { Metadata::fetch('https://shop.example/product'); throw new RuntimeException('Expected size limit'); }
    catch (Problem $e) { verify(str_contains($e->getMessage(),'12 MB'),'Decompressed page limit has a specific explanation'); }
    $GLOBALS['replies']=[['status'=>200,'body'=>str_repeat(' ',Metadata::MAX_HTML_BYTES)]];
    verify(strlen(Metadata::fetch('https://shop.example/product')['body'])===Metadata::MAX_HTML_BYTES,'Exact page size boundary is accepted');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=');
    $fallbackHtml='<title>Produkt</title><meta property="og:image" content="/broken.jpg"><meta property="og:image" content="/broken.jpg"><meta name="twitter:image" content="/not-an-image.jpg"><div class="product-gallery"><img data-src="/working.png"></div>';
    $GLOBALS['replies']=[['status'=>200,'body'=>$fallbackHtml],['status'=>404],['status'=>200,'body'=>'<html>Bot check</html>'],['status'=>200,'body'=>$png]];
    $before=count($GLOBALS['calls']); $recovered=Metadata::preview('https://shop.example/product');
    verify($recovered['hasImage'] && $recovered['warning']==='' && count($GLOBALS['calls'])===$before+4,'Recover from broken and invalid images, deduplicate sources');
    verify(!isset($recovered['imageUrls'],$recovered['imageUrl']),'Internal image candidates never escape into form/API preview');
    verify($GLOBALS['replies']===[],'Expected image fallback sequence completed');
    $GLOBALS['replies']=[['status'=>200,'body'=>'<title>Produkt</title><meta property="og:image" content="/huge.png"><meta name="twitter:image" content="https://images.example/smaller.jpg">'],['status'=>200,'body'=>str_repeat('x',2*1024*1024+1)],['status'=>200,'body'=>$png]];
    verify(Metadata::preview('https://shop.example/product')['hasImage'],'Oversized original falls back to smaller supported product image');
    $GLOBALS['replies']=[['status'=>200,'body'=>'<title>Produkt</title><meta property="og:image" content="http://127.0.0.1/private"><meta name="twitter:image" content="https://images.example/safe.png">'],['status'=>200,'body'=>$png]];
    verify(Metadata::preview('https://shop.example/product')['hasImage'],'Unsafe image destination skipped, safe alternative succeeds');
    $GLOBALS['replies']=[['status'=>200,'body'=>'<title>Produkt</title><meta property="og:image" content="/redirect"><meta name="twitter:image" content="https://images.example/safe.png">'],['status'=>302,'headers'=>['location'=>'http://169.254.169.254/metadata']],['status'=>200,'body'=>$png]];
    verify(Metadata::preview('https://shop.example/product')['hasImage'],'Image redirects are revalidated before safe fallback');
    $many='<title>Produkt</title>';
    for ($i=0;$i<10;$i++) { $many.='<meta property="og:image" content="/failed-'.$i.'.jpg">'; }
    $GLOBALS['replies']=array_merge([['status'=>200,'body'=>$many]],array_fill(0,5,['status'=>404]));
    $before=count($GLOBALS['calls']); $missing=Metadata::preview('https://shop.example/product');
    verify(!$missing['hasImage'] && !isset($_SESSION['importImage']) && count($GLOBALS['calls'])===$before+6 && str_contains($missing['warning'],'Produktbilder'),'Bounded fallback attempts, explanatory warning, no stale image');
    $GLOBALS['replies']=[['status'=>200,'body'=>'<title>Produkt ohne Bild</title>']];
    verify(str_contains(Metadata::preview('https://shop.example/product')['warning'],'kein Produktbild'),'Missing-image result has an explicit explanation');
    blocked(fn()=>Metadata::fetch('https://shop.example/product',1024,microtime(true)-1));
    $search=Metadata::googleImagesUrl('Orzly Lenkräder',$url); parse_str(parse_url($search,PHP_URL_QUERY),$searchParams);
    verify(parse_url($search,PHP_URL_HOST)==='www.google.com' && $searchParams['tbm']==='isch' && $searchParams['q']==='Orzly Lenkräder B0FLM3M1JJ','Explicit Google image search with product title and ASIN');
    verify(Metadata::googleImagesUrl('')==='https://www.google.com/imghp?hl=de','Manual search when title is empty');
    $GLOBALS['replies']=[['status'=>200,'body'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=')]];
    verify(Metadata::imagePreview('https://images.example/manual.png')['hasImage'] && isset($_SESSION['importImage']),'Manual image address becomes checked local preview');
    blocked(fn()=>Metadata::imagePreview('http://127.0.0.1/private.png'));
    verify(!isset($_SESSION['importImage']),'Rejected manual image does not reuse prior preview');
    $GLOBALS['replies']=[['status'=>200,'body'=>'<svg onload="alert(1)"></svg>']];blocked(fn()=>Metadata::imagePreview('https://images.example/manual.svg'));
    $GLOBALS['replies']=[['status'=>200,'body'=>str_repeat('x',2*1024*1024+1)]];blocked(fn()=>Metadata::imagePreview('https://images.example/too-large.png'));
    echo "OK: $checks import transport checks\n";
}
