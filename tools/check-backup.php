<?php
declare(strict_types=1);
// Optionales Offline-Werkzeug, kein öffentliches Betreiber-Endpoint.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/Store.php';
try {
    if ($argc!==2 || !is_file($argv[1])) { throw new RuntimeException('Aufruf: php tools/check-backup.php /pfad/zur/state.php'); }
    $s=MyWish\Store::decode((string)file_get_contents($argv[1]));
    echo 'Sicherung gültig: Schema '.$s['schemaVersion'].', '.count($s['users']).' Konten, '.count($s['lists'])." Listen.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
