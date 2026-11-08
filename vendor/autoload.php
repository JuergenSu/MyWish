<?php
// Lokaler Autoloader für die mitgelieferte BSD-lizenzierte JWT-Bibliothek.
spl_autoload_register(static function (string $class): void {
    $prefix='Firebase\\JWT\\';
    if (!str_starts_with($class,$prefix)) { return; }
    $short=substr($class,strlen($prefix));
    if (!preg_match('/^[A-Za-z]+$/D',$short)) { return; }
    $file=__DIR__.'/firebase/php-jwt/src/'.$short.'.php';
    if (is_file($file)) { require $file; }
});
