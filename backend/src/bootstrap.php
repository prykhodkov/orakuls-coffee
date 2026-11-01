<?php

declare(strict_types=1);

use App\Http\ErrorHandler;
use App\Support\Config;

// Корінь застосунку (там лежать src/ і .env). Точка входу на хостингу може задати його сама.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Автозавантаження без Composer: App\Foo\Bar → src/Foo/Bar.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('UTC');

Config::load(APP_ROOT . '/.env');
ErrorHandler::register();
