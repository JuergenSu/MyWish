<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/Store.php';
try {
    $options=getopt('',['storage:','data:','web-root:']);
    if (!isset($options['storage'],$options['web-root'])) { throw new RuntimeException('Aufruf: php tools/cleanup-storage.php --storage=/storage --web-root=/oeffentlicher/webroot [--data=/privater/datenordner]'); }
    if (!is_dir($options['web-root'])) { throw new RuntimeException('Der öffentliche Webroot fehlt.'); }
    $_SERVER['DOCUMENT_ROOT']=realpath($options['web-root']);
    $store=new MyWish\Store($options['storage'],$options['data'] ?? null);$removed=$store->cleanup();
    echo 'Nicht referenzierte Dateien entfernt: '.$removed['records'].' JSON-Dateien, '.$removed['images']." Bilder. Aktuelle und vorherige Generation bleiben erhalten.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
