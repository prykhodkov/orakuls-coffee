<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Збирає повну схему (schema.sql) з міграцій, щоб файл для ручного імпорту через phpMyAdmin
 * не міг розійтись із міграціями (D-16). Вручну schema.sql не редагується.
 */
final class SchemaBuilder
{
    public static function build(string $migrationsDir): string
    {
        $names = Migrator::files($migrationsDir);

        $out = "-- Orākuls Coffee · повна схема БД (MySQL 5.7)\n"
            . "-- ЗГЕНЕРОВАНО командою `php bin/build-schema.php` з database/migrations/*.sql. Вручну не редагувати.\n"
            . "-- Імпорт: phpMyAdmin → обрати порожню базу → вкладка «Імпорт» → цей файл.\n"
            . "-- Даних тут немає: сід на прод не потрапляє, фрази завантажує адмін через імпорт.\n"
            . "\n"
            . "SET NAMES utf8mb4;\n"
            . "\n"
            . Migrator::TABLE_DDL . ";\n"
            . "\n";

        foreach ($names as $name) {
            $out .= "-- ---- {$name} ----\n";
            // Коментарі міграцій сюди свідомо не переносяться: файл гарантовано виконується тим самим розбирачем
            foreach (SqlSplitter::split(Migrator::read($migrationsDir, $name)) as $statement) {
                $out .= $statement . ";\n\n";
            }
        }

        // Імена потрапляють в облік, тож runner після ручного імпорту не застосує ці міграції вдруге
        $rows = array_map(
            static fn (string $name): string => "  ('{$name}', UTC_TIMESTAMP())",
            $names
        );

        return $out
            . "INSERT IGNORE INTO `migrations` (`name`, `applied_at`) VALUES\n"
            . implode(",\n", $rows) . ";\n";
    }
}
