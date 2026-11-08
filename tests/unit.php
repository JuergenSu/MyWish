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
    foreach (['abcdefgh', '12345678', 'äöüßéabc', 'abcdefg!', '1234567#', 'äöüßéab!', 'abcdefg€', 'abcdefg🎁', str_repeat('a',72)] as $pass) {
        check(App::password(['password'=>$pass])===$pass, 'Valid password returned unchanged');
    }
    foreach ([null, [], 12345678, '', 'abcdefg', 'abcdef!', 'äöüßéa!', "abcdefg\0!", "abcdefg\xFF!", str_repeat('a',73)] as $pass) {
        rejects(fn()=>App::password(['password'=>$pass]),422);
    }
    check(App::password(['newPassword'=>'abcdefgh'],'newPassword')==='abcdefgh','Password change allows no special character');
    rejects(fn()=>App::password(['newPassword'=>'abcdefg'],'newPassword'),422);
    check(App::money('39,99')===3999,'Cent conversion'); check(App::money('0')===0,'Zero price'); check(App::money('')===null,'No price'); check(App::money('9.9')===990,'Decimal');
    foreach (['-1','1.001','1,000.50','nan'] as $bad) { rejects(fn()=>App::money($bad),422); }
    foreach (['file:///etc/passwd','javascript:alert(1)','https://user:pass@example.com'] as $bad) { rejects(fn()=>App::webUrl($bad),422); }
    foreach (['127.0.0.1','0.0.0.0','10.0.0.1','172.16.1.1','192.168.1.1','169.254.169.254','100.80.1.1','::1','::ffff:127.0.0.1','224.0.0.1','192.0.0.1','192.0.2.1','198.18.0.1','198.51.100.2','203.0.113.1'] as $ip) { check(!Metadata::publicAddress($ip),'SSRF '.$ip); }
    check(Metadata::publicAddress('93.184.216.34'),'Public target');
    rejects(fn()=>App::imageBytes('<?php echo 123;'),422); rejects(fn()=>App::imageBytes('<svg></svg>'),422);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC');
    check(App::imageBytes($png)['mime']==='image/png','PNG accepted and normalized with GD');
    $source=imagecreatetruecolor(1080,2400);
    imagealphablending($source,false); imagesavealpha($source,true);
    imagefill($source,0,0,imagecolorallocatealpha($source,0,0,0,127));
    imagefilledrectangle($source,0,0,1079,79,imagecolorallocate($source,255,0,0));
    imagefilledrectangle($source,0,2320,1079,2399,imagecolorallocate($source,0,0,255));
    ob_start(); imagepng($source); $screenshot=ob_get_clean(); unset($source);
    $large=$screenshot.str_repeat("x",App::MAX_UPLOAD_BYTES-strlen($screenshot));
    $preview=App::imageBytes($large); unset($large);
    $previewBytes=base64_decode($preview['data']); $size=getimagesizefromstring($previewBytes);
    check($size[0]===540 && $size[1]===1200,'8 MB screenshot keeps its aspect ratio within preview bounds');
    check(strlen($previewBytes)<strlen($screenshot) && strlen($previewBytes)<=2097152,'Only the resized preview is retained');
    $decoded=imagecreatefromstring($previewBytes);
    check((imagecolorat($decoded,270,5)&0xFFFFFF)===0xFF0000 && (imagecolorat($decoded,270,1195)&0xFFFFFF)===0x0000FF,'Both screenshot edges survive without cropping');
    check((imagecolorat($decoded,5,600)>>24)===127,'PNG transparency survives resizing'); unset($decoded);
    $small=getimagesizefromstring(base64_decode(App::imageBytes($png)['data']));
    check($small[0]===1 && $small[1]===1,'Small images are not enlarged');
    rejects(fn()=>App::imageBytes(str_repeat('x',App::MAX_UPLOAD_BYTES+1)),422);
    rejects(fn()=>App::imageBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=')),422);
    foreach (['imagejpeg','imagewebp'] as $encoder) {
        $picture=imagecreatetruecolor(2400,600); ob_start(); $encoder($picture); $bytes=ob_get_clean(); unset($picture);
        if ($encoder==='imagejpeg') {
            foreach ([2,3,4,5,6,7,8] as $orientation) {
                $tiff='II'.pack('vVv',42,8,1).pack('vvVvvV',0x0112,3,1,$orientation,0,0);
                $exif="Exif\0\0".$tiff;
                $oriented=substr($bytes,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($bytes,2);
                $turned=getimagesizefromstring(base64_decode(App::imageBytes($oriented)['data']));
                check($turned[0]===($orientation>=5 ? 300 : 1200) && $turned[1]===($orientation>=5 ? 1200 : 300),'Phone JPEG orientation survives metadata removal');
            }
        }
        $info=getimagesizefromstring(base64_decode(App::imageBytes($bytes)['data']));
        check($info[0]===1200 && $info[1]===300,'Landscape JPEG/WebP is scaled without cropping');
    }
    // A genuine detailed PNG can exceed 2 MB even at preview dimensions.
    $chunk=static fn(string $type,string $data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $raw=''; for ($y=0;$y<1200;$y++) { $raw.="\0".random_bytes(3600); }
    $noise="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1200,1200,8,2,0,0,0)).$chunk('IDAT',gzcompress($raw)).$chunk('IEND',''); unset($raw);
    $compressed=App::imageBytes($noise); unset($noise);
    check($compressed['mime']==='image/webp' && strlen(base64_decode($compressed['data']))<=2097152,'Detailed PNG uses a bounded WebP preview');
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
    file_put_contents($dir.'/state.php',Store::GUARD.json_encode(array_replace(Store::empty(),['schemaVersion'=>Store::VERSION+1]))); rejects(fn()=>$store->transaction(fn(&$s)=>$s),503);
    echo "OK: $checks unit/storage/security checks\n";
} finally { foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
