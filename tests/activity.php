<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require __DIR__.'/fixtures/legacy-state.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\{Store,App,Activity,Problem};
session_start();$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) { throw new RuntimeException($label); }$checks++; }
function denied(callable $fn,int $status): void { try { $fn(); } catch (Problem $e) { check($e->status===$status,'Expected controlled rejection');return; }throw new RuntimeException('Expected failure'); }
$root=sys_get_temp_dir().'/mywish-activity-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    file_put_contents($root.'/state.php',Store::GUARD.json_encode(legacyState(3)));
    $store=new Store($root);$app=new App($store);$state=$store->verify();
    $a=str_repeat('a',32);$b=str_repeat('b',32);$list=str_repeat('d',32);$wish=str_repeat('f',32);
    check(!isset($state['settings']['activity']),'Migration never manufactures historic activity');
    $_SESSION=[];denied(fn()=>$app->activityForVisit(),401);
    $_SESSION=['userId'=>$b,'version'=>3];check($app->activityForVisit()['firstVisit'],'First visit establishes baseline without historic flood');
    $_SESSION=['userId'=>$a,'version'=>3];$new=[];
    for ($i=0;$i<5;$i++) { $new[]=$app->act('saveWish',['listId'=>$list,'title'=>'Neu '.$i,'price'=>'1'])['id']; }
    $newUser=App::id();
    $store->transaction(function(&$s) use ($newUser) { $s['users'][]=array_replace($s['users'][0],['id'=>$newUser,'username'=>'new-person','name'=>'Neue Person']); });
    $_SESSION=['userId'=>$b,'version'=>3];$feed=$app->activityForVisit();$people=array_column($feed['entries'],null,'actorId');
    check($people[$a]['counts']===['wishAdded'=>5],'Multiple creations in the same second are counted precisely');
    check($people[$newUser]['counts']===['userRegistered'=>1],'Registration is grouped under the new person');
    check(!$feed['truncated'] && !$feed['firstVisit'],'Normal summary is complete');
    $_SESSION=['userId'=>$b,'version'=>3];$reopened=new App(new Store($root));
    check($reopened->activityForVisit()['entries']===[],'Baseline survives a new session and store instance');
    $log=$store->read(fn(&$s)=>$s['settings']['activity']);
    $app->act('saveReservationPrice',['id'=>$wish,'purchasePrice'=>'123']);
    $app->act('setReservationPurchased',['id'=>$wish,'purchased'=>true]);
    $app->act('unreserve',['id'=>$wish]);$app->act('reserve',['id'=>$wish]);
    $store->transaction(function(&$s) { $s['users'][0]['isAdmin']=true;$s['attempts']=[]; });
    check($store->read(fn(&$s)=>$s['settings']['activity'])===$log,'Reservations, purchase prices, checks, roles and login counters never produce public events');
    $_SESSION=['userId'=>$a,'version'=>3];
    $app->act('saveWish',['id'=>$new[0],'listId'=>$list,'title'=>'Neu 0','price'=>'1']);
    check($store->read(fn(&$s)=>$s['settings']['activity'])===$log,'Saving unchanged public content creates no false activity');
    $app->act('saveWish',['id'=>$new[0],'listId'=>$list,'title'=>'Geändert','price'=>'1']);
    $app->act('deleteWish',['id'=>$new[1],'confirm'=>'yes']);
    $_SESSION=['userId'=>$b,'version'=>3];$app->snapshot();
    $feed=$app->activityForVisit();check($feed['entries'][0]['counts']===['wishUpdated'=>1,'wishRemoved'=>1],'Background snapshots do not consume changes; edits and deletion are recorded');
    $_SESSION=['userId'=>$a,'version'=>3];$app->activityForVisit();
    $app->act('saveList',['name'=>'Meine neue Liste','occasion'=>'Anderes']);
    check($app->activityForVisit()['entries']===[],'Own changes are excluded');
    $store->transaction(function(&$s) use ($list) {
        for ($i=0;$i<Activity::LIMIT+3;$i++) { $s['wishes'][]=array_replace($s['wishes'][0],['id'=>App::id(),'listId'=>$list,'title'=>'Batch '.$i,'image'=>null]); }
    });
    $log=$store->read(fn(&$s)=>$s['settings']['activity']);
    check(count($log['events'])===Activity::LIMIT,'Activity storage stays bounded');
    $_SESSION=['userId'=>$b,'version'=>3];$feed=$app->activityForVisit();
    check($feed['truncated'] && $feed['entries'][0]['counts']['wishAdded']===Activity::LIMIT,'Truncation is explicit instead of pretending the report is complete');
    check($app->activityForVisit()['entries']===[],'Repeated visit does not replay consumed changes');
    $another=App::id();
    $store->transaction(function(&$s) use ($another) { $s['users'][]=array_replace($s['users'][0],['id'=>$another,'username'=>'temporary','name'=>'Temporary']); });
    check(in_array($another,array_column($store->read(fn(&$s)=>$s['settings']['activity']['events']),'actorId'),true),'Fresh registration is present before deletion');
    $store->transaction(function(&$s) use ($another) { $s['users']=array_values(array_filter($s['users'],fn($u)=>$u['id']!==$another)); });
    check(!in_array($another,array_column($store->read(fn(&$s)=>$s['settings']['activity']['events']),'actorId'),true),'Recent activity of a deleted account is purged');
    $store->transaction(function(&$s) use ($newUser) { $s['users']=array_values(array_filter($s['users'],fn($u)=>$u['id']!==$newUser)); });
    check(!in_array($newUser,array_column($store->read(fn(&$s)=>$s['settings']['activity']['events']),'actorId'),true),'Deleted accounts leave no active activity identifiers');
    $state=$store->verify();$state['users'][0]['activityCursor']='1';denied(fn()=>Store::validate($state),503);
    $bad=$log;$bad['events'][0]['type']='reservationAdded';denied(fn()=>Activity::validate($bad),503);
    $bad=$log;$bad['events'][0]['sequence']=0;denied(fn()=>Activity::validate($bad),503);
    check(!array_key_exists('activityCursor',$app->snapshot()['user']),'Cursor is not part of the public user snapshot');
    echo "OK: $checks activity/history/privacy checks\n";
} finally { removeTestTree($root); }
