<?php

declare(strict_types=1);

// Перевірки проти тестової БД orakuls_test: сам накочує міграції, заливає сід і викликає Kernel у процесі.
// Запуск: docker compose exec php php tests/api.php
// Нові перевірки ендпоінтів кладуться в tests/api/<назва>.php — змінювати цей файл не треба.

use App\Database\DatabaseGuard;

define('APP_ROOT', dirname(__DIR__));
const TEST_DB_NAME = 'orakuls_test';

// ДО bootstrap і до першого Db::pdo(): getenv() має пріоритет над .env, тож DB_NAME з .env ігнорується,
// а Db тримає з'єднання статично — після першого виклику змінити БД уже неможливо.
putenv('DB_NAME=' . TEST_DB_NAME);

// Облікові дані адміна для тестів авторизації (BE-004): так само до bootstrap і поверх .env, тож тести не залежать
// від локального backend/.env. Cost 4 замість 12 за замовчуванням: 1 мс замість ~165 мс на кожен password_verify.
const TEST_ADMIN_LOGIN = 'test-admin';
const TEST_ADMIN_PASSWORD = 'test-password-123456';
const TEST_TOKEN_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'; // 64 символи
putenv('ADMIN_LOGIN=' . TEST_ADMIN_LOGIN);
putenv('ADMIN_PASSWORD_HASH=' . password_hash(TEST_ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
putenv('TOKEN_SECRET=' . TEST_TOKEN_SECRET);
putenv('TOKEN_TTL_HOURS='); // порожнє = за замовчуванням (168 год)

require APP_ROOT . '/src/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/db-lib.php';

// bootstrap вішає HTTP-орієнтований обробник винятків, тому підготовку БД ловимо самі
try {
    $pdo = testPdo();
    DatabaseGuard::assertTestDatabase($pdo); // не *_test → нічого не змінено

    resetTestDatabase($pdo);
    testMigrator()->up();
    reseed();

    foreach (glob(__DIR__ . '/api/*.php') ?: [] as $file) { // кожен файл реєструє свої test(...)
        require $file;
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
    // Найімовірніша причина в того, хто оновив проєкт, але не перестворив volume (init-скрипти MySQL працюють лише при його створенні)
    if ($e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1044, 1049], true)) {
        $message .= "\nТестова БД " . TEST_DB_NAME . ' не створена. Один раз виконай: docker compose down -v && docker compose up -d --wait';
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

runTests();
