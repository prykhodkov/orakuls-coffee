<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Застосовує SQL-міграції з каталогу database/migrations і веде облік у таблиці migrations.
 * На shared-хостингу без CLI схему розгортають імпортом schema.sql, тож runner — для локальної
 * розробки й тестів (а на хостингу з CLI — теж придатний).
 */
final class Migrator
{
    /**
     * Таблицю migrations створює сам runner, а не міграційний файл: файлу потрібен облік,
     * якого ще немає. Цей DDL — єдине джерело, його ж використовує SchemaBuilder для schema.sql.
     */
    public const TABLE_DDL = "CREATE TABLE IF NOT EXISTS `migrations` (\n"
        . "  `name` VARCHAR(100) NOT NULL,\n"
        . "  `applied_at` DATETIME NOT NULL,\n"
        . "  PRIMARY KEY (`name`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private const LOCK_NAME = 'orakuls_migrate';

    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    /**
     * @return list<string> імена файлів у порядку застосування
     */
    public static function files(string $directory): array
    {
        $paths = is_dir($directory) ? (glob(rtrim($directory, '/\\') . '/*.sql') ?: []) : [];
        if ($paths === []) {
            throw new RuntimeException("No migration files found in {$directory}");
        }

        $names = [];
        $byPrefix = [];
        foreach ($paths as $path) {
            $name = basename($path);
            // Опечатка в імені не повинна призводити до того, що міграцію мовчки не застосують
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $name, $m) !== 1) {
                throw new RuntimeException("Invalid migration file name: {$name} (expected NNN_snake_case.sql)");
            }
            if (isset($byPrefix[$m[1]])) {
                throw new RuntimeException("Duplicate migration number {$m[1]}: {$byPrefix[$m[1]]} and {$name}");
            }
            $byPrefix[$m[1]] = $name;
            $names[] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** Зміст файлу: BOM знято, \r\n → \n (на Windows git може віддати CRLF). */
    public static function read(string $directory, string $name): string
    {
        $content = file_get_contents(rtrim($directory, '/\\') . '/' . $name);
        if ($content === false) {
            throw new RuntimeException("Cannot read migration file: {$name}");
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        return str_replace("\r\n", "\n", $content);
    }

    /**
     * Без побічних ефектів: таблицю migrations не створює.
     *
     * @return array{applied: list<string>, pending: list<string>}
     */
    public function status(): array
    {
        $applied = $this->tableExists() ? $this->appliedNames() : [];
        $pending = array_values(array_diff(self::files($this->directory), $applied));

        return ['applied' => $applied, 'pending' => $pending];
    }

    /**
     * @return list<string> імена міграцій, застосованих саме цим запуском
     */
    public function up(): array
    {
        // Advisory-lock: два одночасні запуски не повинні застосовувати одну міграцію двічі
        $lock = $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 10)");
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration is running');
        }

        try {
            $this->pdo->exec(self::TABLE_DDL);
            $applied = $this->appliedNames();
            $done = [];

            foreach (self::files($this->directory) as $name) {
                if (in_array($name, $applied, true)) {
                    continue;
                }
                $this->apply($name);
                $done[] = $name;
            }

            return $done;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')")->fetchColumn();
        }
    }

    private function apply(string $name): void
    {
        $statements = SqlSplitter::split(self::read($this->directory, $name));
        if ($statements === []) {
            throw new RuntimeException("Migration {$name} contains no statements");
        }

        // DDL у MySQL неатомарний (неявний COMMIT), тож транзакція не допоможе: при збої міграція
        // не записується в migrations, а повторний запуск доробить решту завдяки IF NOT EXISTS.
        foreach ($statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf('Migration %s failed on statement #%d: %s', $name, $index + 1, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        $insert = $this->pdo->prepare('INSERT INTO `migrations` (`name`, `applied_at`) VALUES (?, UTC_TIMESTAMP())');
        $insert->execute([$name]);
    }

    private function tableExists(): bool
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations'"
        );

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return list<string>
     */
    private function appliedNames(): array
    {
        return $this->pdo->query('SELECT `name` FROM `migrations` ORDER BY `name`')->fetchAll(PDO::FETCH_COLUMN);
    }
}
