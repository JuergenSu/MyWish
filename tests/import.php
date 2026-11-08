<?php
declare(strict_types=1);
// Deterministischer Transport-Doppelgänger: Netzwerkpolitik wird ohne Fremdserver geprüft.
namespace MyWish {
    $replies=[]; $calls=[];
    function gethostbynamel(string $host): array|false { return match($host) { 'shop.example'=>['93.184.216.34'], 'private.example'=>['93.184.216.34','10.0.0.1'], default=>false }; }
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
    echo "OK: $checks import transport checks\n";
}
