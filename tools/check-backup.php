<?php
declare(strict_types=1);
// Optionales Offline-Werkzeug, kein öffentliches Betreiber-Endpoint.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/Store.php';
try {
    if ($argc!==2 || (!is_file($argv[1]) && !is_dir($argv[1]))) { throw new RuntimeException('Aufruf: php tools/check-backup.php /pfad/zur/alten-state.php ODER /pfad/zum/json-sicherungsordner'); }
    if (is_file($argv[1]) && filesize($argv[1])<4096) {
        $raw=file_get_contents($argv[1]);
        if (str_starts_with($raw,MyWish\Store::GUARD)) {
            $header=json_decode(substr($raw,strlen(MyWish\Store::GUARD)),true);
            if (($header['schemaVersion'] ?? null)===4 && isset($header['migration'])) { throw new RuntimeException('Diese state.php ist nur ein Migrationsverweis. Bitte den vollständigen JSON-/Bild-Sicherungsordner prüfen.'); }
        }
    }
    $s=is_dir($argv[1]) ? MyWish\DiskStorage::checkDirectory($argv[1]) : MyWish\Store::decode((string)file_get_contents($argv[1]));
    if (!is_dir($argv[1]) && $s['schemaVersion']>=4) { throw new RuntimeException('Schema 4 benötigt eine Sicherung des vollständigen JSON-/Bildordners.'); }
    echo 'Sicherung gültig: Schema '.$s['schemaVersion'].', '.count($s['users']).' Konten, '.count($s['lists'])." Listen.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
