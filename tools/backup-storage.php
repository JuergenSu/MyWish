<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/Store.php';
try {
    $options=getopt('',['storage:','data:','target:','web-root:']);
    if (!isset($options['storage'],$options['target'],$options['web-root'])) { throw new RuntimeException('Aufruf: php tools/backup-storage.php --storage=/storage --target=/privater/neuer-sicherungsordner --web-root=/oeffentlicher/webroot [--data=/privater/datenordner]'); }
    if (!is_dir($options['web-root'])) { throw new RuntimeException('Der öffentliche Webroot fehlt.'); }
    $_SERVER['DOCUMENT_ROOT']=realpath($options['web-root']);
    $store=new MyWish\Store($options['storage'],$options['data'] ?? null);$store->backup($options['target']);
    MyWish\DiskStorage::checkDirectory($options['target']);
    echo "Konsistente Sicherung einschließlich Bilddateien erstellt und geprüft.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
