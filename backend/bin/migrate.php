<?php

declare(strict_types=1);

// Застосовує міграції з database/migrations.
// Запуск: docker compose exec php php bin/migrate.php [--status]

use App\Database\Migrator;
use App\Support\Db;

if (PHP_SAPI !== 'cli') { // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

// bootstrap вішає HTTP-орієнтований обробник винятків, тому в CLI ловимо все самі
try {
    $migrator = new Migrator(Db::pdo(), APP_ROOT . '/database/migrations');

    if (in_array('--status', $argv, true)) {
        $status = $migrator->status();
        echo "applied:\n";
        foreach ($status['applied'] as $name) {
            echo "  {$name}\n";
        }
        echo "pending:\n";
        foreach ($status['pending'] as $name) {
            echo "  {$name}\n";
        }
        exit(0);
    }

    $applied = $migrator->up();
    foreach ($applied as $name) {
        echo "Applied: {$name}\n";
    }
    if ($applied === []) {
        echo "Nothing to migrate.\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
