<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/App.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\{Store,DiskStorage,App,Problem};
$checks=0;
function check(bool $ok,string $label): void { global $checks;if (!$ok) { throw new RuntimeException($label); }$checks++; }
function blocked(callable $call): void { try { $call(); } catch (Problem $error) { check($error->status===503,'Storage failures return a controlled 503');return; }throw new RuntimeException('Expected storage failure'); }
function picture(int $color=0x635BDB): array
{
    $image=imagecreatetruecolor(128,128);imagefill($image,0,0,$color);ob_start();imagepng($image);$bytes=ob_get_clean();
    return ['mime'=>'image/png','data'=>base64_encode($bytes),'sourceUrl'=>'https://shop.example/product'];
}
function legacyState(int $version): array
{
    $state=Store::empty();$state['schemaVersion']=$version;$now='2026-11-14T12:00:00+00:00';
    $state['settings']=['siteName'=>'Migration test','registration'=>'open','createdAt'=>$now];
    foreach (['a','b','c'] as $index=>$letter) {
        $state['users'][]=['id'=>str_repeat($letter,32),'username'=>'user'.$letter,'name'=>'Person '.$letter,'passwordHash'=>$index===1 && $version>=2 ? null : password_hash('abcdefgh',PASSWORD_DEFAULT),'sessionVersion'=>3,'createdAt'=>$now,'googleSub'=>$index===1 && $version>=2 ? 'google-b' : null,'googleEmail'=>$index===1 && $version>=2 ? 'b@example.com' : null];
    }
    $photo=picture();if ($version<3) { unset($photo['sourceUrl']); }
    $state['lists'][]=['id'=>str_repeat('d',32),'ownerUserId'=>str_repeat('a',32),'name'=>'Geburtstag','description'=>'Alle Daten bleiben erhalten.','occasion'=>'Geburtstag','eventDate'=>'2026-12-12','cover'=>'lavender','image'=>$photo,'createdAt'=>$now,'updatedAt'=>$now];
    $state['lists'][]=array_replace($state['lists'][0],['id'=>str_repeat('e',32),'ownerUserId'=>str_repeat('b',32),'name'=>'Zweite Liste','image'=>null]);
    $state['wishes'][]=['id'=>str_repeat('f',32),'listId'=>str_repeat('d',32),'title'=>'Ein Produkt','description'=>'Beschreibung','priceCents'=>3999,'currency'=>'EUR','productUrl'=>'https://shop.example/product','quantity'=>3,'image'=>$photo,'createdAt'=>$now,'updatedAt'=>$now];
    $state['wishes'][]=array_replace($state['wishes'][0],['id'=>str_repeat('1',32),'title'=>'Ohne Bild','image'=>null]);
    $state['reservations'][]=['id'=>str_repeat('2',32),'wishId'=>str_repeat('f',32),'userId'=>str_repeat('b',32),'createdAt'=>$now];
    $state['attempts'][]=['ip'=>hash('sha256','test'),'at'=>time(),'kind'=>'login'];
    if ($version===1) { foreach ($state['users'] as &$user) { unset($user['googleSub'],$user['googleEmail']); }unset($user); }
    return $state;
}
function source(string $directory,int $version=3): array
{
    mkdir($directory,0700);$state=legacyState($version);$raw=Store::GUARD.json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);file_put_contents($directory.'/state.php',$raw);return [$state,$raw];
}
$root=sys_get_temp_dir().'/mywish-storage-'.bin2hex(random_bytes(6));mkdir($root,0700);session_start();
try {
    foreach ([1,2,3] as $version) {
        $directory=$root.'/v'.$version;[$legacy,$raw]=source($directory,$version);$store=new Store($directory);$state=$store->verify();
        $backups=glob($directory.'/backup-schema-'.$version.'-*.php');check(count($backups)===1 && file_get_contents($backups[0])===$raw,'Byte-identical pre-migration backup: '.$version);
        check($state['schemaVersion']===4 && $state['reservations']===$legacy['reservations'] && $state['attempts']===$legacy['attempts'],'Reservations and rate counters survive migration');
        $expected=$legacy['users'];if ($version===1) { foreach ($expected as &$user) { $user['googleSub']=null;$user['googleEmail']=null; }unset($user); }
        check($state['users']===$expected,'IDs, hashes, Google-only accounts and session versions are preserved');
        foreach (['lists','wishes'] as $table) { foreach ($state[$table] as $i=>$row) {
            $before=$legacy[$table][$i];$image=$row['image'];unset($row['image'],$before['image']);check($row===$before,'Record metadata is preserved');
            if ($image) { $handle=$store->openImage($image);check(stream_get_contents($handle)===base64_decode($legacy[$table][$i]['image']['data']),'Binary image is byte-identical');fclose($handle);
                check(($image['sourceUrl'] ?? null)===($legacy[$table][$i]['image']['sourceUrl'] ?? null),'Image source survives migration');
            }
        } }
        $data=$store->dataDirectory();check(count(glob($data.'/images/*'))===1,'Identical images are deduplicated');
        $manifest=file_get_contents($data.'/manifest.json');$store->verify();check($manifest===file_get_contents($data.'/manifest.json') && count(glob($directory.'/backup-schema-*.php'))===1,'Migration is idempotent');
        foreach (glob($data.'/records/*.json') as $record) { $bytes=file_get_contents($record);check(!str_contains($bytes,'"data":') && !str_contains($bytes,$legacy['lists'][0]['image']['data']),'JSON metadata contains no embedded image bytes'); }
        check(str_contains(file_get_contents($directory.'/state.php'),'"schemaVersion": 4') && strlen(file_get_contents($directory.'/state.php'))<600,'Legacy monolith is retired with an old-version barrier');
    }
    $store=new Store($root.'/v3');$data=$store->dataDirectory();
    $_SESSION=['userId'=>str_repeat('a',32),'version'=>3];$app=new App($store);
    check(!array_key_exists('available',$app->snapshot()['wishes'][0]),'Owner privacy remains unchanged after migration');
    $public=$app->sharedList(str_repeat('d',32));check(!isset($public['wishes'][0]['reservedByMe']) && !isset($public['wishes'][0]['available']),'Public views do not disclose reservations');
    $alias=$root.'/alias';mkdir($alias,0700);(new Store($alias,$data))->verify();
    $count=$store->read(fn(&$state)=>count($state['attempts']));$workers=[];
    for ($i=0;$i<12;$i++) {
        $path=$i%2===0 ? $root.'/v3' : $alias;
        $code='require '.var_export(dirname(__DIR__).'/src/Store.php',true).';$s=new MyWish\\Store('.var_export($path,true).');$s->transaction(function(&$state){usleep(10000);$state["attempts"][]=["ip"=>"worker","at"=>time(),"kind"=>"login"];});';
        $process=proc_open([PHP_BINARY,'-n','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];
    }
    foreach ($workers as [$process,$pipes]) { $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0 && $out==='' && $error==='','Concurrent JSON writer succeeds'); }
    check($store->read(fn(&$state)=>count($state['attempts']))===$count+12,'Two storage entrypoints share one transaction lock without lost updates');
    $before=$store->read(fn(&$state)=>$state);$manifest=json_decode(file_get_contents($data.'/manifest.json'),true);
    $app->act('saveWish',['id'=>str_repeat('f',32),'listId'=>str_repeat('d',32),'title'=>'Nur Titel ändern','quantity'=>'3']);
    $after=json_decode(file_get_contents($data.'/manifest.json'),true);
    foreach (['settings','users','lists','reservations','attempts'] as $table) { check($manifest['collections'][$table]===$after['collections'][$table],'Only the changed collection is rewritten: '.$table); }
    check($manifest['collections']['wishes']!==$after['collections']['wishes'],'Wish metadata gets a new immutable version');
    $current=file_get_contents($data.'/manifest.json');chmod($data,0500);
    try { blocked(fn()=>$store->transaction(function(&$state) { $state['attempts'][]=['ip'=>'failed','at'=>time(),'kind'=>'login'];$state['lists'][0]['name']='Partial'; })); }
    finally { chmod($data,0700); }
    check(file_get_contents($data.'/manifest.json')===$current && $store->read(fn(&$s)=>$s['lists'][0]['name'])==='Geburtstag','Failure before the manifest switch leaves all old collections active');
    // A missing image must not make unrelated metadata requests read every image.
    $image=$store->read(fn(&$state)=>$state['wishes'][0]['image']);$photo=$data.'/images/'.$image['file'];rename($photo,$photo.'.hidden');
    check($app->snapshot()['wishes'][0]['image']===true,'Metadata queries do not open binary image files');blocked(fn()=>$store->openImage($image));rename($photo.'.hidden',$photo);
    $backup=$root.'/backup';$store->backup($backup);$checked=DiskStorage::checkDirectory($backup);check($checked===$store->read(fn(&$state)=>$state),'Consistent backup includes JSON and image files');
    $restore=$root.'/restore';mkdir($restore,0700);$restored=new Store($restore,$backup);check($restored->verify()===$checked,'Backup restores into a fresh instance without rewriting IDs');
    $users=$data.'/records/'.$after['collections']['users'];$bytes=file_get_contents($users);file_put_contents($users,'[]');
    blocked(fn()=>$app->snapshot());check($store->settings()['siteName']==='Migration test','Settings requests read only their own small JSON collection');file_put_contents($users,$bytes);
    $bytes=file_get_contents($photo);file_put_contents($photo,substr($bytes,0,-1));blocked(fn()=>$store->openImage($image));file_put_contents($photo,$bytes);
    $bad=$image;$bad['file']='../../etc/passwd';blocked(fn()=>Store::validateImageReference($bad));
    // Failure after installing data but before publishing the locator is recoverable.
    $interrupted=$root.'/interrupted';[$legacy,$raw]=source($interrupted);mkdir($interrupted.'/layout.php');
    blocked(fn()=>(new Store($interrupted))->verify());check(str_contains(file_get_contents($interrupted.'/state.php'),'"migration"'),'Interrupted activation keeps a recovery locator');
    rmdir($interrupted.'/layout.php');$recovered=(new Store($interrupted))->verify();check($recovered['reservations']===$legacy['reservations'],'Retry finishes activation without losing data');
    check(file_get_contents(glob($interrupted.'/backup-schema-3-*.php')[0])===$raw,'Recovery retains the original backup');
    $store->transaction(function(&$state) { foreach (['lists','wishes'] as $table) { foreach ($state[$table] as &$row) { $row['image']=null; }unset($row); } });
    check(is_file($photo),'Previous generation still protects a just-removed image');
    $store->transaction(function(&$state) { $state['attempts'][]=['ip'=>'next','at'=>time(),'kind'=>'login']; });
    check(!is_file($photo),'Unreferenced older images are removed after the next commit');
    $orphanImage=$data.'/images/'.str_repeat('9',64).'.png';$orphanRecord=$data.'/records/users-'.str_repeat('9',64).'.json';
    file_put_contents($orphanImage,'orphan');file_put_contents($orphanRecord,'[]');$removed=$store->cleanup();
    check(!file_exists($orphanImage) && !file_exists($orphanRecord) && $removed['images']===1 && $removed['records']>=1,'Cleanup removes unpublished orphan files without touching current/previous snapshots');
    $cli=$root.'/cli';[$legacy,$raw]=source($cli);$cliData=$root.'/cli-data';
    $command=static function(array $arguments): array {
        $process=proc_open(array_merge([PHP_BINARY,'-n'], $arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$error];
    };
    $tool=dirname(__DIR__).'/tools/migrate-storage.php';$cliWeb=$root.'/cli-public';mkdir($cliWeb,0700);$args=[$tool,'--storage='.$cli,'--data='.$cliData,'--web-root='.$cliWeb];
    [$exit]=$command(array_merge($args,['--dry-run']));
    check($exit===0 && !file_exists($cliData) && !file_exists($cli.'/layout.php') && file_get_contents($cli.'/state.php')===$raw,'CLI dry-run performs no writes');
    [$exit,$out,$errors]=$command($args);check($exit===0 && $errors==='' && str_contains($out,'Schema 4'),'CLI migration runs without optional image extensions');
    [$exit]=$command([dirname(__DIR__).'/tools/check-backup.php',$cliData]);check($exit===0,'Offline checker verifies JSON and every image');
    [$exit]=$command([dirname(__DIR__).'/tools/backup-storage.php','--storage='.$cli,'--target='.$root.'/cli-backup','--web-root='.$cliWeb]);check($exit===0,'CLI creates a consistent portable backup');
    $web=$root.'/public';mkdir($web,0700);$inside=$web.'/storage';[$legacy,$raw]=source($inside);$_SERVER['DOCUMENT_ROOT']=$web;
    blocked(fn()=>(new Store($inside,$web.'/unsafe'))->verify());check(file_get_contents($inside.'/state.php')===$raw && !file_exists($web.'/unsafe'),'Raw JSON is never published under the webroot');
    $safe=new Store($inside);$safe->verify();check(dirname($safe->dataDirectory())===$root,'Default private data is placed beside the document root');unset($_SERVER['DOCUMENT_ROOT']);
    $large=$root.'/large';[$legacy,$raw]=source($large);$stream=fopen($large.'/state.php','ab');
    for ($i=0;$i<6;$i++) { fwrite($stream,str_repeat(' ',1024*1024)); }fclose($stream);$digest=hash_file('sha256',$large.'/state.php');
    $oldLimit=ini_get('memory_limit');ini_set('memory_limit','32M');
    try { blocked(fn()=>(new Store($large))->verify()); }finally { ini_set('memory_limit',$oldLimit); }
    check(!file_exists($large.'/layout.php') && glob($large.'/backup-schema-*.php')===[] && hash_file('sha256',$large.'/state.php')===$digest,'Memory preflight refuses large migrations before changing any files');
    [$exit]=$command([$tool,'--storage='.$cli,'--data='.$cliWeb.'/unsafe','--web-root='.$cliWeb,'--dry-run']);
    check($exit!==0 && !file_exists($cliWeb.'/unsafe'),'CLI also rejects public JSON targets during dry-run');
    $future=$root.'/future';[$legacy,$raw]=source($future);$legacy['schemaVersion']=99;$raw=Store::GUARD.json_encode($legacy);file_put_contents($future.'/state.php',$raw);
    blocked(fn()=>(new Store($future))->verify());check(file_get_contents($future.'/state.php')===$raw && !file_exists($future.'/layout.php'),'Unknown legacy schemas are never overwritten');
    $fresh=$root.'/fresh';mkdir($fresh,0700);$empty=new Store($fresh);check($empty->settings()===null && $empty->read(fn(&$state)=>$state['users'])===[],'Fresh installations start in the new format');
    echo "OK: $checks split-storage/migration/security checks\n";
} finally { $_SESSION=[];session_destroy();removeTestTree($root); }
