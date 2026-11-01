<?php

declare(strict_types=1);

// Юнит-перевірки каркаса без PHPUnit і без БД.
// Запуск: docker compose run --rm --no-deps php php tests/run.php

use App\Auth\AuthConfig;
use App\Auth\TokenService;
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
use App\Support\IdList;
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

// ---------- BE-003: IdList, Request::rawQuery, маршрут /api/message ----------

test('IdList::parse: таблиця «вхід → результат»', function () {
    $cases = [
        'null' => [null, []],
        'порожній рядок' => ['', []],
        'одне число' => ['5', [5]],
        'список' => ['5,12,40', [5, 12, 40]],
        'пробіли навколо чисел' => [' 5 , 12 ', [5, 12]],
        'сміття між числами' => ['5,abc,40', [5, 40]],
        'порожній елемент' => ['1,,2', [1, 2]],
        'самі коми' => [',,,', []],
        'від\'ємне' => ['-5', []],
        'плюс' => ['+5', []],
        'дробове' => ['1.5', []],
        'експонента' => ['1e3', []],
        '11 цифр' => ['12345678901', []],
        '10 цифр, max INT UNSIGNED' => ['4294967295', [4294967295]],
        'нулі на початку' => ['007', [7]],
        'нуль' => ['0', [0]],
        'повтори' => ['5,5,6,5', [5, 6]],
        'арабсько-індійська цифра' => ['٣', []],
        'сміття не займає місце' => ['abc,x,7', [7]],
        'число з пробілом всередині' => ['1 2', []],
        'перенос рядка навколо числа' => ["\n5\t", [5]],
    ];

    foreach ($cases as $name => [$input, $expected]) {
        assertSame($expected, IdList::parse($input), $name);
    }
});

test('IdList::parse: враховує лише перші 200 коректних ID; max задається параметром', function () {
    $ids = range(1, 300);
    $result = IdList::parse(implode(',', $ids));

    assertSame(200, count($result));
    assertSame(range(1, 200), $result, 'перші 200 у порядку появи');
    assertSame(IdList::MAX_IDS, 200);
    assertSame([1, 2, 3], IdList::parse('1,2,3,4,5', 3));
});

test('IdList::parse: 1500 коректних ID в одному рядку і рядок на 1 МБ сміття не кидають винятків', function () {
    $many = IdList::parse(implode(',', range(1, 1500)));
    assertTrue(count($many) <= 200, 'довжина ' . count($many));
    assertSame(range(1, 200), $many);

    assertSame([], IdList::parse(str_repeat('a,', 524288))); // 1 МБ
    // Коректні ID далеко за межею розглянутих фрагментів — не потрапляють, але й помилки немає
    assertSame([], IdList::parse(str_repeat('a,', 2000) . '7'));
});

test('Request::rawQuery: повертає рядок, масив і null як є; query() на масив кидає 400', function () {
    $request = new Request('GET', '/x', ['a' => 'text', 'b' => ['1', '2'], 'c' => '']);

    assertSame('text', $request->rawQuery('a'));
    assertSame(['1', '2'], $request->rawQuery('b'));
    assertSame('', $request->rawQuery('c'));
    assertSame(null, $request->rawQuery('missing'));

    $e = assertThrows(fn () => $request->query('b'), ApiException::class);
    assertSame(400, $e->status);
});

test('маршрут GET /api/message зареєстрований; lang перевіряється до звернення до БД (400 без БД)', function () {
    foreach ([[], ['lang' => ''], ['lang' => 'de'], ['lang' => 'LV'], ['lang' => 'lv '], ['lang' => ['lv']]] as $query) {
        $response = apiRequest('GET', '/api/message', $query);

        assertSame(400, $response->status, json_encode($query));
        assertSame('bad_request', decode($response)['error']['code']);
        assertSame(['code', 'message'], array_keys(decode($response)['error']), 'без fields');
    }

    // Інші методи — 404, а не 405 і не 200
    foreach (['POST', 'PUT', 'DELETE'] as $method) {
        $response = apiRequest($method, '/api/message', ['lang' => 'lv']);
        assertSame(404, $response->status, $method);
        assertSame('not_found', decode($response)['error']['code']);
    }
});

// ---------- BE-004: спільний код авторизації (ApiException, ErrorHandler, Request::ip, Iso8601) ----------

test('ApiException::rateLimited: Retry-After; 0 і від\'ємне → 1; без аргументу — без заголовків', function () {
    assertSame(['Retry-After' => '120'], ApiException::rateLimited(120)->headers);
    assertSame(['Retry-After' => '1'], ApiException::rateLimited(0)->headers);
    assertSame(['Retry-After' => '1'], ApiException::rateLimited(-5)->headers);
    assertSame([], ApiException::rateLimited()->headers);
    assertSame('Too many requests', ApiException::rateLimited(10)->getMessage());
});

test('ErrorHandler: 429 несе Retry-After і не втрачає Cache-Control: no-store; тіло в стандартному форматі', function () {
    $response = App\Http\ErrorHandler::toResponse(ApiException::rateLimited(37));

    assertSame(429, $response->status);
    assertSame('37', $response->headers['Retry-After']);
    assertSame('no-store', $response->headers['Cache-Control']);
    assertSame('{"error":{"code":"rate_limited","message":"Too many requests"}}', $response->body);

    // Помилка без заголовків лишається такою, як була
    $plain = App\Http\ErrorHandler::toResponse(ApiException::rateLimited());
    assertTrue(!isset($plain->headers['Retry-After']), 'без Retry-After');
    assertSame('no-store', $plain->headers['Cache-Control']);
});

test('ErrorHandler: заголовки винятку не можуть прибрати Cache-Control: no-store', function () {
    $response = App\Http\ErrorHandler::toResponse(new ApiException(429, 'rate_limited', 'x', [], ['Cache-Control' => 'public']));

    assertSame('no-store', $response->headers['Cache-Control']);
});

test('Request::fromGlobals: ip береться лише з REMOTE_ADDR; сміття й відсутнє значення → unknown; X-Forwarded-For ігнорується', function () {
    $saved = $_SERVER;
    try {
        $cases = [
            ['203.0.113.5', '203.0.113.5'],
            ['2001:db8::1', '2001:db8::1'],
            ['not-an-ip', 'unknown'],
            ['', 'unknown'],
            ['203.0.113.5, 10.0.0.1', 'unknown'],
        ];
        foreach ($cases as [$remote, $expected]) {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/x', 'REMOTE_ADDR' => $remote];
            assertSame($expected, Request::fromGlobals()->ip, "REMOTE_ADDR='{$remote}'");
        }

        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/x'];
        assertSame('unknown', Request::fromGlobals()->ip, 'REMOTE_ADDR відсутній');

        $_SERVER = [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/x', 'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_REAL_IP' => '198.51.100.8', 'HTTP_CLIENT_IP' => '198.51.100.9',
        ];
        assertSame('203.0.113.5', Request::fromGlobals()->ip, 'проксі-заголовки не діють');
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/x', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        assertSame('unknown', Request::fromGlobals()->ip, 'без REMOTE_ADDR заголовок теж не береться');
    } finally {
        $_SERVER = $saved;
    }

    assertSame('unknown', (new Request('GET', '/x'))->ip, 'значення за замовчуванням у конструкторі');
    assertSame('203.0.113.5', (new Request('GET', '/x', [], [], '', '203.0.113.5'))->ip);
});

test('Iso8601::fromTimestamp: Unix-час → ISO 8601 UTC з Z', function () {
    assertSame('1970-01-01T00:00:00Z', Iso8601::fromTimestamp(0));
    assertSame('2026-11-01T02:26:40Z', Iso8601::fromTimestamp(1793500000));
    assertSame('1969-12-31T23:59:59Z', Iso8601::fromTimestamp(-1));
});

// ---------- BE-004: TokenService ----------

const UNIT_HEADER_JSON = '{"alg":"HS256","typ":"JWT"}';

function unitB64(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function unitConfig(string $login = 'test-admin', ?string $hash = null, string $secret = '0123456789abcdef0123456789abcdef', int $ttl = 3600): AuthConfig
{
    // cost 4: 1 мс замість ~165 мс при cost за замовчуванням
    return new AuthConfig($login, $hash ?? password_hash('unit-password-1', PASSWORD_BCRYPT, ['cost' => 4]), $secret, $ttl);
}

/** «Ручний» випуск токена тим самим алгоритмом ключа — для негативних перевірок. */
function unitForge(AuthConfig $config, string $headerPart, string $payloadPart, ?string $signaturePart = null): string
{
    $key = hash_hmac('sha256', $config->passwordHash, $config->secret, true);
    $signature = $signaturePart ?? unitB64(hash_hmac('sha256', $headerPart . '.' . $payloadPart, $key, true));

    return $headerPart . '.' . $payloadPart . '.' . $signature;
}

/** Токен з довільним payload і коректними header та підписом. */
function unitForgePayload(AuthConfig $config, mixed $claims): string
{
    return unitForge($config, unitB64(UNIT_HEADER_JSON), unitB64(is_string($claims) ? $claims : json_encode($claims)));
}

test('token: header-константа дорівнює відомому заголовку JWT HS256', function () {
    assertSame('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', unitB64(UNIT_HEADER_JSON));
    $issued = (new TokenService(unitConfig()))->issue(1000);
    assertSame('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', explode('.', $issued['token'])[0]);
});

test('token: дійсний у момент випуску й за секунду до закінчення; не дійсний у момент exp і пізніше; expiresAt = now + ttl', function () {
    $service = new TokenService(unitConfig(ttl: 3600));
    $issued = $service->issue(1000);

    assertSame(4600, $issued['expiresAt']);
    assertSame(3, count(explode('.', $issued['token'])));
    assertTrue($service->verify($issued['token'], 1000), 'у момент випуску');
    assertTrue($service->verify($issued['token'], 4599), 'за секунду до exp');
    assertTrue(!$service->verify($issued['token'], 4600), 'рівно в момент exp (суворе exp > now)');
    assertTrue(!$service->verify($issued['token'], 4601), 'після exp');
    assertTrue(!$service->verify($issued['token'], 10 ** 10), 'значно пізніше');
});

test('token: payload містить рівно sub, iat, exp у фіксованому порядку', function () {
    $token = (new TokenService(unitConfig()))->issue(1000)['token'];
    $payload = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true);

    assertSame('{"sub":"test-admin","iat":1000,"exp":4600}', $payload);
});

test('token: ключ залежить від хеша пароля, секрету й логіна — токен однієї конфігурації не проходить в іншій', function () {
    $a = unitConfig();
    $token = (new TokenService($a))->issue(1000)['token'];

    assertTrue((new TokenService($a))->verify($token, 1001), 'контроль: та сама конфігурація');
    // Інший хеш пароля (той самий пароль, інша сіль) — тобто «пароль змінили»
    assertTrue(!(new TokenService(unitConfig(hash: password_hash('unit-password-1', PASSWORD_BCRYPT, ['cost' => 4]))))->verify($token, 1001), 'інший хеш');
    assertTrue(!(new TokenService(unitConfig(hash: $a->passwordHash, secret: 'ffffffffffffffffffffffffffffffff')))->verify($token, 1001), 'інший секрет');
    assertTrue(!(new TokenService(unitConfig(login: 'other', hash: $a->passwordHash)))->verify($token, 1001), 'інший логін');
});

test('token: зміна будь-якого символу в кожній із трьох частин → false', function () {
    $service = new TokenService(unitConfig());
    $token = $service->issue(1000)['token'];
    assertTrue($service->verify($token, 1001), 'контроль');

    $parts = explode('.', $token);
    foreach ($parts as $i => $part) {
        for ($pos = 0; $pos < strlen($part); $pos++) {
            $changed = $parts;
            $changed[$i][$pos] = $part[$pos] === 'A' ? 'B' : 'A';
            assertTrue(!$service->verify(implode('.', $changed), 1001), "частина {$i}, позиція {$pos}");
        }
    }
});

test('token: payload підмінено на довший exp при старому підписі; усічений токен; 2 і 4 частини; порожні частини; роздільник-пробіл', function () {
    $config = unitConfig();
    $service = new TokenService($config);
    $token = $service->issue(1000)['token'];
    [$h, $p, $s] = explode('.', $token);

    $newPayload = unitB64('{"sub":"test-admin","iat":1000,"exp":' . (1000 + 10 * 365 * 86400) . '}');
    assertTrue(!$service->verify("{$h}.{$newPayload}.{$s}", 1001), 'підмінений payload');
    assertTrue(!$service->verify(substr($token, 0, -1), 1001), 'усічений на 1 символ');
    assertTrue(!$service->verify(substr($token, 0, 60), 1001), 'усічений');
    assertTrue(!$service->verify("{$h}.{$p}", 1001), '2 частини');
    assertTrue(!$service->verify("{$h}.{$p}.{$s}.{$s}", 1001), '4 частини');
    assertTrue(!$service->verify("{$h}.{$p}.{$s}.", 1001), '4 частини з порожньою останньою');
    assertTrue(!$service->verify("{$h}..{$s}", 1001), 'порожній payload');
    assertTrue(!$service->verify(".{$p}.{$s}", 1001), 'порожній header');
    assertTrue(!$service->verify("{$h}.{$p}.", 1001), 'порожній підпис');
    assertTrue(!$service->verify('', 1001), 'порожній рядок');
    assertTrue(!$service->verify('...', 1001), 'самі крапки');
    assertTrue(!$service->verify("{$h} {$p} {$s}", 1001), 'пробіли замість крапок');
    assertTrue(!$service->verify(" {$token}", 1001), 'пробіл на початку');
    assertTrue(!$service->verify("{$token} ", 1001), 'пробіл у кінці');
    assertTrue(!$service->verify("{$token}\n", 1001), 'перенос рядка в кінці');
});

test('token: підміна header — навіть коли підпис пораховано «правильно» над підміненим header', function () {
    $config = unitConfig();
    $service = new TokenService($config);
    $payload = unitB64('{"sub":"test-admin","iat":1000,"exp":9999999999}');

    // Контроль: ті самі ключ і payload з коректним header проходять — отже, відмови нижче саме через header
    assertTrue($service->verify(unitForge($config, unitB64(UNIT_HEADER_JSON), $payload), 1001), 'контроль forge');

    $headers = [
        'alg none' => '{"alg":"none","typ":"JWT"}',
        'alg HS512' => '{"alg":"HS512","typ":"JWT"}',
        'alg hs256 у нижньому регістрі' => '{"alg":"hs256","typ":"JWT"}',
        'зайве поле' => '{"alg":"HS256","typ":"JWT","kid":"x"}',
        'інший порядок ключів' => '{"typ":"JWT","alg":"HS256"}',
        'пробіл після коми' => '{"alg":"HS256", "typ":"JWT"}',
        'без typ' => '{"alg":"HS256"}',
        'порожній об\'єкт' => '{}',
    ];
    foreach ($headers as $name => $json) {
        $header = unitB64($json);
        assertTrue(!$service->verify(unitForge($config, $header, $payload), 1001), "{$name}: з правильним підписом");
        assertTrue(!$service->verify(unitForge($config, $header, $payload, ''), 1001), "{$name}: з порожнім підписом");
    }

    // alg none у класичному вигляді: header.payload. (порожній підпис) і з підписом від справжнього токена
    $none = unitB64('{"alg":"none","typ":"JWT"}');
    assertTrue(!$service->verify("{$none}.{$payload}.", 1001), 'alg none без підпису');
    $real = explode('.', $service->issue(1000)['token']);
    assertTrue(!$service->verify("{$none}.{$real[1]}.{$real[2]}", 1001), 'alg none з реальним підписом');

    // Header у стандартному base64 з паддінгом
    $padded = base64_encode('{"alg":"HS256","typ":"JWT"} ');
    assertTrue(str_ends_with($padded, '='), 'контроль: паддінг справді є');
    assertTrue(!$service->verify(unitForge($config, $padded, $payload), 1001), 'header зі стандартним base64 і паддінгом');
});

test('token: неканонічний base64url (паддінг, + і /, пробіл, перенос, зайві біти) відхиляється в підписі й payload', function () {
    $config = unitConfig();
    $service = new TokenService($config);
    $token = $service->issue(1000)['token'];
    [$h, $p, $s] = explode('.', $token);

    // Підпис: 32 байти = 43 символи, останній має 2 зайві біти, що в канонічному записі нульові
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $last = strpos($alphabet, $s[42]);
    assertSame(0, $last & 3, 'контроль: зайві біти канонічного підпису нульові');
    $extraBits = substr($s, 0, 42) . $alphabet[$last | 1];
    assertTrue(base64_decode(strtr($extraBits, '-_', '+/'), true) === base64_decode(strtr($s, '-_', '+/'), true), 'контроль: base64_decode ці біти ігнорує');
    assertTrue(!$service->verify("{$h}.{$p}.{$extraBits}", 1001), 'зайві біти в підписі');
    assertTrue(!$service->verify("{$h}.{$p}.{$s}=", 1001), 'паддінг у підписі');
    assertTrue(!$service->verify("{$h}.{$p}." . substr($s, 0, 42) . ' ', 1001), 'пробіл у підписі');
    assertTrue(!$service->verify("{$h}.{$p}.{$s}\n", 1001), 'перенос у підписі');
    assertTrue(!$service->verify("{$h}.{$p}." . strtr($s, '-_', '+/') . '=', 1001), 'стандартний алфавіт + паддінг (якщо є - і _)');

    // Payload: підпис пораховано над неканонічним рядком, тож відмова саме через декодер
    foreach (['{"sub":"test-admin","iat":1000,"exp":9999999999}', '{"sub":"test-admin","iat":1000,"exp":99999999999}', '{"sub":"test-admin","iat":1000,"exp":9999999999 }'] as $json) {
        $padded = base64_encode($json);
        if (!str_ends_with($padded, '=')) {
            continue;
        }
        assertTrue($service->verify(unitForge($config, $h, unitB64($json)), 1001), 'контроль: канонічний payload проходить');
        assertTrue(!$service->verify(unitForge($config, $h, $padded), 1001), 'payload із паддінгом');
        $withNewline = unitB64($json) . "\n";
        assertTrue(!$service->verify(unitForge($config, $h, $withNewline), 1001), 'payload з переносом');
        return;
    }
    throw new AssertionError('не знайдено JSON, чий base64 має паддінг');
});

test('token: довжина > 2048 → false без винятків', function () {
    $service = new TokenService(unitConfig());
    $token = $service->issue(1000)['token'];

    assertTrue(!$service->verify(str_repeat('a', 3000), 1001));
    assertTrue(!$service->verify($token . str_repeat('A', 3000), 1001));
    assertTrue(!$service->verify(str_repeat('a.', 2000) . 'a', 1001));
    assertTrue(!$service->verify(str_repeat("\0", 100), 1001), 'нульові байти');
    assertTrue(!$service->verify("\xFF\xFE.\xFF.\xFF", 1001), 'не UTF-8');
});

test('token: payload з нерядковими/відсутніми полями → false (токен підписано правильним ключем)', function () {
    $config = unitConfig();
    $service = new TokenService($config);

    assertTrue($service->verify(unitForgePayload($config, ['sub' => 'test-admin', 'iat' => 1000, 'exp' => 9999999999]), 1001), 'контроль');

    $bad = [
        'exp рядком' => '{"sub":"test-admin","iat":1000,"exp":"9999999999"}',
        'exp float' => '{"sub":"test-admin","iat":1000,"exp":9999999999.5}',
        'exp > int64 (float)' => '{"sub":"test-admin","iat":1000,"exp":99999999999999999999}',
        'iat рядком' => '{"sub":"test-admin","iat":"1000","exp":9999999999}',
        'відсутній exp' => '{"sub":"test-admin","iat":1000}',
        'відсутній iat' => '{"sub":"test-admin","exp":9999999999}',
        'відсутній sub' => '{"iat":1000,"exp":9999999999}',
        'sub null' => '{"sub":null,"iat":1000,"exp":9999999999}',
        'sub число' => '{"sub":123,"iat":1000,"exp":9999999999}',
        'sub масив' => '{"sub":["test-admin"],"iat":1000,"exp":9999999999}',
        'sub інший' => '{"sub":"other","iat":1000,"exp":9999999999}',
        'sub в іншому регістрі' => '{"sub":"Test-Admin","iat":1000,"exp":9999999999}',
        'sub з пробілом' => '{"sub":"test-admin ","iat":1000,"exp":9999999999}',
        'exp у минулому' => '{"sub":"test-admin","iat":1000,"exp":1000}',
        'payload — список' => '[1,2,3]',
        'payload — число' => '12345',
        'payload — рядок' => '"test-admin"',
        'payload — null' => 'null',
        'payload — не JSON' => 'not json',
        'payload — обірваний JSON' => '{"sub":"test-admin",',
        'payload — глибоко вкладений' => '{"sub":"test-admin","iat":1000,"exp":9999999999,"x":[[[[[[1]]]]]]}',
    ];
    foreach ($bad as $name => $json) {
        assertTrue(!$service->verify(unitForgePayload($config, $json), 1001), $name);
    }
});

// ---------- BE-004: AuthConfig ----------

/** Допоміжне: усі змінні конфігурації автентифікації з коректними значеннями + перевизначення. */
function unitAuthEnv(array $override = []): array
{
    return $override + [
        'ADMIN_LOGIN' => 'test-admin',
        'ADMIN_PASSWORD_HASH' => password_hash('unit-password-1', PASSWORD_BCRYPT, ['cost' => 4]),
        'TOKEN_SECRET' => 'UNIT-SECRET-VALUE-0123456789abcdef',
        'TOKEN_TTL_HOURS' => '',
    ];
}

test('AuthConfig::fromEnv: коректна конфігурація → поля; порожнє TOKEN_TTL_HOURS = 168 год; 24 → 24 год; межі 1 і 720', function () {
    $config = withEnv(unitAuthEnv(), fn () => AuthConfig::fromEnv());
    assertSame('test-admin', $config->login);
    assertSame('UNIT-SECRET-VALUE-0123456789abcdef', $config->secret);
    assertTrue(password_verify('unit-password-1', $config->passwordHash), 'хеш читається як є');
    assertSame(168 * 3600, $config->ttlSeconds, 'порожнє значення');

    assertSame(24 * 3600, withEnv(unitAuthEnv(['TOKEN_TTL_HOURS' => '24']), fn () => AuthConfig::fromEnv())->ttlSeconds);
    assertSame(3600, withEnv(unitAuthEnv(['TOKEN_TTL_HOURS' => '1']), fn () => AuthConfig::fromEnv())->ttlSeconds, 'нижня межа');
    assertSame(720 * 3600, withEnv(unitAuthEnv(['TOKEN_TTL_HOURS' => '720']), fn () => AuthConfig::fromEnv())->ttlSeconds, 'верхня межа');
});

test('AuthConfig::fromEnv: хеш із $ береться буквально (без інтерполяції)', function () {
    $hash = password_hash('unit-password-1', PASSWORD_BCRYPT, ['cost' => 4]);
    assertTrue(str_contains($hash, '$'), 'контроль: хеш містить $');
    assertSame($hash, withEnv(unitAuthEnv(['ADMIN_PASSWORD_HASH' => $hash]), fn () => AuthConfig::fromEnv())->passwordHash);
});

test('AuthConfig::fromEnv: кожна некоректна конфігурація → ApiException 500; у лозі назва змінної, але немає значень', function () use ($testLog) {
    $secrets = ['UNIT-SECRET-VALUE-0123456789abcdef', 'sssssssssssssssssssssssssssssss', 'not-a-hash-SENTINEL'];
    $cases = [
        'ADMIN_LOGIN порожній' => [['ADMIN_LOGIN' => ''], 'ADMIN_LOGIN'],
        'ADMIN_PASSWORD_HASH порожній' => [['ADMIN_PASSWORD_HASH' => ''], 'ADMIN_PASSWORD_HASH'],
        'ADMIN_PASSWORD_HASH не хеш' => [['ADMIN_PASSWORD_HASH' => 'not-a-hash-SENTINEL'], 'ADMIN_PASSWORD_HASH'],
        'TOKEN_SECRET 31 символ' => [['TOKEN_SECRET' => 'sssssssssssssssssssssssssssssss'], 'TOKEN_SECRET'],
        'TOKEN_SECRET порожній' => [['TOKEN_SECRET' => ''], 'TOKEN_SECRET'],
        'TTL 0' => [['TOKEN_TTL_HOURS' => '0'], 'TOKEN_TTL_HOURS'],
        'TTL 721' => [['TOKEN_TTL_HOURS' => '721'], 'TOKEN_TTL_HOURS'],
        'TTL abc' => [['TOKEN_TTL_HOURS' => 'abc'], 'TOKEN_TTL_HOURS'],
        'TTL -1' => [['TOKEN_TTL_HOURS' => '-1'], 'TOKEN_TTL_HOURS'],
        'TTL 1.5' => [['TOKEN_TTL_HOURS' => '1.5'], 'TOKEN_TTL_HOURS'],
        'TTL з пробілом' => [['TOKEN_TTL_HOURS' => ' 24'], 'TOKEN_TTL_HOURS'],
        'TTL з переносом' => [['TOKEN_TTL_HOURS' => "24\n"], 'TOKEN_TTL_HOURS'],
        'TTL гігантський' => [['TOKEN_TTL_HOURS' => '99999999999999999999'], 'TOKEN_TTL_HOURS'],
    ];
    assertSame(31, strlen('sssssssssssssssssssssssssssssss'), 'контроль: рівно 31 символ');

    foreach ($cases as $name => [$override, $variable]) {
        file_put_contents($testLog, '');
        $e = assertThrows(fn () => withEnv(unitAuthEnv($override), fn () => AuthConfig::fromEnv()), ApiException::class);
        assertSame(500, $e->status, $name);
        assertSame('server_error', $e->errorCode, $name);
        $log = (string) file_get_contents($testLog);
        assertTrue(str_contains($log, $variable), "{$name}: у лозі має бути назва змінної");
        foreach ($secrets as $secret) {
            assertTrue(!str_contains($log, $secret), "{$name}: у лозі значення секрету");
        }
        assertTrue(!str_contains($log, '$2y$'), "{$name}: у лозі хеш");
    }

    // Контроль: рівно 32 символи секрету проходять
    withEnv(unitAuthEnv(['TOKEN_SECRET' => str_repeat('k', 32)]), fn () => AuthConfig::fromEnv());
});

// ---------- BE-004: AdminGuard (без БД) ----------

test('guard: конфігурацію автентифікації читають лише адмін-шляхи (публічні шляхи від неї не залежать)', function () {
    $calls = 0;
    $guard = new App\Http\AdminGuard(function () use (&$calls): AuthConfig {
        $calls++;

        return unitConfig();
    });
    $router = new Router();
    $router->get('/api/message', fn () => Response::json(['ok' => true]));
    $router->get('/api/administrator', fn () => Response::json(['ok' => true]));
    $router->post('/api/admin/auth/login', fn () => Response::json(['login' => true]));
    $kernel = new App\Http\Kernel($router, $guard);

    foreach ([['GET', '/api/message'], ['GET', '/api/health'], ['GET', '/api/administrator'], ['GET', '/api/admins'], ['GET', '/'], ['GET', '/api/ADMIN/x']] as [$method, $path]) {
        $kernel->handle(new Request($method, $path));
    }
    assertSame(0, $calls, 'публічні шляхи не читали конфігурацію');

    assertSame(200, $kernel->handle(new Request('POST', '/api/admin/auth/login'))->status, 'POST /login без токена пропускається');
    assertSame(0, $calls, 'guard не читає конфігурацію для POST /login (її читає контролер)');

    assertSame(401, $kernel->handle(new Request('GET', '/api/admin/x'))->status);
    assertSame(1, $calls, 'адмін-шлях прочитав конфігурацію');
});

test('guard: помилка конфігурації на адмін-шляху → 500, а не пропуск; обробник маршруту не викликається', function () use ($testLog) {
    $reached = false;
    $guard = new App\Http\AdminGuard(static fn (): AuthConfig => throw ApiException::server());
    $router = new Router();
    $router->get('/api/admin/secret', function () use (&$reached) {
        $reached = true;

        return Response::json(['secret' => 1]);
    });
    $kernel = new App\Http\Kernel($router, $guard);

    foreach ([[], ['authorization' => 'Bearer a.b.c'], ['authorization' => 'garbage']] as $headers) {
        $response = $kernel->handle(new Request('GET', '/api/admin/secret', [], $headers));
        assertSame(500, $response->status);
        assertSame('server_error', decode($response)['error']['code']);
    }
    assertTrue(!$reached, 'обробник захищеного маршруту не виконувався');
});

test('guard: валідний токен пропускає до маршруту; без guard\'а (kernelWith) Kernel нічого не захищає — лише для тестових ядер', function () {
    $config = unitConfig();
    $router = new Router();
    $router->get('/api/admin/ok', fn () => Response::json(['ok' => true]));
    $kernel = new App\Http\Kernel($router, new App\Http\AdminGuard(static fn (): AuthConfig => $config));

    $token = (new TokenService($config))->issue(time())['token'];
    assertSame(200, $kernel->handle(new Request('GET', '/api/admin/ok', [], ['authorization' => "Bearer {$token}"]))->status);
    assertSame(401, $kernel->handle(new Request('GET', '/api/admin/ok'))->status);

    // Зафіксовано, що боєве ядро створюється з guard'ом: Kernel::create() без нього відкрило б адмінку
    $reflection = new ReflectionProperty(App\Http\Kernel::class, 'guard');
    $production = App\Http\Kernel::create();
    assertTrue($reflection->getValue($production) instanceof App\Http\AdminGuard, 'Kernel::create() має містити AdminGuard');
});

// ---------- BE-004: bin/hash-password.php ----------

/**
 * Запускає bin/hash-password.php з даним stdin (pipe, тож TTY-гілка не задіяна).
 *
 * @param list<string> $args
 * @return array{code: int, stdout: string, stderr: string}
 */
function runHashPassword(string $stdin, array $args = []): array
{
    $process = proc_open(
        [PHP_BINARY, APP_ROOT . '/bin/hash-password.php', ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start bin/hash-password.php');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

test('hash-password: пароль зі stdin → ADMIN_PASSWORD_HASH=\'$2y$…\'; хеш проходить password_verify лише з тим самим паролем', function () {
    $result = runHashPassword("correct-horse-battery\n");

    assertSame(0, $result['code'], $result['stderr']);
    assertSame(1, preg_match('/^ADMIN_PASSWORD_HASH=\'(\$2y\$[^\']+)\'$/D', rtrim($result['stdout'], "\n"), $m), $result['stdout']);
    assertTrue(password_verify('correct-horse-battery', $m[1]), 'той самий пароль');
    assertTrue(!password_verify('correct-horse-batterz', $m[1]), 'інший пароль');
    assertSame('', $result['stderr'], 'у STDERR нічого зайвого');
});

test('hash-password: \\r\\n відкидається, пробіли на краях зберігаються, довгий багатобайтовий пароль в межах 72 байт приймається', function () {
    $result = runHashPassword("  edge spaces 123  \r\n");
    assertSame(0, $result['code'], $result['stderr']);
    preg_match('/\'(\$2y\$[^\']+)\'/', $result['stdout'], $m);
    assertTrue(password_verify('  edge spaces 123  ', $m[1]), 'пробіли на краях частина пароля');

    $unicode = 'Пароль-для-адмінки'; // 18 символів, 33 байти
    $result = runHashPassword("{$unicode}\n");
    assertSame(0, $result['code'], $result['stderr']);
    preg_match('/\'(\$2y\$[^\']+)\'/', $result['stdout'], $m);
    assertTrue(password_verify($unicode, $m[1]));

    // Без кінцевого переносу рядка (printf '%s' без \n)
    $result = runHashPassword('no-newline-at-end');
    assertSame(0, $result['code'], $result['stderr']);
});

test('hash-password: короткий (11 символів), задовгий (73 байти), порожній пароль → код 1, STDOUT порожній, пароля в STDERR немає', function () {
    $cases = [
        '11 символів' => str_repeat('a', 11),
        '73 байти' => str_repeat('b', 73),
        'NUL-байт у паролі' => "abcdefghijk\0lmn",
    ];
    foreach ($cases as $name => $password) {
        $result = runHashPassword($password . "\n");
        assertSame(1, $result['code'], $name);
        assertSame('', $result['stdout'], "{$name}: STDOUT");
        assertTrue($result['stderr'] !== '', "{$name}: є пояснення");
        assertTrue(!str_contains($result['stderr'], $password), "{$name}: пароль у STDERR");
    }

    foreach (['порожній stdin' => '', 'порожній рядок' => "\n", 'лише \\r\\n' => "\r\n"] as $name => $stdin) {
        $result = runHashPassword($stdin);
        assertSame(1, $result['code'], $name);
        assertSame('', $result['stdout'], "{$name}: STDOUT");
    }

    // Межа: 72 байти ще приймається, 12 символів — теж
    assertSame(0, runHashPassword(str_repeat('c', 72) . "\n")['code'], '72 байти');
    assertSame(0, runHashPassword(str_repeat('d', 12) . "\n")['code'], '12 символів');
});

test('hash-password: гілка TTY (прихований ввід через stty) працює і не відлунює пароль на екран', function () {
    // Емуляція терміналу утилітою script (util-linux). Без неї перевірку пропускаємо.
    // Регресія: колись гілка завжди відмовляла, бо shell_exec() повертає null і при успішному `stty -echo`.
    if (trim((string) shell_exec('command -v script 2>/dev/null')) === '') {
        return;
    }

    $inner = PHP_BINARY . ' ' . escapeshellarg(APP_ROOT . '/bin/hash-password.php');
    $process = proc_open(
        'script -qec ' . escapeshellarg($inner) . ' /dev/null',
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start script');
    }
    stream_set_blocking($pipes[1], false);

    $output = '';
    $read = static function () use (&$output, $pipes): bool {
        $chunk = fread($pipes[1], 4096);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $output .= $chunk;

        return true;
    };
    // Вводимо рядок лише після приглашення: воно друкується ПІСЛЯ `stty -echo`, тож гонки з відлунням немає
    $waitFor = static function (string $needle) use (&$output, $read): void {
        $deadline = microtime(true) + 15;
        while (!str_contains($output, $needle)) {
            if (microtime(true) > $deadline) {
                throw new AssertionError("не дочекались «{$needle}», вивід: {$output}");
            }
            if (!$read()) {
                usleep(20000);
            }
        }
    };

    try {
        $waitFor('Password: ');
        fwrite($pipes[0], "correct-horse-battery\n");
        $waitFor('Repeat: ');
        fwrite($pipes[0], "correct-horse-battery\n");
        fclose($pipes[0]);

        $deadline = microtime(true) + 15;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            if (!$read()) {
                usleep(20000);
            }
        }
        while ($read()) {
        }
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
    }

    assertSame(0, $code, $output);
    assertSame(1, preg_match('/ADMIN_PASSWORD_HASH=\'(\$2y\$[^\']+)\'/', $output, $m), $output);
    assertTrue(password_verify('correct-horse-battery', $m[1]), 'хеш відповідає введеному паролю');
    assertTrue(!str_contains($output, 'correct-horse-battery'), 'пароль не повинен відлунюватись на екран');
});

test('hash-password: пароль в аргументах командного рядка ігнорується (береться лише stdin)', function () {
    $result = runHashPassword('', ['argument-password-123']);

    assertSame(1, $result['code']);
    assertSame('', $result['stdout']);
});

// ---------- Запуск ----------

runTests();
