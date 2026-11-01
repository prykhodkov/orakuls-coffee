<?php

declare(strict_types=1);

// Перевірки схеми, міграцій і сіду (BE-002). Підключається з tests/api.php, коли БД уже накочена й засіяна.

use App\Database\Seeder;
use App\Database\SqlSplitter;
use App\Domain\Categories;
use App\Support\Iso8601;

const SEED_FILE = APP_ROOT . '/database/seed/phrases.php';

/** @return array<string, array<string, mixed>> колонки phrases за іменем */
function phraseColumns(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS'
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'phrases'"
    )->fetchAll();

    return array_column($rows, null, 'COLUMN_NAME');
}

test('міграції створили login_attempts, migrations і phrases; у migrations записані всі файли міграцій', function () {
    $pdo = testPdo();
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    assertSame(['login_attempts', 'migrations', 'phrases'], $tables);
    assertSame(
        App\Database\Migrator::files(APP_ROOT . '/database/migrations'),
        $pdo->query('SELECT name FROM migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)
    );
});

test('повторний up() повертає [] і не чіпає дані та облік міграцій', function () {
    $pdo = testPdo();
    $before = phraseCount($pdo);
    $appliedAt = $pdo->query('SELECT applied_at FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

    assertSame([], testMigrator()->up());
    assertSame([], testMigrator()->up());

    assertSame($before, phraseCount($pdo), 'кількість фраз');
    assertSame(
        count(App\Database\Migrator::files(APP_ROOT . '/database/migrations')),
        (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn(),
        'рядків у migrations'
    );
    assertSame($appliedAt, $pdo->query('SELECT applied_at FROM migrations')->fetchAll(PDO::FETCH_COLUMN));
});

test('status(): pending порожній, applied дорівнює списку файлів міграцій', function () {
    $status = testMigrator()->status();

    assertSame([], $status['pending']);
    assertSame(App\Database\Migrator::files(APP_ROOT . '/database/migrations'), $status['applied']);
});

test('login_attempts: типи, NOT NULL, attempted_at без DEFAULT, індекс (ip, attempted_at), InnoDB, utf8mb4_unicode_ci', function () {
    $pdo = testPdo();
    $columns = array_column($pdo->query(
        'SELECT COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS'
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'"
    )->fetchAll(), null, 'COLUMN_NAME');

    assertSame(['id', 'ip', 'attempted_at'], array_keys($columns), 'набір колонок');
    assertSame('bigint(20) unsigned', $columns['id']['COLUMN_TYPE']);
    assertTrue(str_contains($columns['id']['EXTRA'], 'auto_increment'), 'id AUTO_INCREMENT');
    assertSame('varchar(45)', $columns['ip']['COLUMN_TYPE']);
    assertSame('NO', $columns['ip']['IS_NULLABLE']);
    assertSame('utf8mb4_unicode_ci', $columns['ip']['COLLATION_NAME']);
    assertSame('datetime', $columns['attempted_at']['COLUMN_TYPE']);
    assertSame('NO', $columns['attempted_at']['IS_NULLABLE']);
    assertSame(null, $columns['attempted_at']['COLUMN_DEFAULT'], 'attempted_at: DEFAULT має бути відсутній');

    $index = $pdo->query(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
        . " AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_ip_time' ORDER BY SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['ip', 'attempted_at'], $index, 'індекс по (ip, attempted_at) у цьому порядку');

    $table = $pdo->query(
        "SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'"
    )->fetch();
    assertSame('InnoDB', $table['ENGINE']);
    assertSame('utf8mb4_unicode_ci', $table['TABLE_COLLATION']);
});

test('phrases: InnoDB, utf8mb4_unicode_ci у таблиці й усіх текстових колонках', function () {
    $pdo = testPdo();
    $table = $pdo->query(
        "SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'phrases'"
    )->fetch();

    assertSame('InnoDB', $table['ENGINE']);
    assertSame('utf8mb4_unicode_ci', $table['TABLE_COLLATION']);

    foreach (['category', 'text_lv', 'text_en', 'text_ru'] as $column) {
        assertSame('utf8mb4_unicode_ci', phraseColumns($pdo)[$column]['COLLATION_NAME'], $column);
    }
});

test('phrases: типи колонок, nullable і default', function () {
    $columns = phraseColumns(testPdo());

    assertSame('varchar(16)', $columns['category']['COLUMN_TYPE']);
    assertSame('NO', $columns['category']['IS_NULLABLE']);
    foreach (['text_lv', 'text_en', 'text_ru'] as $column) {
        assertSame('varchar(500)', $columns[$column]['COLUMN_TYPE'], $column);
        assertSame('NO', $columns[$column]['IS_NULLABLE'], $column);
        assertSame('', $columns[$column]['COLUMN_DEFAULT'], $column);
    }
    assertSame('tinyint(1)', $columns['is_active']['COLUMN_TYPE']);
    assertSame('NO', $columns['is_active']['IS_NULLABLE']);
    assertSame('1', $columns['is_active']['COLUMN_DEFAULT']);
    foreach (['created_at', 'updated_at'] as $column) {
        assertSame('datetime', $columns[$column]['COLUMN_TYPE'], $column);
        assertSame('NO', $columns[$column]['IS_NULLABLE'], $column);
        assertSame(null, $columns[$column]['COLUMN_DEFAULT'], "{$column}: DEFAULT має бути відсутній");
    }
});

test('діакритика, кирилиця, емодзі та \n записуються й читаються без спотворень', function () {
    $pdo = testPdo();
    $ids = reseed();
    $stmt = $pdo->prepare('SELECT text_lv, text_en, text_ru FROM phrases WHERE id = ?');

    $stmt->execute([$ids['diacritics']]);
    assertSame(
        ['text_lv' => 'Šodien veiksme būs tavā pusē.', 'text_en' => 'Luck is on your side today.', 'text_ru' => 'Сегодня удача на твоей стороне.'],
        $stmt->fetch()
    );

    $stmt->execute([$ids['emoji']]);
    assertSame('🍀 Luck loves you 💛', $stmt->fetch()['text_en']);

    $stmt->execute([$ids['multiline']]);
    assertSame("Line one\nLine two", $stmt->fetch()['text_en']);

    // Усі рядки сіду після прогону через БД збігаються з джерелом байт у байт
    foreach (Seeder::load(SEED_FILE) as $row) {
        $stmt->execute([$ids[$row['key']]]);
        assertSame(['text_lv' => $row['lv'], 'text_en' => $row['en'], 'text_ru' => $row['ru']], $stmt->fetch(), $row['key']);
    }
});

test('len500: рівно 500 символів (у т.ч. 4-байтових) зберігаються повністю', function () {
    $pdo = testPdo();
    $ids = reseed();

    $row = $pdo->query(
        "SELECT CHAR_LENGTH(text_lv) AS lv, CHAR_LENGTH(text_en) AS en, text_en FROM phrases WHERE id = {$ids['len500']}"
    )->fetch();

    assertSame(500, $row['lv']);
    assertSame(500, $row['en']);
    assertSame(str_repeat('😀', 500), $row['text_en']);
});

test('501-й символ відхиляється помилкою 1406 (strict mode), а не обрізається мовчки', function () {
    $pdo = testPdo();
    $before = phraseCount($pdo);

    try {
        $e = assertThrows(function () use ($pdo) {
            $pdo->prepare(
                'INSERT INTO phrases (category, text_lv, text_en, text_ru, is_active, created_at, updated_at)'
                . " VALUES ('love', ?, '', '', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([str_repeat('😀', 501)]);
        }, PDOException::class);

        assertSame(1406, $e->errorInfo[1] ?? null);
        assertSame('22001', $e->getCode());
        assertSame($before, phraseCount($pdo), 'рядок не з\'явився');
    } finally {
        reseed(); // невдалий INSERT міг зсунути AUTO_INCREMENT
    }
});

test('індекси на category та is_active існують', function () {
    $indexed = testPdo()->query(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'phrases'"
        . " AND SEQ_IN_INDEX = 1 AND INDEX_NAME <> 'PRIMARY'"
    )->fetchAll(PDO::FETCH_COLUMN);

    assertTrue(in_array('category', $indexed, true), 'індекс за category');
    assertTrue(in_array('is_active', $indexed, true), 'індекс за is_active');
});

test('UTC_TIMESTAMP() у БД збігається з gmdate(); Iso8601::fromDb() дає суфікс Z', function () {
    $pdo = testPdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "INSERT INTO phrases (category, text_lv, text_en, text_ru, is_active, created_at, updated_at)"
            . " VALUES ('love', 'utc', '', '', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $created = (string) $pdo->query("SELECT created_at FROM phrases WHERE text_lv = 'utc'")->fetchColumn();
    } finally {
        $pdo->rollBack();
        reseed();
    }

    assertTrue(abs(strtotime($created . ' UTC') - time()) <= 5, "created_at={$created}, gmdate=" . gmdate('Y-m-d H:i:s'));
    $iso = Iso8601::fromDb($created);
    assertTrue(str_ends_with($iso, 'Z'), $iso);
    assertSame(str_replace(' ', 'T', $created) . 'Z', $iso);
});

test('типи з БД: id і is_active — int (не рядок і не bool); DATETIME — рядок', function () {
    $row = testPdo()->query('SELECT id, is_active, created_at FROM phrases ORDER BY id LIMIT 1')->fetch();

    assertSame(1, $row['id']);
    assertSame(1, $row['is_active']);
    assertSame(Seeder::CREATED_AT, $row['created_at']);
});

test('сід детермінований: двічі reseed() дає ту саму карту key → id; id з 1 у порядку файлу', function () {
    $first = reseed();
    $second = reseed();
    $keys = array_column(Seeder::load(SEED_FILE), 'key');

    assertSame($first, $second);
    assertSame($keys, array_keys($first), 'порядок ключів = порядок файлу');
    assertSame(range(1, count($keys)), array_values($first), 'id починаються з 1');
    assertSame(count($keys), phraseCount(testPdo()));
});

test('склад сіду: усі обов\'язкові випадки на місці', function () {
    $pdo = testPdo();
    $ids = reseed();
    $rows = $pdo->query('SELECT * FROM phrases ORDER BY id')->fetchAll();
    $byId = array_column($rows, null, 'id');

    // Кожна з 6 категорій: ≥ 3 активні фрази з трьома мовами
    foreach (Categories::CODES as $category) {
        $full = array_filter($rows, static fn (array $r): bool => $r['category'] === $category
            && $r['is_active'] === 1 && $r['text_lv'] !== '' && $r['text_en'] !== '' && $r['text_ru'] !== '');
        assertTrue(count($full) >= 3, "категорія {$category}: активних повних фраз " . count($full));
    }

    assertSame(0, $byId[$ids['hidden']]['is_active']);
    assertSame('', $byId[$ids['only_lv']]['text_en']);
    assertSame('', $byId[$ids['only_lv']]['text_ru']);
    assertSame('', $byId[$ids['only_en']]['text_lv']);
    assertSame('', $byId[$ids['no_ru']]['text_ru']);
    assertSame('   ', $byId[$ids['blank_en']]['text_en']);
    assertSame('', trim($byId[$ids['blank_en']]['text_en']));
    assertSame(Seeder::CREATED_AT, $byId[$ids['love_1']]['created_at']);
    assertSame(Seeder::CREATED_AT, $byId[$ids['love_1']]['updated_at']);
});

test('пошук нечутливий до регістру й діакритики (D-13): sodien знаходить Šodien, тест — Тест', function () {
    $pdo = testPdo();
    $ids = reseed();
    $find = $pdo->prepare('SELECT id FROM phrases WHERE text_lv LIKE ? OR text_ru LIKE ? ORDER BY id');

    $find->execute(['%sodien%', '%sodien%']);
    assertTrue(in_array($ids['diacritics'], $find->fetchAll(PDO::FETCH_COLUMN), true), 'sodien');

    $find->execute(['%тест%', '%тест%']);
    assertSame([$ids['cyrillic_case']], $find->fetchAll(PDO::FETCH_COLUMN), 'тест');
});

test('міграція з помилкою в другому операторі не ковтається, не потрапляє в migrations, а повтор доробляє решту', function () {
    $pdo = testPdo();
    $dir = sys_get_temp_dir() . '/orakuls-mig-fail-' . getmypid();
    mkdir($dir);
    $file = $dir . '/900_probe.sql';

    try {
        // Другий оператор падає: саме цей випадок PDO::exec() з кількома операторами пропускає мовчки
        file_put_contents($file, "CREATE TABLE IF NOT EXISTS `probe` (id INT);\nINSERT INTO `no_such_table` VALUES (1);\n");
        $migrator = new App\Database\Migrator($pdo, $dir);

        $e = assertThrows(fn () => $migrator->up(), RuntimeException::class);
        assertTrue(str_contains($e->getMessage(), '900_probe.sql') && str_contains($e->getMessage(), 'statement #2'), $e->getMessage());
        assertSame(['900_probe.sql'], $migrator->status()['pending'], 'невдала міграція не записана в migrations');

        // З'єднання лишається придатним (немає 2014 pending result sets)
        assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
        assertSame(
            App\Database\Migrator::files(APP_ROOT . '/database/migrations'),
            $migrator->status()['applied'],
            '900_probe не записана, лише реальні міграції'
        );

        // Виправлений файл при повторному запуску доробляє решту й застосовується
        file_put_contents($file, "CREATE TABLE IF NOT EXISTS `probe` (id INT);\nINSERT INTO `probe` VALUES (1);\n");
        assertSame(['900_probe.sql'], $migrator->up());
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS `probe`');
        $pdo->exec("DELETE FROM migrations WHERE name = '900_probe.sql'");
        @unlink($file);
        @rmdir($dir);
    }
});

// Паритет має бути останнім у файлі: він скидає БД і в кінці відновлює стандартний стан.
test('паритет: schema.sql дає ту саму схему, що й послідовність міграцій; облік міграцій збігається', function () {
    $pdo = testPdo();

    try {
        $fromMigrations = schemaSignature($pdo);

        resetTestDatabase($pdo);
        $schema = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/database/schema.sql'));
        foreach (SqlSplitter::split($schema) as $statement) {
            $pdo->exec($statement); // по одному оператору: exec() з кількома ковтає помилки
        }
        $fromSchema = schemaSignature($pdo);

        assertSame($fromMigrations, $fromSchema);
        assertSame(
            App\Database\Migrator::files(APP_ROOT . '/database/migrations'),
            $pdo->query('SELECT name FROM migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN),
            'імпортований schema.sql має записати застосовані міграції'
        );
        assertSame([], testMigrator()->up(), 'після імпорту schema.sql runner нічого не застосовує вдруге');
    } finally {
        resetTestDatabase($pdo);
        testMigrator()->up();
        reseed();
    }
});
