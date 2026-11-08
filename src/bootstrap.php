<?php
declare(strict_types=1);
require_once __DIR__.'/Store.php';
require_once __DIR__.'/App.php';
require_once __DIR__.'/Metadata.php';
require_once __DIR__.'/GoogleOidc.php';
require_once __DIR__.'/OpenAiImages.php';

use MyWish\Problem;
use MyWish\Store;
use MyWish\App;

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$csp="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
header('Content-Security-Policy: '.$csp.';');
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
function bootFailure(string $message): never
{
    http_response_code(503);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>['message'=>$message]],JSON_UNESCAPED_UNICODE);
    } else { header('Content-Type: text/html; charset=utf-8'); echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); }
    exit;
}
if (PHP_VERSION_ID < 80200 || !extension_loaded('json') || !extension_loaded('session')) {
    bootFailure('MyWish benötigt PHP 8.2 oder neuer mit JSON- und Session-Unterstützung.');
}
$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
if (str_ends_with($base, '/auth/google')) { $base=substr($base,0,-strlen('/auth/google')); }
if (str_ends_with($base, '/api')) { $base = dirname($base); }
$base = $base === '/' || $base === '.' ? '' : rtrim($base, '/');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('mywish_'.substr(hash('sha256', __DIR__), 0, 10));
session_set_cookie_params(['lifetime'=>0,'path'=>$base.'/', 'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly'=>true,'samesite'=>'Lax']);
if (!@session_start()) { bootFailure('Die PHP-Session kann nicht gestartet werden. Bitte Hosting-Einstellungen prüfen.'); }
if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 7200) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
try {
$config = is_file(dirname(__DIR__).'/config.php') ? require dirname(__DIR__).'/config.php' : [];
if (!is_array($config)) { throw new Problem(503,'config.php muss ein Konfigurationsarray zurückgeben.'); }
$storageDirectory=$config['storage'] ?? dirname(__DIR__).'/storage';
$app = new App(new Store($storageDirectory), $config['registration'] ?? null);
$google = new MyWish\GoogleOidc(is_array($config['google'] ?? null) ? $config['google'] : [], $storageDirectory);
$imageSearch = new MyWish\OpenAiImages(is_array($config['openai'] ?? null) ? $config['openai'] : [], $storageDirectory);
if ($google->available()) { header('Content-Security-Policy: '.$csp.' https://accounts.google.com;'); } }
catch (Throwable $e) { bootFailure($e instanceof Problem ? $e->getMessage() : 'MyWish kann die Daten nicht öffnen. Bitte Konfiguration prüfen.'); }
function url(string $query = ''): string { global $base; return $base.'/index.php'.($query !== '' ? '?'.$query : ''); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
