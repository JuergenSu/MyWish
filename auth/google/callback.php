<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
require dirname(__DIR__,2).'/src/Http.php';
try {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { throw new MyWish\Problem(405,'Diese Rücksprungadresse benötigt eine Google-Weiterleitung.'); }
    $pending=MyWish\GoogleOidc::consume($_GET);
    if ($pending['purpose']==='login' && isset($_SESSION['userId'])) { throw new MyWish\Problem(409,'Deine Sitzung hat sich geändert. Bitte erneut beginnen.'); }
    $identity=$google->complete($_GET['code'],$pending);
    $app->googleIdentity($identity,$pending['purpose'],$pending);
    $_SESSION['flash']=match($pending['purpose']) { 'link'=>'Dein Google-Konto ist jetzt verknüpft.', 'reauthenticate'=>'Google bestätigt. Du kannst dein Konto jetzt für fünf Minuten bearbeiten.', default=>'Willkommen zurück!' };
    header('Location: '.url($pending['purpose']==='login' ? '' : 'page=account'),true,303);
} catch (Throwable $e) {
    [, $message]=safeError($e); $_SESSION['authError']=$message;
    header('Location: '.url(isset($_SESSION['userId']) ? 'page=account' : 'page=login'),true,303);
}
