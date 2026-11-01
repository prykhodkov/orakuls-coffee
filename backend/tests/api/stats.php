<?php

declare(strict_types=1);

// Перевірки зведення для адмінки (BE-008): GET /api/admin/stats.
// Підключається з tests/api.php. Файл самодостатній: власні помічники з префіксом stats*, не залежить від інших
// тест-файлів. Тести, що пишуть у БД, обгорнуті у withFreshSeed (tests/db-lib.php): сід (30 фраз, id 1…30)
// відновлюється до і після. НЕ викликати withFreshSeed усередині withBigTable і навпаки (TRUNCATE комітить транзакцію).
//
// Очікування виводяться з даних сіду (незалежний «оракул» у PHP) і зі списку GET /api/admin/phrases:
// число в плитці адмінки мусить збігатися з total списку з відповідним фільтром.

use App\Database\Seeder;
use App\Domain\Categories;
use App\Domain\Languages;
use App\Http\Response;

const STATS_PATH = '/api/admin/stats';
const STATS_SEED_FILE = APP_ROOT . '/database/seed/phrases.php';

/** Токен адміна: один раз на процес (інакше вхід рахувався б у statementCount). */
function statsToken(): string
{
    static $token = null;
    $token ??= authToken();

    return $token;
}

/** Запит до будь-якого шляху; токен за замовчуванням — адміна, null → без заголовка Authorization. */
function statsCall(string $method, string $path, array $query = [], ?string $token = null, bool $anonymous = false): Response
{
    $headers = $anonymous ? [] : ['authorization' => 'Bearer ' . ($token ?? statsToken())];

    return apiRequest($method, $path, $query, $headers);
}

function statsGet(array $query = []): Response
{
    return statsCall('GET', STATS_PATH, $query);
}

function statsAnonymous(array $query = []): Response
{
    return statsCall('GET', STATS_PATH, $query, null, true);
}

/** Розібране тіло відповіді 200. */
function statsOf(array $query = []): array
{
    $response = statsGet($query);
    assertSame(200, $response->status, (string) $response->body);

    return decode($response);
}

/** total зі списку GET /api/admin/phrases із заданим фільтром (perPage=1: потрібен лише total). */
function statsListTotal(array $query): int
{
    $response = statsCall('GET', '/api/admin/phrases', $query + ['perPage' => '1']);
    assertSame(200, $response->status, (string) $response->body);

    return decode($response)['total'];
}

/** Запис фрази через API: POST/PUT з тілом {category, text, isActive?}. */
function statsWrite(string $method, string $path, array $body): Response
{
    return apiRequest(
        $method,
        $path,
        [],
        ['authorization' => 'Bearer ' . statsToken(), 'content-type' => 'application/json'],
        json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Порожній переклад в оракулі: лише пробіли, як TRIM у MySQL (trim() у PHP обрізав би ще табуляцію й переноси).
 */
function statsIsBlank(?string $text): bool
{
    return trim((string) $text, ' ') === '';
}

/**
 * Оракул: зведення, обчислене в PHP з рядків (формат Seeder::load: category, active, lv, en, ru).
 *
 * @param list<array<string, mixed>> $rows
 * @return array{total: int, active: int, byCategory: array<string, int>, missing: array<string, int>}
 */
function statsOracle(array $rows): array
{
    $stats = [
        'total' => count($rows),
        'active' => 0,
        'byCategory' => array_fill_keys(Categories::CODES, 0),
        'missing' => array_fill_keys(Languages::CODES, 0),
    ];
    foreach ($rows as $row) {
        if ($row['active'] ?? true) {
            $stats['active']++;
        }
        if (isset($stats['byCategory'][$row['category']])) {
            $stats['byCategory'][$row['category']]++;
        }
        foreach (Languages::CODES as $lang) {
            if (statsIsBlank($row[$lang] ?? '')) {
                $stats['missing'][$lang]++;
            }
        }
    }

    return $stats;
}

/** Оракул сіду. */
function statsSeedOracle(): array
{
    return statsOracle(Seeder::load(STATS_SEED_FILE));
}

/**
 * Кожне число зведення збігається з total списку з відповідним фільтром; сума byCategory == total.
 */
function statsAssertMatchesList(array $stats, string $note): void
{
    assertSame($stats['total'], statsListTotal([]), "{$note}: total == список без фільтрів");
    assertSame($stats['active'], statsListTotal(['isActive' => 'true']), "{$note}: active == ?isActive=true");
    foreach (Categories::CODES as $code) {
        assertSame($stats['byCategory'][$code], statsListTotal(['category' => $code]), "{$note}: byCategory.{$code} == ?category={$code}");
    }
    foreach (Languages::CODES as $lang) {
        assertSame($stats['missing'][$lang], statsListTotal(['missing' => $lang]), "{$note}: missing.{$lang} == ?missing={$lang}");
    }
    assertSame($stats['total'], array_sum($stats['byCategory']), "{$note}: сума byCategory == total");
}

/** Медіана часу виконання $fn у мілісекундах за $n викликів. */
function statsMedianMs(callable $fn, int $n): float
{
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[intdiv($n, 2)];
}

/** Копія зведення з прирістами: $delta — ['total' => 1, 'byCategory.love' => -1, 'missing.en' => 1 …]. */
function statsShift(array $stats, array $delta): array
{
    foreach ($delta as $path => $by) {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            $stats[$parts[0]] += $by;
        } else {
            $stats[$parts[0]][$parts[1]] += $by;
        }
    }

    return $stats;
}

// ---------- Захист ----------

test('stats: без токена → 401 unauthorized (і з query), жодного SQL', function () {
    statsToken(); // токен отримано до замірів

    authAssertUnauthorized(statsAnonymous(), 'без токена');
    authAssertUnauthorized(statsAnonymous(['category' => 'love']), 'без токена, з query');
    authAssertUnauthorized(statsCall('GET', STATS_PATH, [], 'garbage.token.value'), 'сміттєвий токен');

    assertSame(0, statementCount(static fn () => statsAnonymous()), '401: жодного SQL');
    assertSame(0, statementCount(static fn () => statsAnonymous(['category' => 'love'])), '401 з query: жодного SQL');
});

// ---------- Форма і типи ----------

test('stats: форма й типи — ключі total, active, byCategory, missing у порядку; усе int; об\'єкти у сирому JSON; no-store', function () {
    $response = statsGet();
    assertSame(200, $response->status, (string) $response->body);
    $data = decode($response);
    $body = (string) $response->body;

    assertSame(['total', 'active', 'byCategory', 'missing'], array_keys($data), 'ключі відповіді');
    assertSame(true, is_int($data['total']), 'total — int');
    assertSame(true, is_int($data['active']), 'active — int');
    assertSame(['love', 'money', 'luck', 'work', 'travel', 'mood'], array_keys($data['byCategory']), 'ключі byCategory (порядок)');
    assertSame(['lv', 'en', 'ru'], array_keys($data['missing']), 'ключі missing (порядок)');
    foreach ($data['byCategory'] as $code => $value) {
        assertSame(true, is_int($value), "byCategory.{$code} — int, не рядок");
    }
    foreach ($data['missing'] as $lang => $value) {
        assertSame(true, is_int($value), "missing.{$lang} — int, не рядок");
    }

    assertTrue(str_contains($body, '"byCategory":{'), 'byCategory у сирому JSON — об\'єкт: ' . $body);
    assertTrue(str_contains($body, '"missing":{'), 'missing у сирому JSON — об\'єкт: ' . $body);
    assertTrue(preg_match('/"(total|active|love|money|luck|work|travel|mood|lv|en|ru)":"/', $body) !== 1, 'у сирому JSON немає чисел у лапках: ' . $body);

    assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    assertSame('application/json; charset=utf-8', $response->headers['Content-Type'] ?? null);
});

// ---------- Значення ----------

test('stats: сід збігається з оракулом, обчисленим у PHP з даних сіду; прихована фраза і en з пробілів враховані', function () {
    withFreshSeed(function () {
        $rows = Seeder::load(STATS_SEED_FILE);
        $expected = statsOracle($rows);

        // Захист від беззубого тесту: якщо сід зміниться, ці умови перестануть виконуватись
        assertTrue($expected['active'] < $expected['total'], 'у сіді є прихована фраза');
        $blank = array_values(array_filter($rows, static fn (array $row): bool => $row['key'] === 'blank_en'));
        assertSame(1, count($blank), 'у сіді є blank_en');
        assertTrue($blank[0]['en'] !== '' && statsIsBlank($blank[0]['en']), 'en у blank_en складається лише з пробілів');
        assertTrue($expected['missing']['en'] >= 1 && $expected['missing']['ru'] >= 1 && $expected['missing']['lv'] >= 1, 'є фрази без кожного з перекладів');

        $actual = statsOf();
        assertSame($expected['total'], $actual['total'], 'total');
        assertSame($expected['active'], $actual['active'], 'active');
        foreach (Categories::CODES as $code) {
            assertSame($expected['byCategory'][$code], $actual['byCategory'][$code], "byCategory.{$code}");
        }
        foreach (Languages::CODES as $lang) {
            assertSame($expected['missing'][$lang], $actual['missing'][$lang], "missing.{$lang}");
        }
        assertSame($expected, $actual, 'повна відповідь');
    });
});

test('stats: порожня БД → total 0, active 0, усі 6 категорій і 3 мови = 0; у сирому JSON об\'єкти, не []; int, не null', function () {
    withFreshSeed(function () {
        statsToken();
        testPdo()->exec('DELETE FROM `phrases`');
        assertSame(0, phraseCount(testPdo()));

        $response = statsGet();
        assertSame(200, $response->status, (string) $response->body);
        $data = decode($response);
        $body = (string) $response->body;

        assertSame(['total', 'active', 'byCategory', 'missing'], array_keys($data));
        assertSame(0, $data['total']);
        assertSame(0, $data['active']);
        assertSame(['love' => 0, 'money' => 0, 'luck' => 0, 'work' => 0, 'travel' => 0, 'mood' => 0], $data['byCategory']);
        assertSame(['lv' => 0, 'en' => 0, 'ru' => 0], $data['missing']);
        assertTrue(str_contains($body, '"byCategory":{"love":0,'), 'byCategory — об\'єкт: ' . $body);
        assertTrue(str_contains($body, '"missing":{"lv":0,'), 'missing — об\'єкт: ' . $body);
        assertTrue(!str_contains($body, '[]') && !str_contains($body, 'null'), 'немає [] і null: ' . $body);
    });
});

test('stats: числа збігаються з total списку фраз з відповідним фільтром (total, isActive, category, missing); сума byCategory == total', function () {
    withFreshSeed(function () {
        statsAssertMatchesList(statsOf(), 'сід');
    });
});

test('stats: переклад із самих пробілів вважається порожнім: blank_en входить у missing.en і в ?missing=en', function () {
    withFreshSeed(function () {
        $blankId = (int) testPdo()->query("SELECT `id` FROM `phrases` WHERE `text_en` = '   '")->fetchColumn();
        assertTrue($blankId > 0, 'фраза з en із трьох пробілів є в БД');

        $list = decode(statsCall('GET', '/api/admin/phrases', ['missing' => 'en', 'perPage' => '100']));
        $ids = array_column($list['items'], 'id');
        assertTrue(in_array($blankId, $ids, true), '?missing=en містить blank_en');
        assertSame($list['total'], statsOf()['missing']['en'], 'missing.en == total ?missing=en');
    });

    // У колації utf8mb4_unicode_ci (PAD SPACE) '   ' = '' істинне й без TRIM, тож поведінкою цю умову не відрізнити від
    // спрощеної; фіксуємо хоча б сам вигляд умови, щоб TRIM не зник непомітно (він потрібен при іншій колації)
    assertSame('TRIM(`text_en`) = \'\'', \App\Repository\PhraseRepository::lacksTextSql('en'), 'умова порожнього перекладу');
});

test('stats: прихована фраза з порожніми en і ru → total+1, active без змін, категорія+1, missing.en+1, missing.ru+1, missing.lv без змін', function () {
    withFreshSeed(function () {
        $before = statsOf();

        $response = statsWrite('POST', '/api/admin/phrases', [
            'category' => 'work',
            'text' => ['lv' => 'Slēpta bez tulkojumiem', 'en' => '', 'ru' => ''],
            'isActive' => false,
        ]);
        assertSame(201, $response->status, (string) $response->body);

        $after = statsOf();
        assertSame(
            statsShift($before, ['total' => 1, 'byCategory.work' => 1, 'missing.en' => 1, 'missing.ru' => 1]),
            $after,
            'зсув'
        );
        assertSame($before['active'], $after['active'], 'active без змін');
        assertSame($before['missing']['lv'], $after['missing']['lv'], 'missing.lv без змін');
        statsAssertMatchesList($after, 'після POST');
    });
});

test('stats: зміни видно одразу без кешу — POST, PUT (категорія, isActive, очищення en), DELETE, імпорт; кожен крок == точний зсув == список', function () {
    withFreshSeed(function () {
        $baseline = statsOf();
        $step = static function (array $expected, string $note): array {
            $actual = statsOf();
            assertSame($expected, $actual, $note);
            statsAssertMatchesList($actual, $note);

            return $actual;
        };

        // 1. POST нової фрази: travel, без ru
        $created = statsWrite('POST', '/api/admin/phrases', ['category' => 'travel', 'text' => ['lv' => 'Jauna', 'en' => 'New', 'ru' => '']]);
        assertSame(201, $created->status, (string) $created->body);
        $id = decode($created)['id'];
        $path = "/api/admin/phrases/{$id}";
        $expected = statsShift($baseline, ['total' => 1, 'active' => 1, 'byCategory.travel' => 1, 'missing.ru' => 1]);
        $step($expected, 'POST');

        // 2. PUT: категорія travel → money
        $response = statsWrite('PUT', $path, ['category' => 'money', 'text' => ['lv' => 'Jauna', 'en' => 'New', 'ru' => '']]);
        assertSame(200, $response->status, (string) $response->body);
        $expected = statsShift($expected, ['byCategory.travel' => -1, 'byCategory.money' => 1]);
        $step($expected, 'PUT категорія');

        // 3. PUT: isActive → false
        $response = statsWrite('PUT', $path, ['category' => 'money', 'text' => ['lv' => 'Jauna', 'en' => 'New', 'ru' => ''], 'isActive' => false]);
        assertSame(200, $response->status, (string) $response->body);
        $expected = statsShift($expected, ['active' => -1]);
        $step($expected, 'PUT isActive=false');

        // 4. PUT: очищення en
        $response = statsWrite('PUT', $path, ['category' => 'money', 'text' => ['lv' => 'Jauna', 'en' => '', 'ru' => ''], 'isActive' => false]);
        assertSame(200, $response->status, (string) $response->body);
        $expected = statsShift($expected, ['missing.en' => 1]);
        $step($expected, 'PUT очищення en');

        // 5. PUT існуючої blank_en: заповнення en → missing.en-1
        $blankId = (int) testPdo()->query("SELECT `id` FROM `phrases` WHERE `text_en` = '   '")->fetchColumn();
        $blank = decode(statsCall('GET', "/api/admin/phrases/{$blankId}"));
        $response = statsWrite('PUT', "/api/admin/phrases/{$blankId}", [
            'category' => $blank['category'],
            'text' => ['en' => 'Filled in'] + $blank['text'],
            'isActive' => $blank['isActive'],
        ]);
        assertSame(200, $response->status, (string) $response->body);
        $expected = statsShift($expected, ['missing.en' => -1]);
        $step($expected, 'PUT заповнення blank_en');

        // 6. DELETE створеної фрази (money, неактивна, без en і ru)
        $response = statsCall('DELETE', $path);
        assertSame(204, $response->status, (string) $response->body);
        $expected = statsShift($expected, ['total' => -1, 'byCategory.money' => -1, 'missing.en' => -1, 'missing.ru' => -1]);
        $step($expected, 'DELETE');
        assertSame(statsShift($baseline, ['missing.en' => -1]), $expected, 'після видалення — сід, у якому blank_en заповнено');

        // 7. Імпорт 3 елементів: love повна, mood без ru, work повна
        $response = statsWrite('POST', '/api/admin/phrases/import', ['items' => [
            ['category' => 'love', 'text' => ['lv' => 'I1', 'en' => 'I1', 'ru' => 'I1']],
            ['category' => 'mood', 'text' => ['lv' => 'I2', 'en' => 'I2']],
            ['category' => 'work', 'text' => ['lv' => 'I3', 'en' => 'I3', 'ru' => 'I3']],
        ]]);
        assertSame(200, $response->status, (string) $response->body);
        assertSame(3, decode($response)['created']);
        $expected = statsShift($expected, ['total' => 3, 'active' => 3, 'byCategory.love' => 1, 'byCategory.mood' => 1, 'byCategory.work' => 1, 'missing.ru' => 1]);
        $step($expected, 'імпорт');
    });
});

test('stats: рівно один SQL-запит — на сіді і на порожній БД', function () {
    withFreshSeed(function () {
        statsToken();
        statsGet(); // прогрів

        assertSame(1, statementCount(static fn () => statsGet()), 'сід');
        assertSame(1, statementCount(static fn () => statsGet(['category' => 'love'])), 'сід, з query');

        testPdo()->exec('DELETE FROM `phrases`');
        assertSame(1, statementCount(static fn () => statsGet()), 'порожня БД');
    });
});

test('stats: 600 фраз — значення за оракулом, узгоджені зі списком, медіана 20 запитів < 200 мс', function () {
    statsToken();
    withBigTable(function () {
        // withBigTable додає 570 активних love-фраз із lv і порожніми en та ru
        $seed = statsSeedOracle();
        $expected = statsShift($seed, [
            'total' => 570, 'active' => 570, 'byCategory.love' => 570, 'missing.en' => 570, 'missing.ru' => 570,
        ]);
        assertSame(600, $expected['total']);
        assertSame(575, $expected['byCategory']['love']);

        $actual = statsOf();
        assertSame($expected, $actual, 'значення на 600 фразах');
        statsAssertMatchesList($actual, '600 фраз');

        $median = statsMedianMs(static function (): void {
            $response = statsGet();
            assertSame(200, $response->status);
        }, 20);
        assertTrue($median < 200.0, sprintf('медіана %.2f мс, очікувано < 200 мс', $median));
    });
});

// ---------- Параметри і методи ----------

test('stats: зайві query-параметри ігноруються (category, isActive, missing, x, category[]) — тіло те саме, що без параметрів', function () {
    withFreshSeed(function () {
        $plain = statsGet();
        assertSame(200, $plain->status);

        $withScalars = statsGet(['category' => 'love', 'isActive' => 'false', 'missing' => 'en', 'x' => '1']);
        assertSame(200, $withScalars->status, (string) $withScalars->body);
        assertSame($plain->body, $withScalars->body, 'скалярні параметри');

        $withArray = statsGet(['category' => ['x'], 'isActive' => ['y']]); // ?category[]=x — для цього ендпоінта не помилка
        assertSame(200, $withArray->status, (string) $withArray->body);
        assertSame($plain->body, $withArray->body, 'параметри-масиви');

        $invalid = statsGet(['category' => 'nope', 'perPage' => '0', 'page' => 'abc']);
        assertSame(200, $invalid->status, (string) $invalid->body);
        assertSame($plain->body, $invalid->body, 'недійсні значення фільтрів списку не діють');
    });
});

test('stats: POST, PUT, DELETE, PATCH на /api/admin/stats → 404 not_found; /api/admin/stats/1 → 404', function () {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
        $response = statsCall($method, STATS_PATH);
        assertSame(404, $response->status, "{$method}: " . $response->body);
        assertSame('not_found', decode($response)['error']['code'], $method);
    }
    $response = statsCall('GET', STATS_PATH . '/1');
    assertSame(404, $response->status, (string) $response->body);
    assertSame('not_found', decode($response)['error']['code']);
});

// ---------- Документована поведінка ----------

test('stats: категорія поза списком (прямий INSERT) входить у total, але не в byCategory; помилки немає', function () {
    withFreshSeed(function () {
        $before = statsOf();

        testPdo()->exec(
            "INSERT INTO `phrases` (`category`, `text_lv`, `text_en`, `text_ru`, `is_active`, `created_at`, `updated_at`)"
            . " VALUES ('other', 'x', 'y', 'z', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        $after = statsOf();
        assertSame($before['total'] + 1, $after['total'], 'total+1');
        assertSame($before['active'] + 1, $after['active'], 'active+1');
        assertSame($before['byCategory'], $after['byCategory'], 'byCategory без змін');
        assertSame($after['total'] - 1, array_sum($after['byCategory']), 'сума byCategory на 1 менша за total');
        assertSame($before['missing'], $after['missing'], 'missing без змін');
    });
});
