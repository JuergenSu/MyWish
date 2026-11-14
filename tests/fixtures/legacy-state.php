<?php
declare(strict_types=1);
use MyWish\Store;
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
