<?php

declare(strict_types=1);

// Допоміжні функції для перевірок, що працюють з тестовою БД. Підключається з tests/api.php
// після lib.php і bootstrap. Код «стерти всі таблиці» живе лише тут, у tests/, а не в src/:
// у продакшн-коді його бути не повинно.

use App\Database\DatabaseGuard;
use App\Database\Migrator;
use App\Database\Seeder;
use App\Support\Db;

function testPdo(): PDO
{
    return Db::pdo();
}

function testMigrator(): Migrator
{
    return new Migrator(testPdo(), APP_ROOT . '/database/migrations');
}

/** Видаляє всі таблиці бази. Першою дією перевіряє, що це тестова БД. DROP комітить неявно — транзакції тут немає. */
function resetTestDatabase(PDO $pdo): void
{
    DatabaseGuard::assertTestDatabase($pdo);

    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/**
 * Повертає тестову БД до стандартного стану сіду. Тест, що змінює дані, викликає це в кінці.
 *
 * @return array<string, int> key → id
 */
function reseed(): array
{
    return (new Seeder(testPdo(), APP_ROOT . '/database/seed/phrases.php'))->run();
}

function phraseCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM `phrases`')->fetchColumn();
}

/**
 * «Підпис» схеми поточної БД з information_schema — для перевірки, що schema.sql дає ту саму
 * схему, що й міграції. Лічильники AUTO_INCREMENT у підпис не входять.
 *
 * @return array{tables: list<array<string, mixed>>, columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>}
 */
function schemaSignature(PDO $pdo): array
{
    return [
        'tables' => $pdo->query(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        )->fetchAll(),
        'columns' => $pdo->query(
            'SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
        )->fetchAll(),
        'indexes' => $pdo->query(
            'SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE'
            . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
            . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        )->fetchAll(),
    ];
}
