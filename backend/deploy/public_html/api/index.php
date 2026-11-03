<?php

declare(strict_types=1);

// Точка входу API на хостингу (BE-009). Код застосунку й .env лежать ВИЩЕ веб-кореня, у публічній папці — лише цей файл:
//   ~/orakuls/            src/, database/, bin/, .env      (недоступне по HTTP)
//   ~/public_html/api/    index.php (цей файл), .user.ini
// APP_ROOT = ~/orakuls. Інше розташування можна задати змінною оточення ORAKULS_ROOT (абсолютний шлях).
// Локальна розробка використовує backend/public/index.php, а не цей файл.

$root = getenv('ORAKULS_ROOT') ?: dirname(__DIR__, 2) . '/orakuls';

if (!is_file($root . '/src/bootstrap.php')) {
    // Без деталей клієнту: шлях до коду не розкриваємо. Причину видно в лозі.
    error_log('Orakuls: application root not found, expected ' . $root . '/src/bootstrap.php');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":{"code":"server_error","message":"Internal server error"}}';
    exit;
}

define('APP_ROOT', $root);
require APP_ROOT . '/src/bootstrap.php';

use App\Http\Kernel;
use App\Http\Request;

Kernel::create()->handle(Request::fromGlobals())->send();
