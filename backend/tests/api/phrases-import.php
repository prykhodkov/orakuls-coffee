<?php

declare(strict_types=1);

// Перевірки масового імпорту фраз (BE-007): POST /api/admin/phrases/import.
// Підключається з tests/api.php. Файл самодостатній: власні помічники (importRequest, item, bulkItems, importRows …),
// не залежить від tests/api/phrases-write.php. Кожен тест, що пише в БД, обгорнутий у withFreshSeed (tests/db-lib.php):
// сід (30 фраз, id 1…30) відновлюється до і після. НЕ викликати withFreshSeed усередині іншого withFreshSeed.

use App\Domain\Categories;
use App\Http\Response;
use App\Repository\PhraseRepository;

const IMPORT_PATH = '/api/admin/phrases/import';
const IMPORT_SEED_COUNT = 30;
const IMPORT_500_BODY = '{"error":{"code":"server_error","message":"Internal server error"}}';

/**
 * POST імпорту. Масив кодується в JSON, рядок іде як є (зламаний JSON, `{"items":{}}`).
 * Токен адміна за замовчуванням береться один раз на процес (інакше вхід рахувався б у statementCount).
 *
 * @param array<string, mixed>|string|null $body
 */
function importRequest(array|string|null $body, ?string $token = null): Response
{
    static $adminToken = null;
    $adminToken ??= authToken();

    return importSend($body, $token ?? $adminToken);
}

/** Той самий запит без заголовка Authorization. */
function importAnonymous(array|string|null $body): Response
{
    return importSend($body, null);
}

function importSend(array|string|null $body, ?string $token): Response
{
    $headers = ['content-type' => 'application/json'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (string) $body;

    return apiRequest('POST', IMPORT_PATH, [], $headers, $raw);
}

/** GET з токеном адміна. */
function importGet(string $path, array $query = []): Response
{
    static $token = null;
    $token ??= authToken();

    return apiRequest('GET', $path, $query, ['authorization' => 'Bearer ' . $token]);
}

/** Елемент імпорту: категорія love і три короткі тексти, якщо не задано інше. */
function item(string $category = 'love', array $text = ['lv' => 'Sveiki', 'en' => 'Hello', 'ru' => 'Привет']): array
{
    return ['category' => $category, 'text' => $text];
}

/** n валідних елементів з унікальними короткими текстами; категорії йдуть по колу. */
function bulkItems(int $n): array
{
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        $items[] = item(Categories::CODES[$i % count(Categories::CODES)], [
            'lv' => "Imports {$i}",
            'en' => "Import {$i}",
            'ru' => "Імпорт номер {$i}",
        ]);
    }

    return $items;
}

/** Відповідь 200 → розібране тіло (рівно ключі created, errors). */
function importOk(Response $response): array
{
    assertSame(200, $response->status, (string) $response->body);
    $data = decode($response);
    assertSame(['created', 'errors'], array_keys($data), 'ключі відповіді');
    assertSame(true, is_int($data['created']), 'created — ціле');
    assertSame(true, is_array($data['errors']) && array_is_list($data['errors']), 'errors — список');

    return $data;
}

/** Нові (id > 30) рядки phrases за зростанням id. */
function importRows(): array
{
    return testPdo()->query('SELECT * FROM `phrases` WHERE `id` > ' . IMPORT_SEED_COUNT . ' ORDER BY `id`')->fetchAll();
}

/** 422 validation_error з точно такими fields (порядок ключів значущий), no-store. */
function importAssertRejected(Response $response, array $fields, string $note): void
{
    assertSame(422, $response->status, $note . ': ' . $response->body);
    $error = decode($response)['error'];
    assertSame('validation_error', $error['code'], $note);
    assertSame($fields, $error['fields'], $note);
    assertSame('no-store', $response->headers['Cache-Control'] ?? null, "{$note}: no-store");
}

/** Лічильники транзакцій цього з'єднання (START TRANSACTION / COMMIT / ROLLBACK). */
function importTxCounters(): array
{
    $rows = testPdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_begin', 'Com_commit', 'Com_rollback')")->fetchAll(PDO::FETCH_KEY_PAIR);

    return ['begin' => (int) $rows['Com_begin'], 'commit' => (int) $rows['Com_commit'], 'rollback' => (int) $rows['Com_rollback']];
}

/** Скільки транзакційних команд виконав $fn: [begin, commit, rollback]. */
function importTxDelta(callable $fn): array
{
    $before = importTxCounters();
    $fn();
    $after = importTxCounters();

    return [$after['begin'] - $before['begin'], $after['commit'] - $before['commit'], $after['rollback'] - $before['rollback']];
}

/** Кількість SQL-запитів (виконань prepared statement) під час імпорту $body. */
function importStatements(array|string|null $body): int
{
    return statementCount(static function () use ($body): void {
        importRequest($body);
    });
}

// ---------- Захист ----------

test('phrases-import: без токена → 401 навіть із порожнім і невалідним тілом, БД не змінюється', function () {
    withFreshSeed(function () {
        $before = tableDump();

        foreach ([[], ['items' => bulkItems(3)], ['items' => 5], 'not json', '', '{"items":{}}'] as $body) {
            authAssertUnauthorized(importAnonymous($body), 'без токена');
        }
        authAssertUnauthorized(importRequest(['items' => bulkItems(3)], 'garbage.token.value'), 'сміттєвий токен');

        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

// ---------- Щасливий шлях ----------

test('phrases-import: 600 валідних → 200, created 600, errors [] (масив), усі активні, createdAt = updatedAt, no-store', function () {
    withFreshSeed(function () {
        $response = importRequest(['items' => bulkItems(600)]);
        $data = importOk($response);

        assertSame(600, $data['created']);
        assertSame([], $data['errors']);
        assertTrue(str_contains((string) $response->body, '"errors":[]'), 'у сирому JSON errors — []: ' . substr((string) $response->body, 0, 80));
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        assertSame('application/json; charset=utf-8', $response->headers['Content-Type'] ?? null);

        assertSame(630, phraseCount(testPdo()));
        $rows = importRows();
        assertSame(600, count($rows));
        assertSame('Imports 0', $rows[0]['text_lv']);
        assertSame('Імпорт номер 599', $rows[599]['text_ru']);
        assertSame('love', $rows[0]['category']);
        assertSame('money', $rows[1]['category']);

        $bad = (int) testPdo()->query(
            'SELECT COUNT(*) FROM `phrases` WHERE `id` > ' . IMPORT_SEED_COUNT . ' AND (`is_active` <> 1 OR `created_at` <> `updated_at`'
            . ' OR ABS(TIMESTAMPDIFF(SECOND, `created_at`, UTC_TIMESTAMP())) > 10)'
        )->fetchColumn();
        assertSame(0, $bad, 'усі нові: is_active = 1, created_at = updated_at ≈ зараз (UTC)');
    });
});

test('phrases-import: помилки елементів — index = позиція в запиті (з 0), errors за зростанням index, у БД лише валідні', function () {
    withFreshSeed(function () {
        $items = [
            item('love', ['lv' => 'V0', 'en' => 'V0', 'ru' => 'V0']),             // 0 ок
            item('LOVE'),                                                         // 1 category invalid
            ['text' => ['lv' => 'a', 'en' => 'b', 'ru' => 'c']],                  // 2 category required
            item('love', ['lv' => '', 'en' => ' ', 'ru' => "\u{00A0}"]),          // 3 text empty
            item('money', ['lv' => str_repeat('a', 501), 'en' => 'b', 'ru' => 'c']), // 4 text.lv too_long
            item('luck', ['lv' => 'a', 'en' => 'b', 'ru' => 5]),                  // 5 text.ru invalid
            item('work', ['lv' => 'V6', 'en' => 'V6', 'ru' => 'V6']),             // 6 ок
            item('mood', ['lv' => 'V7', 'en' => 'V7', 'ru' => 'V7']),             // 7 ок
        ];
        $data = importOk(importRequest(['items' => $items]));

        assertSame(3, $data['created']);
        assertSame(
            [
                ['index' => 1, 'fields' => ['category' => 'invalid']],
                ['index' => 2, 'fields' => ['category' => 'required']],
                ['index' => 3, 'fields' => ['text' => 'empty']],
                ['index' => 4, 'fields' => ['text.lv' => 'too_long']],
                ['index' => 5, 'fields' => ['text.ru' => 'invalid']],
            ],
            $data['errors']
        );
        assertSame(count($items), $data['created'] + count($data['errors']), 'created + errors = items');

        $rows = importRows();
        assertSame(3, count($rows), 'кількість зросла рівно на created');
        assertSame(['V0', 'V6', 'V7'], array_column($rows, 'text_lv'));
        assertSame(33, phraseCount(testPdo()));
    });
});

test('phrases-import: кілька помилок в одному елементі — всі разом в одному fields у порядку category, text.lv', function () {
    withFreshSeed(function () {
        $data = importOk(importRequest(['items' => [['category' => 'x', 'text' => ['lv' => 5, 'en' => '', 'ru' => 'y']]]]));

        assertSame(0, $data['created']);
        assertSame([['index' => 0, 'fields' => ['category' => 'invalid', 'text.lv' => 'invalid']]], $data['errors']);
        assertSame(['category', 'text.lv'], array_keys($data['errors'][0]['fields']));
        assertSame(30, phraseCount(testPdo()));
    });
});

test('phrases-import: елемент не об\'єкт (число, рядок, null, bool, список) → item invalid; [] — порожній об\'єкт; валідні збережено', function () {
    withFreshSeed(function () {
        $items = [
            item('love', ['lv' => 'A', 'en' => 'A', 'ru' => 'A']), // 0
            5,                                                     // 1
            'x',                                                   // 2
            null,                                                  // 3
            true,                                                  // 4
            [1, 2],                                                // 5
            item('love', ['lv' => 'B', 'en' => 'B', 'ru' => 'B']), // 6
            [],                                                    // 7 порожній об'єкт ([] і {} однакові після json_decode)
            false,                                                 // 8
            item('love', ['lv' => 'C', 'en' => 'C', 'ru' => 'C']), // 9
        ];
        $data = importOk(importRequest(['items' => $items]));

        $itemInvalid = ['item' => 'invalid'];
        assertSame(3, $data['created']);
        assertSame(
            [
                ['index' => 1, 'fields' => $itemInvalid],
                ['index' => 2, 'fields' => $itemInvalid],
                ['index' => 3, 'fields' => $itemInvalid],
                ['index' => 4, 'fields' => $itemInvalid],
                ['index' => 5, 'fields' => $itemInvalid],
                ['index' => 7, 'fields' => ['category' => 'required', 'text' => 'required']],
                ['index' => 8, 'fields' => $itemInvalid],
            ],
            $data['errors']
        );
        assertSame(['A', 'B', 'C'], array_column(importRows(), 'text_lv'));

        // Сирий `{}` як елемент — те саме, що []
        $data = importOk(importRequest('{"items":[{}]}'));
        assertSame([['index' => 0, 'fields' => ['category' => 'required', 'text' => 'required']]], $data['errors']);
    });
});

test('phrases-import: isActive та зайві ключі (id, createdAt, text.de) ігноруються: фрази активні, id і createdAt не з тіла', function () {
    withFreshSeed(function () {
        $extra = ['id' => 999, 'createdAt' => '2000-01-01T00:00:00Z', 'updatedAt' => '2000-01-01T00:00:00Z', 'unknown' => ['x']];
        $items = [
            item() + ['isActive' => false],
            item() + ['isActive' => 'x'],
            item() + ['isActive' => null],
            item() + ['isActive' => 1],
            item() + ['isActive' => []],
            item() + ['isActive' => true],
            item('love', ['lv' => 'a', 'en' => 'b', 'ru' => 'c', 'de' => 'Hallo']) + $extra,
        ];
        $data = importOk(importRequest(['items' => $items]));

        assertSame(7, $data['created']);
        assertSame([], $data['errors']);

        $rows = importRows();
        assertSame(7, count($rows));
        foreach ($rows as $row) {
            assertSame(1, (int) $row['is_active'], 'id ' . $row['id'] . ' активна');
            assertTrue((int) $row['id'] !== 999);
            assertTrue(!str_starts_with((string) $row['created_at'], '2000'), 'createdAt поточний');
        }
        assertSame(null, testPdo()->query('SELECT 1 FROM `phrases` WHERE `id` = 999')->fetchColumn() ?: null);

        $shown = decode(importGet('/api/admin/phrases/' . $rows[6]['id']));
        assertSame(true, $shown['isActive']);
        assertSame(['lv' => 'a', 'en' => 'b', 'ru' => 'c'], $shown['text']);
        assertSame(true, abs(strtotime($shown['createdAt']) - time()) <= 10, 'createdAt ≈ зараз: ' . $shown['createdAt']);
    });
});

test('phrases-import: м\'яке правило мов — без ключа або null → "" (створено); {} → text empty; text відсутній → required', function () {
    withFreshSeed(function () {
        $items = [
            item('love', ['lv' => 'Latviski', 'ru' => 'Русский']),                       // 0 без en
            item('love', ['lv' => 'Latviski', 'en' => null, 'ru' => 'Русский']),         // 1 en null
            item('love', []),                                                            // 2 text: {} → empty
            ['category' => 'love'],                                                      // 3 text відсутній → required
            item('love', ['lv' => null, 'en' => null, 'ru' => null]),                    // 4 усі null → empty
            item('love', ['en' => 'Only English']),                                      // 5 лише en → ок
            ['category' => 'love', 'text' => 'abc'],                                     // 6 text не об'єкт → invalid
            ['category' => 'love', 'text' => ['lv' => 'x', 'en' => 5, 'ru' => null]],    // 7 en = 5 → invalid
        ];
        $data = importOk(importRequest(['items' => $items]));

        assertSame(3, $data['created']);
        assertSame(
            [
                ['index' => 2, 'fields' => ['text' => 'empty']],
                ['index' => 3, 'fields' => ['text' => 'required']],
                ['index' => 4, 'fields' => ['text' => 'empty']],
                ['index' => 6, 'fields' => ['text' => 'invalid']],
                ['index' => 7, 'fields' => ['text.en' => 'invalid']],
            ],
            $data['errors']
        );

        $rows = importRows();
        assertSame(3, count($rows));
        assertSame(['Latviski', '', 'Русский'], [$rows[0]['text_lv'], $rows[0]['text_en'], $rows[0]['text_ru']], 'без en');
        assertSame(['Latviski', '', 'Русский'], [$rows[1]['text_lv'], $rows[1]['text_en'], $rows[1]['text_ru']], 'en = null');
        assertSame(['', 'Only English', ''], [$rows[2]['text_lv'], $rows[2]['text_en'], $rows[2]['text_ru']], 'лише en');
    });
});

test('phrases-import: довжина 500 символів (a, š, я, 😀) зберігається, 501 → too_long; trim; текст і спецсимволи байт у байт; керівні символи → invalid', function () {
    withFreshSeed(function () {
        $items = [];
        foreach (['a', 'š', 'я', '😀'] as $char) {
            foreach (['lv', 'en', 'ru'] as $lang) {
                $items[] = item('love', ['lv' => 'x', 'en' => 'x', 'ru' => 'x', $lang => str_repeat($char, 500)]);
            }
        }
        $limit = count($items);
        foreach (['a', '😀'] as $char) {
            $items[] = item('love', ['lv' => str_repeat($char, 501), 'en' => 'x', 'ru' => 'x']);
        }
        $data = importOk(importRequest(['items' => $items]));
        assertSame($limit, $data['created']);
        assertSame(
            [
                ['index' => $limit, 'fields' => ['text.lv' => 'too_long']],
                ['index' => $limit + 1, 'fields' => ['text.lv' => 'too_long']],
            ],
            $data['errors']
        );
        $lengths = testPdo()->query(
            'SELECT MAX(GREATEST(CHAR_LENGTH(`text_lv`), CHAR_LENGTH(`text_en`), CHAR_LENGTH(`text_ru`))) FROM `phrases` WHERE `id` > ' . IMPORT_SEED_COUNT
        )->fetchColumn();
        assertSame(500, (int) $lengths, 'максимум 500 символів, 501 не потрапив');
        $rows = importRows();
        assertSame(str_repeat('😀', 500), $rows[$limit - 1]['text_ru']);
        assertSame(str_repeat('š', 500), $rows[3]['text_lv']);
    });

    withFreshSeed(function () {
        // Trim: звичайні й юнікодні пробіли; пробіли навколо 500 символів не рахуються
        $items = [
            item('love', ['lv' => "  \u{00A0}Hello\u{3000} \n", 'en' => "\u{FEFF}\u{2028}Hi\u{2029}", 'ru' => "\t Привіт \r\n"]),
            item('love', ['lv' => '  ' . str_repeat('a', 500) . "  \n", 'en' => 'x', 'ru' => 'x']),
        ];
        $samples = [
            "a\nb", "a\r\nb", "a\tb", '<script>alert(1)</script>', "'; DROP TABLE phrases; --", '100% _ \\',
            'say "hi" & <b>bold</b> \'single\'', '😀 Šodien būs labi, Привет', "\\' OR 1=1 -- \\", '%_%', "\u{200B}x",
        ];
        foreach ($samples as $sample) {
            $items[] = item('love', ['lv' => $sample, 'en' => $sample, 'ru' => $sample]);
        }
        $data = importOk(importRequest(['items' => $items]));
        assertSame(count($items), $data['created']);
        assertSame([], $data['errors']);

        $rows = importRows();
        assertSame(['Hello', 'Hi', 'Привіт'], [$rows[0]['text_lv'], $rows[0]['text_en'], $rows[0]['text_ru']], 'trimmed');
        assertSame(str_repeat('a', 500), $rows[1]['text_lv'], 'пробіли навколо 500 символів');
        foreach ($samples as $i => $sample) {
            assertSame(bin2hex($sample), bin2hex($rows[$i + 2]['text_lv']), 'lv: ' . $sample);
            assertSame(bin2hex($sample), bin2hex($rows[$i + 2]['text_ru']), 'ru: ' . $sample);
        }
        assertSame(30 + count($items), phraseCount(testPdo()), 'таблиця на місці');
    });

    withFreshSeed(function () {
        $items = [];
        foreach (["a\0b", "a\x01b", "a\x02", "a\x7Fb"] as $bad) {
            foreach (['lv', 'en', 'ru'] as $lang) {
                $items[] = item('love', ['lv' => 'x', 'en' => 'x', 'ru' => 'x', $lang => $bad]);
            }
        }
        $data = importOk(importRequest(['items' => $items]));
        assertSame(0, $data['created']);
        assertSame(count($items), count($data['errors']));
        foreach ($data['errors'] as $i => $error) {
            $lang = ['lv', 'en', 'ru'][$i % 3];
            assertSame(['index' => $i, 'fields' => ["text.{$lang}" => 'invalid']], $error, "елемент {$i}");
        }
        assertSame(30, phraseCount(testPdo()));
    });
});

test('phrases-import: порядок записів = порядок валідних елементів у запиті (зростаючі id), помилкові не створюють дірок', function () {
    withFreshSeed(function () {
        $items = [
            item('love', ['lv' => 'P0', 'en' => '', 'ru' => '']),
            item('nope'),
            item('love', ['lv' => 'P1', 'en' => '', 'ru' => '']),
            item('love', ['lv' => 'P2', 'en' => '', 'ru' => '']),
            5,
            item('love', ['lv' => 'P3', 'en' => '', 'ru' => '']),
            ['category' => 'love', 'text' => []],
            item('love', ['lv' => 'P4', 'en' => '', 'ru' => '']),
        ];
        $data = importOk(importRequest(['items' => $items]));
        assertSame(5, $data['created']);
        assertSame([1, 4, 6], array_column($data['errors'], 'index'));

        $rows = importRows();
        assertSame(['P0', 'P1', 'P2', 'P3', 'P4'], array_column($rows, 'text_lv'), 'порядок збігається з порядком запиту');
        assertSame([31, 32, 33, 34, 35], array_map('intval', array_column($rows, 'id')), 'id зростають без дірок');
    });
});

// ---------- Порожній список, ліміт, items ----------

test('phrases-import: порожній список (і {} — те саме після json_decode) → 200, created 0, errors [], жодного SQL', function () {
    withFreshSeed(function () {
        importRequest(['items' => []]); // токен отримано і з'єднання прогріто до замірів
        $before = tableDump();

        foreach (['{"items":[]}' => 'items: []', '{"items":{}}' => 'items: {}'] as $raw => $note) {
            $response = null;
            $statements = statementCount(static function () use ($raw, &$response): void {
                $response = importRequest($raw);
            });
            $data = importOk($response);
            assertSame(0, $data['created'], $note);
            assertSame([], $data['errors'], $note);
            assertTrue(str_contains((string) $response->body, '"errors":[]'), $note);
            assertSame(0, $statements, "{$note}: жодного SQL");
        }
        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

test('phrases-import: ліміт — рівно 1000 елементів → 200; 1001 → 422 items too_many, БД не змінилась, 0 запитів', function () {
    withFreshSeed(function () {
        importRequest(['items' => []]);
        $before = tableDump();

        $response = null;
        $statements = statementCount(static function () use (&$response): void {
            $response = importRequest(['items' => bulkItems(1001)]);
        });
        importAssertRejected($response, ['items' => 'too_many'], '1001');
        assertSame(0, $statements, '1001: жодного SQL');
        assertSame($before, tableDump(), '1001: нічого не збережено');

        // Навіть коли всі елементи невалідні
        importAssertRejected(importRequest(['items' => array_fill(0, 1001, 5)]), ['items' => 'too_many'], '1001 невалідних');

        $data = importOk(importRequest(['items' => bulkItems(1000)]));
        assertSame(1000, $data['created']);
        assertSame([], $data['errors']);
        assertSame(1030, phraseCount(testPdo()));
    });
});

test('phrases-import: items — немає → required; не список (рядок, число, null, bool, непорожній об\'єкт) → invalid; БД не змінилась, 0 запитів', function () {
    withFreshSeed(function () {
        importRequest(['items' => []]);
        $before = tableDump();

        $cases = [
            'немає' => ['{}', ['items' => 'required']],
            'інший ключ' => ['{"item":[]}', ['items' => 'required']],
            '"abc"' => ['{"items":"abc"}', ['items' => 'invalid']],
            '5' => ['{"items":5}', ['items' => 'invalid']],
            '1.5' => ['{"items":1.5}', ['items' => 'invalid']],
            'null' => ['{"items":null}', ['items' => 'invalid']],
            'true' => ['{"items":true}', ['items' => 'invalid']],
            'false' => ['{"items":false}', ['items' => 'invalid']],
            'об\'єкт' => ['{"items":{"a":1}}', ['items' => 'invalid']],
            'об\'єкт з елементом' => ['{"items":{"a":{"category":"love","text":{"lv":"x","en":"","ru":""}}}}', ['items' => 'invalid']],
        ];
        foreach ($cases as $name => [$raw, $fields]) {
            $response = null;
            $statements = statementCount(static function () use ($raw, &$response): void {
                $response = importRequest($raw);
            });
            importAssertRejected($response, $fields, (string) $name);
            assertSame(0, $statements, "{$name}: жодного SQL");
        }
        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

test('phrases-import: некоректне тіло → 400 bad_request (не 500): порожнє, не JSON, список, рядок, число, зламаний JSON, lone surrogate, байт \\xff', function () {
    withFreshSeed(function () {
        importRequest(['items' => []]);
        $before = tableDump();
        $bodies = [
            'порожнє' => '',
            'пробіли' => '   ',
            'не JSON' => 'not json',
            'список' => '[1,2]',
            'список з об\'єктом' => '[{"category":"love"}]',
            'рядок' => '"str"',
            'число' => '5',
            'null' => 'null',
            'зламаний JSON' => '{"items":[',
            'lone surrogate' => '{"items":["\ud800"]}',
            'невалідний UTF-8' => "{\"items\":[\"\xff\"]}",
        ];
        foreach ($bodies as $name => $raw) {
            $response = null;
            $statements = statementCount(static function () use ($raw, &$response): void {
                $response = importRequest($raw);
            });
            assertSame(400, $response->status, "{$name}: " . $response->body);
            assertSame('bad_request', decode($response)['error']['code'], $name);
            assertSame('no-store', $response->headers['Cache-Control'] ?? null, "{$name}: no-store");
            assertSame(0, $statements, "{$name}: жодного SQL");
        }
        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

// ---------- Швидкодія, повтор, збій БД ----------

test('phrases-import: швидкодія — 1000 найдовших елементів (3 × 500 емодзі) → 200 за < 10 с (медіана 3 запусків)', function () {
    $items = [];
    for ($i = 0; $i < 1000; $i++) {
        $items[] = item(Categories::CODES[$i % count(Categories::CODES)], [
            'lv' => str_repeat('😀', 499) . '🙂',
            'en' => str_repeat('😀', 500),
            'ru' => str_repeat('😀', 500),
        ]);
    }
    importRequest(['items' => []]); // токен отримано до замірів

    $times = [];
    withFreshSeed(function () use ($items, &$times) {
        for ($run = 0; $run < 3; $run++) {
            reseed();
            $start = hrtime(true);
            $response = importRequest(['items' => $items]);
            $times[] = (hrtime(true) - $start) / 1e9;

            $data = importOk($response);
            assertSame(1000, $data['created'], "запуск {$run}");
            assertSame([], $data['errors']);
            assertSame(1030, phraseCount(testPdo()));
        }
        $row = importRows()[999];
        assertSame(500, mb_strlen($row['text_lv'], 'UTF-8'));
        assertSame(str_repeat('😀', 499) . '🙂', $row['text_lv']);
    });

    sort($times);
    assertTrue($times[1] < 10.0, sprintf('медіана %.2f с (усі: %s)', $times[1], implode(', ', array_map(static fn (float $t): string => sprintf('%.2f', $t), $times))));
});

test('phrases-import: повторний імпорт того самого набору створює дублікати; старі рядки без змін', function () {
    withFreshSeed(function () {
        $before = tableDump();
        $body = ['items' => bulkItems(50)];

        assertSame(50, importOk(importRequest($body))['created']);
        assertSame(50, importOk(importRequest($body))['created']);

        assertSame(130, phraseCount(testPdo()));
        assertSame($before, array_slice(tableDump(), 0, IMPORT_SEED_COUNT), 'старі рядки (id 1…30) без змін');
        $rows = importRows();
        assertSame(100, count($rows));
        assertSame(array_column(array_slice($rows, 0, 50), 'text_lv'), array_column(array_slice($rows, 50), 'text_lv'), 'дублікати');
    });
});

test('phrases-import: збій БД посеред запиту → 500 server_error без деталей, відкат УСІХ пакетів (30 фраз), тригер видаляється', function () {
    withFreshSeed(function () {
        $items = bulkItems(250);
        $items[219]['text']['en'] = 'BOOM'; // третій пакет (200…249); перед ним два успішні пакети
        importRequest(['items' => []]);

        $pdo = testPdo();
        $pdo->exec('DROP TRIGGER IF EXISTS `phrases_boom`');
        try {
            $pdo->exec(
                "CREATE TRIGGER `phrases_boom` BEFORE INSERT ON `phrases` FOR EACH ROW BEGIN"
                . " IF NEW.`text_en` = 'BOOM' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom trigger'; END IF; END"
            );

            $response = null;
            $tx = null;
            $log = authCaptureLog(static function () use ($items, &$response, &$tx): void {
                $tx = importTxDelta(static function () use ($items, &$response): void {
                    $response = importRequest(['items' => $items]);
                });
            });

            assertSame(500, $response->status, (string) $response->body);
            assertSame(IMPORT_500_BODY, $response->body, 'тіло без деталей');
            assertTrue(stripos((string) $response->body, 'boom') === false && stripos((string) $response->body, 'sql') === false, 'у відповіді немає boom/SQL');
            assertSame('no-store', $response->headers['Cache-Control'] ?? null);
            assertTrue(stripos($log, 'boom') !== false, 'деталі збою — в error_log: ' . $log);
            assertSame([1, 0, 1], $tx, 'START TRANSACTION, без COMMIT, ROLLBACK');
            assertSame(false, $pdo->inTransaction(), 'транзакція закрита');
            assertSame(30, phraseCount($pdo), 'відкат усіх пакетів, зокрема двох перших');
        } finally {
            $pdo->exec('DROP TRIGGER IF EXISTS `phrases_boom`');
        }

        // Після збою імпорт працює
        $data = importOk(importRequest(['items' => bulkItems(3)]));
        assertSame(3, $data['created']);
        assertSame(33, phraseCount($pdo));
    });
});

test('phrases-import: кількість запитів — ceil(valid / 100) вставок, одна транзакція; 250 → 3; 100 → 1; 101 → 2; невалідні й 400/422 → 0', function () {
    withFreshSeed(function () {
        importRequest(['items' => []]); // токен і з'єднання прогріто

        foreach ([[250, 3], [100, 1], [101, 2], [1, 1], [200, 2], [1000, 10]] as [$n, $expected]) {
            $body = ['items' => bulkItems($n)];
            $response = null;
            $statements = null;
            // statementCount всередині: зчитування лічильників транзакцій саме є prepared statements
            $tx = importTxDelta(static function () use ($body, &$response, &$statements): void {
                $statements = statementCount(static function () use ($body, &$response): void {
                    $response = importRequest($body);
                });
            });
            assertSame($n, importOk($response)['created'], "{$n}: created");
            assertSame($expected, $statements, "{$n} валідних → {$expected} вставок");
            assertSame([1, 1, 0], $tx, "{$n}: одна транзакція START/COMMIT");
        }

        // Лише невалідні: БД не відкривається, транзакції немає
        $response = null;
        $statements = null;
        $tx = importTxDelta(static function () use (&$response, &$statements): void {
            $statements = statementCount(static function () use (&$response): void {
                $response = importRequest(['items' => [5, item('nope'), ['category' => 'love']]]);
            });
        });
        assertSame(0, importOk($response)['created']);
        assertSame(0, $statements, 'усі невалідні → 0 запитів');
        assertSame([0, 0, 0], $tx, 'усі невалідні → без транзакції');

        // Репозиторій з порожнім списком: нічого не виконує і транзакції не відкриває
        $repo = new PhraseRepository(testPdo());
        $result = null;
        $statements = null;
        $tx = importTxDelta(static function () use ($repo, &$result, &$statements): void {
            $statements = statementCount(static function () use ($repo, &$result): void {
                $result = $repo->createMany([]);
            });
        });
        assertSame(0, $result);
        assertSame(0, $statements, 'createMany([]) → 0 запитів');
        assertSame([0, 0, 0], $tx, 'createMany([]) → без транзакції');

        // Відхилені на рівні запиту
        foreach (['', 'not json', '{}', '{"items":"x"}', '{"items":[]}'] as $raw) {
            assertSame(0, importStatements($raw), 'запит-рівень: ' . $raw);
        }
        assertSame(0, importStatements(['items' => bulkItems(1001)]), '1001');
        assertSame(0, statementCount(static fn () => importAnonymous(['items' => bulkItems(3)])), '401');
    });
});

// ---------- Видимість, заголовки, чистота ----------

test('phrases-import: імпортовані фрази одразу в GET /api/admin/phrases (== GET /{id}) і в /api/message за мовою; без en — не видається для en', function () {
    withFreshSeed(function () {
        $items = [
            item('love', ['lv' => 'Imports A', 'en' => 'Import A', 'ru' => 'Импорт A']),
            item('money', ['lv' => 'Imports B', 'en' => 'Import B', 'ru' => 'Импорт B']),
            item('luck', ['lv' => 'Tikai latviski', 'ru' => 'Только русский']), // без en
        ];
        assertSame(3, importOk(importRequest(['items' => $items]))['created']);

        $list = decode(importGet('/api/admin/phrases', ['perPage' => '3']));
        assertSame(33, $list['total']);
        assertSame(['Tikai latviski', 'Imports B', 'Imports A'], array_map(static fn (array $p): string => $p['text']['lv'], $list['items']), 'нові — на початку (id за спаданням)');
        foreach ($list['items'] as $phrase) {
            $shown = importGet('/api/admin/phrases/' . $phrase['id']);
            assertSame(200, $shown->status);
            assertSame($phrase, decode($shown), 'GET /{id} == елемент списку');
            assertSame(true, $phrase['isActive']);
        }
        assertSame('', $list['items'][0]['text']['en']);

        [$noEn, $b, $a] = $list['items'];
        $message = static fn (string $lang): Response => apiRequest('GET', '/api/message', ['lang' => $lang]);

        keepOnlyActive(testPdo(), $noEn['id']);
        $lv = $message('lv');
        assertSame(200, $lv->status);
        assertSame(['id' => $noEn['id'], 'lang' => 'lv', 'text' => 'Tikai latviski'], decode($lv));
        assertSame(404, $message('en')->status, 'без перекладу en не видається');
        assertSame(200, $message('ru')->status);

        keepOnlyActive(testPdo(), $a['id']);
        testPdo()->prepare('UPDATE `phrases` SET `is_active` = 1 WHERE `id` = ?')->execute([$a['id']]); // попередній keepOnlyActive деактивував $a
        assertSame(['id' => $a['id'], 'lang' => 'en', 'text' => 'Import A'], decode($message('en')));
    });
});

test('phrases-import: Cache-Control: no-store у відповідях 200, 422, 400 і 401', function () {
    withFreshSeed(function () {
        $responses = [
            '200' => importRequest(['items' => bulkItems(2)]),
            '422' => importRequest(['items' => 'x']),
            '400' => importRequest('not json'),
            '401' => importAnonymous(['items' => []]),
        ];
        foreach ($responses as $status => $response) {
            assertSame((int) $status, $response->status, "статус {$status}");
            assertSame('no-store', $response->headers['Cache-Control'] ?? null, "no-store: {$status}");
        }
    });
});

test('phrases-import: withFreshSeed після імпорту повертає сід (30 фраз, max id 30, AUTO_INCREMENT скинуто), тригерів немає', function () {
    withFreshSeed(function () {
        assertSame(5, importOk(importRequest(['items' => bulkItems(5)]))['created']);
        assertSame(35, phraseCount(testPdo()));
    });

    assertSame(30, phraseCount(testPdo()), 'після виходу: 30 фраз');
    assertSame(30, (int) testPdo()->query('SELECT MAX(`id`) FROM `phrases`')->fetchColumn(), 'після виходу: max id = 30');
    assertSame([], testPdo()->query('SHOW TRIGGERS')->fetchAll(), 'тригерів немає');

    withFreshSeed(function () {
        importRequest(['items' => bulkItems(1)]);
        assertSame(31, (int) importRows()[0]['id'], 'AUTO_INCREMENT теж скинуто');
    });
    assertSame(30, phraseCount(testPdo()));
});
