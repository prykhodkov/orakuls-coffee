<?php

declare(strict_types=1);

// Юнит-перевірки каркаса без PHPUnit і без БД.
// Запуск: docker compose run --rm --no-deps php php tests/run.php

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\ApiException;
use App\Support\Config;
use App\Support\Json;

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

// error_log() у тестах іде в тимчасовий файл, щоб перевірити, що деталі помилки потрапляють в лог
$testLog = sys_get_temp_dir() . '/orakuls-test-' . getmypid() . '.log';
ini_set('error_log', $testLog);

/** @var array<string, callable> $tests */
$tests = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function assertSame(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(
            ($note !== '' ? "{$note}: " : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $condition, string $note = ''): void
{
    assertSame(true, $condition, $note);
}

function decode(Response $response): array
{
    return json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
}

function kernelWith(callable $configure): Kernel
{
    $router = new Router();
    $configure($router);

    return new Kernel($router);
}

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

// ---------- Запуск ----------

$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ok    {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  {$name}\n        " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

@unlink($testLog);

echo sprintf("\n%d тестів, %d провалено\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
