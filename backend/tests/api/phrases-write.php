<?php

declare(strict_types=1);

// Перевірки запису фраз (BE-006): POST /api/admin/phrases, PUT і DELETE /api/admin/phrases/{id}.
// Підключається з tests/api.php. Файл самодостатній: власні помічники (writeRequest, adminWrite, validBody, rowOf …),
// не залежить від tests/api/phrases.php. Кожен тест, що пише в БД, обгорнутий у withFreshSeed (tests/db-lib.php):
// сід (30 фраз, id 1…30) відновлюється до і після, тож тести не залежать від сусідніх і нічого їм не лишають.

use App\Http\Response;

const WRITE_KEYS = ['id', 'category', 'text', 'isActive', 'createdAt', 'updatedAt'];
const WRITE_SEED_CREATED_AT = '2026-10-26T18:30:00Z';

/**
 * Запит до API з тілом. Масив кодується в JSON, рядок іде як є (для зламаного JSON); $token = null — без Authorization.
 *
 * @param array<string, mixed>|string|null $body
 * @param array<string, mixed>             $query
 */
function writeRequest(string $method, string $path, array|string|null $body, ?string $token = null, array $query = []): Response
{
    $headers = ['content-type' => 'application/json'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (string) $body;

    return apiRequest($method, $path, $query, $headers, $raw);
}

/** Запит з дійсним токеном адміна (токен береться один раз на процес). */
function adminWrite(string $method, string $path, array|string|null $body = null, array $query = []): Response
{
    static $token = null;
    $token ??= authToken();

    return writeRequest($method, $path, $body, $token, $query);
}

/** Валідне тіло запису: категорія love, три короткі тексти, isActive не задано. */
function validBody(array $override = []): array
{
    return array_replace(['category' => 'love', 'text' => ['lv' => 'Jauna frāze', 'en' => 'New phrase', 'ru' => 'Новая фраза']], $override);
}

/** Тіло, де text.$lang замінено на $value, а решта мов — короткі непорожні тексти. */
function bodyWithText(string $lang, mixed $value, array $override = []): array
{
    return validBody(['text' => ['lv' => 'a', 'en' => 'b', 'ru' => 'c', $lang => $value]] + $override);
}

/** Сирий рядок phrases з БД (або null). */
function rowOf(int $id): ?array
{
    $stmt = testPdo()->prepare('SELECT * FROM `phrases` WHERE `id` = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** @return int найбільший id у phrases */
function maxPhraseId(): int
{
    return (int) testPdo()->query('SELECT MAX(`id`) FROM `phrases`')->fetchColumn();
}

/** 422 validation_error з точно такими fields (порядок ключів значущий), no-store. */
function assertFieldErrors(Response $response, array $expected, string $note): void
{
    assertSame(422, $response->status, $note . ': ' . $response->body);
    $error = decode($response)['error'];
    assertSame('validation_error', $error['code'], $note);
    assertSame($expected, $error['fields'], $note);
    assertSame('no-store', $response->headers['Cache-Control'] ?? null, "{$note}: no-store");
}

function assertErrorCode(Response $response, int $status, string $code, string $note): void
{
    assertSame($status, $response->status, $note . ': ' . $response->body);
    assertSame($code, decode($response)['error']['code'] ?? null, $note);
    assertSame('no-store', $response->headers['Cache-Control'] ?? null, "{$note}: no-store");
}

/** Дата ISO 8601 UTC у форматі Z і в межах ±10 с від поточного часу. */
function assertIsNow(string $iso, string $note): void
{
    assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $iso), "{$note}: формат {$iso}");
    assertTrue(abs(strtotime($iso) - time()) <= 10, "{$note}: {$iso} поза ±10 с від зараз");
}

/** POST валідного тіла, очікує 201; повертає розібрану відповідь. */
function createdPhrase(array $body): array
{
    $response = adminWrite('POST', '/api/admin/phrases', $body);
    assertSame(201, $response->status, (string) $response->body);

    return decode($response);
}

// ---------- Захист ----------

test('phrases-write: без токена POST, PUT і DELETE → 401 навіть із невалідним тілом, БД не змінюється', function () {
    withFreshSeed(function () {
        $before = tableDump();

        foreach ([
            ['POST', '/api/admin/phrases', validBody()],
            ['POST', '/api/admin/phrases', []],
            ['POST', '/api/admin/phrases', 'not json'],
            ['PUT', '/api/admin/phrases/1', validBody()],
            ['PUT', '/api/admin/phrases/1', []],
            ['PUT', '/api/admin/phrases/abc', 'not json'],
            ['DELETE', '/api/admin/phrases/1', null],
            ['DELETE', '/api/admin/phrases/abc', null],
        ] as [$method, $path, $body]) {
            authAssertUnauthorized(writeRequest($method, $path, $body), "{$method} {$path}");
        }
        authAssertUnauthorized(writeRequest('POST', '/api/admin/phrases', validBody(), 'garbage.token.value'), 'сміттєвий токен');

        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

// ---------- POST ----------

test('phrases-write: POST валідного тіла → 201, форма Phrase, isActive=true, createdAt=updatedAt, no-store', function () {
    withFreshSeed(function () {
        $response = adminWrite('POST', '/api/admin/phrases', validBody());
        $data = decode($response);

        assertSame(201, $response->status, (string) $response->body);
        assertSame(WRITE_KEYS, array_keys($data));
        assertSame(31, $data['id'], 'перший новий id на свіжому сіді');
        assertSame('love', $data['category']);
        assertSame(['lv' => 'Jauna frāze', 'en' => 'New phrase', 'ru' => 'Новая фраза'], $data['text']);
        assertSame(true, $data['isActive']);
        assertSame($data['createdAt'], $data['updatedAt']);
        assertIsNow($data['createdAt'], 'createdAt');
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        assertSame('application/json; charset=utf-8', $response->headers['Content-Type'] ?? null);

        $row = rowOf(31);
        assertTrue($row !== null, 'рядок створено');
        assertSame('Jauna frāze', $row['text_lv']);
        assertSame(31, phraseCount(testPdo()));
    });
});

test('phrases-write: створена фраза є в GET /{id} і на початку списку (найбільший id) і збігається з відповіддю POST', function () {
    withFreshSeed(function () {
        $created = createdPhrase(validBody());

        $show = adminWrite('GET', "/api/admin/phrases/{$created['id']}");
        assertSame(200, $show->status);
        assertSame($created, decode($show));

        $list = decode(adminWrite('GET', '/api/admin/phrases', null, ['perPage' => '1']));
        assertSame(31, $list['total']);
        assertSame($created, $list['items'][0]);
    });
});

test('phrases-write: category — немає / null → required; невалідна → invalid; усі 6 кодів → 201', function () {
    withFreshSeed(function () {
        $without = validBody();
        unset($without['category']);
        assertFieldErrors(adminWrite('POST', '/api/admin/phrases', $without), ['category' => 'required'], 'немає');
        assertFieldErrors(adminWrite('POST', '/api/admin/phrases', validBody(['category' => null])), ['category' => 'required'], 'null');

        foreach (['LOVE', 'love ', ' love', "love\n", 'unknown', '5', 5, ['love'], '', true] as $bad) {
            assertFieldErrors(
                adminWrite('POST', '/api/admin/phrases', validBody(['category' => $bad])),
                ['category' => 'invalid'],
                'category=' . json_encode($bad)
            );
        }
        assertSame(30, phraseCount(testPdo()), 'відхилені запити нічого не створили');

        foreach (['love', 'money', 'luck', 'work', 'travel', 'mood'] as $code) {
            $data = createdPhrase(validBody(['category' => $code]));
            assertSame($code, $data['category']);
            assertSame($code, rowOf($data['id'])['category']);
        }
    });
});

test('phrases-write: text, де всі мови порожні (або лише пробіли, включно з NBSP/U+3000/U+FEFF) → 422 text=empty; одна непорожня → 201', function () {
    withFreshSeed(function () {
        $blanks = ['', '   ', "\n\t\r", "\u{00A0}", "\u{3000}", "\u{FEFF}", " \u{00A0}\u{3000}\u{FEFF}\n "];
        foreach ($blanks as $blank) {
            assertFieldErrors(
                adminWrite('POST', '/api/admin/phrases', validBody(['text' => ['lv' => $blank, 'en' => $blank, 'ru' => $blank]])),
                ['text' => 'empty'],
                json_encode($blank)
            );
        }
        assertFieldErrors(
            adminWrite('POST', '/api/admin/phrases', validBody(['text' => ['lv' => '', 'en' => ' ', 'ru' => "\u{00A0}"]])),
            ['text' => 'empty'],
            'різні порожні'
        );
        assertSame(30, phraseCount(testPdo()));

        $data = createdPhrase(validBody(['text' => ['lv' => '', 'en' => 'Only English', 'ru' => '']]));
        assertSame(['lv' => '', 'en' => 'Only English', 'ru' => ''], $data['text']);
    });
});

test('phrases-write: довжина в символах — 500 (a, š, я, емодзі) → 201 і CHAR_LENGTH=500; 501 → 422 too_long, а не 500', function () {
    withFreshSeed(function () {
        foreach (['a', 'š', 'я', '😀'] as $char) {
            foreach (['lv', 'en', 'ru'] as $lang) {
                $data = createdPhrase(bodyWithText($lang, str_repeat($char, 500)));
                assertSame(str_repeat($char, 500), $data['text'][$lang], "відповідь: 500 × {$char} ({$lang})");

                $stmt = testPdo()->prepare("SELECT CHAR_LENGTH(`text_{$lang}`) FROM `phrases` WHERE `id` = ?");
                $stmt->execute([$data['id']]);
                assertSame(500, (int) $stmt->fetchColumn(), "БД: 500 × {$char} ({$lang})");
            }
        }

        $count = phraseCount(testPdo());
        foreach (['a', '😀'] as $char) {
            foreach (['lv', 'en', 'ru'] as $lang) {
                assertFieldErrors(
                    adminWrite('POST', '/api/admin/phrases', bodyWithText($lang, str_repeat($char, 501))),
                    ["text.{$lang}" => 'too_long'],
                    "501 × {$char} ({$lang})"
                );
            }
        }
        assertSame($count, phraseCount(testPdo()), 'відхилені запити нічого не створили');
    });
});

test('phrases-write: текст зберігається trimmed (юнікодні пробіли теж); пробіли навколо 500 символів не рахуються; \\n всередині лишається', function () {
    withFreshSeed(function () {
        $data = createdPhrase(bodyWithText('lv', '  ' . str_repeat('a', 500) . "  \n"));
        assertSame(str_repeat('a', 500), $data['text']['lv']);
        assertSame(str_repeat('a', 500), rowOf($data['id'])['text_lv']);

        $data = createdPhrase(bodyWithText('en', "\u{00A0} hi \u{3000}"));
        assertSame('hi', $data['text']['en']);
        assertSame('hi', rowOf($data['id'])['text_en']);

        $data = createdPhrase(bodyWithText('ru', "\u{FEFF}\n Строка 1\nСтрока 2 \u{2028}"));
        assertSame("Строка 1\nСтрока 2", $data['text']['ru']);
        assertSame("Строка 1\nСтрока 2", rowOf($data['id'])['text_ru']);

        // ​ не є пробілом у JS trim — лишається
        $data = createdPhrase(bodyWithText('lv', "\u{200B}x"));
        assertSame("\u{200B}x", $data['text']['lv']);
    });
});

test('phrases-write: форма text — немає → required; не об\'єкт → invalid; {} → три required; ключ мови: немає → required, не рядок → invalid', function () {
    withFreshSeed(function () {
        $without = validBody();
        unset($without['text']);
        assertFieldErrors(adminWrite('POST', '/api/admin/phrases', $without), ['text' => 'required'], 'немає text');

        foreach (['abc', 5, null, true, ['a', 'b', 'c']] as $bad) {
            assertFieldErrors(adminWrite('POST', '/api/admin/phrases', validBody(['text' => $bad])), ['text' => 'invalid'], 'text=' . json_encode($bad));
        }

        $allRequired = ['text.lv' => 'required', 'text.en' => 'required', 'text.ru' => 'required'];
        assertFieldErrors(adminWrite('POST', '/api/admin/phrases', validBody(['text' => (object) []])), $allRequired, 'text={}');
        assertFieldErrors(
            adminWrite('POST', '/api/admin/phrases', '{"category":"love","text":{}}'),
            $allRequired,
            'сирий {"text":{}}'
        );
        assertFieldErrors(adminWrite('POST', '/api/admin/phrases', validBody(['text' => []])), $allRequired, 'text=[]');

        assertFieldErrors(
            adminWrite('POST', '/api/admin/phrases', validBody(['text' => ['lv' => 'a', 'ru' => 'c']])),
            ['text.en' => 'required'],
            'без en'
        );
        foreach (['lv', 'en', 'ru'] as $lang) {
            foreach ([5, null, true, [], ['x'], 1.5] as $bad) {
                assertFieldErrors(
                    adminWrite('POST', '/api/admin/phrases', bodyWithText($lang, $bad)),
                    ["text.{$lang}" => 'invalid'],
                    "{$lang}=" . json_encode($bad)
                );
            }
        }
        assertSame(30, phraseCount(testPdo()));
    });
});

test('phrases-write: isActive не boolean → 422 invalid; false → 201 і is_active=0', function () {
    withFreshSeed(function () {
        foreach (['yes', 1, 0, null, 'true', 'false', '', [], 1.0] as $bad) {
            assertFieldErrors(
                adminWrite('POST', '/api/admin/phrases', validBody(['isActive' => $bad])),
                ['isActive' => 'invalid'],
                'isActive=' . json_encode($bad)
            );
        }
        assertSame(30, phraseCount(testPdo()));

        $data = createdPhrase(validBody(['isActive' => false]));
        assertSame(false, $data['isActive']);
        assertSame(0, (int) rowOf($data['id'])['is_active']);

        $data = createdPhrase(validBody(['isActive' => true]));
        assertSame(true, $data['isActive']);
        assertSame(1, (int) rowOf($data['id'])['is_active']);
    });
});

test('phrases-write: кілька помилок повертаються разом у порядку category, text.lv, isActive', function () {
    withFreshSeed(function () {
        $response = adminWrite('POST', '/api/admin/phrases', ['category' => 'x', 'text' => ['lv' => 5, 'en' => '', 'ru' => 'y'], 'isActive' => 'no']);
        assertFieldErrors($response, ['category' => 'invalid', 'text.lv' => 'invalid', 'isActive' => 'invalid'], 'три поля');
        assertSame(['category', 'text.lv', 'isActive'], array_keys(decode($response)['error']['fields']));

        assertFieldErrors(adminWrite('POST', '/api/admin/phrases', '{}'), ['category' => 'required', 'text' => 'required'], 'порожній об\'єкт');
    });
});

test('phrases-write: керівні символи (\\0, \\x01, \\x02, \\x7F) → 422 invalid для кожної мови; \\n, \\r\\n, \\t проходять байт у байт', function () {
    withFreshSeed(function () {
        foreach (["a\0b", "a\x01b", "a\x02", "a\x7Fb", "\x01hi"] as $bad) {
            foreach (['lv', 'en', 'ru'] as $lang) {
                assertFieldErrors(
                    adminWrite('POST', '/api/admin/phrases', bodyWithText($lang, $bad)),
                    ["text.{$lang}" => 'invalid'],
                    "{$lang}=" . bin2hex($bad)
                );
            }
        }
        assertSame(30, phraseCount(testPdo()));

        foreach (["x\ny", "x\r\ny", "x\ty"] as $ok) {
            foreach (['lv', 'en', 'ru'] as $lang) {
                $data = createdPhrase(bodyWithText($lang, $ok));
                assertSame(bin2hex($ok), bin2hex($data['text'][$lang]), "відповідь {$lang}");
                assertSame(bin2hex($ok), bin2hex(rowOf($data['id'])["text_{$lang}"]), "БД {$lang}");
            }
        }
    });
});

test('phrases-write: некоректне тіло → 400 bad_request (не 500): порожнє, не JSON, масив, рядок, число, lone surrogate, невалідний UTF-8', function () {
    withFreshSeed(function () {
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
            'lone surrogate' => '{"category":"love","text":{"lv":"\ud800","en":"b","ru":"c"}}',
            'невалідний UTF-8' => '{"category":"love","text":{"lv":"' . "\xff" . '","en":"b","ru":"c"}}',
            'обірваний JSON' => '{"category":"love"',
        ];
        foreach ($bodies as $name => $raw) {
            assertErrorCode(adminWrite('POST', '/api/admin/phrases', $raw), 400, 'bad_request', "POST {$name}");
            assertErrorCode(adminWrite('PUT', '/api/admin/phrases/1', $raw), 400, 'bad_request', "PUT {$name}");
        }
        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

test('phrases-write: зайві поля (id, createdAt, text.de) ігноруються: id не з тіла, createdAt поточний', function () {
    withFreshSeed(function () {
        $data = createdPhrase(validBody([
            'id' => 999,
            'createdAt' => '2000-01-01T00:00:00Z',
            'updatedAt' => '2000-01-01T00:00:00Z',
            'unknown' => ['x'],
            'text' => ['lv' => 'a', 'en' => 'b', 'ru' => 'c', 'de' => 'Hallo'],
        ]));

        assertSame(31, $data['id']);
        assertTrue($data['id'] !== 999);
        assertSame(['lv' => 'a', 'en' => 'b', 'ru' => 'c'], $data['text']);
        assertIsNow($data['createdAt'], 'createdAt');
        assertSame(null, rowOf(999));
    });
});

test('phrases-write: HTML, SQL-ін\'єкції і спецсимволи зберігаються байт у байт; таблиця на місці', function () {
    withFreshSeed(function () {
        $samples = [
            '<script>alert(1)</script>',
            "'; DROP TABLE phrases; --",
            '100% _ \\',
            'say "hi" & <b>bold</b> \'single\'',
            '😀 Šodien būs labi, Привет',
            "\\' OR 1=1 -- \\",
            '%_%',
        ];
        foreach ($samples as $sample) {
            $before = phraseCount(testPdo());
            $data = createdPhrase(validBody(['text' => ['lv' => $sample, 'en' => $sample, 'ru' => $sample]]));

            assertSame($sample, $data['text']['lv'], 'відповідь: ' . $sample);
            $shown = decode(adminWrite('GET', "/api/admin/phrases/{$data['id']}"));
            assertSame($sample, $shown['text']['ru'], 'GET: ' . $sample);
            $row = rowOf($data['id']);
            assertSame($sample, $row['text_lv'], 'БД lv: ' . $sample);
            assertSame($sample, $row['text_en'], 'БД en: ' . $sample);
            assertSame($before + 1, phraseCount(testPdo()), 'рівно +1 рядок, таблиця на місці');
        }
    });
});

// ---------- PUT ----------

test('phrases-write: PUT повністю замінює category/text/isActive, createdAt лишається, updatedAt оновлюється, решта рядків без змін', function () {
    withFreshSeed(function () {
        $before = tableDump();
        $old = rowOf(5);
        assertSame('2026-10-26 18:30:00', $old['created_at'], 'сид: created_at');

        $response = adminWrite('PUT', '/api/admin/phrases/5', [
            'category' => 'mood',
            'text' => ['lv' => 'Jauns', 'en' => "New\ntext", 'ru' => ''],
            'isActive' => false,
        ]);
        $data = decode($response);

        assertSame(200, $response->status, (string) $response->body);
        assertSame(WRITE_KEYS, array_keys($data));
        assertSame(5, $data['id']);
        assertSame('mood', $data['category']);
        assertSame(['lv' => 'Jauns', 'en' => "New\ntext", 'ru' => ''], $data['text']);
        assertSame(false, $data['isActive']);
        assertSame(WRITE_SEED_CREATED_AT, $data['createdAt'], 'createdAt не змінився');
        assertTrue($data['updatedAt'] !== WRITE_SEED_CREATED_AT, 'updatedAt змінився');
        assertIsNow($data['updatedAt'], 'updatedAt');
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);

        assertSame($data, decode(adminWrite('GET', '/api/admin/phrases/5')), 'відповідь PUT == GET');

        $after = tableDump();
        assertSame($old['created_at'], rowOf(5)['created_at'], 'created_at в БД');
        $rest = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] !== 5));
        assertSame(29, count($rest($after)));
        assertSame($rest($before), $rest($after), 'решта 29 рядків без змін');
    });
});

test('phrases-write: PUT без isActive → true, навіть якщо фраза була прихована (id 19)', function () {
    withFreshSeed(function () {
        assertSame(0, (int) rowOf(19)['is_active'], 'сид: id 19 прихована');

        $data = decode(adminWrite('PUT', '/api/admin/phrases/19', validBody()));
        assertSame(true, $data['isActive']);
        assertSame(1, (int) rowOf(19)['is_active']);
    });
});

test('phrases-write: PUT тими самими даними двічі поспіль → 200 обидва рази (не 404 через rowCount=0)', function () {
    withFreshSeed(function () {
        $body = validBody();
        for ($i = 0; $i < 3; $i++) {
            $response = adminWrite('PUT', '/api/admin/phrases/7', $body);
            assertSame(200, $response->status, "спроба {$i}: " . $response->body);
        }
    });
});

test('phrases-write: PUT помилки — неіснуючий id → 404; неканонічний id → 404 навіть із поганим тілом; 422/400 не змінюють БД', function () {
    withFreshSeed(function () {
        $before = tableDump();

        assertErrorCode(adminWrite('PUT', '/api/admin/phrases/999', validBody()), 404, 'not_found', 'неіснуючий id');

        foreach (['007', 'abc', '0', '1.5', '-1', '12345678901', '1%0A', '+1', '1e2', ' 1'] as $badId) {
            foreach ([validBody(), '{}', 'not json'] as $body) {
                assertErrorCode(adminWrite('PUT', "/api/admin/phrases/{$badId}", $body), 404, 'not_found', "id={$badId}");
            }
        }

        foreach ([1, 999] as $id) {
            assertFieldErrors(
                adminWrite('PUT', "/api/admin/phrases/{$id}", ['category' => 'x', 'text' => 'abc']),
                ['category' => 'invalid', 'text' => 'invalid'],
                "422 для id={$id}"
            );
            assertErrorCode(adminWrite('PUT', "/api/admin/phrases/{$id}", 'not json'), 400, 'bad_request', "400 для id={$id}");
        }

        assertSame($before, tableDump(), 'БД не змінилась');
    });
});

test('phrases-write: PUT isActive:false прибирає фразу з /api/message, isActive:true повертає', function () {
    withFreshSeed(function () {
        keepOnlyActive(testPdo(), 1);
        $text = decode(adminWrite('GET', '/api/admin/phrases/1'))['text'];
        $message = static fn () => apiRequest('GET', '/api/message', ['lang' => 'en']);

        assertSame(200, $message()->status, 'до: єдина активна фраза видається');

        assertSame(200, adminWrite('PUT', '/api/admin/phrases/1', validBody(['text' => $text, 'isActive' => false]))->status);
        assertSame(404, $message()->status, 'прихована: у видачі немає');

        assertSame(200, adminWrite('PUT', '/api/admin/phrases/1', validBody(['text' => $text, 'isActive' => true]))->status);
        $response = $message();
        assertSame(200, $response->status);
        assertSame(['id' => 1, 'lang' => 'en', 'text' => $text['en']], decode($response));
    });
});

test('phrases-write: очищення перекладу через PUT прибирає фразу з видачі цієї мови, але не інших; missing=en її показує', function () {
    withFreshSeed(function () {
        $created = createdPhrase(validBody(['text' => ['lv' => 'Latviski', 'en' => 'English', 'ru' => 'Русский']]));
        $id = $created['id'];
        keepOnlyActive(testPdo(), $id);

        assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'en'])->status);
        $missing = static fn (): array => array_column(decode(adminWrite('GET', '/api/admin/phrases', null, ['missing' => 'en']))['items'], 'id');
        assertTrue(!in_array($id, $missing(), true), 'до очищення en не відсутній');

        assertSame(200, adminWrite('PUT', "/api/admin/phrases/{$id}", validBody(['text' => ['lv' => 'Latviski', 'en' => '', 'ru' => 'Русский']]))->status);

        assertSame(404, apiRequest('GET', '/api/message', ['lang' => 'en'])->status, 'en більше не видається');
        $lv = apiRequest('GET', '/api/message', ['lang' => 'lv']);
        assertSame(200, $lv->status, 'lv видається');
        assertSame($id, decode($lv)['id']);
        assertTrue(in_array($id, $missing(), true), 'missing=en містить фразу');
    });
});

// ---------- DELETE ----------

test('phrases-write: DELETE існуючої → 204 без тіла, no-store; GET → 404; повторний DELETE → 404; решта рядків без змін', function () {
    withFreshSeed(function () {
        $before = tableDump();

        $response = adminWrite('DELETE', '/api/admin/phrases/5');
        assertSame(204, $response->status);
        assertTrue(in_array($response->body, [null, ''], true), 'тіло порожнє');
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);

        assertErrorCode(adminWrite('GET', '/api/admin/phrases/5'), 404, 'not_found', 'GET після видалення');
        assertErrorCode(adminWrite('DELETE', '/api/admin/phrases/5'), 404, 'not_found', 'повторний DELETE');

        $rest = array_values(array_filter($before, static fn (array $r): bool => (int) $r['id'] !== 5));
        assertSame($rest, tableDump(), 'решта 29 рядків без змін');
        assertSame(29, decode(adminWrite('GET', '/api/admin/phrases'))['total']);
    });
});

test('phrases-write: DELETE неіснуючої або неканонічної → 404 not_found, БД не змінюється', function () {
    withFreshSeed(function () {
        $before = tableDump();

        foreach (['999', '007', 'abc', '0', '1.5', '-1', '12345678901', '1%0A'] as $id) {
            assertErrorCode(adminWrite('DELETE', "/api/admin/phrases/{$id}"), 404, 'not_found', "id={$id}");
        }

        assertSame($before, tableDump());
    });
});

// ---------- Заголовки, кількість запитів, чистота ----------

test('phrases-write: Cache-Control: no-store у відповідях 201, 200, 204, 422, 400, 404 і 401', function () {
    withFreshSeed(function () {
        $responses = [
            '201' => adminWrite('POST', '/api/admin/phrases', validBody()),
            '200' => adminWrite('PUT', '/api/admin/phrases/1', validBody()),
            '204' => adminWrite('DELETE', '/api/admin/phrases/2'),
            '422' => adminWrite('POST', '/api/admin/phrases', []),
            '400' => adminWrite('POST', '/api/admin/phrases', 'not json'),
            '404 PUT' => adminWrite('PUT', '/api/admin/phrases/999', validBody()),
            '404 DELETE' => adminWrite('DELETE', '/api/admin/phrases/999'),
            '401' => writeRequest('POST', '/api/admin/phrases', validBody()),
        ];
        foreach ($responses as $name => $response) {
            assertSame((int) explode(' ', (string) $name)[0], $response->status, "статус {$name}");
            assertSame('no-store', $response->headers['Cache-Control'] ?? null, "no-store: {$name}");
        }
    });
});

test('phrases-write: кількість SQL-запитів — відхилені 0; POST 2; PUT 2; PUT неіснуючого 2; DELETE 1; DELETE неіснуючого 1', function () {
    withFreshSeed(function () {
        adminWrite('GET', '/api/admin/phrases/1'); // токен отримано і з'єднання прогріто до замірів

        $count = static fn (string $method, string $path, array|string|null $body): int => statementCount(
            static function () use ($method, $path, $body): void {
                adminWrite($method, $path, $body);
            }
        );

        assertSame(0, $count('POST', '/api/admin/phrases', []), 'POST 422');
        assertSame(0, $count('POST', '/api/admin/phrases', 'not json'), 'POST 400');
        assertSame(0, $count('PUT', '/api/admin/phrases/abc', validBody()), 'PUT 404 за форматом id');
        assertSame(0, $count('PUT', '/api/admin/phrases/1', []), 'PUT 422 (існуючий)');
        assertSame(0, $count('PUT', '/api/admin/phrases/999', []), 'PUT 422 (неіснуючий)');
        assertSame(0, $count('PUT', '/api/admin/phrases/1', 'not json'), 'PUT 400');
        assertSame(0, $count('DELETE', '/api/admin/phrases/abc', null), 'DELETE 404 за форматом id');
        assertSame(0, statementCount(static fn () => writeRequest('POST', '/api/admin/phrases', validBody())), 'POST 401');

        assertSame(2, $count('POST', '/api/admin/phrases', validBody()), 'POST 201');
        assertSame(2, $count('PUT', '/api/admin/phrases/1', validBody()), 'PUT 200');
        assertSame(2, $count('PUT', '/api/admin/phrases/999', validBody()), 'PUT неіснуючого з валідним тілом');
        assertSame(1, $count('DELETE', '/api/admin/phrases/2', null), 'DELETE 204');
        assertSame(1, $count('DELETE', '/api/admin/phrases/2', null), 'DELETE неіснуючого');
    });
});

test('phrases-write: withFreshSeed повертає сід (30 фраз, max id 30) — новий POST знову дає id 31', function () {
    withFreshSeed(function () {
        assertSame(31, createdPhrase(validBody())['id']);
        assertSame(200, adminWrite('PUT', '/api/admin/phrases/1', validBody())->status);
        assertSame(204, adminWrite('DELETE', '/api/admin/phrases/3')->status);
    });

    assertSame(30, phraseCount(testPdo()), 'після виходу: 30 фраз');
    assertSame(30, maxPhraseId(), 'після виходу: max id = 30');
    assertSame('2026-10-26 18:30:00', rowOf(1)['created_at'], 'сид відновлено');

    withFreshSeed(function () {
        assertSame(31, createdPhrase(validBody())['id'], 'AUTO_INCREMENT теж скинуто');
    });
    assertSame(30, phraseCount(testPdo()));
});
