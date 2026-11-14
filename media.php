<?php
declare(strict_types=1);
require __DIR__.'/src/bootstrap.php';
require __DIR__.'/src/Http.php';
try {
    $image=$app->store->read(function(&$s) {
        $type=$_GET['type'] ?? '';
        if ($type==='preview') {
            MyWish\App::user($s);
            if (!isset($_SESSION['importImage']) || time()-$_SESSION['importImage']['at']>=1800) { throw new MyWish\Problem(404,'Bild nicht gefunden.'); }
            return $_SESSION['importImage']['image'];
        }
        if (!in_array($type,['lists','wishes'],true)) { throw new MyWish\Problem(404,'Bild nicht gefunden.'); }
        if (!array_key_exists('share',$_GET)) { MyWish\App::user($s); }
        $row=MyWish\App::find($s[$type],is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
        if (array_key_exists('share',$_GET)) {
            $listId=returnListId($_GET['share']);
            if ($listId==='' || ($type==='lists' ? $row['id'] : $row['listId'])!==$listId) { throw new MyWish\Problem(404,'Bild nicht gefunden.'); }
            MyWish\App::find($s['lists'],$listId);
        }
        if (!$row['image']) { throw new MyWish\Problem(404,'Bild nicht gefunden.'); }
        return $row['image'];
    });
    if (!in_array($image['mime'],['image/jpeg','image/png','image/webp'],true)) { throw new MyWish\Problem(404,'Bild nicht gefunden.'); }
    header('Content-Type: '.$image['mime']); header('Content-Disposition: inline');
    if (isset($image['data'])) { echo base64_decode($image['data'],true); }
    else {
        $file=$app->store->openImage($image);
        try { header('Content-Length: '.(string)$image['bytes']);fpassthru($file); }
        finally { fclose($file); }
    }
} catch (Throwable $ex) { [$status,$message]=safeError($ex); http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $message; }
