<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require __DIR__.'/fixtures/legacy-state.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\{Store,App,Problem};
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) { throw new RuntimeException($label); }$checks++; }
function denied(callable $fn,int $status): void { try { $fn(); } catch (Problem $e) { check($e->status===$status,'Expected status '.$status);return; }throw new RuntimeException('Expected rejection'); }
$root=sys_get_temp_dir().'/mywish-links-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    file_put_contents($root.'/state.php',Store::GUARD.json_encode(legacyState(3)));
    $store=new Store($root);$app=new App($store);$store->verify();
    $a=str_repeat('a',32);$b=str_repeat('b',32);$list=str_repeat('d',32);$other=str_repeat('e',32);
    $_SESSION=['userId'=>$a,'version'=>3];
    $save=fn(string $url,array $extra=[])=>$app->act('saveWish',array_replace(['listId'=>$list,'title'=>'Produkt','productUrl'=>$url],$extra));
    $url='https://shop.example/item?sku=123&color=red';
    $wish=$save($url)['id'];
    $manifest=$store->dataDirectory().'/manifest.json';$before=file_get_contents($manifest);
    foreach ([$url,$url.'#details','http://SHOP.EXAMPLE:80/item?color=red&sku=123','https://shop.example:443/item?color=red&sku=123&utm_source=newsletter&fbclid=abc','https://shop.example/item?color=red&%73ku=123&gclid=test'] as $duplicate) {
        denied(fn()=>$save($duplicate),409);
        check(file_get_contents($manifest)===$before,'Rejected duplicate leaves data, activity and reservations unchanged');
    }
    check($save($url,['id'=>$wish,'title'=>'Geänderter Titel'])['id']===$wish,'The same wish remains editable');
    check($save('https://shop.example/item?sku=123&color=red&utm_medium=email',['id'=>$wish])['id']===$wish,'Changing only tracking on the same wish is allowed');
    foreach (['https://shop.example/item?sku=124&color=red','https://shop.example/item?sku=123&color=blue','https://shop.example/Item?sku=123&color=red','https://other.example/item?sku=123&color=red','https://shop.example:8443/item?sku=123&color=red','https://shop.example/item?sku=123&sku=123&color=red'] as $different) {
        check(isset($save($different)['id']),'Distinct products, variants, paths, hosts, ports and repeated parameters remain saveable');
    }
    $hash=$save('https://shop.example/#/product/1')['id'];
    check(isset($save('https://shop.example/#/product/2')['id']),'Different hash routes remain distinct');
    denied(fn()=>$save('http://SHOP.EXAMPLE/#/product/1'),409);
    foreach (['https://www.amazon.de/dp/B0FCG8N5NC','https://www.amazon.de/SONOFF-Sensor/dp/B0FCG8N5NC/?psc=1&ref=test','http://m.amazon.de/gp/aw/d/b0fcg8n5nc','https://smile.amazon.de/gp/product/B0FCG8N5NC?th=1'] as $index=>$amazon) {
        if ($index===0) { $amazonWish=$save($amazon)['id']; } else { denied(fn()=>$save($amazon),409); }
    }
    check(isset($save('https://www.amazon.de/dp/B0G1586BZG')['id']),'Different Amazon ASIN remains saveable');
    $blank=$save('')['id'];check(isset($save('')['id']),'Multiple wishes without links are allowed');
    denied(fn()=>$save($url,['id'=>$blank]),409);
    check($store->read(fn(&$s)=>App::find($s['wishes'],$blank)['productUrl'])==='','Rejected edit keeps original wish');
    // Existing duplicate data is not deleted or made impossible to edit.
    check($save('https://shop.example/product',['id'=>str_repeat('f',32)])['id']===str_repeat('f',32),'Legacy duplicates can still be edited without changing the product');
    denied(fn()=>$save('https://shop.example/product'),409);
    $newList=$app->act('saveList',['name'=>'Andere Liste','occasion'=>'Anderes'])['id'];
    check(isset($save($url,['listId'=>$newList])['id']),'The same owner can use the link in another list');
    $_SESSION=['userId'=>$b,'version'=>3];
    check(isset($save($url,['listId'=>$other])['id']),'Another person can use the same link');
    denied(fn()=>$save($url),403);
    $_SESSION=['userId'=>$a,'version'=>3];
    $app->act('deleteWish',['id'=>$amazonWish,'confirm'=>'yes']);
    check(isset($save('https://www.amazon.de/dp/B0FCG8N5NC')['id']),'A removed link can be added again');
    if (function_exists('pcntl_fork')) {
        $children=[];
        for ($i=0;$i<2;$i++) {
            $pid=pcntl_fork();
            if ($pid===0) {
                try { (new App(new Store($root)))->act('saveWish',['listId'=>$list,'title'=>'Parallel','productUrl'=>'https://shop.example/concurrent']);exit(0); }
                catch (Problem $e) { exit($e->status===409 ? 10 : 1); }
            }
            if ($pid<0) { throw new RuntimeException('Fork failed'); }$children[]=$pid;
        }
        $results=[];foreach ($children as $pid) { pcntl_waitpid($pid,$status);check(pcntl_wifexited($status),'Concurrent worker exited normally');$results[]=pcntl_wexitstatus($status); }
        sort($results);check($results===[0,10],'Exactly one of two concurrent duplicate saves succeeds');
        check($store->read(fn(&$s)=>count(array_filter($s['wishes'],fn($w)=>$w['productUrl']==='https://shop.example/concurrent')))===1,'Concurrent duplicate is never stored twice');
    }
    echo "OK: $checks duplicate-link/scope/concurrency checks\n";
} finally { removeTestTree($root); }
