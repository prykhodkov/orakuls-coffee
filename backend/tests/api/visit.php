<?php

declare(strict_types=1);

// Перевірки обліку відкриттів (BE-011): DailyStatsRepository, POST /api/visit, межі дня за Ригою, паралельні запити,
// відсутність персональних даних, збій БД. Підключається з tests/api.php. Файл самодостатній: усі помічники з префіксом visit*.
// Тести не залежать від вмісту daily_stats: порівнюють знімки «до/після» (visitDiff), тож нічого зайвого не пропустять.

use App\Controller\VisitController;
use App\Domain\StatMetrics;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repository\DailyStatsRepository;
use App\Support\RigaClock;

const VISIT_PATH = '/api/visit';

function visitMoment(string $iso): DateTimeImmutable
{
    return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
}

/** Kernel із VisitController, у якого «зараз» — заданий момент UTC (ISO 8601 із Z). */
function visitKernel(string $instantUtc): Kernel
{
    return kernelWith(fn (Router $r) => $r->post(
        VISIT_PATH,
        [new VisitController(new RigaClock(fn () => visitMoment($instantUtc))), 'record']
    ));
}

/** @param array<string, string> $headers */
function visitPost(Kernel $kernel, string $body, array $headers = [], string $ip = 'unknown'): Response
{
    return $kernel->handle(new Request('POST', VISIT_PATH, [], $headers, $body, $ip));
}

/** @return array<string, int> '2026-11-03|visit' => count */
function visitSnapshot(): array
{
    $map = [];
    foreach (testPdo()->query('SELECT `day`, `metric`, `count` FROM `daily_stats`')->fetchAll() as $row) {
        $map["{$row['day']}|{$row['metric']}"] = (int) $row['count'];
    }
    ksort($map);

    return $map;
}

/**
 * Зміщення між знімками (відсортована карта); рядок без змін не потрапляє. Зменшення лічильника — провал.
 *
 * @param array<string, int> $before
 * @param array<string, int> $after
 * @return array<string, int>
 */
function visitDiff(array $before, array $after): array
{
    $diff = [];
    foreach ($after + $before as $key => $_) {
        $delta = ($after[$key] ?? 0) - ($before[$key] ?? 0);
        assertTrue($delta >= 0, "лічильник {$key} зменшився");
        if ($delta !== 0) {
            $diff[$key] = $delta;
        }
    }
    ksort($diff);

    return $diff;
}

/** Сума лічильника метрики по всіх днях — нечутлива до півночі за Ригою. */
function visitTotal(string $metric): int
{
    $stmt = testPdo()->prepare('SELECT COALESCE(SUM(`count`), 0) FROM `daily_stats` WHERE `metric` = ?');
    $stmt->execute([$metric]);

    return (int) $stmt->fetchColumn();
}

// ---------- DailyStatsRepository ----------

test('increment: рядок створюється з 1, далі 2, 3; метрики й дні незалежні; нічого зайвого', function () {
    withFreshSeed(function () {
        $repo = new DailyStatsRepository(testPdo());
        $day = visitMoment('2026-11-03T10:00:00Z');
        $other = visitMoment('2026-11-04T10:00:00Z');

        for ($i = 1; $i <= 3; $i++) {
            $before = visitSnapshot();
            $repo->increment('visit', $day);
            assertSame(['2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()), "крок {$i}");
        }
        assertSame(3, visitSnapshot()['2026-11-03|visit']);

        $repo->increment('scan', $day);
        $repo->increment('visit', $other);
        $repo->increment('message_lv', $day);
        assertSame(
            ['2026-11-03|message_lv' => 1, '2026-11-03|scan' => 1, '2026-11-03|visit' => 3, '2026-11-04|visit' => 1],
            visitSnapshot()
        );
    });
});

test('increment: день за переданим моментом у будь-якій зоні й за годинником репозиторію', function () {
    withFreshSeed(function () {
        $repo = new DailyStatsRepository(testPdo());

        foreach ([
            visitMoment('2026-11-02T22:30:00Z'),
            new DateTimeImmutable('2026-11-03T00:30:00+02:00'),
            new DateTimeImmutable('2026-11-02T17:30:00', new DateTimeZone('America/New_York')),
        ] as $moment) {
            $before = visitSnapshot();
            $repo->increment('visit', $moment);
            assertSame(['2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()), $moment->format('c'));
        }

        // Без $now день бере годинник репозиторію
        $fixed = new DailyStatsRepository(testPdo(), new RigaClock(fn () => visitMoment('2026-03-29T21:00:00Z')));
        $before = visitSnapshot();
        $fixed->increment('scan');
        assertSame(['2026-03-30|scan' => 1], visitDiff($before, visitSnapshot()));

        // Реальний годинник: допустимі «сьогодні» до і після виклику (захист від півночі за Ригою)
        $clock = new RigaClock();
        $a = $clock->today();
        $before = visitSnapshot();
        (new DailyStatsRepository(testPdo()))->increment('message_en');
        $b = $clock->today();
        $diff = visitDiff($before, visitSnapshot());
        assertSame(1, count($diff));
        assertTrue(in_array(array_key_first($diff), ["{$a}|message_en", "{$b}|message_en"], true), 'день реального годинника');
    });
});

test('increment/incrementMany: невідома метрика → виняток без SQL; дублікати рахуються раз; порожній список — без запиту', function () {
    withFreshSeed(function () {
        $repo = new DailyStatsRepository(testPdo());
        $moment = visitMoment('2026-11-03T10:00:00Z');

        foreach (['Visit', 'visit ', 'x', '', "visit'; DROP TABLE daily_stats;--", 'message_de'] as $bad) {
            $before = visitSnapshot();
            $queries = statementCount(function () use ($repo, $bad, $moment) {
                assertThrows(fn () => $repo->increment($bad, $moment), InvalidArgumentException::class);
            });
            assertSame(0, $queries, "SQL для метрики " . json_encode($bad));
            assertSame($before, visitSnapshot());
        }

        // Одна погана серед добрих — не записано нічого
        assertThrows(fn () => $repo->incrementMany(['visit', 'scan', 'nope'], $moment), InvalidArgumentException::class);
        assertThrows(fn () => $repo->incrementMany(['visit', 5], $moment), InvalidArgumentException::class);
        assertSame([], visitSnapshot());

        $repo->incrementMany(['visit', 'visit'], $moment);
        assertSame(['2026-11-03|visit' => 1], visitSnapshot(), 'дублікати = +1, не +2');

        assertSame(0, statementCount(fn () => $repo->incrementMany([], $moment)), 'порожній список — 0 запитів');
        assertSame(['2026-11-03|visit' => 1], visitSnapshot());
    });
});

test('rangeTotals: форма рядка й типи, порядок, межі включно, порожні дні відсутні, невалідні аргументи', function () {
    withFreshSeed(function () {
        $repo = new DailyStatsRepository(testPdo());
        assertSame([], $repo->rangeTotals('2026-01-01', '2026-12-31'), 'порожня таблиця');

        $rows = [
            ['2026-11-01', 'visit', 5], ['2026-11-03', 'scan', 2], ['2026-11-03', 'visit', 7], ['2026-11-03', 'message_ru', 1],
            ['2026-11-05', 'visit', 9], ['2026-11-06', 'visit', 4],
        ];
        $insert = testPdo()->prepare('INSERT INTO `daily_stats` (`day`, `metric`, `count`) VALUES (?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute($row);
        }

        $result = $repo->rangeTotals('2026-11-03', '2026-11-05');
        assertSame([
            ['day' => '2026-11-03', 'metric' => 'message_ru', 'count' => 1],
            ['day' => '2026-11-03', 'metric' => 'scan', 'count' => 2],
            ['day' => '2026-11-03', 'metric' => 'visit', 'count' => 7],
            ['day' => '2026-11-05', 'metric' => 'visit', 'count' => 9],
        ], $result, 'межі включно, порядок day, metric, типи string/string/int');

        assertSame(3, count($repo->rangeTotals('2026-11-03', '2026-11-03')), 'from == to');
        assertSame([], $repo->rangeTotals('2026-11-02', '2026-11-02'), 'день без рядків');

        foreach ([['2026-02-30', '2026-03-01'], ['2026-11-04', '2026-11-03'], ['bad', '2026-11-03'], ['2026-11-03', '2026-11-3'], ['0000-01-01', '2026-11-03']] as [$from, $to]) {
            assertSame(0, statementCount(function () use ($repo, $from, $to) {
                assertThrows(fn () => $repo->rangeTotals($from, $to), InvalidArgumentException::class);
            }), "SQL для {$from}…{$to}");
        }

        assertSame(1, statementCount(fn () => $repo->rangeTotals('2026-11-01', '2026-11-06')), 'рівно 1 запит');
        assertSame(1, statementCount(fn () => $repo->rangeTotals('2025-11-03', '2026-11-03')), '366 днів — теж 1 запит');
    });
});

// ---------- POST /api/visit ----------

test('POST /api/visit {"source":"qr"}: 204 без тіла, no-store, +1 visit і +1 scan за день за Ригою', function () {
    withFreshSeed(function () {
        $before = visitSnapshot();
        $response = visitPost(visitKernel('2026-11-02T22:30:00Z'), '{"source":"qr"}');

        assertSame(204, $response->status);
        assertSame(null, $response->body);
        assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        assertSame(['2026-11-03|scan' => 1, '2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()));
    });
});

test('POST /api/visit {"source":"direct"}: 204, лише visit; рядок scan не створюється навіть з нулем', function () {
    withFreshSeed(function () {
        $before = visitSnapshot();
        $response = visitPost(visitKernel('2026-11-02T22:30:00Z'), '{"source":"direct"}');

        assertSame(204, $response->status);
        assertSame(['2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()));
        assertTrue(!isset(visitSnapshot()['2026-11-03|scan']), 'рядка scan немає');
    });
});

test('POST /api/visit: відсутній, невідомий чи не-рядковий source → direct (204, лише visit); зайві ключі ігноруються', function () {
    withFreshSeed(function () {
        $kernel = visitKernel('2026-11-02T22:30:00Z');
        $bodies = [
            '{}', '[]', '{"source":null}', '{"source":"abc"}', '{"source":""}', '{"source":"QR"}', '{"source":" qr"}', '{"source":"qr "}',
            '{"source":"qr\u0000"}', '{"source":123}', '{"source":0}', '{"source":1.5}', '{"source":true}', '{"source":false}',
            '{"source":["qr"]}', '{"qr":true}', '{"source":{"a":1}}', '{"x":1}',
        ];
        foreach ($bodies as $body) {
            $before = visitSnapshot();
            $response = visitPost($kernel, $body);
            assertSame(204, $response->status, $body);
            assertSame(['2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()), $body);
        }

        // Контроль: зайві ключі ігноруються, qr лишається qr
        $before = visitSnapshot();
        assertSame(204, visitPost($kernel, '{"source":"qr","utm":"x","extra":[1]}')->status);
        assertSame(['2026-11-03|scan' => 1, '2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()));
    });
});

test('POST /api/visit: 400 (порожнє, не-JSON, не-об\'єкт) нічого не рахує і не звертається до БД', function () {
    withFreshSeed(function () {
        $kernel = visitKernel('2026-11-02T22:30:00Z');
        foreach (['', '   ', 'not json', '{', '5', '"qr"', 'null', 'true', '[1]', '["qr"]'] as $body) {
            $before = visitSnapshot();
            $queries = statementCount(function () use ($kernel, $body) {
                $response = visitPost($kernel, $body);
                assertSame(400, $response->status, "тіло: {$body}");
                assertSame('bad_request', decode($response)['error']['code'], $body);
            });
            assertSame(0, $queries, "SQL на 400: {$body}");
            assertSame($before, visitSnapshot(), "лічильники: {$body}");
        }
    });
});

test('POST /api/visit: кількість SQL-запитів — 1 і для qr, і для direct', function () {
    withFreshSeed(function () {
        $kernel = visitKernel('2026-11-02T22:30:00Z');
        assertSame(1, statementCount(fn () => visitPost($kernel, '{"source":"qr"}')), 'qr: visit і scan одним оператором');
        assertSame(1, statementCount(fn () => visitPost($kernel, '{"source":"direct"}')), 'direct');
    });
});

test('POST /api/visit: боєвий маршрут без авторизації; адреса, Referer і User-Agent не рахуються', function () {
    withFreshSeed(function () {
        $beforeVisit = visitTotal('visit');
        $beforeScan = visitTotal('scan');

        assertSame(204, apiRequest('POST', VISIT_PATH, [], [], '{"source":"direct"}')->status, 'без Authorization');
        assertSame(204, apiRequest('POST', VISIT_PATH, [], ['authorization' => 'Bearer garbage'], '{"source":"direct"}')->status, 'зі сміттєвим токеном');

        // Джерело — лише тіло: ?qr у адресі, Referer і User-Agent сканування не створюють
        $response = apiRequest(
            'POST',
            VISIT_PATH,
            ['qr' => '1', 'source' => 'qr'],
            ['referer' => 'https://example.com/?qr', 'user-agent' => 'qr'],
            '{"source":"direct"}'
        );
        assertSame(204, $response->status);

        assertSame($beforeVisit + 3, visitTotal('visit'), 'три відкриття');
        assertSame($beforeScan, visitTotal('scan'), 'сканувань немає');
    });
});

test('межі дня за Ригою через ендпоінт: зимовий і літній час, доби 23/25 год, межа року; без дублювання дня', function () {
    $cases = [
        '2026-11-02T22:30:00Z' => '2026-11-03', '2026-11-02T21:59:59Z' => '2026-11-02',
        '2026-03-28T21:59:59Z' => '2026-03-28', '2026-03-28T22:00:00Z' => '2026-03-29', '2026-03-29T20:59:59Z' => '2026-03-29', '2026-03-29T21:00:00Z' => '2026-03-30',
        '2026-10-24T20:59:59Z' => '2026-10-24', '2026-10-24T21:00:00Z' => '2026-10-25', '2026-10-25T21:59:59Z' => '2026-10-25', '2026-10-25T22:00:00Z' => '2026-10-26',
        '2026-12-31T21:59:59Z' => '2026-12-31', '2026-12-31T22:00:00Z' => '2027-01-01',
    ];
    foreach ($cases as $instant => $day) {
        withFreshSeed(function () use ($instant, $day) {
            $before = visitSnapshot();
            assertSame(204, visitPost(visitKernel($instant), '{"source":"qr"}')->status, $instant);
            assertSame(["{$day}|scan" => 1, "{$day}|visit" => 1], visitDiff($before, visitSnapshot()), $instant);
        });
    }

    // Повторена година 2026-10-25 (03:xx двічі): один день, +2
    withFreshSeed(function () {
        foreach (['2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z'] as $instant) {
            visitPost(visitKernel($instant), '{"source":"direct"}');
        }
        assertSame(['2026-10-25|visit' => 2], visitSnapshot());
    });

    // Доба 23 год (2026-03-29): початок і кінець доби — той самий день
    withFreshSeed(function () {
        foreach (['2026-03-28T22:00:00Z', '2026-03-29T20:59:59Z'] as $instant) {
            visitPost(visitKernel($instant), '{"source":"direct"}');
        }
        assertSame(['2026-03-29|visit' => 2], visitSnapshot());
    });
});

test('50 паралельних запитів (50 процесів з бар\'єром): лічильники точні, помилок немає', function () {
    withFreshSeed(function () {
        $worker = APP_ROOT . '/tests/workers/visit-worker.php';
        $env = ['DB_NAME' => TEST_DB_NAME] + getenv();
        $sources = array_merge(array_fill(0, 30, 'qr'), array_fill(0, 20, 'direct'));

        $procs = [];
        foreach ($sources as $i => $source) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, $worker, '2026-11-02T22:30:00Z', $source], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, $env);
            assertTrue(is_resource($proc), "воркер #{$i} не запущено");
            $procs[] = [$proc, $pipes];
        }

        try {
            // Чекаємо ready від усіх (до 30 с), потім одним махом даємо старт
            $deadline = microtime(true) + 30;
            foreach ($procs as $i => [$proc, $pipes]) {
                stream_set_timeout($pipes[1], max(1, (int) ceil($deadline - microtime(true))));
                $line = fgets($pipes[1]);
                if ($line !== "ready\n") {
                    // stderr читаємо лише при збої: аргумент-повідомлення обчислюється завжди й заблокувався б на живому воркері
                    stream_set_blocking($pipes[2], false);
                    throw new AssertionError("воркер #{$i} не готовий: " . var_export($line, true) . ' ' . stream_get_contents($pipes[2]));
                }
            }
            foreach ($procs as [$proc, $pipes]) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }
            foreach ($procs as $i => [$proc, $pipes]) {
                $err = stream_get_contents($pipes[2]);
                assertSame('', $err, "stderr воркера #{$i}");
                fclose($pipes[1]);
                fclose($pipes[2]);
                assertSame(0, proc_close($proc), "код виходу воркера #{$i}");
            }
        } catch (Throwable $e) {
            foreach ($procs as [$proc, $pipes]) {
                @proc_terminate($proc);
            }
            throw $e;
        }

        assertSame(['2026-11-03|scan' => 30, '2026-11-03|visit' => 50], visitSnapshot());
    });
});

test('випадкова серія з 120 запитів на 5 днях збігається з оракулом; scan ≤ visit у кожному дні', function () {
    withFreshSeed(function () {
        mt_srand(42);
        $instants = ['2026-03-29T21:30:00Z', '2026-10-25T00:30:00Z', '2026-10-25T23:30:00Z', '2026-11-02T22:30:00Z', '2026-12-31T22:00:00Z'];
        $bodies = ['{"source":"qr"}', '{"source":"direct"}', '{}', '{"source":123}', '{"source":"QR"}'];
        $expected = [];
        for ($i = 0; $i < 120; $i++) {
            $instant = $instants[mt_rand(0, 4)];
            $bodyIndex = mt_rand(0, 4);
            assertSame(204, visitPost(visitKernel($instant), $bodies[$bodyIndex])->status);
            $day = RigaClock::dayOf(visitMoment($instant));
            $expected["{$day}|visit"] = ($expected["{$day}|visit"] ?? 0) + 1;
            if ($bodyIndex === 0) {
                $expected["{$day}|scan"] = ($expected["{$day}|scan"] ?? 0) + 1;
            }
        }
        ksort($expected);

        $actual = visitSnapshot();
        assertSame($expected, $actual);
        assertSame(120, visitTotal('visit'));
        foreach ($actual as $key => $count) {
            if (str_ends_with($key, '|scan')) {
                assertTrue($count <= $actual[substr($key, 0, -4) . 'visit'], "scan ≤ visit: {$key}");
            }
        }
    });
});

test('персональних даних немає: у daily_stats лише (day, metric, count); IP, User-Agent, Referer не зберігаються і не логуються', function () {
    withFreshSeed(function () {
        $sentinels = ['203.0.113.7', 'SENTINEL-UA-7f3a', 'SENTINEL-REF-9c1', '198.51.100.77'];
        $attempts = authAttemptCount();

        $log = authCaptureLog(function () use ($sentinels) {
            $kernel = visitKernel('2026-11-02T22:30:00Z');
            $headers = ['user-agent' => $sentinels[1], 'referer' => 'https://' . $sentinels[2] . '/', 'x-forwarded-for' => $sentinels[3]];
            assertSame(204, visitPost($kernel, '{"source":"qr"}', $headers, $sentinels[0])->status);
            assertSame(204, visitPost($kernel, '{"source":"direct"}', $headers, $sentinels[0])->status);
            assertSame(400, visitPost($kernel, '', $headers, $sentinels[0])->status);
        });
        assertSame('', $log, 'успішні запити й 400 нічого не логують');

        $columns = testPdo()->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_stats' ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['day', 'metric', 'count'], $columns);

        foreach (['daily_stats', 'phrases', 'login_attempts', 'migrations'] as $table) {
            foreach (testPdo()->query("SELECT * FROM `{$table}`")->fetchAll() as $row) {
                $flat = json_encode($row, JSON_UNESCAPED_UNICODE);
                foreach ($sentinels as $sentinel) {
                    assertTrue(!str_contains($flat, $sentinel), "{$table} містить {$sentinel}");
                }
            }
        }
        assertSame($attempts, authAttemptCount(), 'login_attempts не змінилась');
    });
});

test('збій БД → 500 server_error без подробиць, деталі в лозі без персональних даних; після відновлення знову 204', function () {
    withFreshSeed(function () {
        $kernel = visitKernel('2026-11-02T22:30:00Z');
        $headers = ['user-agent' => 'SENTINEL-UA-7f3a'];
        $pdo = testPdo();

        $pdo->exec('RENAME TABLE `daily_stats` TO `daily_stats_off`');
        try {
            foreach (['{"source":"qr"}', '{"source":"direct"}'] as $body) {
                $response = null;
                $log = authCaptureLog(function () use ($kernel, $body, $headers, &$response) {
                    $response = visitPost($kernel, $body, $headers, '203.0.113.7');
                });
                assertSame(500, $response->status, $body);
                assertSame('server_error', decode($response)['error']['code']);
                assertSame('no-store', $response->headers['Cache-Control'] ?? null);
                foreach (['daily_stats', 'SQL', 'PDO', 'Table'] as $leak) {
                    assertTrue(!str_contains((string) $response->body, $leak), "тіло 500 розкриває {$leak}");
                }
                assertTrue(str_contains($log, 'daily_stats'), 'деталі винятку в лозі');
                assertTrue(!str_contains($log, 'SENTINEL-UA-7f3a') && !str_contains($log, '203.0.113.7'), 'лог без персональних даних');
            }
        } finally {
            $pdo->exec('RENAME TABLE `daily_stats_off` TO `daily_stats`');
        }

        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_stats'")->fetchColumn(), 'таблицю відновлено');
        $before = visitSnapshot();
        assertSame(204, visitPost($kernel, '{"source":"qr"}')->status);
        assertSame(['2026-11-03|scan' => 1, '2026-11-03|visit' => 1], visitDiff($before, visitSnapshot()), 'без залишків від невдалих спроб');
    });
});

test('reseed() очищає daily_stats, фрази лишаються на місці', function () {
    (new DailyStatsRepository(testPdo()))->increment(StatMetrics::VISIT, visitMoment('2026-11-03T10:00:00Z'));
    assertTrue(visitSnapshot() !== [], 'рядок записано');

    reseed();

    assertSame([], visitSnapshot());
    assertSame(30, phraseCount(testPdo()), 'сід фраз');
});
