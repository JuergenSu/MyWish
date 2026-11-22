<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require __DIR__.'/fixtures/legacy-state.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\{Store,App,Problem};
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) { throw new RuntimeException($label); }$checks++; }
function denied(callable $fn,int $status): void { try { $fn(); } catch (Problem $ex) { check($ex->status===$status,'Expected rejection');return; }throw new RuntimeException('Expected failure'); }
$root=sys_get_temp_dir().'/mywish-prices-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $legacy=legacyState(3);file_put_contents($root.'/state.php',Store::GUARD.json_encode($legacy));
    $store=new Store($root);$app=new App($store);$store->verify();
    $a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);$wish=str_repeat('f',32);
    $_SESSION=['userId'=>$a,'version'=>3];$owner=$app->snapshot();$public=$app->sharedList(str_repeat('d',32));
    $_SESSION=['userId'=>$b,'version'=>3];
    check($app->snapshot()['reservationSummary'][0]['totalCents']===3999,'Legacy reservation uses wish price without migration');
    foreach (['12,34','0','12.34','9999999,99',''] as $price) {
        $app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>$price]);
        $expected=App::money($price);$snapshot=$app->snapshot();
        check($snapshot['reservationSummary'][0]['totalCents']===($expected ?? 3999),'Actual amount overrides estimate, including zero; clearing restores estimate');
        check($snapshot['wishes'][0]['purchasePriceCents']===$expected,'Actual price belongs only to own reservation');
    }
    $app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'12,34']);
    $manifest=file_get_contents($store->dataDirectory().'/manifest.json');
    foreach (['-1','1,234','1.000,00','abc','10000000',[]] as $bad) { denied(fn()=>$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>$bad]),422); }
    check(file_get_contents($store->dataDirectory().'/manifest.json')===$manifest,'Invalid amounts never commit');
    check((new App(new Store($root)))->snapshot()['wishes'][0]['purchasePriceCents']===1234,'Price survives reopening store');
    $_SESSION=['userId'=>$a,'version'=>3];denied(fn()=>$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'7']),403);
    check($app->snapshot()===$owner && $app->sharedList(str_repeat('d',32))===$public,'Owner and public snapshots remain identical after purchase');
    $_SESSION=['userId'=>$c,'version'=>3];denied(fn()=>$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'7','userId'=>$b]),403);
    check(!array_key_exists('purchasePriceCents',$app->snapshot()['wishes'][0]),'Another giver cannot read purchase price');
    $store->transaction(function(&$s) use ($a,$c) {
        $s['users'][2]['name']=$s['users'][0]['name'];
        $s['lists'][]=array_replace($s['lists'][0],['id'=>str_repeat('6',32),'image'=>null]);
        $s['lists'][]=array_replace($s['lists'][0],['id'=>str_repeat('7',32),'ownerUserId'=>$c,'image'=>null]);
        $s['wishes'][]=array_replace($s['wishes'][0],['id'=>str_repeat('8',32),'listId'=>str_repeat('6',32),'priceCents'=>null,'image'=>null]);
        $s['wishes'][]=array_replace($s['wishes'][0],['id'=>str_repeat('9',32),'listId'=>str_repeat('7',32),'priceCents'=>2000,'image'=>null]);
    });
    $_SESSION=['userId'=>$b,'version'=>3];
    $app->act('reserve',['id'=>str_repeat('8',32)]);$app->act('reserve',['id'=>str_repeat('9',32)]);
    $groups=array_column($app->snapshot()['reservationSummary'],null,'ownerUserId');
    check(count($groups)===2 && $groups[$a]['totalCents']===1234 && $groups[$a]['unpricedCount']===1 && $groups[$a]['reservationCount']===2,'Multiple lists merge by recipient; missing prices are explicit');
    check($groups[$c]['totalCents']===2000,'Equal display names remain separate recipients');
    $app->act('unreserve',['id'=>$wish]);denied(fn()=>$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'5']),403);
    check($app->snapshot()['reservationSummary'][0]['totalCents']===0,'Freed reservation disappears from budget');
    $app->act('reserve',['id'=>$wish]);check($app->snapshot()['wishes'][0]['purchasePriceCents']===null,'Re-reserving never restores old purchase amount');
    $state=$store->verify();
    foreach ([-1,1.5,'123',true,1000000000] as $bad) { $invalid=$state;$invalid['reservations'][0]['purchasePriceCents']=$bad;denied(fn()=>Store::validate($invalid),503); }
    $_SESSION=[];denied(fn()=>$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'1']),401);
    echo "OK: $checks reservation price/budget/privacy checks\n";
} finally { removeTestTree($root); }
