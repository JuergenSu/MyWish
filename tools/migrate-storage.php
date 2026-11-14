<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/Store.php';
use MyWish\{Store,DiskStorage};
try {
    $options=getopt('',['storage:','data:','web-root:','dry-run']);
    if (!isset($options['storage'],$options['web-root'])) { throw new RuntimeException('Aufruf: php tools/migrate-storage.php --storage=/alter/storage --data=/privater/mywish-datenordner --web-root=/oeffentlicher/webroot [--dry-run]'); }
    if (!is_dir($options['web-root'])) { throw new RuntimeException('Der öffentliche Webroot fehlt.'); }
    $_SERVER['DOCUMENT_ROOT']=realpath($options['web-root']);
    $directory=realpath($options['storage']);if ($directory===false || !is_dir($directory)) { throw new RuntimeException('Der bisherige Datenordner fehlt.'); }
    if (isset($options['dry-run'])) {
        $locator=null;
        if (is_file($directory.'/layout.php')) {
            $raw=file_get_contents($directory.'/layout.php');
            if (!str_starts_with($raw,Store::GUARD)) { throw new RuntimeException('Der Datenverweis ist beschädigt.'); }
            $locator=json_decode(substr($raw,strlen(Store::GUARD)),true,64,JSON_THROW_ON_ERROR);
        } else {
            $file=$directory.'/state.php';if (!is_file($file)) { throw new RuntimeException('Kein bisheriger Datenbestand gefunden. Es wurden keine Dateien angelegt.'); }
            Store::checkMigrationMemory(filesize($file));$raw=file_get_contents($file);
            if (strlen($raw)<4096 && str_starts_with($raw,Store::GUARD)) {
                $small=json_decode(substr($raw,strlen(Store::GUARD)),true);
                if (($small['schemaVersion'] ?? null)===4) { $locator=$small['migration'] ?? null; }
            }
            if ($locator===null) { $state=Store::decode($raw); }
        }
        if ($locator!==null) {
            if (!is_array($locator) || !is_string($locator['directory'] ?? null) || !is_string($locator['datasetId'] ?? null)) { throw new RuntimeException('Der Datenverweis ist beschädigt.'); }
            $target=DiskStorage::plannedDirectory($directory,$options['data'] ?? $locator['directory'],$_SERVER['DOCUMENT_ROOT']);
            $state=DiskStorage::checkDirectory($target);$manifest=json_decode(file_get_contents($target.'/manifest.json'),true,64,JSON_THROW_ON_ERROR);
            if ($manifest['datasetId']!==$locator['datasetId']) { throw new RuntimeException('Das Ziel gehört zu einem anderen Datenbestand.'); }
        } else { $target=DiskStorage::plannedDirectory($directory,$options['data'] ?? null,$_SERVER['DOCUMENT_ROOT']); }
        echo "Prüflauf: Datenformat und Referenzen gültig; keine Dateien verändert.\nGeplanter privater Datenordner: ".$target."\n";
    } else {
        $store=new Store($directory,$options['data'] ?? null);$state=$store->verify();
        echo 'Migration/Prüfung abgeschlossen. Privater Datenordner: '.$store->dataDirectory()."\n";
    }
    $images=0;foreach (['lists','wishes'] as $table) { foreach ($state[$table] as $row) { if ($row['image']!==null) { $images++; } } }
    echo 'Schema '.$state['schemaVersion'].': '.count($state['users']).' Konten, '.count($state['lists']).' Listen, '.count($state['wishes']).' Wünsche, '.$images." Bildverweise.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
