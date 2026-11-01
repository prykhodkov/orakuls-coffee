<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    /**
     * Ліниве підключення: запити, що не торкаються БД, її не відкривають.
     * Нативні prepared statements і STRINGIFY_FETCHES=false дають числа числами (id, total…).
     */
    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::require('DB_HOST'),
            Config::get('DB_PORT', '3306'),
            Config::require('DB_NAME')
        );

        // Сесійні налаштування не залежать від конфігурації конкретного хостингу.
        $init = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
            . "sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

        self::$pdo = new PDO($dsn, Config::require('DB_USER'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => $init,
        ]);

        return self::$pdo;
    }
}
