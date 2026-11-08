<?php
declare(strict_types=1);
use MyWish\App;
use MyWish\Problem;
use MyWish\Metadata;

function input(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>4*1024*1024) { throw new Problem(413,'Die Anfrage ist zu groß (maximal 4 MB).'); }
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $raw=file_get_contents('php://input');
        try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new Problem(400,'Die JSON-Anfrage ist ungültig.'); }
        if (!is_array($data) || !str_starts_with(ltrim($raw),'{')) { throw new Problem(400,'Ein JSON-Objekt wird erwartet.'); }
    } else { $data=$_POST; }
    unset($data['_image']);
    if (isset($_FILES['image'])) { $data['_image']=$_FILES['image']; }
    return $data;
}
function mutate(App $app,string $action,array $in): array
{
    if (!in_array($action,['setup','login','register'],true)) { $app->store->transaction(fn(&$s)=>App::user($s)); }
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf'] ?? '');
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'],$csrf)) { throw new Problem(403,'Das Formular ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.'); }
    if ($action==='import') { return Metadata::preview(App::webUrl(App::text($in,'productUrl',2048,true))); }
    if ($action==='importImage') {
        $result=Metadata::imagePreview(App::webUrl(App::text($in,'imageUrl',2048,true)));
        $result['productUrl']=App::webUrl(App::text($in,'productUrl',2048));
        return $result;
    }
    return $app->act($action,$in);
}
function safeError(Throwable $e): array
{
    if ($e instanceof Problem) { return [$e->status,$e->getMessage()]; }
    // Keine Eingaben, Tokens, Pfade oder Stacktraces protokollieren.
    error_log('MyWish: internal failure ('.get_class($e).')');
    return [500,'Ein technischer Fehler ist aufgetreten. Bitte erneut versuchen; bei Wiederholung den Betreiber kontaktieren.'];
}
