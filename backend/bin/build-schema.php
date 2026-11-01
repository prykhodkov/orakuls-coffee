<?php

declare(strict_types=1);

// Генерує database/schema.sql з міграцій.
// Запуск: docker compose exec php php bin/build-schema.php [--check]
//   --check  нічого не пише; завершується з кодом 1, якщо збережений schema.sql застарів.

use App\Database\SchemaBuilder;

if (PHP_SAPI !== 'cli') { // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

try {
    $target = APP_ROOT . '/database/schema.sql';
    $schema = SchemaBuilder::build(APP_ROOT . '/database/migrations');

    if (in_array('--check', $argv, true)) {
        // CRLF нормалізуємо: git на Windows може віддати файл з \r\n
        $saved = is_file($target) ? str_replace("\r\n", "\n", (string) file_get_contents($target)) : null;
        if ($saved !== $schema) {
            fwrite(STDERR, "schema.sql is out of date. Run: php bin/build-schema.php\n");
            exit(1);
        }
        echo "schema.sql is up to date.\n";
        exit(0);
    }

    if (file_put_contents($target, $schema) === false) {
        throw new RuntimeException('Cannot write database/schema.sql');
    }
    echo "Written database/schema.sql\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
