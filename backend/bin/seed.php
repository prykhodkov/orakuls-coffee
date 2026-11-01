<?php

declare(strict_types=1);

// Наповнює ТЕСТОВУ БД відомими даними (відмовляється працювати з БД, назва якої не закінчується на _test).
// Запуск: docker compose exec -e DB_NAME=orakuls_test php php bin/seed.php

use App\Database\Seeder;
use App\Support\Db;

if (PHP_SAPI !== 'cli') { // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

// bootstrap вішає HTTP-орієнтований обробник винятків, тому в CLI ловимо все самі
try {
    $pdo = Db::pdo();
    $map = (new Seeder($pdo, APP_ROOT . '/database/seed/phrases.php'))->run();
    $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    echo sprintf("Seeded %d phrases into %s.\n", count($map), $name);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
