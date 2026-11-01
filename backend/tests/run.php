<?php

declare(strict_types=1);

// Юнит-перевірки каркаса без PHPUnit і без БД.
// Запуск: docker compose run --rm --no-deps php php tests/run.php

use App\Database\DatabaseGuard;
use App\Database\Migrator;
use App\Database\SchemaBuilder;
use App\Database\Seeder;
use App\Database\SqlSplitter;
use App\Domain\Categories;
use App\Domain\Languages;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\ApiException;
use App\Support\Config;
use App\Support\Iso8601;
use App\Support\Json;

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

// error_log() у тестах іде в тимчасовий файл, щоб перевірити, що деталі помилки потрапляють в лог
$testLog = sys_get_temp_dir() . '/orakuls-test-' . getmypid() . '.log';
ini_set('error_log', $testLog);
register_shutdown_function(static fn () => @unlink($testLog));

// ---------- Роутер ----------

test('router: передає параметр шляху в обробник', function () {
    $kernel = kernelWith(fn (Router $r) => $r->get(
        '/api/admin/phrases/{id}',
        fn (Request $req, array $p) => Response::json(['id' => (int) $p['id']])
    ));

    $response = $kernel->handle(new Request('GET', '/api/admin/phrases/42'));

    assertSame(200, $response->status);
    assertSame('{"id":42}', $response->body);
});

test('router: невідомий шлях → 404 not_found у форматі помилки', function () {
    $response = kernelWith(fn (Router $r) => null)->handle(new Request('GET', '/api/nope'));

    assertSame(404, $response->status);
    assertSame('not_found', decode($response)['error']['code']);
    assertTrue(isset(decode($response)['error']['message']));
});

test('router: непідтримуваний метод на існуючий шлях → 404', function () {
    $kernel = kernelWith(fn (Router $r) => $r->get('/api/ping', fn () => Response::json(['ok' => true])));

    $response = $kernel->handle(new Request('POST', '/api/ping'));

    assertSame(404, $response->status);
    assertSame('not_found', decode($response)['error']['code']);
});

test('router: параметр шляху не захоплює слеші', function () {
    $kernel = kernelWith(fn (Router $r) => $r->get('/api/x/{id}', fn () => Response::json([])));

    assertSame(404, $kernel->handle(new Request('GET', '/api/x/1/2'))->status);
});

// ---------- Request ----------

test('request: некоректний JSON → 400 bad_request', function () {
    try {
        (new Request('POST', '/', [], [], '{"a":'))->json();
        throw new AssertionError('очікувався ApiException');
    } catch (ApiException $e) {
        assertSame(400, $e->status);
        assertSame('bad_request', $e->errorCode);
    }
});

test('request: порожнє тіло, число і список → 400', function () {
    foreach (['', '   ', '42', '"str"', '[1,2]'] as $body) {
        try {
            (new Request('POST', '/', [], [], $body))->json();
            throw new AssertionError("очікувався ApiException для тіла: {$body}");
        } catch (ApiException $e) {
            assertSame(400, $e->status, $body);
        }
    }
});

test('request: валідний JSON-об\'єкт розбирається', function () {
    $data = (new Request('POST', '/', [], [], '{"login":"admin","text":{"lv":"Šodien"}}'))->json();

    assertSame('admin', $data['login']);
    assertSame('Šodien', $data['text']['lv']);
});

test('request: query-параметр-масив → 400, відсутній → null', function () {
    $request = new Request('GET', '/', ['a' => ['1'], 'lang' => 'lv']);

    assertSame('lv', $request->query('lang'));
    assertSame(null, $request->query('missing'));
    try {
        $request->query('a');
        throw new AssertionError('очікувався ApiException');
    } catch (ApiException $e) {
        assertSame(400, $e->status);
    }
});

test('request: заголовки не залежать від регістру', function () {
    $request = new Request('GET', '/', [], ['authorization' => 'Bearer abc']);

    assertSame('Bearer abc', $request->header('Authorization'));
});

// ---------- JSON ----------

test('json: кирилиця, латиська і слеші не екрануються', function () {
    assertSame(
        '{"lv":"Šodien veiksme būs tavā pusē.","ru":"Сегодня удача","u":"/api"}',
        Json::encode(['lv' => 'Šodien veiksme būs tavā pusē.', 'ru' => 'Сегодня удача', 'u' => '/api'])
    );
});

test('json: числа лишаються числами, булеві — булевими', function () {
    assertSame('{"id":42,"total":600,"isActive":true,"off":false}', Json::encode([
        'id' => 42, 'total' => 600, 'isActive' => true, 'off' => false,
    ]));
});

test('json: порожній асоціативний масив через Json::object → {}', function () {
    assertSame('{}', Json::encode(Json::object([])));
    assertSame('{"text":{},"byCategory":{"love":0}}', Json::encode([
        'text' => Json::object([]),
        'byCategory' => Json::object(['love' => 0]),
    ]));
    assertSame('[]', Json::encode([]), 'контроль: без Json::object порожній масив — це []');
});

// ---------- Формат відповідей і помилок ----------

test('response: JSON-відповідь має Content-Type з charset=utf-8', function () {
    $response = Response::json(['ok' => true]);

    assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    assertSame(200, $response->status);
});

test('response: noContent → 204 без тіла', function () {
    $response = Response::noContent();

    assertSame(204, $response->status);
    assertSame(null, $response->body);
});

test('помилка validation_error: fields — об\'єкт, і порожній теж', function () {
    $kernel = kernelWith(fn (Router $r) => $r->post('/api/x', function () {
        throw ApiException::validation(['text.lv' => 'too_long']);
    }));
    $response = $kernel->handle(new Request('POST', '/api/x'));

    assertSame(422, $response->status);
    assertSame(
        '{"error":{"code":"validation_error","message":"Validation failed","fields":{"text.lv":"too_long"}}}',
        $response->body
    );

    $empty = kernelWith(fn (Router $r) => $r->post('/api/x', function () {
        throw ApiException::validation([]);
    }))->handle(new Request('POST', '/api/x'));
    assertTrue(str_contains((string) $empty->body, '"fields":{}'), 'порожні fields мають бути {}');
});

test('помилки не-validation не мають поля fields', function () {
    $kernel = kernelWith(fn (Router $r) => $r->get('/api/x', function () {
        throw ApiException::unauthorized();
    }));
    $response = $kernel->handle(new Request('GET', '/api/x'));

    assertSame(401, $response->status);
    assertSame('{"error":{"code":"unauthorized","message":"Unauthorized"}}', $response->body);
});

test('усі фабрики ApiException мають статус і код з API.md', function () {
    $expected = [
        [ApiException::badRequest(), 400, 'bad_request'],
        [ApiException::unauthorized(), 401, 'unauthorized'],
        [ApiException::notFound(), 404, 'not_found'],
        [ApiException::validation([]), 422, 'validation_error'],
        [ApiException::rateLimited(), 429, 'rate_limited'],
        [ApiException::server(), 500, 'server_error'],
    ];
    foreach ($expected as [$e, $status, $code]) {
        assertSame($status, $e->status);
        assertSame($code, $e->errorCode);
    }
});

test('необроблений виняток → 500 server_error без stack trace і внутрішніх деталей, деталі в лозі', function () use ($testLog) {
    $kernel = kernelWith(fn (Router $r) => $r->get('/api/boom', function () {
        throw new RuntimeException('SECRET-INTERNAL-DETAIL');
    }));
    $response = $kernel->handle(new Request('GET', '/api/boom'));

    assertSame(500, $response->status);
    assertSame('server_error', decode($response)['error']['code']);
    foreach (['SECRET-INTERNAL-DETAIL', 'RuntimeException', 'Stack trace', '.php', '#0'] as $leak) {
        assertTrue(!str_contains((string) $response->body, $leak), "у відповіді витік: {$leak}");
    }
    assertTrue(str_contains((string) file_get_contents($testLog), 'SECRET-INTERNAL-DETAIL'), 'деталі мають бути в лозі');
});

test('PHP warning перетворюється на 500, а не на «успішну» відповідь', function () {
    $kernel = kernelWith(fn (Router $r) => $r->get('/api/warn', function () {
        $a = [];

        return Response::json(['v' => $a['missing']]); // Warning: Undefined array key
    }));
    $response = $kernel->handle(new Request('GET', '/api/warn'));

    assertSame(500, $response->status);
    assertSame('server_error', decode($response)['error']['code']);
});

// ---------- Config ----------

test('config: .env розбирається (коментарі, лапки, $ без інтерполяції), env має пріоритет', function () {
    $file = sys_get_temp_dir() . '/orakuls-test-' . getmypid() . '.env';
    file_put_contents($file, implode("\n", [
        '# коментар',
        '',
        'T_PLAIN=value',
        'T_QUOTED="with spaces"',
        "T_HASH='\$2y\$10\$abcDEF'",
        'T_FLAG=true',
        'T_OVERRIDE=from-file',
    ]));
    putenv('T_OVERRIDE=from-env');

    Config::load($file);

    assertSame('value', Config::get('T_PLAIN'));
    assertSame('with spaces', Config::get('T_QUOTED'));
    assertSame('$2y$10$abcDEF', Config::get('T_HASH'));
    assertSame(true, Config::bool('T_FLAG'));
    assertSame(false, Config::bool('T_ABSENT'));
    assertSame('from-env', Config::get('T_OVERRIDE'));
    assertSame('dflt', Config::get('T_ABSENT', 'dflt'));

    putenv('T_OVERRIDE');
    unlink($file);
});

test('config: require кидає виняток для відсутнього значення', function () {
    try {
        Config::require('T_DEFINITELY_ABSENT');
        throw new AssertionError('очікувався виняток');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'T_DEFINITELY_ABSENT'));
    }
});


// ---------- SqlSplitter ----------

test('sql-splitter: два оператори, із кінцевим «;» і без нього', function () {
    assertSame(['A', 'B'], SqlSplitter::split('A; B;'));
    assertSame(['A', 'B'], SqlSplitter::split("A;\nB"));
});

test('sql-splitter: «;» усередині \'…\', "…" і `…` не розриває оператор', function () {
    $sql = 'INSERT INTO t VALUES (\'a;b\'); SELECT "c;d"; SELECT `e;f` FROM x';

    assertSame(['INSERT INTO t VALUES (\'a;b\')', 'SELECT "c;d"', 'SELECT `e;f` FROM x'], SqlSplitter::split($sql));
});

test('sql-splitter: \\\' і \'\' усередині рядка не закривають його', function () {
    $sql = <<<'SQL'
    SELECT 'it\'s; ok', 'it''s; ok';
    SELECT 2;
    SQL;

    assertSame(["SELECT 'it\\'s; ok', 'it''s; ok'", 'SELECT 2'], SqlSplitter::split($sql));
});

test('sql-splitter: -- коментар із «;» зникає й не розриває оператор', function () {
    assertSame(['SELECT 1'], SqlSplitter::split("-- коментар; з крапкою з комою\nSELECT 1;"));

    $inline = SqlSplitter::split("SELECT 1 -- хвіст; тут\n, 2;");
    assertSame(1, count($inline));
    assertTrue(!str_contains($inline[0], 'хвіст') && str_contains($inline[0], ', 2'));
});

test('sql-splitter: # коментар до кінця рядка', function () {
    assertSame(['SELECT 2'], SqlSplitter::split("# x; y\nSELECT 2;"));
});

test('sql-splitter: /* … ; … */ зникає, оператор не розривається', function () {
    $result = SqlSplitter::split('SELECT /* ; */ 3;');

    assertSame(1, count($result));
    assertTrue(!str_contains($result[0], '/*') && !str_contains($result[0], ';'));
    assertSame(1, preg_match('/^SELECT\s+3$/', $result[0]));
});

test('sql-splitter: умовний коментар /*!40101 … */ зберігається', function () {
    assertSame(['/*!40101 SET x = 1 */', 'SELECT 1'], SqlSplitter::split("/*!40101 SET x = 1 */;\nSELECT 1;"));
});

test('sql-splitter: «--» без пропуску після нього — це вираз, а не коментар', function () {
    assertSame(['SELECT 5--3'], SqlSplitter::split('SELECT 5--3;'));
});

test('sql-splitter: лише коментарі і порожні оператори → []', function () {
    assertSame([], SqlSplitter::split("-- a\n;;  /* b */ ;\n# c"));
    assertSame([], SqlSplitter::split(''));
});

test('sql-splitter: DELIMITER не підтримується', function () {
    assertThrows(fn () => SqlSplitter::split("DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END//"), RuntimeException::class);
    assertThrows(fn () => SqlSplitter::split('delimiter ;;'), RuntimeException::class);
});

test('sql-splitter: незакритий рядок і блочний коментар → виняток із позицією', function () {
    $e = assertThrows(fn () => SqlSplitter::split("SELECT 1;\nSELECT 'abc"), RuntimeException::class);
    assertTrue(str_contains($e->getMessage(), 'line 2'), $e->getMessage());
    assertThrows(fn () => SqlSplitter::split('SELECT 1 /* x'), RuntimeException::class);
});

test('sql-splitter: CRLF-вхід дає той самий результат, що й LF', function () {
    $lf = "-- c\nCREATE TABLE a (\n  id INT\n);\n\nINSERT INTO a VALUES (1);\n";

    assertSame(SqlSplitter::split($lf), SqlSplitter::split(str_replace("\n", "\r\n", $lf)));
});

// ---------- Domain / Support ----------

test('categories: рівно шість кодів з API.md у фіксованому порядку; перевірка сувора', function () {
    assertSame(['love', 'money', 'luck', 'work', 'travel', 'mood'], Categories::CODES);
    assertTrue(Categories::isValid('love'));
    foreach (['LOVE', 'love ', '', 'x'] as $bad) {
        assertSame(false, Categories::isValid($bad), "'{$bad}'");
    }
});

test('languages: коди; column() — білий список для імені колонки', function () {
    assertSame(['lv', 'en', 'ru'], Languages::CODES);
    assertSame('text_lv', Languages::column('lv'));
    assertSame('text_ru', Languages::column('ru'));
    assertThrows(fn () => Languages::column('lv; DROP TABLE phrases'), InvalidArgumentException::class);
    assertThrows(fn () => Languages::column('de'), InvalidArgumentException::class);
});

test('iso8601: DATETIME з БД → ISO 8601 з Z; некоректні значення — виняток', function () {
    assertSame('2026-10-26T18:30:00Z', Iso8601::fromDb('2026-10-26 18:30:00'));
    assertThrows(fn () => Iso8601::fromDb('2026-02-31 00:00:00'), InvalidArgumentException::class);
    assertThrows(fn () => Iso8601::fromDb('abc'), InvalidArgumentException::class);
});

test('database-guard: лише імена, що закінчуються на _test', function () {
    foreach (['orakuls_test', 'x_test'] as $ok) {
        assertSame(true, DatabaseGuard::isTestDatabaseName($ok), $ok);
    }
    foreach (['orakuls', '', null, 'test', '_test', 'orakuls_test_x', 'orakuls-test'] as $bad) {
        assertSame(false, DatabaseGuard::isTestDatabaseName($bad), var_export($bad, true));
    }
});

// ---------- Migrator::files / SchemaBuilder ----------

/** Створює тимчасовий каталог із файлами [ім'я => вміст]; видаляється наприкінці прогону. */
function tempDirWith(array $files): string
{
    static $dirs = [];
    if ($dirs === []) {
        register_shutdown_function(static function () use (&$dirs): void {
            foreach ($dirs as $dir) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        });
    }

    $dir = sys_get_temp_dir() . '/orakuls-mig-' . getmypid() . '-' . count($dirs);
    mkdir($dir);
    $dirs[] = $dir;
    foreach ($files as $name => $content) {
        file_put_contents($dir . '/' . $name, $content);
    }

    return $dir;
}

test('migrator: files() сортує за іменем', function () {
    $dir = tempDirWith(['002_b.sql' => 'SELECT 2', '001_a.sql' => 'SELECT 1', '010_c.sql' => 'SELECT 3']);

    assertSame(['001_a.sql', '002_b.sql', '010_c.sql'], Migrator::files($dir));
});

test('migrator: files() відхиляє некоректні імена', function () {
    foreach (['1_x.sql', '001-x.sql', '001_X.sql'] as $bad) {
        $dir = tempDirWith([$bad => 'SELECT 1']);
        assertThrows(fn () => Migrator::files($dir), RuntimeException::class);
    }
});

test('migrator: files() відхиляє однаковий числовий префікс і порожній каталог', function () {
    $dup = tempDirWith(['002_a.sql' => 'SELECT 1', '002_b.sql' => 'SELECT 2']);
    assertThrows(fn () => Migrator::files($dup), RuntimeException::class);

    assertThrows(fn () => Migrator::files(tempDirWith([])), RuntimeException::class);
    assertThrows(fn () => Migrator::files(sys_get_temp_dir() . '/orakuls-no-such-dir'), RuntimeException::class);
});

test('migrator: read() знімає BOM і нормалізує CRLF', function () {
    $dir = tempDirWith(['001_a.sql' => "\xEF\xBB\xBFSELECT 1;\r\nSELECT 2;\r\n"]);

    assertSame("SELECT 1;\nSELECT 2;\n", Migrator::read($dir, '001_a.sql'));
});

test('schema-builder: точний текст для двох міграцій', function () {
    $dir = tempDirWith([
        '001_a.sql' => "-- коментар не потрапляє\nCREATE TABLE IF NOT EXISTS a (id INT);\n",
        '002_b.sql' => "ALTER TABLE a ADD x INT; ALTER TABLE a ADD y INT;\n",
    ]);

    $expected = "-- Orākuls Coffee · повна схема БД (MySQL 5.7)\n"
        . "-- ЗГЕНЕРОВАНО командою `php bin/build-schema.php` з database/migrations/*.sql. Вручну не редагувати.\n"
        . "-- Імпорт: phpMyAdmin → обрати порожню базу → вкладка «Імпорт» → цей файл.\n"
        . "-- Даних тут немає: сід на прод не потрапляє, фрази завантажує адмін через імпорт.\n"
        . "\n"
        . "SET NAMES utf8mb4;\n"
        . "\n"
        . Migrator::TABLE_DDL . ";\n"
        . "\n"
        . "-- ---- 001_a.sql ----\n"
        . "CREATE TABLE IF NOT EXISTS a (id INT);\n"
        . "\n"
        . "-- ---- 002_b.sql ----\n"
        . "ALTER TABLE a ADD x INT;\n"
        . "\n"
        . "ALTER TABLE a ADD y INT;\n"
        . "\n"
        . "INSERT IGNORE INTO `migrations` (`name`, `applied_at`) VALUES\n"
        . "  ('001_a.sql', UTC_TIMESTAMP()),\n"
        . "  ('002_b.sql', UTC_TIMESTAMP());\n";

    assertSame($expected, SchemaBuilder::build($dir));
});

test('schema.sql актуальний: збігається з результатом генерації з міграцій', function () {
    $saved = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/database/schema.sql'));

    assertTrue(
        SchemaBuilder::build(APP_ROOT . '/database/migrations') === $saved,
        'schema.sql застарів — запусти: php bin/build-schema.php'
    );
});

// ---------- Сід ----------

test('сід: дані завантажуються без БД; ключі унікальні, категорії валідні, довжини ≤ 500 символів', function () {
    $rows = Seeder::load(APP_ROOT . '/database/seed/phrases.php'); // кидає виняток на будь-яке порушення

    assertSame(30, count($rows));
    $byKey = array_column($rows, null, 'key');
    assertSame(30, count($byKey), 'ключі унікальні');
    assertSame(500, mb_strlen($byKey['len500']['lv']));
    assertSame(500, mb_strlen($byKey['len500']['en']));
    assertSame(false, $byKey['hidden']['active']);
});

// ---------- Запуск ----------

runTests();
