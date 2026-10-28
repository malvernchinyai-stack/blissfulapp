<?php
// Local development only:  php -S 127.0.0.1:8080 -t public bin/dev-router.php
$file = __DIR__ . '/../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_URI'] !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/../public/index.php';
