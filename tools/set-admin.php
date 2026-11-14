<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/Store.php';
try {
    $options=getopt('',['web-root:','username:','enable','disable']);
    if (!isset($options['web-root'],$options['username']) || isset($options['enable'])===isset($options['disable'])) { throw new RuntimeException('Aufruf: php tools/set-admin.php --web-root=/public_html --username=kennung --enable|--disable'); }
    if (!is_dir($options['web-root'])) { throw new RuntimeException('Der öffentliche Webroot fehlt.'); }
    $_SERVER['DOCUMENT_ROOT']=realpath($options['web-root']);
    $config=is_file(dirname(__DIR__).'/config.php') ? require dirname(__DIR__).'/config.php' : [];
    if (!is_array($config)) { throw new RuntimeException('Ungültige Konfiguration.'); }
    $storage=$config['storage'] ?? dirname(__DIR__).'/storage';
    if (!is_file($storage.'/layout.php')) { throw new RuntimeException('Bitte den Datenbestand zuerst über den Browser migrieren oder MyWish einrichten.'); }
    $store=new MyWish\Store($storage,$config['data'] ?? null);
    $name=strtolower(trim($options['username']));$enabled=isset($options['enable']);
    $store->transaction(static function(&$state) use ($name,$enabled) {
        foreach ($state['users'] as &$user) {
            if ($user['username']===$name) { $user['isAdmin']=$enabled;return; }
        }
        throw new RuntimeException('Das Konto wurde nicht gefunden. Es wurden keine Rechte geändert.');
    });
    echo $enabled ? "Admin-Statistik freigeschaltet.\n" : "Admin-Zugriff entfernt.\n";
} catch (Throwable $ex) { fwrite(STDERR,$ex->getMessage()."\n");exit(1); }
