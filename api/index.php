<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/Http.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $method=$_SERVER['REQUEST_METHOD'];
    $action=is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    if ($method==='GET' && $action==='session') {
        $settings=$app->settings();
        $data=['installed'=>$settings!==null,'registration'=>$settings['registration'] ?? null];
    } elseif ($method==='GET' && $action==='state') { $data=$app->snapshot(); }
    elseif ($method==='POST') {
        $in=input(); $action=$action ?: (is_string($in['action'] ?? null) ? $in['action'] : '');
        $data=mutate($app,$action,$in);
        if (in_array($action,['setup','register'],true) || (in_array($action,['saveList','saveWish'],true) && empty($in['id']))) { http_response_code(201); }
    } else { header('Allow: GET, POST'); throw new MyWish\Problem(405,'Diese HTTP-Methode ist nicht erlaubt. Änderungen benötigen POST.'); }
    echo json_encode(['ok'=>true,'data'=>$data,'csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    [$status,$message]=safeError($ex); http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>['message'=>$message],'csrf'=>$_SESSION['csrf']],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
