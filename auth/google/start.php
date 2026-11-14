<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
require dirname(__DIR__,2).'/src/Http.php';
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); throw new MyWish\Problem(405,'Die Google-Anmeldung bitte über den Anmeldeknopf starten.'); }
    $in=input(); $token=$in['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'],$token)) { throw new MyWish\Problem(403,'Das Formular ist abgelaufen. Bitte neu laden.'); }
    $purpose=MyWish\App::text($in,'purpose',20,true);
    $user=null;
    if ($purpose==='login') {
        if (isset($_SESSION['userId'])) { throw new MyWish\Problem(409,'Du bist bereits angemeldet. Google kannst du unter Mein Konto verknüpfen.'); }
        if (!$app->settings()) { throw new MyWish\Problem(409,'Bitte MyWish zuerst einrichten.'); }
    } else {
        $user=$app->store->read(fn(&$s)=>MyWish\App::user($s));
        if ($purpose==='link' && !MyWish\App::verifyCurrent($in,$user)) { throw new MyWish\Problem(403,'Zum Verknüpfen bitte dein aktuelles Passwort bestätigen.'); }
        if ($purpose==='reauthenticate' && $user['googleSub']===null) { throw new MyWish\Problem(403,'Dieses Konto ist nicht mit Google verknüpft.'); }
    }
    header('Location: '.$google->begin($purpose,$user),true,303);
} catch (Throwable $e) {
    [$status,$message]=safeError($e); http_response_code($status); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Google-Anmeldung</title><h1>Google-Anmeldung</h1><p>'.e($message).'</p><a href="'.e(url(isset($_SESSION['userId']) ? 'page=account' : 'page=login')).'">Zurück zu MyWish</a></html>';
}
