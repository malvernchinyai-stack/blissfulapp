<?php
declare(strict_types=1);

// Front controller for app.blissfulevents.co.zw. Only this folder is web-accessible;
// application code, .env, storage and uploads live outside the web root.
// Locally the code sits one level up. On DirectAdmin, put the absolute path of the backend folder
// in a file named `.app-path` next to this index.php (dotfiles are blocked from the web by .htaccess).
$appDir = is_file(dirname(__DIR__) . '/src/bootstrap.php')
    ? dirname(__DIR__)
    : trim((string) @file_get_contents(__DIR__ . '/.app-path'));
if ($appDir === '' || !is_file($appDir . '/src/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"status":"unavailable"}';
    exit;
}
require $appDir . '/src/bootstrap.php';

\Blissful\Http\Kernel::handle(\Blissful\Http\Request::fromGlobals())->send();
