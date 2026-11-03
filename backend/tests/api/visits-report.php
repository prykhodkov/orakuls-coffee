<?php

declare(strict_types=1);

// Перевірки GET /api/admin/visits (BE-013): звіт зі статистики за період. Підключається з tests/api.php. Самодостатній: помічники
// з префіксом vr*. Історія для звіту — окрема фікстура database/seed/daily_stats.php (vrWithHistory), у reseed() вона не потрапляє.
// Очікування рахує незалежний оракул (vrOracle: календарні дати через gmmktime, без ReportPeriod/VisitReport) і ручні константи.

use App\Database\Seeder;
use App\Http\Response;
use App\Support\RigaClock;

const VR_PATH = '/api/admin/visits';
const VR_HISTORY_FILE = APP_ROOT . '/database/seed/daily_stats.php';

/** Один токен на процес: вхід теж робить SQL, а statementCount не повинен його рахувати. */
function vrToken(): string
{
    static $token = null;

    return $token ??= authToken();
}

/** @param array<string, mixed> $query */
function vrGet(array $query = [], ?string $token = null): Response
{
    return apiRequest('GET', VR_PATH, $query, ['authorization' => 'Bearer ' . ($token ?? vrToken())]);
}

/** @param array<string, mixed> $query */
function vrAnonymous(array $query = []): Response
{
    return apiRequest('GET', VR_PATH, $query);
}

/** Тіло відповіді 200 як масив. */
function vrOf(string $from, string $to): array
{
    $response = vrGet(['from' => $from, 'to' => $to]);
    assertSame(200, $response->status, "{$from}…{$to}: " . $response->body);

    return decode($response);
}

/** Єдина точка виклику годинника проєкту (BE-011) у цьому файлі. */
function vrToday(): string
{
    return (new RigaClock())->today();
}

/** День ± $n діб на «чистих» датах (gmmktime: без DST і без ReportPeriod). */
function vrAddDays(string $date, int $n): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $date));

    return gmdate('Y-m-d', gmmktime(0, 0, 0, $m, $d + $n, $y));
}

/**
 * Незалежне очікування звіту з рядків [{day, metric, count}] (формат Seeder::loadStats); не використовує ReportPeriod/VisitReport.
 *
 * @param list<array{day: string, metric: string, count: int}> $rows
 */
function vrOracle(string $from, string $to, array $rows): array
{
    $byKey = [];
    foreach ($rows as $row) {
        $byKey["{$row['day']}|{$row['metric']}"] = ($byKey["{$row['day']}|{$row['metric']}"] ?? 0) + $row['count'];
    }

    $days = [];
    $total = ['visits' => 0, 'scans' => 0, 'messages' => 0, 'byLang' => ['lv' => 0, 'en' => 0, 'ru' => 0]];
    for ($date = $from; $date <= $to; $date = vrAddDays($date, 1)) {
        $lang = ['lv' => $byKey["{$date}|message_lv"] ?? 0, 'en' => $byKey["{$date}|message_en"] ?? 0, 'ru' => $byKey["{$date}|message_ru"] ?? 0];
        $day = ['date' => $date, 'visits' => $byKey["{$date}|visit"] ?? 0, 'scans' => $byKey["{$date}|scan"] ?? 0, 'messages' => array_sum($lang), 'byLang' => $lang];
        $days[] = $day;
        $total['visits'] += $day['visits'];
        $total['scans'] += $day['scans'];
        $total['messages'] += $day['messages'];
        foreach ($lang as $code => $count) {
            $total['byLang'][$code] += $count;
        }
    }

    return ['total' => $total, 'days' => $days];
}

/** Фікстура історії в daily_stats на час $fn; потім reseed() (він же очищає таблицю). */
function vrWithHistory(callable $fn): void
{
    withFreshSeed(function () use ($fn) {
        (new Seeder(testPdo(), APP_ROOT . '/database/seed/phrases.php'))->runStats(VR_HISTORY_FILE);
        $fn();
    });
}

/** @param list<array{0: string, 1: string, 2: int}> $rows [day, metric, count] — прямий INSERT, незалежний від коду BE-011 */
function vrInsert(array $rows): void
{
    $insert = testPdo()->prepare('INSERT INTO `daily_stats` (`day`, `metric`, `count`) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        $insert->execute($row);
    }
}

/** Наскрізні тести залежать від годинника: якщо за час виконання змінилась доба за Ригою, тіло повторюється один раз. */
function vrWithStableDay(callable $fn): void
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $today = vrToday();
        $stable = false;
        withFreshSeed(function () use ($fn, $today, &$stable) {
            $fn($today);
            $stable = vrToday() === $today;
        });
        if ($stable) {
            return;
        }
    }

    throw new AssertionError('доба за Ригою змінювалась під час тесту двічі поспіль');
}

/** Ручні контрольні числа з фікстури (рахувались вручну, незалежно від оракулу). */
const VR_MANUAL = [
    ['2025-12-29', '2026-01-03', 6, ['visits' => 45, 'scans' => 32, 'messages' => 25, 'lv' => 13, 'en' => 3, 'ru' => 9]],
    ['2026-02-27', '2026-03-02', 4, ['visits' => 71, 'scans' => 55, 'messages' => 48, 'lv' => 24, 'en' => 9, 'ru' => 15]],
    ['2026-03-28', '2026-03-30', 3, ['visits' => 65, 'scans' => 51, 'messages' => 43, 'lv' => 21, 'en' => 8, 'ru' => 14]],
    ['2026-10-24', '2026-10-26', 3, ['visits' => 94, 'scans' => 72, 'messages' => 62, 'lv' => 30, 'en' => 11, 'ru' => 21]],
];

function vrAssertTotals(array $total, array $expected, string $note): void
{
    assertSame(
        ['visits' => $expected['visits'], 'scans' => $expected['scans'], 'messages' => $expected['messages'], 'byLang' => ['lv' => $expected['lv'], 'en' => $expected['en'], 'ru' => $expected['ru']]],
        $total,
        $note
    );
}

// ---------- Доступ і форма ----------

test('звіт: без токена — 401 і з правильними, і з поганими параметрами (guard раніше за валідацію), 0 SQL', function () {
    $token = vrToken();
    foreach ([['from' => '2026-11-01', 'to' => '2026-11-03'], ['from' => 'bad'], []] as $query) {
        assertSame(0, statementCount(function () use ($query) {
            authAssertUnauthorized(vrAnonymous($query), json_encode($query));
        }));
    }
    authAssertUnauthorized(vrGet(['from' => '2026-11-01', 'to' => '2026-11-03'], 'garbage.token.value'), 'сміттєвий токен');
    assertSame(200, vrGet(['from' => '2026-11-01', 'to' => '2026-11-03'], $token)->status, 'з токеном — 200');
});

test('звіт: форма відповіді й типи — ключі й їх порядок, byLang як об\'єкт, int, no-store', function () {
    withFreshSeed(function () {
        $response = vrGet(['from' => '2026-11-01', 'to' => '2026-11-03']);
        assertSame(200, $response->status);
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        assertTrue(str_starts_with($response->headers['Content-Type'] ?? '', 'application/json'));

        $data = decode($response);
        assertSame(['from', 'to', 'timezone', 'total', 'days'], array_keys($data));
        assertSame('Europe/Riga', $data['timezone']);
        assertSame('2026-11-01', $data['from']);
        assertSame('2026-11-03', $data['to']);
        assertSame(['visits', 'scans', 'messages', 'byLang'], array_keys($data['total']));
        assertSame(['date', 'visits', 'scans', 'messages', 'byLang'], array_keys($data['days'][0]));
        assertSame(['lv', 'en', 'ru'], array_keys($data['total']['byLang']));
        assertSame(['lv', 'en', 'ru'], array_keys($data['days'][0]['byLang']));

        // Сирий JSON: об'єкти й числа, а не масиви й рядки
        assertTrue(str_contains((string) $response->body, '"byLang":{"lv":0,"en":0,"ru":0}'), 'byLang — об\'єкт');
        assertTrue(!str_contains((string) $response->body, '"byLang":['), 'byLang не список');
        assertTrue(str_contains((string) $response->body, '"days":['), 'days — список');
        assertTrue(str_contains((string) $response->body, '"visits":0'), 'нулі — числа');
    });
});

test('звіт: порожня таблиця — усі дні періоду з нулями int, не null і не рядок', function () {
    withFreshSeed(function () {
        $data = vrOf('2026-11-01', '2026-11-05');
        assertSame(5, count($data['days']));
        foreach ($data['days'] as $day) {
            assertSame([0, 0, 0, 0, 0, 0], [$day['visits'], $day['scans'], $day['messages'], $day['byLang']['lv'], $day['byLang']['en'], $day['byLang']['ru']]);
        }
        vrAssertTotals($data['total'], ['visits' => 0, 'scans' => 0, 'messages' => 0, 'lv' => 0, 'en' => 0, 'ru' => 0], 'total');
    });
});

// ---------- Дані ----------

test('звіт: історія збігається з оракулом і з ручними контрольними числами (включно з переходом року, місяця, днями без даних)', function () {
    vrWithHistory(function () {
        $history = Seeder::loadStats(VR_HISTORY_FILE);

        foreach (VR_MANUAL as [$from, $to, $dayCount, $manual]) {
            $data = vrOf($from, $to);
            assertSame($dayCount, count($data['days']), "{$from}…{$to}: днів");
            vrAssertTotals($data['total'], $manual, "{$from}…{$to}: ручні числа");
            $oracle = vrOracle($from, $to, $history);
            assertSame($oracle['days'], $data['days'], "{$from}…{$to}: дні за оракулом");
            assertSame($oracle['total'], $data['total'], "{$from}…{$to}: total за оракулом");
        }

        foreach ([['2025-12-29', '2026-10-27'], ['2025-12-31', '2026-01-01'], ['2025-12-30', '2025-12-30'], ['2026-01-02', '2026-01-02'], ['2026-01-03', '2026-01-03'], ['2026-01-01', '2026-01-01']] as [$from, $to]) {
            $data = vrOf($from, $to);
            $oracle = vrOracle($from, $to, $history);
            assertSame($oracle['days'], $data['days'], "{$from}…{$to}");
            assertSame($oracle['total'], $data['total'], "{$from}…{$to}");
        }
    });
});

test('звіт: кожен день присутній — N днів по зростанню, сусідні різняться на добу, перший = from, останній = to', function () {
    withFreshSeed(function () {
        foreach ([1, 2, 7, 30, 31, 365, 366] as $n) {
            $from = '2025-11-04';
            $to = vrAddDays($from, $n - 1);
            $data = vrOf($from, $to);
            assertSame($n, count($data['days']), "N={$n}");
            assertSame($from, $data['days'][0]['date']);
            assertSame($to, $data['days'][$n - 1]['date']);
            for ($i = 1; $i < $n; $i++) {
                assertSame(vrAddDays($data['days'][$i - 1]['date'], 1), $data['days'][$i]['date'], "N={$n} день #{$i}");
            }
        }
    });
});

test('звіт: інваріанти — total = сума днів, messages = lv + en + ru, scans ≤ visits', function () {
    vrWithHistory(function () {
        $data = vrOf('2025-12-29', '2026-10-27');

        $sum = ['visits' => 0, 'scans' => 0, 'messages' => 0, 'lv' => 0, 'en' => 0, 'ru' => 0];
        foreach ($data['days'] as $day) {
            assertSame($day['byLang']['lv'] + $day['byLang']['en'] + $day['byLang']['ru'], $day['messages'], $day['date']);
            assertTrue($day['scans'] <= $day['visits'], "scans ≤ visits: {$day['date']}");
            $sum['visits'] += $day['visits'];
            $sum['scans'] += $day['scans'];
            $sum['messages'] += $day['messages'];
            foreach (['lv', 'en', 'ru'] as $lang) {
                $sum[$lang] += $day['byLang'][$lang];
            }
        }
        vrAssertTotals($data['total'], $sum, 'total = сума днів');
        assertSame($data['total']['byLang']['lv'] + $data['total']['byLang']['en'] + $data['total']['byLang']['ru'], $data['total']['messages']);
    });
});

test('звіт: один день, майбутнє, межа 9999-12-31 — 200 з нулями', function () {
    vrWithHistory(function () {
        $one = vrOf('2026-10-25', '2026-10-25');
        assertSame(1, count($one['days']));
        assertSame(28, $one['total']['visits']);

        $today = vrToday();
        $future = vrOf($today, vrAddDays($today, 365)); // рівно 366 днів
        assertSame(366, count($future['days']));
        assertSame(0, $future['total']['visits']);

        $far = vrOf('2099-01-01', '2099-01-03');
        assertSame(3, count($far['days']));
        assertSame(0, $far['total']['visits']);

        $last = vrOf('9999-12-31', '9999-12-31');
        assertSame(['9999-12-31'], array_column($last['days'], 'date'));
    });
});

test('звіт: межі довжини — 366 днів проходить, 367 → 400; високосний рік; from > to → 400; 0001…9999 → 400 швидко й без SQL', function () {
    withFreshSeed(function () {
        assertSame(366, count(vrOf('2026-01-01', '2027-01-01')['days']));
        assertSame(400, vrGet(['from' => '2026-01-01', 'to' => '2027-01-02'])->status);
        assertSame(366, count(vrOf('2024-01-01', '2024-12-31')['days']), 'високосний');
        assertSame(400, vrGet(['from' => '2024-01-01', 'to' => '2025-01-01'])->status, '367 днів');
        assertSame(400, vrGet(['from' => '2026-11-04', 'to' => '2026-11-03'])->status, 'from > to');

        $start = hrtime(true);
        $queries = statementCount(function () {
            assertSame(400, vrGet(['from' => '0001-01-01', 'to' => '9999-12-31'])->status);
        });
        assertSame(0, $queries, 'без SQL');
        assertTrue((hrtime(true) - $start) / 1e6 < 200, 'швидко');
    });
});

test('звіт: погані значення from і to → 400 bad_request без fields, no-store, без SQL і без відображення вводу; коректні контролі проходять', function () {
    withFreshSeed(function () {
        $bad = [
            '2026-02-30', '2026-13-01', '2026-00-10', '2026-04-31', '2027-02-29', '2100-02-29', '2026-1-5', '26-01-01', '2026/01/01', '20260101',
            '2026-01-01T00:00:00Z', ' 2026-01-01', '2026-01-01 ', "2026-01-01\n", '+2026-01-01', '0000-01-01', 'abc', '<script>alert(1)</script>',
            "2026-01-01'; DROP TABLE daily_stats;--",
        ];
        foreach (['from', 'to'] as $name) {
            $other = $name === 'from' ? 'to' : 'from';
            foreach ($bad as $value) {
                $response = null;
                $queries = statementCount(function () use (&$response, $name, $other, $value) {
                    $response = vrGet([$name => $value, $other => '2026-11-03']);
                });
                assertSame(400, $response->status, "{$name}=" . json_encode($value));
                assertSame(0, $queries, "SQL на 400: {$name}=" . json_encode($value));
                assertSame('no-store', $response->headers['Cache-Control'] ?? null);
                $error = decode($response)['error'];
                assertSame('bad_request', $error['code']);
                assertSame(['code', 'message'], array_keys($error), 'без fields');
                assertTrue(!str_contains($error['message'], trim($value, " \n")) || $value === '', 'повідомлення не повторює ввід');
            }
            // відсутній, порожній, масив
            assertSame(400, vrGet([$other => '2026-11-03'])->status, "{$name} відсутній");
            assertSame(400, vrGet([$name => '', $other => '2026-11-03'])->status, "{$name} порожній");
            assertSame(400, vrGet([$name => ['2026-11-03'], $other => '2026-11-03'])->status, "{$name} масив");
        }
        assertSame(400, vrGet()->status, 'без параметрів');

        // Позитивні контролі
        assertSame(200, vrGet(['from' => '2028-02-29', 'to' => '2028-02-29'])->status);
        assertSame(200, vrGet(['from' => '2000-02-29', 'to' => '2000-02-29'])->status);
        assertSame(200, vrGet(['from' => '0999-01-01', 'to' => '0999-01-02'])->status, 'рік до 1000');
        assertSame('bad_request', decode(vrGet(['from' => '0000-01-01', 'to' => '0000-01-02']))['error']['code'], 'рік 0000 — 400, а не 500');
    });
});

test('звіт: зайві query-параметри ігноруються — відповідь байт у байт така сама', function () {
    vrWithHistory(function () {
        $plain = vrGet(['from' => '2026-03-28', 'to' => '2026-03-30']);
        $noisy = vrGet(['from' => '2026-03-28', 'to' => '2026-03-30', 'x' => '1', 'category' => ['y'], 'lang' => 'lv', 'perPage' => '5']);
        assertSame($plain->body, $noisy->body);
        assertSame($plain->headers, $noisy->headers);
    });
});

test('звіт: інші методи й шляхи → 404 з токеном і 401 без токена', function () {
    withFreshSeed(function () {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $response = apiRequest($method, VR_PATH, ['from' => '2026-11-01', 'to' => '2026-11-03'], ['authorization' => 'Bearer ' . vrToken()]);
            assertSame(404, $response->status, $method);
            assertSame('not_found', decode($response)['error']['code'], $method);
            assertSame(401, apiRequest($method, VR_PATH)->status, "{$method} без токена");
        }
        assertSame(404, apiRequest('GET', VR_PATH . '/1', [], ['authorization' => 'Bearer ' . vrToken()])->status);
    });
});

test('звіт: переходи часу й межі — 2026-03-29 і 2026-10-25 рівно по одному разу, 245 днів, незалежно від пояса за замовчуванням', function () {
    vrWithHistory(function () {
        $history = Seeder::loadStats(VR_HISTORY_FILE);

        foreach ([['2026-03-28', '2026-03-30', '2026-03-29'], ['2026-10-24', '2026-10-26', '2026-10-25']] as [$from, $to, $middle]) {
            $data = vrOf($from, $to);
            $dates = array_column($data['days'], 'date');
            assertSame([$from, $middle, $to], $dates);
            assertSame(1, count(array_keys($dates, $middle, true)), "{$middle} рівно один раз");
        }

        $long = vrOf('2026-03-01', '2026-10-31');
        $dates = array_column($long['days'], 'date');
        assertSame(245, count($dates));
        assertSame(245, count(array_unique($dates)));
        assertSame(vrOracle('2026-03-01', '2026-10-31', $history), ['total' => $long['total'], 'days' => $long['days']]);

        // Те саме тіло при зміненому поясі за замовчуванням: арифметика явно в UTC
        $baseline = vrGet(['from' => '2026-03-28', 'to' => '2026-03-30'])->body;
        $previous = date_default_timezone_get();
        try {
            foreach (['Europe/Riga', 'America/New_York'] as $zone) {
                date_default_timezone_set($zone);
                assertSame($baseline, vrGet(['from' => '2026-03-28', 'to' => '2026-03-30'])->body, $zone);
                assertSame(3, count(vrOf('2026-10-24', '2026-10-26')['days']), $zone);
            }
        } finally {
            date_default_timezone_set($previous);
        }

        // Високосний день з'являється у звіті, а неіснуючий 2027-02-29 — 400
        vrInsert([['2028-02-29', 'visit', 3]]);
        assertSame(3, vrOf('2028-02-29', '2028-02-29')['total']['visits']);
        assertSame(400, vrGet(['from' => '2027-02-29', 'to' => '2027-03-01'])->status);
    });
});

test('звіт: невідома метрика ігнорується, нульовий рядок — нулі, рядок поза періодом не впливає, велике count лишається int', function () {
    withFreshSeed(function () {
        vrInsert([
            ['2026-11-01', 'foo', 100], ['2026-11-01', 'visit', 0], ['2026-10-31', 'visit', 100], ['2026-11-04', 'visit', 100],
            ['2026-11-02', 'visit', 4294967295], ['2026-11-03', 'visit', 4294967295],
        ]);
        $response = vrGet(['from' => '2026-11-01', 'to' => '2026-11-03']);
        $data = decode($response);

        assertSame(0, $data['days'][0]['visits'], 'нульовий рядок і невідома метрика');
        assertSame(4294967295, $data['days'][1]['visits']);
        assertSame(8589934590, $data['total']['visits'], 'сума двох великих значень як int');
        assertTrue(str_contains((string) $response->body, '"visits":8589934590'), 'у JSON — число');
        assertSame(0, $data['total']['scans']);
    });
});

test('звіт: рівно один SQL-запит на успішну відповідь (день, місяць, 366 днів, порожня таблиця, історія); 400 і 401 — нуль', function () {
    vrToken();
    withFreshSeed(function () {
        foreach ([['2026-11-03', '2026-11-03'], ['2026-10-05', '2026-11-03'], ['2025-11-04', '2026-11-04']] as [$from, $to]) {
            assertSame(1, statementCount(fn () => vrOf($from, $to)), "порожня таблиця {$from}…{$to}");
        }
    });
    vrWithHistory(function () {
        assertSame(1, statementCount(fn () => vrOf('2025-12-29', '2026-10-27')), 'історія');
        assertSame(0, statementCount(fn () => vrGet(['from' => 'bad', 'to' => 'bad'])), '400');
        assertSame(0, statementCount(fn () => vrAnonymous(['from' => '2026-11-01', 'to' => '2026-11-03'])), '401');
    });
});

test('звіт: швидкодія — 366 днів × 5 метрик (1830 рядків) за оракулом, медіана 15 запитів < 200 мс', function () {
    withFreshSeed(function () {
        $from = '2025-01-01';
        $to = vrAddDays($from, 365);
        $rows = [];
        $oracleRows = [];
        for ($i = 0; $i < 366; $i++) {
            $day = vrAddDays($from, $i);
            foreach (['visit' => $i + 1, 'scan' => intdiv($i + 1, 2), 'message_lv' => $i % 7 + 1, 'message_en' => $i % 5 + 1, 'message_ru' => $i % 3 + 1] as $metric => $count) {
                $rows[] = [$day, $metric, $count];
                $oracleRows[] = ['day' => $day, 'metric' => $metric, 'count' => $count];
            }
        }
        vrInsert($rows);
        assertSame(1830, count($rows));

        vrToken();
        $data = vrOf($from, $to);
        assertSame(366, count($data['days']));
        assertSame(vrOracle($from, $to, $oracleRows)['total'], $data['total']);

        $times = [];
        for ($i = 0; $i < 15; $i++) {
            $start = hrtime(true);
            assertSame(200, vrGet(['from' => $from, 'to' => $to])->status);
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        assertTrue($times[7] < 200, sprintf('медіана %.1f мс', $times[7]));
    });
});

// ---------- Наскрізні ----------

test('наскрізно: POST /api/visit і GET /api/message змінюють звіт за сьогодні точно й без кешу', function () {
    vrWithStableDay(function (string $today) {
        $yesterday = vrAddDays($today, -1);
        assertSame(0, vrOf($today, $today)['total']['visits'], 'до: нулі (історії немає)');

        $post = fn (string $body) => assertSame(204, apiRequest('POST', '/api/visit', [], [], $body)->status);
        for ($i = 0; $i < 5; $i++) {
            $post('{"source":"qr"}');
        }
        $afterQr = vrOf($today, $today)['total'];
        assertSame([5, 5], [$afterQr['visits'], $afterQr['scans']], 'звіт одразу після qr (без кешу)');

        for ($i = 0; $i < 3; $i++) {
            $post('{"source":"direct"}');
        }
        $post('{"source":123}'); // не рядок → direct

        $plan = ['lv' => 4, 'en' => 2, 'ru' => 3];
        foreach ($plan as $lang => $n) {
            for ($i = 0; $i < $n; $i++) {
                $extra = $i === 1 ? ['exclude' => '1,2,3'] : [];
                assertSame(200, apiRequest('GET', '/api/message', ['lang' => $lang] + $extra)->status, "{$lang} #{$i}");
            }
        }

        $data = vrOf($today, $today);
        vrAssertTotals($data['total'], ['visits' => 9, 'scans' => 5, 'messages' => 9, 'lv' => 4, 'en' => 2, 'ru' => 3], 'сьогодні');
        assertSame($data['total'], ['visits' => $data['days'][0]['visits'], 'scans' => $data['days'][0]['scans'], 'messages' => $data['days'][0]['messages'], 'byLang' => $data['days'][0]['byLang']], 'total = єдиний день');

        $two = vrOf($yesterday, $today);
        assertSame(2, count($two['days']));
        assertSame(0, $two['days'][0]['visits'], 'вчора — нулі');
        assertSame(9, $two['days'][1]['visits']);
        assertTrue($two['total']['scans'] <= $two['total']['visits']);
    });
});

test('наскрізно: що не рахується (400, 404) не змінює звіт; імена метрик у БД — ті, що пишуть BE-011 і BE-012', function () {
    vrWithStableDay(function (string $today) {
        apiRequest('POST', '/api/visit', [], [], '{"source":"qr"}');
        foreach (['lv', 'en', 'ru'] as $lang) {
            apiRequest('GET', '/api/message', ['lang' => $lang]);
        }
        $before = vrOf($today, $today);
        assertSame(['message_en', 'message_lv', 'message_ru', 'scan', 'visit'], testPdo()->query('SELECT `metric` FROM `daily_stats` ORDER BY `metric`')->fetchAll(PDO::FETCH_COLUMN));

        assertSame(400, apiRequest('GET', '/api/message', ['lang' => 'de'])->status);
        assertSame(400, apiRequest('POST', '/api/visit', [], [], '')->status);
        assertSame(400, apiRequest('POST', '/api/visit', [], [], 'not json')->status);
        testPdo()->exec('DELETE FROM `phrases`');
        assertSame(404, apiRequest('GET', '/api/message', ['lang' => 'lv'])->status);

        assertSame($before, vrOf($today, $today), 'звіт не змінився');
    });
});

test('чистота: після тестів звіту daily_stats порожня, а сід фраз на місці', function () {
    assertSame(0, (int) testPdo()->query('SELECT COUNT(*) FROM `daily_stats`')->fetchColumn());
    assertSame(30, phraseCount(testPdo()));
    assertSame(30, (int) testPdo()->query('SELECT MAX(`id`) FROM `phrases`')->fetchColumn());
});
