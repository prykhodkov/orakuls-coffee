<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Запобіжник від випадкового знищення даних: код, що очищає таблиці (сід, тести),
 * працює лише з базою, назва якої закінчується на _test. Захищає і локальну БД розробника, і прод.
 */
final class DatabaseGuard
{
    /** 'orakuls_test' / 'x_test' → true. '', null, 'orakuls', 'test', '_test', 'orakuls_test_x', 'orakuls-test' → false */
    public static function isTestDatabaseName(?string $name): bool
    {
        return $name !== null && preg_match('/^[A-Za-z0-9_]+_test$/', $name) === 1;
    }

    /**
     * Дивиться на БД, до якої фактично підключено PDO (SELECT DATABASE()), а не на конфіг:
     * так запобіжник працює, навіть якщо змінні оточення задано неправильно.
     */
    public static function assertTestDatabase(PDO $pdo): void
    {
        $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $name = is_string($name) ? $name : null;

        if (!self::isTestDatabaseName($name)) {
            throw new RuntimeException(sprintf(
                "Refusing to run: database '%s' is not a test database (its name must end with _test).",
                $name ?? '(none)'
            ));
        }
    }
}
