<?php

declare(strict_types=1);

// Перевірки читання фраз для адмінки (BE-005): GET /api/admin/phrases і GET /api/admin/phrases/{id}.
// Підключається з tests/api.php; допоміжні функції входу (authToken) і withBigTable/statementCount — у tests/auth-lib.php
// та tests/db-lib.php, тож цей файл не залежить від порядку підключення інших.
//
// Очікування для category, isActive, missing і пагінації виводяться з даних сіду (незалежний «оракул» у PHP).
// Для search очікування — явні списки ключів: вони залежать від колації (регістр, діакритика), яку оракул у PHP не відтворить.

use App\Database\Seeder;
use App\Http\Response;

/** GET з дійсним токеном; $query — вже розібраний query. */
function phrasesGet(string $path, array $query = [], ?string $token = null): Response
{
    return apiRequest('GET', $path, $query, ['authorization' => 'Bearer ' . ($token ?? authToken())]);
}

/**
 * Повертає тестову БД до сіду й віддає його рядки з id. НЕ викликати всередині withBigTable (TRUNCATE комітить транзакцію).
 *
 * @return array<string, array{key: string, category: string, active: bool, lv: string, en: string, ru: string, id: int}> key → рядок сіду
 */
function seedRows(): array
{
    $ids = reseed();
    $rows = [];
    foreach (Seeder::load(APP_ROOT . '/database/seed/phrases.php') as $row) {
        $rows[$row['key']] = $row + ['id' => $ids[$row['key']]];
    }

    return $rows;
}

/**
 * Оракул: id рядків сіду, що задовольняють умові, за спаданням.
 *
 * @param array<string, array<string, mixed>> $rows
 * @return list<int>
 */
function oracleIds(array $rows, callable $predicate): array
{
    $ids = array_column(array_filter($rows, $predicate), 'id');
    rsort($ids);

    return array_values($ids);
}

/** @return list<int> id фраз з відповіді списку, у порядку відповіді */
function listIds(Response $response): array
{
    assertSame(200, $response->status, (string) $response->body);

    return array_column(decode($response)['items'], 'id');
}

/**
 * ID за ключами сіду, за спаданням.
 *
 * @param list<string>       $keys
 * @param array<string, int> $ids  key → id (результат reseed())
 * @return list<int>
 */
function idsOf(array $keys, array $ids): array
{
    $result = array_map(static fn (string $key): int => $ids[$key], $keys);
    rsort($result);

    return $result;
}

/** Медіана часу виконання $fn у мілісекундах за $n викликів. */
function phrasesMedianMs(callable $fn, int $n): float
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

/** Перевіряє, що відповідь — 400 bad_request у форматі помилки без fields і з no-store. */
function phrasesAssertBadRequest(Response $response, string $note): void
{
    assertSame(400, $response->status, $note . ': ' . $response->body);
    $data = decode($response);
    assertSame('bad_request', $data['error']['code'] ?? null, $note);
    assertTrue(!isset($data['error']['fields']), "{$note}: у bad_request немає fields");
    assertSame('no-store', $response->headers['Cache-Control'] ?? null, "{$note}: no-store");
}

// ---------- Захист, форма відповіді, заголовки ----------

test('phrases: без токена обидва ендпоінти → 401 unauthorized без fields (і з фільтрами, і з некоректними параметрами)', function () {
    foreach (['/api/admin/phrases', '/api/admin/phrases/1'] as $path) {
        authAssertUnauthorized(apiRequest('GET', $path), $path);
    }
    authAssertUnauthorized(apiRequest('GET', '/api/admin/phrases', ['category' => 'love']), 'з фільтром');
    // guard раніше за валідацію: некоректний параметр без токена — 401, а не 400
    authAssertUnauthorized(apiRequest('GET', '/api/admin/phrases', ['perPage' => '0']), 'некоректний perPage');
    authAssertUnauthorized(apiRequest('GET', '/api/admin/phrases/abc'), 'некоректний id');
    authAssertUnauthorized(apiRequest('GET', '/api/admin/phrases', [], ['authorization' => 'Bearer garbage.token.value']), 'сміттєвий токен');
    assertTrue(!isset(decode(apiRequest('GET', '/api/admin/phrases'))['error']['fields']));
});

test('phrases: список без параметрів → 200, page=1, perPage=50, на сіді 30 фраз від id=30 до id=1', function () {
    $rows = seedRows();
    $response = phrasesGet('/api/admin/phrases');
    $data = decode($response);

    assertSame(200, $response->status);
    assertSame('application/json; charset=utf-8', $response->headers['Content-Type'] ?? null);
    assertSame(1, $data['page']);
    assertSame(50, $data['perPage']);
    assertSame(30, $data['total']);
    assertSame(count($rows), $data['total'], 'total збігається з кількістю рядків сіду');
    assertSame(30, count($data['items']));
    assertSame(range(30, 1), array_column($data['items'], 'id'), 'id за спаданням');
});

test('phrases: типи й форма — ключі відповіді та фрази, int/bool, text-об\'єкт, дати ISO 8601, без службових колонок', function () {
    $rows = seedRows();
    $response = phrasesGet('/api/admin/phrases');
    $data = decode($response);
    $body = (string) $response->body;

    assertSame(['items', 'total', 'page', 'perPage'], array_keys($data));
    foreach (['total', 'page', 'perPage'] as $key) {
        assertTrue(is_int($data[$key]), "{$key} — int");
    }

    foreach ($data['items'] as $item) {
        assertSame(['id', 'category', 'text', 'isActive', 'createdAt', 'updatedAt'], array_keys($item), 'ключі фрази #' . $item['id']);
        assertTrue(is_int($item['id']), 'id — int');
        assertTrue(is_string($item['category']), 'category — рядок');
        assertTrue(is_bool($item['isActive']), 'isActive — bool');
        assertSame(['lv', 'en', 'ru'], array_keys($item['text']), 'ключі text #' . $item['id']);
        foreach ($item['text'] as $lang => $text) {
            assertTrue(is_string($text), "text.{$lang} — рядок");
        }
        foreach (['createdAt', 'updatedAt'] as $key) {
            assertSame(1, preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $item[$key]), "{$key}: {$item[$key]}");
        }
    }

    // Порожній переклад — "", а не null і не відсутній ключ (only_lv: en і ru порожні)
    $onlyLv = array_values(array_filter($data['items'], static fn (array $i): bool => $i['id'] === $rows['only_lv']['id']))[0];
    assertSame(['lv' => $rows['only_lv']['lv'], 'en' => '', 'ru' => ''], $onlyLv['text']);

    // Сире тіло: isActive — true/false, а не 1/0; text — об'єкт; службових імен колонок немає
    assertTrue(str_contains($body, '"isActive":true') && str_contains($body, '"isActive":false'), 'isActive — boolean у тілі');
    assertTrue(!str_contains($body, '"isActive":1') && !str_contains($body, '"isActive":0'), 'isActive не число');
    assertTrue(str_contains($body, '"text":{"lv":'), 'text — JSON-об\'єкт');
    assertTrue(str_starts_with($body, '{"items":[{"id":30,'), 'початок тіла: ' . substr($body, 0, 40));
    foreach (['text_lv', 'text_en', 'text_ru', 'is_active', 'created_at', 'updated_at'] as $column) {
        assertTrue(!str_contains($body, $column), "у тілі немає службової колонки {$column}");
    }

    // Діакритика не екранується в сирому тілі й збігається з сідом байт у байт
    $diacritics = array_values(array_filter($data['items'], static fn (array $i): bool => $i['id'] === $rows['diacritics']['id']))[0];
    assertSame(
        ['lv' => $rows['diacritics']['lv'], 'en' => $rows['diacritics']['en'], 'ru' => $rows['diacritics']['ru']],
        $diacritics['text']
    );
    assertSame('Šodien veiksme būs tavā pusē.', $diacritics['text']['lv']);
    assertTrue(str_contains($body, 'Šodien veiksme būs tavā pusē.'), 'діакритика в сирому тілі');
    assertTrue(!str_contains($body, '\u'), 'у тілі немає \\u-екранування');
    assertSame('mood', $diacritics['category']);
    assertSame(true, $diacritics['isActive']);
});

test('phrases: Cache-Control: no-store у відповідях 200, 400 і 404 обох ендпоінтів', function () {
    seedRows();
    $token = authToken();

    $cases = [
        'список 200' => [phrasesGet('/api/admin/phrases', [], $token), 200],
        'список 400' => [phrasesGet('/api/admin/phrases', ['category' => 'x'], $token), 400],
        'фраза 200' => [phrasesGet('/api/admin/phrases/1', [], $token), 200],
        'фраза 404 (немає)' => [phrasesGet('/api/admin/phrases/999', [], $token), 404],
        'фраза 404 (формат)' => [phrasesGet('/api/admin/phrases/abc', [], $token), 404],
    ];
    foreach ($cases as $name => [$response, $status]) {
        assertSame($status, $response->status, $name);
        assertSame('no-store', $response->headers['Cache-Control'] ?? null, $name);
    }
});

// ---------- Фільтри ----------

test('phrases: category — кожен із 6 кодів дає рівно свої фрази (за оракулом); LOVE, x, «love » → 400', function () {
    $rows = seedRows();
    $token = authToken();

    foreach (['love', 'money', 'luck', 'work', 'travel', 'mood'] as $category) {
        $expected = oracleIds($rows, static fn (array $r): bool => $r['category'] === $category);
        assertSame(5, count($expected), "у сіді по 5 фраз у категорії {$category}");
        $response = phrasesGet('/api/admin/phrases', ['category' => $category], $token);
        assertSame($expected, listIds($response), $category);
        assertSame(5, decode($response)['total'], "total {$category}");
    }

    foreach (['LOVE', 'x', 'love ', 'love%20'] as $bad) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', ['category' => $bad], $token), 'category=' . json_encode($bad));
    }
});

test('phrases: isActive=false → лише приховані ([19]), true → 29 активних; 1, True, yes → 400', function () {
    $rows = seedRows();
    $token = authToken();

    $hidden = oracleIds($rows, static fn (array $r): bool => !$r['active']);
    assertSame([19], $hidden);
    $response = phrasesGet('/api/admin/phrases', ['isActive' => 'false'], $token);
    assertSame($hidden, listIds($response));
    assertSame(1, decode($response)['total']);

    $active = oracleIds($rows, static fn (array $r): bool => $r['active']);
    assertSame(29, count($active));
    $response = phrasesGet('/api/admin/phrases', ['isActive' => 'true'], $token);
    assertSame($active, listIds($response));
    assertSame(29, decode($response)['total']);
    assertTrue(!in_array(19, listIds($response), true), 'прихована фраза не в активних');

    foreach (['1', '0', 'True', 'yes', 'FALSE'] as $bad) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', ['isActive' => $bad], $token), 'isActive=' . $bad);
    }
});

test('phrases: missing=lv/en/ru → фрази з порожнім (після TRIM, зокрема з самих пробілів) перекладом; de, EN → 400', function () {
    $rows = seedRows();
    $token = authToken();

    $expected = ['lv' => [21], 'en' => [23, 20], 'ru' => [22, 21, 20]];
    foreach ($expected as $lang => $ids) {
        $oracle = oracleIds($rows, static fn (array $r): bool => trim($r[$lang], ' ') === '');
        assertSame($ids, $oracle, "оракул missing={$lang} збігається з відомими даними сіду");
        assertSame($ids, listIds(phrasesGet('/api/admin/phrases', ['missing' => $lang], $token)), "missing={$lang}");
    }
    assertTrue(in_array($rows['blank_en']['id'], listIds(phrasesGet('/api/admin/phrases', ['missing' => 'en'], $token)), true), 'blank_en (самі пробіли) входить до missing=en');

    foreach (['de', 'EN', 'en ', 'x'] as $bad) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', ['missing' => $bad], $token), 'missing=' . $bad);
    }
});

// ---------- Пошук ----------

test('phrases: search нечутливий до регістру (LUCK, luck) і працює по кирилиці (ТЕСТ, тест)', function () {
    $ids = reseed();
    $token = authToken();

    $luck = idsOf(['emoji', 'diacritics'], $ids); // [26, 24]
    assertSame([26, 24], $luck);
    assertSame($luck, listIds(phrasesGet('/api/admin/phrases', ['search' => 'LUCK'], $token)), 'LUCK');
    assertSame($luck, listIds(phrasesGet('/api/admin/phrases', ['search' => 'luck'], $token)), 'luck');
    assertSame($luck, listIds(phrasesGet('/api/admin/phrases', ['search' => 'LuCk'], $token)), 'LuCk');

    assertSame([$ids['cyrillic_case']], listIds(phrasesGet('/api/admin/phrases', ['search' => 'ТЕСТ'], $token)), 'ТЕСТ');
    assertSame([$ids['cyrillic_case']], listIds(phrasesGet('/api/admin/phrases', ['search' => 'тест'], $token)), 'тест');
    assertSame([$ids['cyrillic_case']], listIds(phrasesGet('/api/admin/phrases', ['search' => 'Тест'], $token)), 'Тест');
});

test('phrases: search нечутливий до діакритики: sodien → Šodien, bus → būs, ŠODIEN теж', function () {
    $ids = reseed();
    $token = authToken();

    $sodien = idsOf(['mood_2', 'diacritics', 'work_1', 'money_2'], $ids);
    assertSame([24, 17, 10, 5], $sodien);
    foreach (['sodien', 'ŠODIEN', 'Šodien', 'šodien', 'SODIEN'] as $needle) {
        assertSame($sodien, listIds(phrasesGet('/api/admin/phrases', ['search' => $needle], $token)), $needle);
    }

    $bus = idsOf(['diacritics', 'travel_2', 'work_1'], $ids);
    assertSame([24, 14, 10], $bus);
    foreach (['bus', 'būs', 'BUS', 'BŪS'] as $needle) {
        assertSame($bus, listIds(phrasesGet('/api/admin/phrases', ['search' => $needle], $token)), $needle);
    }
});

test('phrases: search — %, _, \\ та решта спецсимволів шукаються як звичайні (100%, _, %, \\, sure_thing; %_ не шаблон)', function () {
    $rows = seedRows();
    $token = authToken();
    $likeChars = [$rows['like_chars']['id']]; // [30]: '100% sure_thing \ backslash'

    $textOf = static fn (array $r): string => $r['lv'] . "\n" . $r['en'] . "\n" . $r['ru'];
    foreach (['100%', '_', '%', '\\', 'sure_thing', 'sure_', '% sure', '\\ back'] as $needle) {
        assertSame($likeChars, listIds(phrasesGet('/api/admin/phrases', ['search' => $needle], $token)), 'search=' . $needle);
        // і оракул у PHP: підрядок є лише в цій фразі
        assertSame($likeChars, oracleIds($rows, static fn (array $r): bool => str_contains($textOf($r), $needle)), 'оракул ' . $needle);
    }

    // %_ не діє як шаблон: у сіді такої послідовності немає, тож порожньо (а не «все»)
    assertSame([], oracleIds($rows, static fn (array $r): bool => str_contains($textOf($r), '%_')));
    assertSame([], listIds(phrasesGet('/api/admin/phrases', ['search' => '%_'], $token)), '%_');
    assertSame([], listIds(phrasesGet('/api/admin/phrases', ['search' => '_%'], $token)), '_%');
    assertSame([], listIds(phrasesGet('/api/admin/phrases', ['search' => '%%'], $token)), '%%');
    assertSame([], listIds(phrasesGet('/api/admin/phrases', ['search' => 's_re'], $token)), '_ не заміняє один символ');
    assertSame([], listIds(phrasesGet('/api/admin/phrases', ['search' => '1%e'], $token)), '% не заміняє довільний рядок');

    // Символи, які на MySQL 5.7 ламали б пошук одного символа, якби були екранувальними (факт 1 плану)
    foreach (['!', '|', '#', '/'] as $needle) {
        $expected = oracleIds($rows, static fn (array $r): bool => str_contains($textOf($r), $needle));
        assertSame($expected, listIds(phrasesGet('/api/admin/phrases', ['search' => $needle], $token)), 'search=' . $needle);
    }

    // Емодзі
    $emoji = listIds(phrasesGet('/api/admin/phrases', ['search' => '🍀'], $token));
    assertTrue(in_array($rows['emoji']['id'], $emoji, true), 'емодзі 🍀 знаходить фразу emoji: ' . json_encode($emoji));
});

test('phrases: search — диференційна перевірка: усі вводи до 3 символів із {% _ \\ ! пробіл 0 1} дають те саме, що str_contains у PHP', function () {
    // Регресія вади LIKE у MySQL 5.7 (utf8mb4_unicode_ci): ввід, що починається з %, давав хибні збіги ("%_" знаходив фразу з "% … _").
    // Алфавіт без літер: колація не впливає на розділові знаки й цифри, тож оракул str_contains точний.
    $rows = seedRows();
    $repository = new App\Repository\PhraseRepository(testPdo());
    $alphabet = ['%', '_', '\\', '!', ' ', '0', '1'];

    $needles = [];
    $level = [''];
    for ($length = 1; $length <= 3; $length++) {
        $next = [];
        foreach ($level as $prefix) {
            foreach ($alphabet as $char) {
                $next[] = $prefix . $char;
            }
        }
        $level = $next;
        $needles = array_merge($needles, $next);
    }

    $checked = 0;
    foreach ($needles as $needle) {
        if (trim($needle) === '') {
            continue; // контролер обрізає пробіли: такий ввід — «немає пошуку»
        }
        // Контролер обрізає пробіли по краях, тож і репозиторію, і оракулу віддаємо обрізаний ввід
        $trimmed = trim($needle);
        $found = array_map(
            static fn (App\Domain\Phrase $p): int => $p->id,
            $repository->search(new App\Domain\PhraseFilter(search: $trimmed, perPage: 100))['items']
        );
        $expected = oracleIds($rows, static function (array $r) use ($trimmed): bool {
            return str_contains($r['lv'], $trimmed) || str_contains($r['en'], $trimmed) || str_contains($r['ru'], $trimmed);
        });
        assertSame($expected, $found, 'search=' . json_encode($needle));
        $checked++;
    }
    assertTrue($checked > 300, "перевірено вводів: {$checked}");
});

test('phrases: search — пробіли по краях, порожній, ліміт 200 символів, ін\'єкції, невалідний UTF-8, \\0 і службовий x01 без помилок', function () {
    $ids = reseed();
    $token = authToken();
    $sodien = [24, 17, 10, 5];

    assertSame($sodien, listIds(phrasesGet('/api/admin/phrases', ['search' => '  sodien  '], $token)), 'пробіли по краях');
    assertSame($sodien, listIds(phrasesGet('/api/admin/phrases', ['search' => "\tsodien\n"], $token)), 'табуляція і переноси по краях');
    assertSame(range(30, 1), listIds(phrasesGet('/api/admin/phrases', ['search' => ''], $token)), 'порожній = немає');
    assertSame(range(30, 1), listIds(phrasesGet('/api/admin/phrases', ['search' => '   '], $token)), 'самі пробіли = немає');

    // Ліміт у символах, не в байтах
    foreach ([str_repeat('a', 200), str_repeat('я', 200), str_repeat('😀', 200)] as $ok) {
        $response = phrasesGet('/api/admin/phrases', ['search' => $ok], $token);
        assertSame(200, $response->status, 'рівно 200 символів: ' . mb_substr($ok, 0, 3));
    }
    foreach ([str_repeat('a', 201), str_repeat('я', 201)] as $tooLong) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', ['search' => $tooLong], $token), 'search 201 символ');
    }

    // Ін'єкції: ввід лише в параметрах, нічого не ламається й нічого не знаходить
    foreach (["' OR '1'='1", "%'; DROP TABLE phrases; --", '1); DELETE FROM phrases; --', "\" OR 1=1 #", "'; SELECT SLEEP(5); --"] as $payload) {
        $response = phrasesGet('/api/admin/phrases', ['search' => $payload], $token);
        assertSame(200, $response->status, $payload . ': ' . $response->body);
        assertSame([], decode($response)['items'], 'ін\'єкція не знаходить нічого: ' . $payload);
        assertSame(0, decode($response)['total']);
    }
    assertSame(30, phraseCount(testPdo()), 'таблиця фраз ціла після ін\'єкцій');
    assertSame(30, decode(phrasesGet('/api/admin/phrases', [], $token))['total']);

    // Невалідний UTF-8, байт 0 і службовий символ екранування: не 500
    foreach (["\xff", "ab\xc3", "\xed\xa0\x80", "a\0b", "\0", "\x01", "x\x01y", "100\x01%"] as $weird) {
        $response = phrasesGet('/api/admin/phrases', ['search' => $weird], $token);
        assertSame(200, $response->status, 'search=' . bin2hex($weird) . ': ' . $response->body);
        assertTrue(is_array(decode($response)['items']), 'items — список для ' . bin2hex($weird));
    }

    // x01 вилучається з вводу: «x\x01y» шукає «xy», а не «x» + службовий символ + «y»
    assertSame([$ids['like_chars']], listIds(phrasesGet('/api/admin/phrases', ['search' => "10\x010%"], $token)), 'x01 усередині слова вилучається');
});

test('phrases: порожні параметри (category=&missing=&isActive=&search=&page=&perPage=) = список без параметрів', function () {
    seedRows();
    $token = authToken();

    $plain = decode(phrasesGet('/api/admin/phrases', [], $token));
    $empty = decode(phrasesGet('/api/admin/phrases', [
        'category' => '', 'missing' => '', 'isActive' => '', 'search' => '', 'page' => '', 'perPage' => '',
    ], $token));

    assertSame($plain, $empty);
    assertSame(30, $empty['total']);
    assertSame(50, $empty['perPage']);
});

test('phrases: фільтри комбінуються через AND (перетин окремих фільтрів)', function () {
    $ids = reseed();
    $token = authToken();

    $cases = [
        'category=mood + search=sodien' => [['category' => 'mood', 'search' => 'sodien'], [24, 17]],
        'category=luck + missing=ru' => [['category' => 'luck', 'missing' => 'ru'], [20]],
        'category=mood + missing=en + isActive=true' => [['category' => 'mood', 'missing' => 'en', 'isActive' => 'true'], [23]],
        'category=love + isActive=false' => [['category' => 'love', 'isActive' => 'false'], [19]],
        'search=sodien + isActive=false' => [['search' => 'sodien', 'isActive' => 'false'], []],
        'усі чотири' => [['category' => 'work', 'missing' => 'lv', 'isActive' => 'true', 'search' => 'ENGLISH'], [21]],
        'усі чотири без збігу' => [['category' => 'love', 'missing' => 'lv', 'isActive' => 'true', 'search' => 'ENGLISH'], []],
        'category + page/perPage' => [['category' => 'mood', 'perPage' => '2', 'page' => '2'], [18, 17]],
    ];
    foreach ($cases as $name => [$query, $expected]) {
        $response = phrasesGet('/api/admin/phrases', $query, $token);
        assertSame($expected, listIds($response), $name);
        // total рахується з тим самим WHERE: за умови perPage ≥ кількості результатів він дорівнює довжині списку
        if (!isset($query['perPage'])) {
            assertSame(count($expected), decode($response)['total'], "total: {$name}");
        }
    }

    // AND, а не OR: результат комбінації — перетин результатів окремих фільтрів
    $combined = ['category' => 'mood', 'missing' => 'en', 'isActive' => 'true', 'search' => 'angļu'];
    $intersection = null;
    foreach ($combined as $name => $value) {
        $single = listIds(phrasesGet('/api/admin/phrases', [$name => $value], $token));
        $intersection = $intersection === null ? $single : array_values(array_intersect($intersection, $single));
    }
    assertSame([$ids['blank_en']], $intersection, 'перетин окремих фільтрів');
    assertSame($intersection, listIds(phrasesGet('/api/admin/phrases', $combined, $token)), 'комбінація = перетин');
});

// ---------- Пагінація ----------

test('phrases: total і сторінки — perPage=10 дає id 30…21, 20…11, 10…1; total із фільтром (5), а не загальний', function () {
    $rows = seedRows();
    $token = authToken();

    foreach ([1 => range(30, 21), 2 => range(20, 11), 3 => range(10, 1)] as $page => $expected) {
        $response = phrasesGet('/api/admin/phrases', ['perPage' => '10', 'page' => (string) $page], $token);
        $data = decode($response);
        assertSame($expected, listIds($response), "page={$page}");
        assertSame(30, $data['total'], "total на сторінці {$page}");
        assertSame($page, $data['page']);
        assertSame(10, $data['perPage']);
    }

    $response = phrasesGet('/api/admin/phrases', ['category' => 'love'], $token);
    assertSame(5, decode($response)['total'], 'total після фільтра, а не загальний');
    assertSame(oracleIds($rows, static fn (array $r): bool => $r['category'] === 'love'), listIds($response));

    // total не залежить від perPage і page
    $response = phrasesGet('/api/admin/phrases', ['category' => 'love', 'perPage' => '2', 'page' => '3'], $token);
    assertSame(5, decode($response)['total']);
    assertSame(1, count(decode($response)['items']), 'остання неповна сторінка');
});

test('phrases: таблиця в 600 фраз — сторінка 1 = id 600…551, сторінка 12 = id 50…1, total=600; сторінка 13 порожня', function () {
    $token = authToken();

    withBigTable(function () use ($token) {
        $first = phrasesGet('/api/admin/phrases', [], $token);
        assertSame(range(600, 551), listIds($first), 'сторінка 1');
        assertSame(600, decode($first)['total']);

        $last = phrasesGet('/api/admin/phrases', ['page' => '12'], $token);
        assertSame(range(50, 1), listIds($last), 'сторінка 12');
        assertSame(50, count(decode($last)['items']));
        assertSame(600, decode($last)['total']);

        $out = phrasesGet('/api/admin/phrases', ['page' => '13'], $token);
        assertSame(200, $out->status);
        $data = decode($out);
        assertSame([], $data['items']);
        assertSame(600, $data['total']);
        assertSame(13, $data['page']);
        assertSame(50, $data['perPage']);
        assertTrue(str_contains((string) $out->body, '"items":[]'), 'items — масив у сирому тілі: ' . $out->body);

        // Дві сусідні сторінки не перекриваються й разом дають суцільний діапазон
        $p2 = listIds(phrasesGet('/api/admin/phrases', ['page' => '2'], $token));
        assertSame(range(550, 501), $p2, 'сторінка 2');
    });
});

test('phrases: сторінка за межами → 200 з items:[] і тим самим page; perPage=100 і perPage=1', function () {
    seedRows();
    $token = authToken();

    foreach (['4', '999999999'] as $page) {
        $response = phrasesGet('/api/admin/phrases', ['perPage' => '10', 'page' => $page], $token);
        $data = decode($response);
        assertSame(200, $response->status, "page={$page}");
        assertSame([], $data['items']);
        assertSame(30, $data['total'], "total за межами, page={$page}");
        assertSame((int) $page, $data['page']);
        assertSame(10, $data['perPage']);
        assertTrue(str_contains((string) $response->body, '"items":[]'), 'items:[] у сирому тілі');
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    // Той самий фільтр, сторінка за межами відфільтрованого результату
    $response = phrasesGet('/api/admin/phrases', ['category' => 'love', 'page' => '2'], $token);
    assertSame([], decode($response)['items']);
    assertSame(5, decode($response)['total']);

    // perPage=1: один елемент, найновіший
    $one = phrasesGet('/api/admin/phrases', ['perPage' => '1'], $token);
    assertSame([30], listIds($one));
    assertSame(1, decode($one)['perPage']);
    assertSame(30, decode($one)['total']);

    // На сіді perPage=100 віддасть усі 30; до 100 перевіряємо на великій таблиці
    assertSame(30, count(decode(phrasesGet('/api/admin/phrases', ['perPage' => '100'], $token))['items']));
    withBigTable(function () use ($token) {
        $response = phrasesGet('/api/admin/phrases', ['perPage' => '100'], $token);
        assertSame(range(600, 501), listIds($response));
        assertSame(100, count(decode($response)['items']));
        assertSame(100, decode($response)['perPage']);

        assertSame(range(100, 1), listIds(phrasesGet('/api/admin/phrases', ['perPage' => '100', 'page' => '6'], $token)), 'сторінка 6 по 100');
    });
});

test('phrases: некоректні page/perPage (0, 101, -1, 1.5, abc, 01, 050, довгі) і параметри-масиви → 400 bad_request без fields', function () {
    seedRows();
    $token = authToken();

    foreach (['0', '101', '1000', '-5', 'abc', '1.5', '050', ' 50', '5 ', '1e1'] as $bad) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', ['perPage' => $bad], $token), 'perPage=' . json_encode($bad));
    }
    foreach (['0', '-1', '1.5', 'abc', '01', '1000000000', '99999999999999999999', ' 1', "1\n"] as $bad) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', ['page' => $bad], $token), 'page=' . json_encode($bad));
    }

    // Параметри-масиви (page[]=1, category[]=love)
    foreach (['page', 'perPage', 'category', 'missing', 'isActive', 'search'] as $name) {
        phrasesAssertBadRequest(phrasesGet('/api/admin/phrases', [$name => ['1']], $token), "{$name}[]");
    }

    // Межі приймаються
    assertSame(200, phrasesGet('/api/admin/phrases', ['page' => '999999999'], $token)->status);
    assertSame(200, phrasesGet('/api/admin/phrases', ['perPage' => '100'], $token)->status);
    assertSame(200, phrasesGet('/api/admin/phrases', ['perPage' => '1'], $token)->status);
});

test('phrases: порожній результат → items:[] (масив, не null) і total:0 (число)', function () {
    seedRows();
    $response = phrasesGet('/api/admin/phrases', ['search' => 'zzzz-немає']);
    $data = decode($response);

    assertSame(200, $response->status);
    assertSame([], $data['items']);
    assertSame(0, $data['total']);
    assertTrue(is_int($data['total']));
    assertSame(1, $data['page']);
    assertSame(50, $data['perPage']);
    assertTrue(str_contains((string) $response->body, '"items":[]'), 'items — [] у сирому тілі: ' . $response->body);
    assertTrue(str_contains((string) $response->body, '"total":0'), 'total — число 0');
});

// ---------- Фраза за ID ----------

test('phrases: GET /{id} → 200 і та сама фраза, що в списку (усі 30); 404 для неіснуючих і неканонічних id', function () {
    $rows = seedRows();
    $token = authToken();

    $list = decode(phrasesGet('/api/admin/phrases', [], $token))['items'];
    assertSame(30, count($list));
    foreach ($list as $item) {
        $response = phrasesGet('/api/admin/phrases/' . $item['id'], [], $token);
        assertSame(200, $response->status, 'id=' . $item['id']);
        assertSame($item, decode($response), 'id=' . $item['id']);
        assertSame(['id', 'category', 'text', 'isActive', 'createdAt', 'updatedAt'], array_keys(decode($response)));
        assertTrue(str_starts_with((string) $response->body, '{"id":' . $item['id'] . ','), 'id — число в тілі');
    }

    $response = phrasesGet('/api/admin/phrases/24', [], $token);
    assertSame($rows['diacritics']['lv'], decode($response)['text']['lv']);

    foreach (['31', '99999', '0', '-1', 'abc', '1.5', '007', '1e3', '4294967296', '99999999999', '1%20', '1%0A', '%20', '0x10', '+1', '1%2F2'] as $bad) {
        $response = phrasesGet('/api/admin/phrases/' . $bad, [], $token);
        assertSame(404, $response->status, "id={$bad}: " . $response->body);
        assertSame('not_found', decode($response)['error']['code'], "id={$bad}");
        assertSame('no-store', $response->headers['Cache-Control'] ?? null, "id={$bad}");
    }
});

// ---------- Кількість запитів, швидкодія ----------

test('phrases: список — рівно 2 SQL-запити, фраза за id — 1, відхилені запити й 404 за форматом id — 0', function () {
    seedRows();
    $token = authToken(); // вхід теж може робити SQL, тож до вимірювань

    $count = static fn (string $path, array $query = []): int => statementCount(static function () use ($path, $query, $token): void {
        phrasesGet($path, $query, $token);
    });

    // Усі гілки списку: без фільтрів, з усіма фільтрами, за межами сторінок, порожній результат
    assertSame(2, $count('/api/admin/phrases'), 'без фільтрів');
    assertSame(2, $count('/api/admin/phrases', ['category' => 'mood', 'search' => 'sodien', 'missing' => 'ru', 'isActive' => 'true', 'page' => '2', 'perPage' => '10']), 'усі фільтри');
    assertSame(2, $count('/api/admin/phrases', ['page' => '999999999']), 'за межами');
    assertSame(2, $count('/api/admin/phrases', ['search' => 'zzzz-немає']), 'порожній результат');

    assertSame(1, $count('/api/admin/phrases/24'), 'фраза за id');
    assertSame(1, $count('/api/admin/phrases/999'), 'неіснуючий id теж 1 запит');

    // Валідація до БД
    $rejected = [
        ['/api/admin/phrases', ['category' => 'x']],
        ['/api/admin/phrases', ['perPage' => '0']],
        ['/api/admin/phrases', ['isActive' => '1']],
        ['/api/admin/phrases', ['search' => str_repeat('a', 201)]],
        ['/api/admin/phrases', ['page' => ['1']]],
        ['/api/admin/phrases', ['missing' => 'de']],
        ['/api/admin/phrases', ['page' => '0']],
        ['/api/admin/phrases/abc', []],
        ['/api/admin/phrases/007', []],
        ['/api/admin/phrases/0', []],
        ['/api/admin/phrases/99999999999', []],
    ];
    foreach ($rejected as [$path, $query]) {
        assertSame(0, $count($path, $query), $path . ' ' . json_encode($query));
    }

    // Контроль вимірювача: запит без SQL справді дає 0, а не «не вимірюється»
    assertSame(0, statementCount(static fn () => null), 'порожня дія — 0 запитів');
});

test('phrases: швидкодія на 600 фразах — медіана з 30 запитів зі search + category + page=2 і без фільтрів < 300 мс', function () {
    $token = authToken();

    withBigTable(function () use ($token) {
        $filtered = phrasesMedianMs(static function () use ($token): void {
            $response = phrasesGet('/api/admin/phrases', ['search' => 'a', 'category' => 'love', 'page' => '2'], $token);
            assertSame(200, $response->status);
        }, 30);
        // Кириличне «а» є в усіх 570 доданих фразах: сторінка 2 справді заповнена
        $heavy = phrasesMedianMs(static function () use ($token): void {
            $response = phrasesGet('/api/admin/phrases', ['search' => 'а', 'category' => 'love', 'page' => '2'], $token);
            assertSame(200, $response->status);
            assertSame(50, count(decode($response)['items']));
        }, 30);
        $plain = phrasesMedianMs(static function () use ($token): void {
            assertSame(200, phrasesGet('/api/admin/phrases', [], $token)->status);
        }, 30);

        assertTrue($filtered < 300, sprintf('search=a + category + page=2: медіана %.1f мс', $filtered));
        assertTrue($heavy < 300, sprintf('search=а (570 збігів) + category + page=2: медіана %.1f мс', $heavy));
        assertTrue($plain < 300, sprintf('без фільтрів: медіана %.1f мс', $plain));
    });
});

test('phrases: на 600 фразах search за унікальним словом знаходить рівно одну фразу (total=1)', function () {
    $token = authToken();

    withBigTable(function () use ($token) {
        $response = phrasesGet('/api/admin/phrases', ['search' => 'номер 417.'], $token);
        $data = decode($response);

        assertSame(1, $data['total']);
        assertSame([447], listIds($response), 'фраза №417 має id 30 + 417');
        assertTrue(str_contains($data['items'][0]['text']['lv'], 'Фраза номер 417.'));

        // Усі 570 доданих фраз знаходяться за спільним словом, а total рахується по всій таблиці
        $many = decode(phrasesGet('/api/admin/phrases', ['search' => 'Фраза номер', 'perPage' => '10'], $token));
        assertSame(570, $many['total']);
        assertSame(10, count($many['items']));
    });
});
