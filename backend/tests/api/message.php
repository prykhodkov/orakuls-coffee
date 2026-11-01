<?php

declare(strict_types=1);

// Перевірки GET /api/message (BE-003) на відомих даних тестової БД. Підключається з tests/api.php.
// Ендпоінт викликається через Kernel у процесі. Статистичні тести навмисно роблять багато запитів
// (по 3000) і мають смугу ±6σ: ні те, ні інше не зменшувати (див. assertUniform у tests/lib.php).

use App\Database\Seeder;
use App\Domain\Languages;

const MESSAGE_SEED_FILE = APP_ROOT . '/database/seed/phrases.php';

/**
 * Множина кандидатів для мови, виведена з даних сіду, а не захардкоджена: active && переклад не порожній.
 * Дзеркалить SQL-критерій TRIM(col) <> '' (лише пробіли) — у сіді інших пробільних символів немає.
 *
 * @param array<string, int> $ids key → id (результат reseed())
 * @return array<string, int> key → id
 */
function availableIds(string $lang, array $ids): array
{
    $result = [];
    foreach (Seeder::load(MESSAGE_SEED_FILE) as $row) {
        if ($row['active'] && trim($row[$lang], ' ') !== '') {
            $result[$row['key']] = $ids[$row['key']];
        }
    }

    return $result;
}

/**
 * $n запитів до ендпоінта; кожен має дати 200.
 *
 * @return array<int, int> id → скільки разів випав
 */
function draw(string $lang, ?string $exclude, int $n): array
{
    $query = ['lang' => $lang];
    if ($exclude !== null) {
        $query['exclude'] = $exclude;
    }

    $counts = [];
    for ($i = 0; $i < $n; $i++) {
        $response = apiRequest('GET', '/api/message', $query);
        if ($response->status !== 200) {
            throw new AssertionError("draw({$lang}): expected 200, got {$response->status}: {$response->body}");
        }
        $id = decode($response)['id'];
        $counts[$id] = ($counts[$id] ?? 0) + 1;
    }

    return $counts;
}

/** Деактивує всі фрази, окрім однієї: відповідь стає детермінованою. */
function keepOnlyActive(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE phrases SET is_active = 0 WHERE id <> ?')->execute([$id]);
}

/** Сесійний лічильник байтів, відправлених сервером БД цьому з'єднанню. Rows_sent у MySQL 5.7 немає. */
function bytesSent(PDO $pdo): int
{
    $row = $pdo->query("SHOW SESSION STATUS LIKE 'Bytes_sent'")->fetch(PDO::FETCH_NUM);

    return (int) $row[1];
}

/**
 * Виконує $fn на таблиці з 600 фразами (сід + 570 довгих) у транзакції, яка відкочується.
 * Відкат не повертає AUTO_INCREMENT, тому в кінці reseed() (TRUNCATE).
 */
function withBigTable(callable $fn): void
{
    $pdo = testPdo();
    reseed();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO phrases (category, text_lv, text_en, text_ru, is_active, created_at, updated_at)'
            . " VALUES ('love', ?, '', '', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        for ($i = 1; $i <= 570; $i++) {
            $insert->execute([str_repeat("Фраза номер {$i}. ", 6)]);
        }
        assertSame(600, phraseCount($pdo), 'у таблиці має бути 600 фраз');

        $fn();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        reseed();
    }
}

test('message: відповідь 200 має рівно ключі id, lang, text (в цьому порядку); id — число', function () {
    reseed();

    foreach (Languages::CODES as $lang) {
        $response = apiRequest('GET', '/api/message', ['lang' => $lang]);
        $data = decode($response);

        assertSame(200, $response->status, $lang);
        assertSame(['id', 'lang', 'text'], array_keys($data), "ключі {$lang}");
        assertTrue(is_int($data['id']), "id — int ({$lang})");
        assertSame($lang, $data['lang']);
        assertTrue(is_string($data['text']) && $data['text'] !== '', "text непорожній ({$lang})");
        assertTrue(str_starts_with($response->body ?? '', '{"id":'), 'id у тілі — число, не рядок: ' . $response->body);
        assertSame('application/json; charset=utf-8', $response->headers['Content-Type'] ?? null);
    }
});

test('message: Cache-Control: no-store у відповідях 200, 400 і 404', function () {
    $pdo = testPdo();
    reseed();

    try {
        assertSame('no-store', apiRequest('GET', '/api/message', ['lang' => 'lv'])->headers['Cache-Control'] ?? null, '200');
        assertSame('no-store', apiRequest('GET', '/api/message')->headers['Cache-Control'] ?? null, '400');

        $pdo->exec("UPDATE phrases SET text_en = ''");
        $response = apiRequest('GET', '/api/message', ['lang' => 'en']);
        assertSame(404, $response->status);
        assertSame('no-store', $response->headers['Cache-Control'] ?? null, '404');
    } finally {
        reseed();
    }
});

test('message: lang відсутній, порожній, de, LV, «lv », масив → 400 bad_request без fields', function () {
    $cases = [
        'відсутній' => [],
        'порожній' => ['lang' => ''],
        'невідомий de' => ['lang' => 'de'],
        'регістр LV' => ['lang' => 'LV'],
        'з пробілом' => ['lang' => 'lv '],
        'масив lang[]=lv' => ['lang' => ['lv']],
    ];

    foreach ($cases as $name => $query) {
        $response = apiRequest('GET', '/api/message', $query);
        $data = decode($response);

        assertSame(400, $response->status, $name);
        assertSame('bad_request', $data['error']['code'], $name);
        assertSame(['code', 'message'], array_keys($data['error']), "{$name}: без fields");
    }

    // Лише коректний lang з некоректним exclude — не 400
    assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'lv', 'exclude' => 'abc'])->status);
});

test('message: неактивна фраза ніколи не повертається; єдина активна — завжди вона; без активних → 404', function () {
    $pdo = testPdo();
    $ids = reseed();

    try {
        foreach (Languages::CODES as $lang) {
            $counts = draw($lang, null, 600);
            assertTrue(!isset($counts[$ids['hidden']]), "hidden не повинна випадати ({$lang})");
        }

        keepOnlyActive($pdo, $ids['luck_1']);
        assertSame([$ids['luck_1'] => 100], draw('lv', null, 100), 'лишилась одна активна');

        $pdo->prepare('UPDATE phrases SET is_active = 0 WHERE id = ?')->execute([$ids['luck_1']]);
        $response = apiRequest('GET', '/api/message', ['lang' => 'lv']);
        assertSame(404, $response->status);
        assertSame('not_found', decode($response)['error']['code']);
    } finally {
        reseed();
    }
});

test('message: порожні й «лише пробіли» переклади не повертаються для своєї мови, але повертаються для інших', function () {
    $ids = reseed();

    $expected = [];
    foreach (Languages::CODES as $lang) {
        $expected[$lang] = availableIds($lang, $ids);
        $got = array_keys(draw($lang, null, 3000));
        sort($got);
        $want = array_values($expected[$lang]);
        sort($want);
        assertSame($want, $got, "множина виданих id для {$lang}");
    }

    $inSet = static fn (string $lang, string $key): bool => in_array($ids[$key], $expected[$lang], true);

    assertTrue(!$inSet('en', 'only_lv') && !$inSet('en', 'blank_en'), 'en: ні only_lv, ні blank_en (пробіли)');
    assertTrue(!$inSet('ru', 'only_lv') && !$inSet('ru', 'only_en') && !$inSet('ru', 'no_ru'), 'ru: ні only_lv, ні only_en, ні no_ru');
    assertTrue(!$inSet('lv', 'only_en') && !$inSet('lv', 'hidden'), 'lv: ні only_en, ні hidden');
    // Але для інших мов ці фрази доступні
    assertTrue($inSet('lv', 'only_lv') && $inSet('lv', 'blank_en') && $inSet('en', 'only_en') && $inSet('ru', 'blank_en'), 'для своїх мов — доступні');
});

test('message: немає доступних фраз для мови → 404 not_found (а для інших мов — 200); усе вимкнено → 404 для всіх', function () {
    $pdo = testPdo();
    reseed();

    try {
        $pdo->exec("UPDATE phrases SET text_en = ''");
        $response = apiRequest('GET', '/api/message', ['lang' => 'en']);
        assertSame(404, $response->status, 'en');
        assertSame('not_found', decode($response)['error']['code']);
        assertSame(['code', 'message'], array_keys(decode($response)['error']));
        assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'lv'])->status, 'lv');
        assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'ru'])->status, 'ru');

        reseed();
        $pdo->exec('UPDATE phrases SET is_active = 0');
        foreach (Languages::CODES as $lang) {
            assertSame(404, apiRequest('GET', '/api/message', ['lang' => $lang])->status, "усе вимкнено: {$lang}");
        }
    } finally {
        reseed();
    }
});

test('message: exclude з 8 ID — жодного влучання за 3000 запитів, решта 20 кандидатів розподілені рівномірно', function () {
    $ids = reseed();
    $available = array_values(availableIds('lv', $ids));
    $excluded = array_slice($available, 0, 8);
    $rest = array_slice($available, 8);
    assertSame(20, count($rest), 'для lv 28 кандидатів, 8 виключено');

    $counts = draw('lv', implode(',', $excluded), 3000);

    foreach ($excluded as $id) {
        assertTrue(!isset($counts[$id]), "виключений id={$id} випав");
    }
    assertUniform($rest, $counts, 3000); // зсуву в бік менших ID немає: виключені саме найменші id
});

test('message: exclude покриває всі доступні → 200 з будь-якою доступною; лишилась одна → завжди вона', function () {
    $ids = reseed();
    $available = array_values(availableIds('lv', $ids));

    $counts = draw('lv', implode(',', $available), 300);
    assertSame([], array_values(array_diff(array_keys($counts), $available)), 'лише доступні id');
    assertTrue(count($counts) > 1, 'вибір усе ще випадковий, а не завжди одна й та сама фраза');

    $allButOne = array_slice($available, 1);
    assertSame([$available[0] => 100], draw('lv', implode(',', $allButOne), 100), 'єдина невиключена');
});

test('message: exclude ніколи не дає 400; сміття пропускається, коректні елементи діють', function () {
    $pdo = testPdo();
    $ids = reseed();
    $valid = array_slice(array_values(availableIds('lv', $ids)), 0, 5);

    // Сміття разом з коректними ID: коректні все одно виключені
    $mixed = 'abc,,-5,1.5,12345678901,' . implode(',', $valid);
    $counts = draw('lv', $mixed, 600);
    foreach ($valid as $id) {
        assertTrue(!isset($counts[$id]), "коректний id={$id} з бруду має діяти");
    }

    // Пробіли навколо чисел толеруються: id 5 і 6 — доступні для lv
    $counts = draw('lv', ' 5 , 6 ', 600);
    assertTrue(!isset($counts[5]) && !isset($counts[6]), 'пробіли навколо чисел');

    $variants = [
        'порожній' => '',
        'самі коми' => ',,,',
        'нечисловий' => 'abc',
        'порожні елементи' => '1,,2',
        'від\'ємний' => '-5',
        'дробовий' => '1.5',
        '11 цифр' => '12345678901',
        'SQL' => "1); DROP TABLE phrases; --",
        'ін\'єкція в лапках' => "' OR '1'='1",
        'відсотки' => '%,_,\\',
        'юнікод' => 'Šodien,Тест,٣',
        'дуже довгий' => str_repeat('9', 5000),
        '1500 ID' => implode(',', range(1, 1500)),
        '1 МБ сміття' => str_repeat('a,', 524288),
    ];
    foreach ($variants as $name => $exclude) {
        $response = apiRequest('GET', '/api/message', ['lang' => 'lv', 'exclude' => $exclude]);
        assertSame(200, $response->status, "exclude: {$name}");
    }
    assertSame(30, phraseCount($pdo), 'таблицю не зачепили');

    // Масив у exclude трактується як відсутній
    assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'lv', 'exclude' => ['1', '2']])->status, 'exclude[]=1&exclude[]=2');
    assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'lv', 'exclude' => [['x']]])->status, 'вкладений масив');
});

test('message: враховуються лише перші 200 коректних ID (201-й діє як відсутній, 1-й — як виключений)', function () {
    $ids = reseed();
    $target = $ids['luck_1'];
    $filler = range(100000, 100249); // неіснуючі коректні ID

    // target на 201-му місці: поза лімітом, тож може випасти
    $beyond = array_merge(array_slice($filler, 0, 200), [$target]);
    $counts = draw('lv', implode(',', $beyond), 600);
    assertTrue(isset($counts[$target]), 'ID на 201-му місці не враховується і має траплятися');

    // target на 200-му місці: ще в межах ліміту, ніколи не випадає
    $atLimit = array_merge(array_slice($filler, 0, 199), [$target]);
    assertTrue(!isset(draw('lv', implode(',', $atLimit), 300)[$target]), 'ID на 200-му місці виключений');

    // target першим, далі 250 інших: виключений
    $first = array_merge([$target], $filler);
    assertTrue(!isset(draw('lv', implode(',', $first), 300)[$target]), 'ID на 1-му місці виключений');

    // сміття не займає місце в ліміті: 300 «abc» перед target
    $junkFirst = implode(',', array_fill(0, 300, 'abc')) . ',' . $target;
    assertTrue(!isset(draw('lv', $junkFirst, 300)[$target]), 'сміття не рахується в ліміт');

    // понад 1000 елементів — без помилок
    assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'lv', 'exclude' => implode(',', range(1, 1500))])->status);
});

test('message: розподіл без exclude рівномірний (3000 запитів, 28 кандидатів для lv, ±6σ)', function () {
    $ids = reseed();
    $candidates = array_values(availableIds('lv', $ids));
    assertSame(28, count($candidates));

    assertUniform($candidates, draw('lv', null, 3000), 3000);
});

test('message: text збігається зі збереженим; діакритика, кирилиця, \n, HTML, емодзі не екрануються', function () {
    $pdo = testPdo();
    $ids = reseed();

    try {
        // Усі видані тексти збігаються з сідом для своєї мови (3 × 1000 запитів)
        $expected = [];
        foreach (Seeder::load(MESSAGE_SEED_FILE) as $row) {
            foreach (Languages::CODES as $lang) {
                $expected[$lang][$ids[$row['key']]] = $row[$lang];
            }
        }
        foreach (Languages::CODES as $lang) {
            for ($i = 0; $i < 1000; $i++) {
                $data = decode(apiRequest('GET', '/api/message', ['lang' => $lang]));
                assertSame($expected[$lang][$data['id']], $data['text'], "{$lang} id={$data['id']}");
            }
        }

        // Детерміновано: сире тіло відповіді
        $body = static function (string $key, string $lang) use ($pdo, $ids): string {
            reseed(); // попередній виклик вимкнув усе, окрім своєї фрази
            keepOnlyActive($pdo, $ids[$key]);
            $response = apiRequest('GET', '/api/message', ['lang' => $lang]);
            assertSame(200, $response->status, $key);

            return (string) $response->body;
        };

        assertSame(
            '{"id":' . $ids['diacritics'] . ',"lang":"lv","text":"Šodien veiksme būs tavā pusē."}',
            $body('diacritics', 'lv')
        );
        assertSame(
            '{"id":' . $ids['diacritics'] . ',"lang":"ru","text":"Сегодня удача на твоей стороне."}',
            $body('diacritics', 'ru')
        );
        assertTrue(str_contains($body('multiline', 'en'), 'Line one\nLine two'), 'перенос рядка — екранований \n у JSON');
        assertTrue(str_contains($body('html', 'en'), '<script>alert(1)</script> & <b>bold</b>'), 'HTML без екранування');
        $emoji = $body('emoji', 'en');
        assertTrue(str_contains($emoji, '🍀 Luck loves you 💛') && !str_contains($emoji, '\u'), 'емодзі без \u-послідовностей');
    } finally {
        reseed();
    }
});

test('message: len500 повертається цілком (500 символів)', function () {
    $pdo = testPdo();
    $ids = reseed();

    try {
        keepOnlyActive($pdo, $ids['len500']);
        foreach (['lv' => str_repeat('š', 500), 'en' => str_repeat('😀', 500)] as $lang => $text) {
            $data = decode(apiRequest('GET', '/api/message', ['lang' => $lang]));
            assertSame(500, mb_strlen($data['text']), $lang);
            assertSame($text, $data['text'], $lang);
        }
    } finally {
        reseed();
    }
});

test('message: один SQL-запит — один рядок: на 600 фразах Bytes_sent у ≥ 10 разів менший, ніж для вибірки всієї таблиці', function () {
    withBigTable(function () {
        $pdo = testPdo();
        $query = ['lang' => 'lv', 'exclude' => implode(',', range(1, 100))];

        // «Порожня» дельта: сам вимірювальний SHOW додає байти до наступного вимірювання
        $a = bytesSent($pdo);
        $b = bytesSent($pdo);
        $overhead = $b - $a;

        $before = bytesSent($pdo);
        $response = apiRequest('GET', '/api/message', $query);
        $after = bytesSent($pdo);
        assertSame(200, $response->status);
        $one = ($after - $before) - $overhead;

        $before = bytesSent($pdo);
        $rows = $pdo->query('SELECT id, text_lv FROM phrases WHERE is_active = 1')->fetchAll();
        $after = bytesSent($pdo);
        $all = ($after - $before) - $overhead;

        assertSame(599, count($rows), '600 фраз, з них одна прихована (hidden)');
        assertTrue($one > 0, "Bytes_sent для одного запиту = {$one}");
        assertTrue($one * 10 < $all, "один запит: {$one} байт, уся таблиця: {$all} байт — різниця менша за 10 разів");
    });
});

test('message: швидкодія — медіана з 30 запитів на 600 фразах з exclude зі 100 ID менша за 200 мс', function () {
    withBigTable(function () {
        $query = ['lang' => 'lv', 'exclude' => implode(',', range(1, 100))];

        apiRequest('GET', '/api/message', $query); // прогрів: перший виклик відкриває prepare-кеш
        $times = [];
        for ($i = 0; $i < 30; $i++) {
            $start = microtime(true);
            $response = apiRequest('GET', '/api/message', $query);
            $times[] = (microtime(true) - $start) * 1000;
            assertSame(200, $response->status);
        }
        sort($times);
        $median = ($times[14] + $times[15]) / 2;

        assertTrue($median < 200, sprintf('медіана %.2f мс (мін %.2f, макс %.2f)', $median, $times[0], $times[29]));
    });
});

test('message: без авторизації; у відповіді немає category, isActive, createdAt, updatedAt', function () {
    reseed();

    $response = apiRequest('GET', '/api/message', ['lang' => 'ru'], []); // жодних заголовків
    $data = decode($response);

    assertSame(200, $response->status);
    foreach (['category', 'isActive', 'createdAt', 'updatedAt', 'is_active', 'created_at', 'text_ru'] as $field) {
        assertTrue(!array_key_exists($field, $data), "зайве поле {$field}");
    }
    assertSame(['id', 'lang', 'text'], array_keys($data));

    // Недійсний Authorization нічого не змінює: ендпоінт його не читає
    assertSame(200, apiRequest('GET', '/api/message', ['lang' => 'ru'], ['authorization' => 'Bearer garbage'])->status);
});

test('message: POST, PUT, DELETE, HEAD → 404 not_found', function () {
    reseed();

    foreach (['POST', 'PUT', 'DELETE', 'HEAD', 'PATCH'] as $method) {
        $response = apiRequest($method, '/api/message', ['lang' => 'lv']);
        assertSame(404, $response->status, $method);
        assertSame('not_found', decode($response)['error']['code'], $method);
    }
});
