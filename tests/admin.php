<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require __DIR__.'/fixtures/legacy-state.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\{App,Store,Problem};
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) { throw new RuntimeException($label); }$checks++; }
function denied(callable $call,int $status): void { try { $call(); } catch (Problem $ex) { check($ex->status===$status,'Expected access or validation failure');return; }throw new RuntimeException('Expected rejection'); }
$root=sys_get_temp_dir().'/mywish-admin-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    file_put_contents($root.'/state.php',Store::GUARD.json_encode(legacyState(3)));
    $store=new Store($root);$state=$store->verify();$app=new App($store);
    check(array_column($state['users'],'isAdmin')===[false,false,false],'Legacy accounts never gain admin rights');
    $_SESSION=[];denied(fn()=>$app->statistics(),401);
    $_SESSION=['userId'=>str_repeat('a',32),'version'=>3];denied(fn()=>$app->statistics(),403);
    $old=$state['users'][0];unset($old['isAdmin']);check(App::publicUser($old)['isAdmin']===false,'Old schema-4 account defaults to false');
    foreach (['true',1,0,null,[]] as $bad) { $invalid=$state;$invalid['users'][0]['isAdmin']=$bad;denied(fn()=>Store::validate($invalid),503); }
    $store->transaction(function(&$s) { $s['users'][0]['isAdmin']=true; });
    $manifest=file_get_contents($store->dataDirectory().'/manifest.json');$stats=$app->statistics();
    check($stats===['Benutzer'=>3,'Listen'=>2,'Wünsche'=>2,'Gewünschte Stücke'=>6,'Benutzer mit Listen'=>2,'Listen ohne Wünsche'=>1,'Listen mit Bild'=>1,'Wünsche mit Bild'=>1,'Wünsche mit Produktlink'=>2,'Admins'=>1,'Konten mit Google'=>1,'Konten mit Passwort'=>2],'Exact aggregate statistics');
    check(file_get_contents($store->dataDirectory().'/manifest.json')===$manifest,'Statistics never commit a generation');
    $store->transaction(function(&$s) { $s['reservations']=[]; });check($app->statistics()===$stats,'Reservation changes reveal nothing through statistics');
    check($app->snapshot()['user']['isAdmin']===true,'Current user can see own role');
    check(!isset($app->sharedList(str_repeat('d',32))['isAdmin']),'Shared list has no admin flag');
    $store->transaction(function(&$s) { $s['users'][0]['isAdmin']=false; });denied(fn()=>$app->statistics(),403);
    $_SESSION['version']=2;denied(fn()=>$app->statistics(),401);
    echo "OK: $checks admin/statistics/security checks\n";
} finally { removeTestTree($root); }
