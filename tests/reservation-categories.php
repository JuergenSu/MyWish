<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require __DIR__.'/fixtures/legacy-state.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\{Store,App,Problem};
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) { throw new RuntimeException($label); }$checks++; }
function denied(callable $fn,int $status): void { try { $fn(); } catch (Problem $ex) { check($ex->status===$status,'Expected status '.$status);return; }throw new RuntimeException('Expected rejection'); }
$root=sys_get_temp_dir().'/mywish-categories-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    file_put_contents($root.'/state.php',Store::GUARD.json_encode(legacyState(3)));
    $store=new Store($root);$app=new App($store);$store->verify();
    $a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);$list=str_repeat('d',32);$wish=str_repeat('f',32);$second=str_repeat('1',32);
    $_SESSION=['userId'=>$b,'version'=>3];$manifest=$store->dataDirectory().'/manifest.json';$before=file_get_contents($manifest);
    $snapshot=$app->snapshot();check($snapshot['wishes'][0]['reservationCategory']==='Sonstiges','Legacy reservation defaults to Sonstiges');
    check($snapshot['reservationSummary'][0]['categories']===['Sonstiges'=>['totalCents'=>3999,'reservationCount'=>1,'purchasedCount'=>0,'unpricedCount'=>0]],'Legacy budget belongs entirely to Sonstiges');
    check(file_get_contents($manifest)===$before,'Reading legacy category does not migrate or rewrite data');
    $set=fn($id,$category)=>$app->act('saveReservationCategory',['id'=>$id,'category'=>$category]);
    foreach (App::RESERVATION_CATEGORIES as $category) {
        $set($wish,$category);check($app->snapshot()['wishes'][0]['reservationCategory']===$category,'Every allowed category can be persisted');
    }
    $set($wish,'Geburtstag');$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'12,34']);$app->act('setReservationPurchased',['id'=>$wish,'purchased'=>true]);
    check((new App(new Store($root)))->snapshot()['wishes'][0]['reservationCategory']==='Geburtstag','Category survives reopening, price edits and purchase status changes');
    $before=file_get_contents($manifest);
    foreach (['Other','geburtstag','<script>',[],123] as $invalid) { denied(fn()=>$set($wish,$invalid),422); }
    check(file_get_contents($manifest)===$before,'Invalid category writes do not commit');
    $_SESSION=['userId'=>$a,'version'=>3];$third=$app->act('saveWish',['listId'=>$list,'title'=>'Zusätzlich','price'=>'10'])['id'];
    $store->transaction(function(&$s) use ($second) { foreach ($s['wishes'] as &$row) { if ($row['id']===$second) { $row['priceCents']=null; } }unset($row); });
    // Refresh public snapshots after the owner's intentional new wish and price change.
    $owner=$app->snapshot();$public=$app->sharedList($list);
    $_SESSION=['userId'=>$b,'version'=>3];$app->act('reserve',['id'=>$second]);$app->act('reserve',['id'=>$third]);$set($third,'Geburtstag');
    $categories=$app->snapshot()['reservationSummary'][0]['categories'];
    check($categories['Geburtstag']===['totalCents'=>2234,'reservationCount'=>2,'purchasedCount'=>1,'unpricedCount'=>0],'Same category combines actual and estimated prices with bought count');
    check($categories['Sonstiges']===['totalCents'=>0,'reservationCount'=>1,'purchasedCount'=>0,'unpricedCount'=>1],'Unpriced reservation is counted without inventing an amount');
    $activity=$store->read(fn(&$s)=>$s['settings']['activity']);
    $set($third,'Weihnachten');$set($second,'Ostern');
    $categories=$app->snapshot()['reservationSummary'][0]['categories'];
    check($categories['Geburtstag']['totalCents']===1234 && $categories['Weihnachten']['totalCents']===1000 && !isset($categories['Sonstiges']),'Moving category removes the old subtotal and updates the new one');
    check($categories['Ostern']['unpricedCount']===1,'Missing price remains in the correct category');
    check($store->read(fn(&$s)=>$s['settings']['activity'])===$activity,'Private category changes never enter the public activity feed');
    $_SESSION=['userId'=>$a,'version'=>3];check($app->snapshot()===$owner && $app->sharedList($list)===$public,'Categories reveal nothing to the recipient or public list');
    denied(fn()=>$set($wish,'Ostern'),403);
    $_SESSION=['userId'=>$c,'version'=>3];denied(fn()=>$set($wish,'Ostern'),403);
    check(!array_key_exists('reservationCategory',$app->snapshot()['wishes'][0]),'Another giver cannot read category');
    $app->act('reserve',['id'=>$wish]);$set($wish,'Ostern');
    $_SESSION=['userId'=>$b,'version'=>3];check($app->snapshot()['wishes'][0]['reservationCategory']==='Geburtstag','Two givers keep independent categories for the same wish');
    $app->act('saveReservationCategory',['id'=>$wish]);check($app->snapshot()['wishes'][0]['reservationCategory']==='Sonstiges','Missing selection resets to Sonstiges');
    $set($wish,'');check($app->snapshot()['wishes'][0]['reservationCategory']==='Sonstiges','Empty selection defaults to Sonstiges');
    $set($wish,null);check($app->snapshot()['wishes'][0]['reservationCategory']==='Sonstiges','Null selection defaults to Sonstiges');
    $set($wish,'Weihnachten');$app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'0']);
    check($app->snapshot()['reservationSummary'][0]['categories']['Weihnachten']['totalCents']===1000,'Explicit zero remains valid in category budget');
    $app->act('unreserve',['id'=>$wish]);denied(fn()=>$set($wish,'Ostern'),403);
    $app->act('reserve',['id'=>$wish]);check($app->snapshot()['wishes'][0]['reservationCategory']==='Sonstiges','Freed and newly reserved gift starts in Sonstiges');
    denied(fn()=>$set(str_repeat('0',32),'Ostern'),404);
    $save=fn(array $fields)=>$app->act('saveReservationDetails',['id'=>$wish]+$fields);
    $_SESSION=['userId'=>$a,'version'=>3];$owner=$app->snapshot();$public=$app->sharedList($list);
    $_SESSION=['userId'=>$b,'version'=>3];
    $activity=$store->read(fn(&$s)=>$s['settings']['activity']);
    $save(['category'=>'Weihnachten','purchasePrice'=>'19,99','purchased'=>'yes']);
    $saved=$app->snapshot()['wishes'][0];
    check($saved['reservationCategory']==='Weihnachten' && $saved['purchasePriceCents']===1999 && $saved['isPurchased']===true,'One save updates all three reservation fields');
    check($app->snapshot()['reservationSummary'][0]['categories']['Weihnachten']['totalCents']===2999,'Combined save updates category subtotal');
    $before=file_get_contents($manifest);
    foreach ([['category'=>'Invalid'],['purchasePrice'=>'bad'],['purchased'=>'true'],['purchased'=>null],['purchased'=>[]]] as $invalid) {
        denied(fn()=>$save(array_replace(['category'=>'Geburtstag','purchasePrice'=>'5','purchased'=>false],$invalid)),422);
        check(file_get_contents($manifest)===$before,'Invalid combined save cannot partially update another field');
    }
    $save(['category'=>'Ostern','purchasePrice'=>'0']);$saved=$app->snapshot()['wishes'][0];
    check($saved['reservationCategory']==='Ostern' && $saved['purchasePriceCents']===0 && $saved['isPurchased']===false,'Unchecked status and explicit zero are saved together');
    $save([]);$saved=$app->snapshot()['wishes'][0];
    check($saved['reservationCategory']==='Sonstiges' && $saved['purchasePriceCents']===null && $saved['isPurchased']===false,'Full-form defaults reset all three fields consistently');
    check($store->read(fn(&$s)=>$s['settings']['activity'])===$activity,'Combined save never emits public activity');
    $_SESSION=['userId'=>$a,'version'=>3];check($app->snapshot()===$owner && $app->sharedList($list)===$public,'Combined save remains invisible to owner and public');denied(fn()=>$save([]),403);
    $_SESSION=['userId'=>$c,'version'=>3];$app->act('unreserve',['id'=>$wish]);denied(fn()=>$save([]),403);
    $_SESSION=['userId'=>$b,'version'=>3];
    denied(fn()=>$app->act('saveReservationDetails',['id'=>str_repeat('0',32)]),404);
    $state=$store->verify();foreach (['Other','',null,1,[]] as $invalid) { $bad=$state;$bad['reservations'][0]['category']=$invalid;denied(fn()=>Store::validate($bad),503); }
    $_SESSION=[];denied(fn()=>$set($wish,'Ostern'),401);denied(fn()=>$save([]),401);
    echo "OK: $checks reservation category/budget/privacy checks\n";
} finally { removeTestTree($root); }
