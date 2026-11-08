<?php
declare(strict_types=1);
use MyWish\App;
use MyWish\Problem;
use MyWish\Metadata;

function input(): array
{
    $length=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length>10*1024*1024) { throw new Problem(413,'Die Anfrage ist zu groß (maximal 10 MB, Bilder maximal 8 MB).'); }
    $serverLimit=ini_parse_quantity(ini_get('post_max_size'));
    if ($serverLimit>0 && $length>$serverLimit) { throw new Problem(413,'Das PHP-Uploadlimit ist zu klein. Bitte post_max_size=10M und upload_max_filesize=8M beim Webhoster einstellen.'); }
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
    if ($action==='searchImage') {
        global $imageSearch;
        $listId=App::text($in,'listId',32,true);
        $userId=$app->store->transaction(function(&$s) use ($listId): string {
            $user=App::user($s); $list=App::find($s['lists'],$listId);
            if ($list['ownerUserId']!==$user['id']) { throw new Problem(403,'Nur der Eigentümer darf Bilder für diese Liste suchen.'); }
            return $user['id'];
        });
        $productUrl=App::webUrl(App::text($in,'productUrl',2048));
        $result=$imageSearch->preview(App::text($in,'title',200,true),$productUrl,$userId);
        // Den vom Nutzer eingegebenen Kauflink erhalten; nur die Suchanfrage bereinigen.
        $result['productUrl']=$productUrl;
        return $result;
    }
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

// Nur eine Listen-ID als Rücksprungziel zulassen, niemals fremde URLs.
function returnListId(mixed $value): string
{
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/D',$value) ? $value : '';
}
function afterLoginQuery(): string
{
    $id=returnListId($_SESSION['returnListId'] ?? '');
    unset($_SESSION['returnListId']);
    return $id!=='' ? 'page=list&id='.$id : '';
}
