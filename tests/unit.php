<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require dirname(__DIR__).'/src/Metadata.php';
use MyWish\App;
use MyWish\Store;
use MyWish\Problem;
use MyWish\Metadata;
$checks=0;
function check(bool $value,string $label): void { global $checks; if (!$value) { throw new RuntimeException($label); } $checks++; }
function rejects(callable $fn,int $status): void { try { $fn(); } catch (Problem $e) { check($e->status===$status,'Status '.$e->status.' != '.$status); return; } throw new RuntimeException('Expected rejection'); }
$dir=sys_get_temp_dir().'/mywish-unit-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    check(App::money('39,99')===3999,'Cent conversion'); check(App::money('0')===0,'Zero price'); check(App::money('')===null,'No price'); check(App::money('9.9')===990,'Decimal');
    foreach (['-1','1.001','1,000.50','nan'] as $bad) { rejects(fn()=>App::money($bad),422); }
    foreach (['file:///etc/passwd','javascript:alert(1)','https://user:pass@example.com'] as $bad) { rejects(fn()=>App::webUrl($bad),422); }
    foreach (['127.0.0.1','0.0.0.0','10.0.0.1','172.16.1.1','192.168.1.1','169.254.169.254','100.80.1.1','::1','::ffff:127.0.0.1','224.0.0.1','192.0.0.1','192.0.2.1','198.18.0.1','198.51.100.2','203.0.113.1'] as $ip) { check(!Metadata::publicAddress($ip),'SSRF '.$ip); }
    check(Metadata::publicAddress('93.184.216.34'),'Public target');
    rejects(fn()=>App::imageBytes('<?php echo 123;'),422); rejects(fn()=>App::imageBytes('<svg></svg>'),422);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=');
    check(App::imageBytes($png)['mime']==='image/png','PNG accepted without GD');
    $meta=Metadata::parse('<html><head><title>Fallback</title><meta property="og:title" content="Schöner Wunsch"><meta property="og:image" content="/bild.png"><script type="application/ld+json">{"@type":"Product","offers":{"price":"39.99","priceCurrency":"EUR"}}</script></head></html>','https://example.com/path/item');
    if (class_exists('DOMDocument')) { check($meta['title']==='Schöner Wunsch' && $meta['price']==='39.99' && $meta['imageUrl']==='https://example.com/bild.png','Metadata fixture'); }
    $store=new Store($dir); $store->transaction(function(&$s) { $s['attempts']=[['ip'=>'initial','at'=>time(),'kind'=>'login']]; });
    $before=file_get_contents($dir.'/state.php');
    try { $store->transaction(function(&$s) { $s['attempts'][]=['ip'=>'test','at'=>time(),'kind'=>'login']; throw new RuntimeException('simulated abort'); }); } catch (RuntimeException) {}
    check(file_get_contents($dir.'/state.php')===$before,'Aborted writes preserve bytes');
    if (function_exists('pcntl_fork')) {
        $children=[];
        for ($i=0;$i<20;$i++) { $pid=pcntl_fork(); if ($pid===0) { (new Store($dir))->transaction(function(&$s) { $n=count($s['attempts']); usleep(1000); $s['attempts'][]=['ip'=>(string)$n,'at'=>time(),'kind'=>'login']; }); exit(0); } $children[]=$pid; }
        foreach ($children as $pid) { pcntl_waitpid($pid,$status); check(pcntl_wexitstatus($status)===0,'Worker succeeded'); }
        check($store->transaction(fn(&$s)=>count($s['attempts']))===21,'20 concurrent writes: no loss');
    }
    file_put_contents($dir.'/state.php',Store::GUARD.'{"broken":'); rejects(fn()=>$store->transaction(fn(&$s)=>$s),503);
    file_put_contents($dir.'/state.php',Store::GUARD.json_encode(array_replace(Store::empty(),['schemaVersion'=>2]))); rejects(fn()=>$store->transaction(fn(&$s)=>$s),503);
    echo "OK: $checks unit/storage/security checks\n";
} finally { foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
