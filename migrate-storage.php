<?php
/** One-time deployment tool. Removed only after the dataset has been verified. */
declare(strict_types=1);
require __DIR__.'/src/bootstrap.php';
require __DIR__.'/src/Http.php';
require __DIR__.'/src/View.php';
use MyWish\{Store,StorageProtection,Problem};
$error='';$success=false;$deleted=false;$already=false;
function migrationCompleted(string $storage): bool
{
    $read=static function(string $file): mixed {
        $raw=is_file($file) ? file_get_contents($file) : '';
        return str_starts_with($raw,Store::GUARD) ? json_decode(substr($raw,strlen(Store::GUARD)),true) : null;
    };
    $done=$read($storage.'/migration-schema4.php');$layout=$read($storage.'/layout.php');
    return is_array($done) && is_array($layout) && ($done['schemaVersion'] ?? null)===4 && is_string($layout['datasetId'] ?? null) && ($done['datasetId'] ?? null)===$layout['datasetId'];
}
try {
    $doneFile=$storageDirectory.'/migration-schema4.php';
    $already=migrationCompleted($storageDirectory);
    if ($already) {
        $success=true;$deleted=@unlink(__FILE__) || !is_file(__FILE__);http_response_code(410);
    } elseif ($_SERVER['REQUEST_METHOD']==='POST') {
        $in=input();$csrf=$in['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'],$csrf)) { throw new Problem(403,'Das Formular ist abgelaufen. Bitte die Seite neu laden.'); }
        if (!is_writable(__DIR__)) { throw new Problem(503,'PHP muss das Migrationsskript nach Abschluss löschen können. Bitte den App-Ordner für diesen einmaligen Lauf beschreibbar machen.'); }
        $limit=ini_parse_quantity(ini_get('memory_limit'));
        if ($limit>0 && $limit<512*1024*1024) { @ini_set('memory_limit','512M'); }
        @set_time_limit(0);ignore_user_abort(true);session_write_close();
        // Different sessions can submit concurrently; only one may complete the run.
        $lock=@fopen($storageDirectory.'/migration-lock.php','c+');
        if (!$lock || !flock($lock,LOCK_EX)) { if (is_resource($lock)) { fclose($lock); }throw new Problem(503,'Die Migration kann gerade nicht gesperrt werden. Bitte erneut versuchen.'); }
        try {
            $already=migrationCompleted($storageDirectory);
            if (!$already) {
                StorageProtection::forMigration($storageDirectory);
                $store->migrate();
                $layoutRaw=file_get_contents($storageDirectory.'/layout.php');$layout=json_decode(substr($layoutRaw,strlen(Store::GUARD)),true,64,JSON_THROW_ON_ERROR);
                Store::writeFile($doneFile,Store::GUARD.json_encode(['schemaVersion'=>4,'datasetId'=>$layout['datasetId'],'completedAt'=>gmdate('c')],JSON_THROW_ON_ERROR));
            }
            $success=true;$deleted=@unlink(__FILE__) || !is_file(__FILE__);
            if ($already) { http_response_code(410); }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    } elseif ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET, POST');throw new Problem(405,'Bitte den Migrationslink im Browser öffnen.'); }
} catch (Throwable $ex) { [$status,$error]=safeError($ex);http_response_code($status); }
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MyWish · Datenspeicher aktualisieren</title><link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css?v=google-first-login"></head>
<body><header class="header"><div class="shell header-inner"><a class="brand" href="<?= e(url()) ?>">MyWish.</a></div></header><main class="shell main"><section class="panel edit-panel stack">
<h1><?= $success ? 'Migration abgeschlossen' : 'Datenspeicher aktualisieren' ?></h1>
<?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if ($success): ?>
<p><?= $already ? 'Dieser Datenbestand wurde bereits aktualisiert.' : 'JSON-Daten und Bilddateien wurden vollständig übernommen und geprüft. Eine Sicherung des bisherigen Datenbestands bleibt erhalten.' ?></p>
<?php if ($deleted): ?><p>Das Migrationsskript wurde gelöscht und kann nicht erneut aufgerufen werden.</p><?php else: ?><div class="notice error" role="alert">Die Migration ist abgeschlossen und gegen erneute Ausführung gesperrt. Das Skript konnte wegen der Dateirechte nicht gelöscht werden. Bitte migrate-storage.php per Dateimanager entfernen.</div><?php endif ?>
<a class="button primary" href="<?= e(url()) ?>">MyWish öffnen</a>
<?php else: ?>
<p>Dieser einmalige Lauf speichert JSON und Bilder getrennt im geschützten Storage-Ordner. Vorher wird der Zugriffsschutz des Webservers geprüft und eine vollständige Sicherung angelegt.</p>
<p>Bei großen Beständen kann der Vorgang etwas dauern. Bitte diesen Tab bis zum Abschluss geöffnet lassen. Nach erfolgreicher Prüfung löscht sich dieses Skript selbst.</p>
<form method="post"><?= csrf() ?><button type="submit" class="button primary">Migration starten</button></form>
<?php endif ?>
</section></main></body></html>
