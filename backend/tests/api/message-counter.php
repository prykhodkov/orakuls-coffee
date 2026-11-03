<?php

declare(strict_types=1);

// Перевірки лічильника виданих послань (BE-012): кожен GET /api/message → 200 збільшує message_<мова> за день за Ригою;
// 400/404 не рахуються; збій лічильника не ламає видачу. Підключається з tests/api.php. Самодостатній: помічники з префіксом counter*.
// Тести не припускають порожню daily_stats: порівнюють дельти (counterDelta), а «сьогодні» — з урахуванням півночі за Ригою.

use App\Controller\MessageController;
use App\Domain\Languages;
use App\Http\Kernel;
use App\Http\Response;
use App\Http\Router;
use App\Support\RigaClock;

const COUNTER_PATH = '/api/message';
const COUNTER_METRICS = ['message_lv', 'message_en', 'message_ru'];

/** @return array<string, int> метрика → сума по ВСІХ днях (нечутлива до півночі) */
function counterTotals(): array
{
    $totals = [];
    foreach (testPdo()->query('SELECT `metric`, SUM(`count`) AS c FROM `daily_stats` GROUP BY `metric`')->fetchAll() as $row) {
        $totals[(string) $row['metric']] = (int) $row['c']; // SUM повертає DECIMAL-рядок
    }

    return $totals;
}

/** Різниця counterTotals() до/після $fn: лише ненульові метрики — будь-яка несподівана зміна (visit, scan, чужа мова) видна. */
function counterDelta(callable $fn): array
{
    $before = counterTotals();
    $fn();
    $after = counterTotals();

    $delta = [];
    foreach ($after + $before as $metric => $_) {
        $d = ($after[$metric] ?? 0) - ($before[$metric] ?? 0);
        assertTrue($d >= 0, "лічильник {$metric} зменшився");
        if ($d !== 0) {
            $delta[$metric] = $d;
        }
    }
    ksort($delta);

    return $delta;
}

function counterRow(string $day, string $metric): int
{
    $stmt = testPdo()->prepare('SELECT `count` FROM `daily_stats` WHERE `day` = ? AND `metric` = ?');
    $stmt->execute([$day, $metric]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/** @param array<string, mixed> $extraQuery */
function counterGet(string $lang, array $extraQuery = []): Response
{
    return apiRequest('GET', COUNTER_PATH, ['lang' => $lang] + $extraQuery);
}

/** Kernel із MessageController, у якого джерело часу — $source (замикання → DateTimeImmutable); null — реальний час. */
function counterKernel(?Closure $source = null): Kernel
{
    return kernelWith(fn (Router $r) => $r->get(COUNTER_PATH, [new MessageController(new RigaClock($source)), 'show']));
}

function counterMoment(string $iso, ?string $zone = null): DateTimeImmutable
{
    return new DateTimeImmutable($iso, new DateTimeZone($zone ?? 'UTC'));
}

/**
 * Паралельні видачі: по процесу на кожне завдання [мова, момент|null]; усі стартують одночасно за сигналом (бар'єр на STDIN).
 *
 * @param list<array{0: string, 1: ?string}> $jobs
 */
function counterParallel(array $jobs): void
{
    $worker = APP_ROOT . '/tests/workers/message-worker.php';
    $env = ['DB_NAME' => TEST_DB_NAME] + getenv();

    $procs = [];
    foreach ($jobs as $i => [$lang, $instant]) {
        $cmd = [PHP_BINARY, $worker, $lang, ...($instant !== null ? [$instant] : [])];
        $pipes = [];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, $env);
        assertTrue(is_resource($proc), "воркер #{$i} не запущено");
        $procs[] = [$proc, $pipes];
    }

    try {
        $deadline = microtime(true) + 60;
        foreach ($procs as $i => [$proc, $pipes]) {
            stream_set_timeout($pipes[1], max(1, (int) ceil($deadline - microtime(true))));
            $line = fgets($pipes[1]);
            if ($line !== "ready\n") {
                // stderr лише при збої: аргумент-повідомлення assert обчислюється завжди й заблокувався б на живому воркері
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
}

// ---------- Що рахується ----------

test('лічильник: кожна успішна видача рахується по мові; інші метрики не змінюються', function () {
    withFreshSeed(function () {
        foreach (Languages::CODES as $lang) {
            $delta = counterDelta(function () use ($lang) {
                $response = counterGet($lang);
                assertSame(200, $response->status, $lang);
            });
            assertSame(["message_{$lang}" => 1], $delta, $lang);
        }

        assertSame(['message_lv' => 3], counterDelta(function () {
            for ($i = 0; $i < 3; $i++) {
                assertSame(200, counterGet('lv')->status);
            }
        }));
    });
});

test('лічильник: рядок за сьогодні за Ригою (до/після запиту), назви метрик — рівно message_lv|en|ru', function () {
    withFreshSeed(function () {
        $clock = new RigaClock();
        $a = $clock->today();
        foreach (Languages::CODES as $lang) {
            assertSame(200, counterGet($lang)->status);
        }
        $b = $clock->today();

        $rows = testPdo()->query('SELECT `day`, `metric`, `count` FROM `daily_stats` ORDER BY `metric`')->fetchAll();
        assertSame(['message_en', 'message_lv', 'message_ru'], array_column($rows, 'metric'), 'назви метрик (за алфавітом), які читатиме BE-013');
        foreach ($rows as $row) {
            assertTrue(in_array($row['day'], [$a, $b], true), "день {$row['day']} має бути сьогоднішнім за Ригою");
            assertSame(1, (int) $row['count']);
        }
    });
});

test('лічильник: 400 (невалідний lang) нічого не рахує і не звертається до БД', function () {
    withFreshSeed(function () {
        foreach ([[], ['lang' => ''], ['lang' => 'de'], ['lang' => 'LV'], ['lang' => 'lv '], ['lang' => ['lv']]] as $query) {
            $delta = counterDelta(function () use ($query) {
                $queries = statementCount(function () use ($query) {
                    assertSame(400, apiRequest('GET', COUNTER_PATH, $query)->status, json_encode($query));
                });
                assertSame(0, $queries, 'SQL на 400: ' . json_encode($query));
            });
            assertSame([], $delta, json_encode($query));
        }
    });
});

test('лічильник: 404 (немає фраз) і не-GET нічого не рахують', function () {
    withFreshSeed(function () {
        // Усі англійські переклади порожні: en → 404, а lv і ru далі видаються
        testPdo()->exec("UPDATE `phrases` SET `text_en` = ''");
        $delta = counterDelta(function () {
            $queries = statementCount(fn () => assertSame(404, counterGet('en')->status));
            assertSame(1, $queries, '404 від вибору: один запит (без лічильника)');
            assertSame(200, counterGet('lv')->status);
            assertSame(200, counterGet('ru')->status);
        });
        assertSame(['message_lv' => 1, 'message_ru' => 1], $delta);

        // Усе вимкнено → 404 для всіх мов
        testPdo()->exec('UPDATE `phrases` SET `is_active` = 0');
        assertSame([], counterDelta(function () {
            foreach (Languages::CODES as $lang) {
                assertSame(404, counterGet($lang)->status, $lang);
            }
        }));

        assertSame([], counterDelta(function () {
            foreach (['POST', 'PUT', 'DELETE', 'HEAD', 'PATCH'] as $method) {
                assertSame(404, apiRequest($method, COUNTER_PATH, ['lang' => 'lv'])->status, $method);
            }
        }));
    });
});

test('лічильник: exclude не впливає на облік — одна видача = одна одиниця, навіть із сміттям і на запасному варіанті', function () {
    withFreshSeed(function () {
        $first = decode(counterGet('lv'))['id'];
        foreach ([
            [], ['exclude' => (string) $first], ['exclude' => 'abc,-5,1.5,12345678901'], ['exclude' => ['1', '2']],
        ] as $extra) {
            $delta = counterDelta(function () use ($extra) {
                assertSame(200, counterGet('lv', $extra)->status, json_encode($extra));
            });
            assertSame(['message_lv' => 1], $delta, json_encode($extra));
        }

        // Імітація фронта: кожен показаний id додається в exclude; кандидатів менше за 40 → спрацьовує запасний варіант BE-003
        $delta = counterDelta(function () {
            $exclude = [];
            for ($i = 0; $i < 40; $i++) {
                $response = counterGet('lv', $exclude === [] ? [] : ['exclude' => implode(',', $exclude)]);
                assertSame(200, $response->status, "видача #{$i}");
                $exclude[] = decode($response)['id'];
            }
        });
        assertSame(['message_lv' => 40], $delta);
    });
});

test('лічильник: один додатковий запис — 200 дає рівно 2 SQL-запити, 404 — 1, 400 — 0', function () {
    withFreshSeed(function () {
        assertSame(2, statementCount(fn () => assertSame(200, counterGet('lv')->status)), '200: вибір + лічильник');

        testPdo()->exec("UPDATE `phrases` SET `text_en` = ''");
        assertSame(1, statementCount(fn () => assertSame(404, counterGet('en')->status)), '404');
        assertSame(0, statementCount(fn () => assertSame(400, counterGet('de')->status)), '400');
    });
});

// ---------- Збій лічильника не ламає видачу ----------

test('збій: таблиця лічильників недоступна → видача 200 тієї ж відповіді, помилка в лозі без IP і User-Agent, потім лічильник відновлюється', function () {
    withFreshSeed(function () {
        $pdo = testPdo();
        keepOnlyActive($pdo, reseed()['diacritics']); // відповідь детермінована
        $healthy = counterGet('lv');
        assertSame(200, $healthy->status);

        $pdo->exec('RENAME TABLE `daily_stats` TO `daily_stats_off`'); // DDL: не всередині транзакції
        try {
            $log = authCaptureLog(function () use ($healthy) {
                for ($i = 0; $i < 5; $i++) {
                    $response = apiRequest('GET', COUNTER_PATH, ['lang' => 'lv'], ['user-agent' => 'UA-MARKER'], '', '203.0.113.77');
                    assertSame(200, $response->status);
                    assertSame($healthy->body, $response->body, 'тіло як без збою');
                    assertSame($healthy->headers, $response->headers, 'заголовки як без збою (разом із порядком)');
                    assertTrue(!str_contains((string) $response->body, 'daily_stats') && !str_contains((string) $response->body, 'SQLSTATE'), 'тіло без подробиць');
                }
            });
            assertTrue(str_contains($log, 'Message counter failed'), 'префікс для пошуку в логах');
            assertTrue(str_contains($log, 'daily_stats'), 'причина збою в лозі');
            assertTrue(!str_contains($log, '203.0.113.77') && !str_contains($log, 'UA-MARKER'), 'лог без персональних даних');
        } finally {
            $pdo->exec('RENAME TABLE `daily_stats_off` TO `daily_stats`');
        }

        assertSame(['message_lv' => 1], counterDelta(fn () => assertSame(200, counterGet('lv')->status)), 'після відновлення лічильник працює');
    });
});

test('збій: помилка БД у самому записі (тригер SIGNAL) → 200, у лозі причина; і коли рядка за день немає, і коли він є', function () {
    withFreshSeed(function () {
        $pdo = testPdo();
        $pdo->exec('DROP TRIGGER IF EXISTS `counter_boom`');
        try {
            $pdo->exec(
                "CREATE TRIGGER `counter_boom` BEFORE INSERT ON `daily_stats` FOR EACH ROW BEGIN"
                . " IF NEW.`metric` LIKE 'message\\_%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom trigger'; END IF; END"
            );

            foreach (['рядка за сьогодні немає', 'рядок за сьогодні є'] as $case) {
                if ($case === 'рядок за сьогодні є') {
                    // BEFORE INSERT спрацьовує й на ON DUPLICATE KEY UPDATE: рядок дописуємо напряму, у обхід тригера
                    $pdo->exec('DROP TRIGGER `counter_boom`');
                    $pdo->prepare("INSERT INTO `daily_stats` (`day`, `metric`, `count`) VALUES (?, 'message_lv', 5)")->execute([(new RigaClock())->today()]);
                    $pdo->exec(
                        "CREATE TRIGGER `counter_boom` BEFORE INSERT ON `daily_stats` FOR EACH ROW BEGIN"
                        . " IF NEW.`metric` LIKE 'message\\_%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom trigger'; END IF; END"
                    );
                }

                $before = counterTotals();
                $log = authCaptureLog(function () use ($case) {
                    assertSame(200, counterGet('lv')->status, $case);
                });
                assertSame($before, counterTotals(), "лічильник не змінився ({$case})");
                assertTrue(str_contains($log, 'boom trigger'), "причина в лозі ({$case})");
            }
        } finally {
            $pdo->exec('DROP TRIGGER IF EXISTS `counter_boom`');
        }

        assertSame([], $pdo->query('SHOW TRIGGERS')->fetchAll(), 'тригерів не лишилось');
        assertSame(['message_lv' => 1], counterDelta(fn () => assertSame(200, counterGet('lv')->status)), 'після збою лічильник працює');
    });
});

test('збій: будь-який Throwable (не лише PDOException) не ламає видачу', function () {
    withFreshSeed(function () {
        $throwers = [
            'RuntimeException' => fn () => throw new RuntimeException('boom'),
            'PDOException' => fn () => throw new PDOException('boom pdo'),
            'TypeError' => fn () => throw new TypeError('boom type'),
            'DivisionByZeroError' => fn () => intdiv(1, 0),
            'Error' => fn () => throw new Error('boom error'),
            // PHP warning ErrorHandler з bootstrap перетворює на ErrorException
            'ErrorException (warning)' => function () {
                $x = [];

                return $x['немає'];
            },
        ];

        foreach ($throwers as $name => $source) {
            $response = null;
            $log = authCaptureLog(function () use ($source, &$response) {
                $response = counterKernel($source)->handle(new App\Http\Request('GET', COUNTER_PATH, ['lang' => 'lv']));
            });
            assertSame(200, $response->status, $name);
            assertSame('lv', decode($response)['lang'], $name);
            assertTrue(str_contains($log, 'Message counter failed'), "{$name}: запис у лозі");
        }
        assertSame([], counterTotals(), 'жодного запису при збої');
    });
});

test('годинник викликається рівно раз і лише на 200 (404 і 400 не доходять до лічильника)', function () {
    withFreshSeed(function () {
        $calls = 0;
        $kernel = counterKernel(function () use (&$calls) {
            $calls++;

            return counterMoment('2026-11-02T22:30:00Z');
        });
        $get = fn (array $q) => $kernel->handle(new App\Http\Request('GET', COUNTER_PATH, $q));

        assertSame(200, $get(['lang' => 'lv'])->status);
        assertSame(1, $calls, '200');

        testPdo()->exec("UPDATE `phrases` SET `text_en` = ''");
        assertSame(404, $get(['lang' => 'en'])->status);
        assertSame(1, $calls, '404 не рахується');

        assertSame(400, $get(['lang' => 'de'])->status);
        assertSame(400, $get([])->status);
        assertSame(1, $calls, '400 не рахується');
    });
});

// ---------- День за Ригою через контролер ----------

test('день за Ригою: контролер → лічильник — межі, зимовий і літній час, повторена година, межа року', function () {
    $cases = [
        ['2026-11-02T22:30:00Z', '2026-11-03'], ['2026-11-02T21:59:00Z', '2026-11-02'],
        ['2026-03-28T22:00:00Z', '2026-03-29'], ['2026-03-29T20:59:00Z', '2026-03-29'], ['2026-03-29T21:00:00Z', '2026-03-30'],
        ['2026-10-24T21:00:00Z', '2026-10-25'], ['2026-10-25T00:30:00Z', '2026-10-25'], ['2026-10-25T01:30:00Z', '2026-10-25'],
        ['2026-10-25T21:59:00Z', '2026-10-25'], ['2026-10-25T22:00:00Z', '2026-10-26'], ['2026-12-31T22:00:00Z', '2027-01-01'],
    ];
    withFreshSeed(function () use ($cases) {
        foreach ($cases as $i => [$instant, $day]) {
            $lang = Languages::CODES[$i % 3]; // мови чергуються
            $before = testPdo()->query('SELECT `day`, `metric`, `count` FROM `daily_stats`')->fetchAll();
            $response = counterKernel(fn () => counterMoment($instant))->handle(new App\Http\Request('GET', COUNTER_PATH, ['lang' => $lang]));
            assertSame(200, $response->status, $instant);

            $after = testPdo()->query('SELECT `day`, `metric`, `count` FROM `daily_stats`')->fetchAll();
            $key = static fn (array $rows): array => array_column($rows, 'count', 'day') ? array_map(static fn ($r) => "{$r['day']}|{$r['metric']}|{$r['count']}", $rows) : [];
            $new = array_values(array_diff($key($after), $key($before)));
            $expectedCount = counterRow($day, "message_{$lang}");
            assertTrue($expectedCount >= 1, "{$instant}: рядок {$day}|message_{$lang} має існувати");
            assertSame(1, count($new), "{$instant}: змінився рівно один рядок");
            assertTrue(str_starts_with($new[0], "{$day}|message_{$lang}|"), "{$instant}: день {$day}, отримано {$new[0]}");
        }

        // Той самий момент в інших поясах дає той самий день
        reseed();
        foreach (['UTC', 'Europe/Riga', 'America/New_York'] as $zone) {
            $moment = counterMoment('2026-11-02T22:30:00Z')->setTimezone(new DateTimeZone($zone));
            counterKernel(fn () => $moment)->handle(new App\Http\Request('GET', COUNTER_PATH, ['lang' => 'ru']));
        }
        assertSame(3, counterRow('2026-11-03', 'message_ru'), 'один день незалежно від пояса джерела');
        assertSame(['message_ru' => 3], counterTotals());
    });
});

// ---------- Паралельні видачі ----------

test('50 паралельних видач за новий день: гонка першої вставки рядка не губить одиниць', function () {
    withFreshSeed(function () {
        counterParallel(array_fill(0, 50, ['lv', '2031-06-15T12:00:00Z']));

        assertSame(50, counterRow('2031-06-15', 'message_lv'));
        assertSame(['message_lv' => 50], counterTotals());
    });
});

test('паралельні видачі за сьогодні (бойовий Kernel): 20 lv + 15 en + 15 ru, visit і scan без змін', function () {
    withFreshSeed(function () {
        $jobs = array_merge(array_fill(0, 20, ['lv', null]), array_fill(0, 15, ['en', null]), array_fill(0, 15, ['ru', null]));
        $delta = counterDelta(fn () => counterParallel($jobs));

        assertSame(['message_en' => 15, 'message_lv' => 20, 'message_ru' => 15], $delta);
    });
});

// ---------- Узгодженість ----------

test('сума message_* дорівнює кількості успішних відповідей; 400 і 404 у суму не входять', function () {
    withFreshSeed(function () {
        testPdo()->exec("UPDATE `phrases` SET `text_ru` = ''"); // ru → 404
        $ok = array_fill_keys(Languages::CODES, 0);

        $delta = counterDelta(function () use (&$ok) {
            foreach (['lv', 'en', 'ru', 'de', 'lv', 'en', '', 'ru', 'lv'] as $lang) {
                $response = $lang === '' ? apiRequest('GET', COUNTER_PATH) : counterGet($lang);
                if ($response->status === 200) {
                    $ok[$lang]++;
                } else {
                    assertTrue(in_array($response->status, [400, 404], true), "статус {$response->status}");
                }
            }
        });

        assertSame(['lv' => 3, 'en' => 2, 'ru' => 0], $ok);
        assertSame(['message_en' => 2, 'message_lv' => 3], $delta);
        assertSame(array_sum($ok), array_sum($delta));
    });
});

test('регресія BE-003: тіло й заголовки відповіді незмінні, успішні видачі нічого не пишуть у лог', function () {
    withFreshSeed(function () {
        $ids = reseed();
        keepOnlyActive(testPdo(), $ids['diacritics']);
        $headers = ['Cache-Control' => 'no-store', 'Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'];

        $log = authCaptureLog(function () use ($ids, $headers) {
            $response = counterGet('lv');
            assertSame('{"id":' . $ids['diacritics'] . ',"lang":"lv","text":"Šodien veiksme būs tavā pusē."}', $response->body);
            assertSame($headers, $response->headers, 'порядок заголовків значущий');

            foreach (['en', 'ru'] as $lang) {
                assertSame(200, counterGet($lang)->status);
            }
        });
        // Порожній лог ловить баги, які ковтає catch (warning, deprecated, «Message counter failed»)
        assertSame('', $log, 'лог успішних видач порожній');
    });
});

test('у daily_stats після видач лише (day, metric, count): відомі метрики, count ≥ 1, жодних персональних даних', function () {
    withFreshSeed(function () {
        foreach (Languages::CODES as $lang) {
            apiRequest('GET', COUNTER_PATH, ['lang' => $lang], ['user-agent' => 'UA-MARKER-2'], '', '203.0.113.78');
        }

        $rows = testPdo()->query('SELECT * FROM `daily_stats`')->fetchAll();
        assertTrue($rows !== [], 'рядки є');
        foreach ($rows as $row) {
            assertSame(['day', 'metric', 'count'], array_keys($row));
            assertTrue(in_array($row['metric'], COUNTER_METRICS, true), "метрика {$row['metric']}");
            assertTrue((int) $row['count'] >= 1);
            assertTrue(!str_contains(json_encode($row), '203.0.113.78') && !str_contains(json_encode($row), 'UA-MARKER-2'));
        }
    });
});
