<?php

declare(strict_types=1);

// Перевірки авторизації адміна (BE-004): вхід, токен, guard у Kernel, ліміт спроб, конфігурація.
// Підключається з tests/api.php; облікові дані (TEST_ADMIN_*, TEST_TOKEN_SECRET) задає tests/api.php через putenv.
// Це задача про безпеку: кожне правило має і «позитивну», і «негативну» перевірку, а позитивні контролі
// (forgeToken з коректними даними → 200) доводять, що негативні відмови настали саме через заявлену причину.
//
// Кожен тест, що створює невдалі спроби, починає з authClearAttempts() і користується власними IP з діапазону
// 203.0.113.0/24 (TEST-NET-3, документаційний), щоб тести не заважали один одному.

use App\Auth\AuthConfig;
use App\Auth\LoginRateLimiter;
use App\Http\Response;

const AUTH_UNAUTHORIZED_BODY = '{"error":{"code":"unauthorized","message":"Unauthorized"}}';
const AUTH_INVALID_CREDENTIALS_BODY = '{"error":{"code":"unauthorized","message":"Invalid credentials"}}';

/**
 * @param array<string, mixed>|string $body масив кодується в JSON, рядок іде як є (для некоректного JSON)
 * @param array<string, string>       $headers
 */
function authLogin(array|string $body, string $ip = '203.0.113.10', array $headers = []): Response
{
    $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

    return apiRequest('POST', '/api/admin/auth/login', [], ['content-type' => 'application/json'] + $headers, $raw, $ip);
}

/** Правильні облікові дані з IP, який у тестах ліміту не використовується. */
function authCredentials(): array
{
    return ['login' => TEST_ADMIN_LOGIN, 'password' => TEST_ADMIN_PASSWORD];
}

function authToken(string $ip = '203.0.113.99'): string
{
    $response = authLogin(authCredentials(), $ip);
    assertSame(200, $response->status, 'вхід для отримання токена: ' . $response->body);

    return decode($response)['token'];
}

function authAs(string $token, string $method, string $path): Response
{
    return apiRequest($method, $path, [], ['authorization' => "Bearer {$token}"]);
}

function authClearAttempts(): void
{
    testPdo()->exec('DELETE FROM login_attempts');
}

function authAttemptCount(?string $ip = null): int
{
    if ($ip === null) {
        return (int) testPdo()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    }
    $stmt = testPdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);

    return (int) $stmt->fetchColumn();
}

/** Кількість невдалих входів з IP (кожен має дати 401, інакше тест зламано). */
function authFail(string $ip, int $times, string $password = 'definitely-wrong-password'): void
{
    for ($i = 0; $i < $times; $i++) {
        $response = authLogin(['login' => TEST_ADMIN_LOGIN, 'password' => $password], $ip);
        assertSame(401, $response->status, "невдала спроба #{$i} з {$ip}: " . $response->body);
    }
}

/**
 * «Ручний» випуск токена тим самим алгоритмом ключа, що й у TokenService, — для негативних перевірок.
 * Header за замовчуванням коректний; підміна header перевіряє, що відмова — через нього, а не через підпис.
 *
 * @param array<string, mixed> $payload
 */
function forgeToken(AuthConfig $config, array $payload, ?string $headerJson = null): string
{
    $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $input = $b64($headerJson ?? '{"alg":"HS256","typ":"JWT"}') . '.' . $b64(json_encode($payload, JSON_THROW_ON_ERROR));
    $key = hash_hmac('sha256', $config->passwordHash, $config->secret, true);

    return $input . '.' . $b64(hash_hmac('sha256', $input, $key, true));
}

/** Конфігурація, з якою зараз працює застосунок (тестова, з putenv у tests/api.php). */
function authConfig(): AuthConfig
{
    return AuthConfig::fromEnv();
}

/** Дійсні claims для forgeToken. */
function authClaims(array $override = []): array
{
    return $override + ['sub' => TEST_ADMIN_LOGIN, 'iat' => time(), 'exp' => time() + 3600];
}

/**
 * Виконує $fn з error_log у тимчасовий файл (щоб не засмічувати вивід і мати що перевірити); повертає вміст логу.
 */
function authCaptureLog(callable $fn): string
{
    $file = tempnam(sys_get_temp_dir(), 'orakuls-auth-log');
    $previous = (string) ini_get('error_log');
    ini_set('error_log', $file);
    try {
        $fn();

        return (string) file_get_contents($file);
    } finally {
        ini_set('error_log', $previous);
        @unlink($file);
    }
}

function authRetryAfter(Response $response): int
{
    $value = $response->headers['Retry-After'] ?? '';
    assertTrue(ctype_digit($value), "Retry-After має бути цілим числом, отримано '{$value}'");

    return (int) $value;
}

function authAssertUnauthorized(Response $response, string $note): void
{
    assertSame(401, $response->status, $note . ': ' . $response->body);
    assertSame(AUTH_UNAUTHORIZED_BODY, $response->body, $note);
}

// ---------- Вхід ----------

test('auth: успішний вхід → 200, рівно token і expiresAt, термін ≈ 168 год, no-store', function () {
    authClearAttempts();
    $before = time();
    $response = authLogin(authCredentials());
    $data = decode($response);

    assertSame(200, $response->status, $response->body);
    assertSame(['token', 'expiresAt'], array_keys($data));
    assertSame(3, count(explode('.', $data['token'])), 'токен із трьох частин');
    assertSame(1, preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $data['expiresAt']), $data['expiresAt']);
    $ttl = strtotime($data['expiresAt']) - $before;
    assertTrue($ttl > 0, 'expiresAt у майбутньому');
    assertTrue(abs($ttl - 168 * 3600) <= 5, "термін {$ttl} с, очікувалось 168 год");
    assertSame('no-store', $response->headers['Cache-Control']);
    assertTrue(str_starts_with($response->headers['Content-Type'], 'application/json'));
    assertSame(0, authAttemptCount(), 'успіх не залишає запису');
});

test('auth: термін із TOKEN_TTL_HOURS; порожнє = 168 год; значення поза 1…720 і нечислові → 500 server_error', function () {
    authClearAttempts();
    $ttl = static function (?string $hours): int {
        return withEnv(['TOKEN_TTL_HOURS' => $hours], function (): int {
            $response = authLogin(authCredentials());
            assertSame(200, $response->status, $response->body);

            return strtotime(decode($response)['expiresAt']) - time();
        });
    };

    assertTrue(abs($ttl('2') - 2 * 3600) <= 5, '2 год');
    assertTrue(abs($ttl('') - 168 * 3600) <= 5, 'порожнє = 168 год');
    assertTrue(abs($ttl('1') - 3600) <= 5, '1 год');
    assertTrue(abs($ttl('720') - 720 * 3600) <= 5, '720 год');

    authCaptureLog(function () {
        foreach (['0', '721', 'abc', '-1', '1.5', ' 5', '5 '] as $bad) {
            $response = withEnv(['TOKEN_TTL_HOURS' => $bad], fn () => authLogin(authCredentials()));
            assertSame(500, $response->status, "TOKEN_TTL_HOURS='{$bad}'");
            assertSame('server_error', decode($response)['error']['code'], $bad);
        }
    });
});

test('auth: невірний логін або пароль, обидва невірні, логін в іншому регістрі → 401 з однаковим тілом байт у байт', function () {
    authClearAttempts();
    $cases = [
        'неправильний пароль' => ['login' => TEST_ADMIN_LOGIN, 'password' => 'wrong-password-123'],
        'неправильний логін' => ['login' => 'nobody', 'password' => TEST_ADMIN_PASSWORD],
        'обидва неправильні' => ['login' => 'nobody', 'password' => 'wrong-password-123'],
        'логін в іншому регістрі' => ['login' => 'Test-Admin', 'password' => TEST_ADMIN_PASSWORD],
        'пароль в іншому регістрі' => ['login' => TEST_ADMIN_LOGIN, 'password' => strtoupper(TEST_ADMIN_PASSWORD)],
        'логін з пробілом' => ['login' => TEST_ADMIN_LOGIN . ' ', 'password' => TEST_ADMIN_PASSWORD],
        'пароль з пробілом' => ['login' => TEST_ADMIN_LOGIN, 'password' => TEST_ADMIN_PASSWORD . ' '],
        'пароль — префікс правильного' => ['login' => TEST_ADMIN_LOGIN, 'password' => substr(TEST_ADMIN_PASSWORD, 0, -1)],
    ];

    $n = 0;
    foreach ($cases as $name => $body) {
        $response = authLogin($body, '203.0.113.' . (110 + $n++)); // окремий IP: ліміт не заважає
        assertSame(401, $response->status, $name);
        assertSame(AUTH_INVALID_CREDENTIALS_BODY, $response->body, $name);
        assertTrue(!array_key_exists('fields', decode($response)['error']), "{$name}: без fields");
        assertTrue(!isset($response->headers['Retry-After']), "{$name}: без Retry-After");
    }
});

test('auth: некоректний JSON і порожнє тіло → 400 bad_request', function () {
    authClearAttempts();
    foreach (['not json', '', '   ', '{"login":', '"text"', '123', 'null', '[1,2]', 'true'] as $body) {
        $response = authLogin($body, '203.0.113.120');
        assertSame(400, $response->status, "тіло: " . var_export($body, true) . ' → ' . $response->body);
        assertSame('bad_request', decode($response)['error']['code']);
    }
    assertSame(0, authAttemptCount(), '400 не рахується як спроба');
});

test('auth: відсутні, не рядкові, порожні login/password і пароль > 1024 байт → 422 validation_error з fields', function () {
    authClearAttempts();
    $required = ['login' => 'required', 'password' => 'required'];
    $cases = [
        ['{}', $required],
        ['[]', $required],
        [['password' => 'x'], ['login' => 'required']],
        [['login' => 'x'], ['password' => 'required']],
        [['login' => '', 'password' => ''], $required],
        [['login' => '', 'password' => 'x'], ['login' => 'required']],
        [['login' => 'x', 'password' => ''], ['password' => 'required']],
        [['login' => 123, 'password' => 456], $required],
        [['login' => null, 'password' => null], $required],
        [['login' => ['a'], 'password' => ['b']], $required],
        [['login' => true, 'password' => false], $required],
        [['login' => 1.5, 'password' => 'x'], ['login' => 'required']],
        [['login' => 'x', 'password' => str_repeat('a', 1025)], ['password' => 'too_long']],
        [['login' => '', 'password' => str_repeat('a', 5000)], ['login' => 'required', 'password' => 'too_long']],
        [['login' => 'x', 'password' => str_repeat('я', 513)], ['password' => 'too_long']], // 1026 байт
    ];

    foreach ($cases as [$body, $fields]) {
        $label = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body;
        $response = authLogin($body, '203.0.113.121');
        $error = decode($response)['error'];

        assertSame(422, $response->status, mb_substr((string) $label, 0, 60) . ' → ' . mb_substr($response->body, 0, 200));
        assertSame('validation_error', $error['code']);
        assertSame($fields, $error['fields'], mb_substr((string) $label, 0, 60));
    }
    assertSame(0, authAttemptCount(), '422 не рахується як спроба');
});

test('auth: 12 запитів із помилками валідації не наближають до ліміту — потім правильний вхід дає 200 і в таблиці 0 рядків', function () {
    authClearAttempts();
    for ($i = 0; $i < 6; $i++) {
        assertSame(422, authLogin('{}', '203.0.113.130')->status);
        assertSame(400, authLogin('garbage', '203.0.113.130')->status);
    }

    assertSame(200, authLogin(authCredentials(), '203.0.113.130')->status);
    assertSame(0, authAttemptCount(), 'жодного запису в login_attempts');
});

test('auth: пароль рівно 1024 байти (неправильний) → 401, а не 422', function () {
    authClearAttempts();
    $response = authLogin(['login' => TEST_ADMIN_LOGIN, 'password' => str_repeat('a', 1024)], '203.0.113.131');

    assertSame(401, $response->status, $response->body);
    assertSame(AUTH_INVALID_CREDENTIALS_BODY, $response->body);
});

// ---------- me та guard ----------

test('auth: GET /me з дійсним токеном → 200 {"login":"test-admin"} і no-store', function () {
    $response = authAs(authToken(), 'GET', '/api/admin/auth/me');

    assertSame(200, $response->status, $response->body);
    assertSame('{"login":"test-admin"}', $response->body);
    assertSame('no-store', $response->headers['Cache-Control']);
});

test('auth: guard закритий — будь-який шлях під /api/admin/ без токена → 401 (окрім POST /login); з токеном невідомий шлях → 404', function () {
    authClearAttempts();
    $closed = [
        ['GET', '/api/admin/auth/me'],
        ['GET', '/api/admin/phrases'],
        ['GET', '/api/admin/stats'],
        ['POST', '/api/admin/phrases/import'],
        ['POST', '/api/admin/phrases'],
        ['PUT', '/api/admin/phrases/1'],
        ['DELETE', '/api/admin/phrases/1'],
        ['GET', '/api/admin/взагалі-щось'],
        ['GET', '/api/admin/nope/nested/path'],
        ['GET', '/api/admin'],
        ['GET', '/api/admin/'],
        ['POST', '/api/admin'],
        ['GET', '/api/admin/auth/login'],
        ['PUT', '/api/admin/auth/login'],
        ['DELETE', '/api/admin/auth/login'],
        ['HEAD', '/api/admin/auth/login'],
        ['PATCH', '/api/admin/auth/login'],
        ['OPTIONS', '/api/admin/auth/login'],
        ['POST', '/api/admin/auth/me'],
        ['POST', '/api/admin/auth/login/'],
        ['POST', '/api/admin/auth/login/extra'],
    ];
    foreach ($closed as [$method, $path]) {
        $response = apiRequest($method, $path);
        authAssertUnauthorized($response, "{$method} {$path}");
        assertSame(['code', 'message'], array_keys(decode($response)['error']), "{$method} {$path}: без fields");
    }

    $token = authToken();
    foreach ([['GET', '/api/admin/взагалі-щось'], ['GET', '/api/admin'], ['GET', '/api/admin/auth/login'], ['PUT', '/api/admin/phrases/1']] as [$method, $path]) {
        $response = authAs($token, $method, $path);
        assertSame(404, $response->status, "{$method} {$path} з токеном: " . $response->body);
        assertSame('not_found', decode($response)['error']['code']);
    }

    // POST /login — єдиний виняток: токена не вимагає (порожнє тіло → 400/422 від самого логіну, не 401)
    assertSame(422, authLogin('{}', '203.0.113.140')->status);
    assertSame(400, authLogin('', '203.0.113.140')->status);
    assertSame(200, authLogin(authCredentials(), '203.0.113.140')->status);
});

test('auth: спроби обійти guard іншим записом шляху ніколи не дають 200 (лише 401/404)', function () {
    $paths = [
        '/api/admin/../admin/auth/me',
        '/api//admin/auth/me',
        '//api/admin/auth/me',
        '/api/%61dmin/auth/me',
        '/api/admin%2Fauth/me',
        '/api/admin%2fauth%2fme',
        '/API/admin/auth/me',
        '/api/ADMIN/auth/me',
        '/api/admin/auth/me/',
        '/api/admin/auth/me%00',
        "/api/admin/auth/me\0",
        '/api/admin/auth/ME',
        '/api/admin/auth/me/.',
        '/api/admin/./auth/me',
        '/api/admin /auth/me',
        '/api/admin/auth/me?x=1',
        '/api/admin/auth/me#x',
        ' /api/admin/auth/me',
        '/api/admin.json',
        '/api/administrator',
    ];
    foreach ($paths as $path) {
        $status = apiRequest('GET', $path)->status;
        assertTrue(in_array($status, [401, 404], true), "GET {$path} → {$status}");
    }
});

// ---------- Підроблені та прострочені токени ----------

test('auth: підроблені токени (підпис, payload, header, форма) → 401; контроль: коректно підписаний токен → 200', function () {
    $config = authConfig();
    $token = authToken();
    [$h, $p, $s] = explode('.', $token);
    $b64 = static fn (string $x): string => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $me = static fn (string $t): Response => authAs($t, 'GET', '/api/admin/auth/me');

    assertSame(200, $me($token)->status, 'контроль: справжній токен');
    assertSame(200, $me(forgeToken($config, authClaims()))->status, 'контроль: forgeToken дає дійсний токен');

    $last = $s[strlen($s) - 1];
    $forged = [
        'змінений останній символ підпису' => "{$h}.{$p}." . substr($s, 0, -1) . ($last === 'A' ? 'B' : 'A'),
        'змінений перший символ підпису' => "{$h}.{$p}." . ($s[0] === 'A' ? 'B' : 'A') . substr($s, 1),
        'змінений символ у payload' => "{$h}." . substr($p, 0, 5) . ($p[5] === 'A' ? 'B' : 'A') . substr($p, 6) . ".{$s}",
        'payload з exp +10 років, підпис старий' => "{$h}." . $b64(json_encode(authClaims(['exp' => time() + 315360000]))) . ".{$s}",
        'alg none, порожній підпис' => $b64('{"alg":"none","typ":"JWT"}') . ".{$p}.",
        'alg none, справжній підпис' => $b64('{"alg":"none","typ":"JWT"}') . ".{$p}.{$s}",
        'alg HS512 (підпис ключем HS256)' => forgeToken($config, authClaims(), '{"alg":"HS512","typ":"JWT"}'),
        'header із зайвим полем' => forgeToken($config, authClaims(), '{"alg":"HS256","typ":"JWT","kid":"1"}'),
        'header без typ' => forgeToken($config, authClaims(), '{"alg":"HS256"}'),
        'лише header і payload (2 частини)' => "{$h}.{$p}",
        '4 частини' => "{$h}.{$p}.{$s}.{$s}",
        '4 частини з порожньою останньою' => "{$h}.{$p}.{$s}.",
        'порожній payload' => "{$h}..{$s}",
        'порожній header' => ".{$p}.{$s}",
        'порожній підпис' => "{$h}.{$p}.",
        'сміття' => 'garbage',
        'одна крапка' => 'a.b',
        'три крапки без вмісту' => '..',
        '= паддінг у підписі' => "{$h}.{$p}.{$s}=",
        '+ у підписі' => "{$h}.{$p}.{$s}+",
        '/ у підписі' => "{$h}.{$p}.a/b",
        'токен на 3000 символів' => str_repeat('a', 1500) . '.' . str_repeat('b', 1000) . '.' . str_repeat('c', 500),
        'токен на 100000 символів' => str_repeat('a', 100000),
    ];
    foreach ($forged as $name => $bad) {
        assertTrue($bad !== $token, "{$name}: тест не змінив токен");
        authAssertUnauthorized($me($bad), $name);
    }
});

test('auth: прострочений токен, токен іншого секрету/логіна, без sub, exp рядком → 401; контроль: exp у майбутньому → 200', function () {
    $config = authConfig();
    $me = static fn (string $t): Response => authAs($t, 'GET', '/api/admin/auth/me');

    assertSame(200, $me(forgeToken($config, authClaims(['exp' => time() + 60])))->status, 'контроль');
    authAssertUnauthorized($me(forgeToken($config, authClaims(['exp' => time() - 1]))), 'exp = now − 1');
    authAssertUnauthorized($me(forgeToken($config, authClaims(['exp' => time() - 86400]))), 'exp у минулому');
    authAssertUnauthorized($me(forgeToken($config, authClaims(['exp' => time(), 'iat' => time() - 3600]))), 'exp = now (суворо)');

    $otherSecret = new AuthConfig($config->login, $config->passwordHash, str_repeat('z', 40), 3600);
    authAssertUnauthorized($me(forgeToken($otherSecret, authClaims())), 'підписано іншим TOKEN_SECRET');
    $otherHash = new AuthConfig($config->login, password_hash('another-password-1', PASSWORD_BCRYPT, ['cost' => 4]), $config->secret, 3600);
    authAssertUnauthorized($me(forgeToken($otherHash, authClaims())), 'підписано ключем з іншого хеша');

    $claims = authClaims();
    unset($claims['sub']);
    authAssertUnauthorized($me(forgeToken($config, $claims)), 'без sub');
    authAssertUnauthorized($me(forgeToken($config, authClaims(['sub' => 'other-user']))), 'sub іншого користувача');
    authAssertUnauthorized($me(forgeToken($config, authClaims(['exp' => (string) (time() + 3600)]))), 'exp рядком');
    authAssertUnauthorized($me(forgeToken($config, authClaims(['exp' => time() + 3600.5]))), 'exp float');
    $claims = authClaims();
    unset($claims['exp']);
    authAssertUnauthorized($me(forgeToken($config, $claims)), 'без exp');
});

test('auth: схема Authorization — тільки Bearer з одним пробілом; bearer/BEARER приймаються; решта → 401', function () {
    $token = authToken();
    $me = static fn (?string $header): Response => apiRequest('GET', '/api/admin/auth/me', [], $header === null ? [] : ['authorization' => $header]);

    foreach (['bearer', 'BEARER', 'Bearer', 'bEaReR'] as $scheme) {
        assertSame(200, $me("{$scheme} {$token}")->status, "{$scheme} приймається");
    }

    $bad = [
        'без заголовка' => null,
        'Basic' => "Basic {$token}",
        'Token' => "Token {$token}",
        'без схеми' => $token,
        'Bearer без токена' => 'Bearer',
        'Bearer з пробілом, токен порожній' => 'Bearer ',
        'два пробіли' => "Bearer  {$token}",
        'пробіл на початку' => " Bearer {$token}",
        'пробіл у кінці' => "Bearer {$token} ",
        'табуляція замість пробілу' => "Bearer\t{$token}",
        'перенос рядка у кінці' => "Bearer {$token}\n",
        'Bearertoken без пробілу' => "Bearer{$token}",
        'два токени' => "Bearer {$token} {$token}",
        'порожній заголовок' => '',
        'Bearer null' => 'Bearer null',
        'Bearer з лапками' => "Bearer \"{$token}\"",
    ];
    foreach ($bad as $name => $header) {
        authAssertUnauthorized($me($header), $name);
    }
});

test('auth: зміна ADMIN_PASSWORD_HASH, TOKEN_SECRET або ADMIN_LOGIN анулює видані токени; після відновлення вони знову дійсні', function () {
    $token = authToken();
    $me = static fn (): Response => authAs($token, 'GET', '/api/admin/auth/me');
    $hash = authConfig()->passwordHash;
    assertSame(200, $me()->status, 'контроль');

    $changes = [
        'інший хеш (інший пароль)' => ['ADMIN_PASSWORD_HASH' => password_hash('another-password-1', PASSWORD_BCRYPT, ['cost' => 4])],
        'інший хеш того самого пароля' => ['ADMIN_PASSWORD_HASH' => password_hash(TEST_ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])],
        'інший TOKEN_SECRET' => ['TOKEN_SECRET' => str_repeat('q', 40)],
        'інший ADMIN_LOGIN' => ['ADMIN_LOGIN' => 'renamed-admin'],
    ];
    foreach ($changes as $name => $vars) {
        $response = withEnv($vars, $me);
        authAssertUnauthorized($response, $name);
        assertSame(200, $me()->status, "після відновлення ({$name}) токен знову дійсний");
    }
    assertSame($hash, authConfig()->passwordHash, 'withEnv відновив хеш');
});

// ---------- Некоректна конфігурація ----------

test('auth: некоректна конфігурація → 500 на логіні й на будь-якому адмін-шляху (з токеном і без), без значень секретів; публічні шляхи працюють', function () {
    authClearAttempts();
    $token = authToken();
    $sentinels = ['SENTINEL-NOT-A-HASH', 'SENTINEL-SHORT-SECRET', TEST_ADMIN_PASSWORD];
    $cases = [
        'порожній ADMIN_PASSWORD_HASH' => ['ADMIN_PASSWORD_HASH' => ''],
        'ADMIN_PASSWORD_HASH не хеш' => ['ADMIN_PASSWORD_HASH' => 'SENTINEL-NOT-A-HASH'],
        'TOKEN_SECRET 31 символ' => ['TOKEN_SECRET' => str_repeat('s', 31)],
        'TOKEN_SECRET коротший' => ['TOKEN_SECRET' => 'SENTINEL-SHORT-SECRET'],
        'порожній TOKEN_SECRET' => ['TOKEN_SECRET' => ''],
        'порожній ADMIN_LOGIN' => ['ADMIN_LOGIN' => ''],
    ];

    $log = authCaptureLog(function () use ($cases, $token, $sentinels) {
        foreach ($cases as $name => $vars) {
            withEnv($vars, function () use ($name, $token, $sentinels) {
                $responses = [
                    'POST /login (правильні дані)' => authLogin(authCredentials(), '203.0.113.150'),
                    'GET /me з токеном' => authAs($token, 'GET', '/api/admin/auth/me'),
                    'GET /me без токена' => apiRequest('GET', '/api/admin/auth/me'),
                    'GET невідомий адмін-шлях без токена' => apiRequest('GET', '/api/admin/nope'),
                    'GET /me зі сміттєвим токеном' => authAs('garbage', 'GET', '/api/admin/auth/me'),
                ];
                foreach ($responses as $what => $response) {
                    assertSame(500, $response->status, "{$name}: {$what} → {$response->status} {$response->body}");
                    assertSame('{"error":{"code":"server_error","message":"Internal server error"}}', $response->body, "{$name}: {$what}");
                    foreach ($sentinels as $secret) {
                        assertTrue(!str_contains((string) $response->body, $secret), "{$name}: у відповіді значення конфігурації");
                    }
                }

                // Публічні шляхи не залежать від адмін-конфігурації
                $message = apiRequest('GET', '/api/message', ['lang' => 'lv']);
                assertSame(200, $message->status, "{$name}: /api/message → " . $message->body);
                assertSame(200, apiRequest('GET', '/api/health')->status, "{$name}: /api/health");
            });
        }
    });

    foreach (['ADMIN_PASSWORD_HASH', 'TOKEN_SECRET', 'ADMIN_LOGIN'] as $variable) {
        assertTrue(str_contains($log, $variable), "у лозі має бути назва {$variable}");
    }
    foreach ($sentinels as $secret) {
        assertTrue(!str_contains($log, $secret), 'у лозі значення конфігурації');
    }
    assertSame(0, authAttemptCount(), '500 не рахується як спроба входу');
});

// ---------- Ліміт спроб ----------

test('auth: 5 невдалих → шоста (навіть правильна) 429 з Retry-After; заблокований запит нічого не записує; інший IP вільний', function () {
    authClearAttempts();
    $a = '203.0.113.160';

    authFail($a, 5);
    assertSame(5, authAttemptCount($a));

    $blocked = authLogin(authCredentials(), $a);
    assertSame(429, $blocked->status, $blocked->body);
    assertSame('{"error":{"code":"rate_limited","message":"Too many requests"}}', $blocked->body);
    $retry = authRetryAfter($blocked);
    assertTrue($retry >= 1 && $retry <= 900, "Retry-After={$retry}");
    assertSame(5, authAttemptCount($a), 'заблокований запит не збільшує лічильник');

    assertSame(429, authLogin(['login' => TEST_ADMIN_LOGIN, 'password' => 'wrong-again-123'], $a)->status, 'неправильний пароль теж 429');
    assertSame(429, authLogin(authCredentials(), $a)->status, 'сьомий запит');
    assertSame(5, authAttemptCount($a), 'блокування не продовжується запитами');

    // Валідація йде раніше за ліміт: некоректний запит заблокованого IP — 422, а не 429, і нічого не пише
    assertSame(422, authLogin('{}', $a)->status);

    assertSame(200, authLogin(authCredentials(), '203.0.113.161')->status, 'інший IP не заблоковано');
    assertSame(401, authLogin(['login' => TEST_ADMIN_LOGIN, 'password' => 'wrong-password-123'], '203.0.113.161')->status, 'інший IP лишається з власним лічильником');
    assertSame(1, authAttemptCount('203.0.113.161'));
});

test('auth: Retry-After рахується від найстарішої з п\'яти спроб; після закінчення вікна вхід знову можливий, прострочені рядки видалені', function () {
    authClearAttempts();
    $a = '203.0.113.170';
    $pdo = testPdo();
    authFail($a, 5);

    $ids = $pdo->query("SELECT id FROM login_attempts WHERE ip = '{$a}' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare('UPDATE login_attempts SET attempted_at = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE id = ?');
    foreach ([800, 600, 300, 100, 10] as $i => $age) {
        $update->execute([$age, $ids[$i]]);
    }

    $blocked = authLogin(authCredentials(), $a);
    assertSame(429, $blocked->status);
    $retry = authRetryAfter($blocked);
    assertTrue(abs($retry - 100) <= 2, "Retry-After={$retry}, очікувалось ≈ 100 (900 − 800)");

    // Чотири спроби у вікні й одна прострочена → блоку немає
    $update->execute([901, $ids[4]]);
    foreach ([10, 20, 30, 40] as $i => $age) {
        $update->execute([$age, $ids[$i]]);
    }
    assertSame(200, authLogin(authCredentials(), $a)->status, '4 у вікні + 1 прострочена');
    assertSame(4, authAttemptCount($a), 'прострочена видалена, свіжі лишились');

    // Усі прострочені
    foreach ($pdo->query("SELECT id FROM login_attempts WHERE ip = '{$a}'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $update->execute([901, $id]);
    }
    assertSame(200, authLogin(authCredentials(), $a)->status, 'після закінчення вікна');
    assertSame(0, authAttemptCount($a), 'прострочені рядки видалено');

    // Межа: найстаріша спроба майже на межі вікна → Retry-After мінімальний, але не менший за 1
    authClearAttempts();
    authFail($a, 5);
    $ids = $pdo->query("SELECT id FROM login_attempts WHERE ip = '{$a}' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([10, 10, 10, 10, 899] as $i => $age) {
        $update->execute([$age, $ids[$i]]);
    }
    $retry = authRetryAfter(authLogin(authCredentials(), $a));
    assertTrue($retry >= 1 && $retry <= 3, "Retry-After={$retry}");
});

test('auth: успішний вхід не збільшує лічильник (3 невдачі + успіх = 3 рядки; ще 2 невдачі → блок)', function () {
    authClearAttempts();
    $ip = '203.0.113.180';

    authFail($ip, 3);
    assertSame(200, authLogin(authCredentials(), $ip)->status);
    assertSame(3, authAttemptCount($ip), 'успіх не додає рядка');

    authFail($ip, 2);
    assertSame(5, authAttemptCount($ip));
    assertSame(429, authLogin(authCredentials(), $ip)->status, 'правильний пароль після 5 невдач — 429');
});

test('auth: X-Forwarded-For не обходить і не підміняє ліміт', function () {
    authClearAttempts();
    $a = '203.0.113.190';
    $d = '203.0.113.191';
    authFail($a, 5);

    // Заблокований IP не звільняється підміною заголовка
    $forged = ['x-forwarded-for' => '198.51.100.7', 'x-real-ip' => '198.51.100.8', 'client-ip' => '198.51.100.9'];
    assertSame(429, authLogin(authCredentials(), $a, $forged)->status, 'заблокований IP + підроблені заголовки');

    // Чистий IP із заголовком, що вказує на заблокований, не успадковує блокування
    assertSame(200, authLogin(authCredentials(), $d, ['x-forwarded-for' => $a])->status, 'чистий IP + XFF заблокованого');

    // Невдача чистого IP записується на нього самого, а не на адресу із заголовка
    assertSame(401, authLogin(['login' => TEST_ADMIN_LOGIN, 'password' => 'wrong-password-123'], $d, ['x-forwarded-for' => '198.51.100.55'])->status);
    assertSame(1, authAttemptCount($d), 'записано на REMOTE_ADDR');
    assertSame(0, authAttemptCount('198.51.100.55'), 'адресу з XFF не записано');
    assertSame(5, authAttemptCount($a), 'лічильник заблокованого IP не змінився');
});

test('auth: ліміт працює на IP з Request::fromGlobals() (REMOTE_ADDR); X-Forwarded-For з «справжнього» шляху розбору теж ігнорується', function () {
    authClearAttempts();
    $a = '203.0.113.195';
    $d = '203.0.113.196';
    authFail($a, 5);

    // Запит проходить через справжній розбір $_SERVER, а не через ip, заданий тестом напряму; тіло в CLI
    // з php://input недоступне, тому переносимо в новий Request лише вирахуваний ip
    $viaGlobals = function (array $server, array $body): Response {
        $saved = $_SERVER;
        try {
            $_SERVER = $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/admin/auth/login'];
            $parsed = App\Http\Request::fromGlobals();
        } finally {
            $_SERVER = $saved;
        }

        return apiKernel()->handle(new App\Http\Request(
            $parsed->method, $parsed->path, [], ['content-type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), $parsed->ip
        ));
    };

    // Заблокований REMOTE_ADDR + XFF чистої адреси → усе одно 429
    assertSame(429, $viaGlobals(['REMOTE_ADDR' => $a, 'HTTP_X_FORWARDED_FOR' => $d], authCredentials())->status, 'XFF не знімає блок');
    // Чистий REMOTE_ADDR + XFF заблокованої адреси → 200
    assertSame(200, $viaGlobals(['REMOTE_ADDR' => $d, 'HTTP_X_FORWARDED_FOR' => $a], authCredentials())->status, 'XFF не переносить блок');
    // Невдача записується на REMOTE_ADDR
    assertSame(401, $viaGlobals(['REMOTE_ADDR' => $d, 'HTTP_X_FORWARDED_FOR' => '198.51.100.99'], ['login' => 'x', 'password' => 'wrong-password-123'])->status);
    assertSame(1, authAttemptCount($d));
    assertSame(0, authAttemptCount('198.51.100.99'));
});

test('auth: записи старші за вікно видаляються під час перевірки; свіжі й чужі в межах вікна лишаються', function () {
    authClearAttempts();
    $pdo = testPdo();
    $insert = $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, UTC_TIMESTAMP() - INTERVAL ? SECOND)');
    foreach ([['203.0.113.201', 2000], ['203.0.113.202', 2000], ['203.0.113.203', 2000], ['203.0.113.204', 901]] as [$ip, $age]) {
        $insert->execute([$ip, $age]);
    }
    $insert->execute(['203.0.113.205', 10]);   // у вікні
    $insert->execute(['203.0.113.206', 899]);  // у вікні, майже на межі
    assertSame(6, authAttemptCount());

    assertSame(200, authLogin(authCredentials(), '203.0.113.200')->status);

    assertSame(0, authAttemptCount('203.0.113.201') + authAttemptCount('203.0.113.202') + authAttemptCount('203.0.113.203') + authAttemptCount('203.0.113.204'), 'прострочені видалені');
    assertSame(1, authAttemptCount('203.0.113.205'), 'свіжий запис лишився');
    assertSame(1, authAttemptCount('203.0.113.206'), 'запис на межі вікна лишився');
});

test('auth: LoginRateLimiter напряму — межа LIMIT, ізоляція IP, обрізання довгого ip до 45 символів', function () {
    authClearAttempts();
    $limiter = new LoginRateLimiter(testPdo());

    assertSame(5, LoginRateLimiter::LIMIT);
    assertSame(900, LoginRateLimiter::WINDOW_SECONDS);
    assertSame(null, $limiter->retryAfter('203.0.113.210'));
    for ($i = 1; $i <= 4; $i++) {
        $limiter->recordFailure('203.0.113.210');
        assertSame(null, $limiter->retryAfter('203.0.113.210'), "після {$i} спроб ще можна");
    }
    $limiter->recordFailure('203.0.113.210');
    $retry = $limiter->retryAfter('203.0.113.210');
    assertTrue($retry !== null && $retry >= 899 && $retry <= 900, 'після 5 спроб — блок майже на все вікно: ' . var_export($retry, true));
    assertSame(null, $limiter->retryAfter('203.0.113.211'), 'інший IP вільний');

    $limiter->recordFailure(str_repeat('x', 100)); // не повинно кидати помилку БД (суворий режим)
    assertSame(1, authAttemptCount(str_repeat('x', 45)));
});

// ---------- Час відповіді, логи, заголовки, публічні шляхи ----------

test('auth: час відповіді не залежить від того, чи вгадано логін (password_verify виконується завжди)', function () {
    authClearAttempts();
    $hash = password_hash(TEST_ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]); // ≈ 40 мс на перевірку

    withEnv(['ADMIN_PASSWORD_HASH' => $hash], function () {
        $time = static function (array $body, string $ip): float {
            $start = hrtime(true);
            $response = authLogin($body, $ip);
            $ms = (hrtime(true) - $start) / 1e6;
            assertSame(401, $response->status, $response->body);

            return $ms;
        };
        $median = static function (array $values): float {
            sort($values);

            return $values[intdiv(count($values), 2)];
        };

        authLogin(authCredentials(), '203.0.113.220'); // прогрів (автозавантаження класів, з'єднання з БД)
        $wrongPassword = $wrongLogin = [];
        for ($i = 0; $i < 5; $i++) {
            $wrongPassword[] = $time(['login' => TEST_ADMIN_LOGIN, 'password' => 'wrong-password-123'], '203.0.113.221');
            $wrongLogin[] = $time(['login' => 'nobody-at-all', 'password' => 'wrong-password-123'], '203.0.113.222');
        }

        $p = $median($wrongPassword);
        $l = $median($wrongLogin);
        assertTrue($p > 5.0, sprintf('bcrypt cost 10 має бути помітним (%.1f мс), інакше перевірка беззуба', $p));
        // Із коротким замиканням неправильний логін відповідав би за ≈ 1 мс проти ≈ 40 мс
        assertTrue($l >= 0.5 * $p, sprintf('неправильний логін %.1f мс проти %.1f мс для неправильного пароля', $l, $p));
    });
});

test('auth: заблокований запит не запускає password_verify — ліміт перевіряється до bcrypt', function () {
    authClearAttempts();
    $hash = password_hash(TEST_ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]); // ≈ 40 мс на перевірку

    withEnv(['ADMIN_PASSWORD_HASH' => $hash], function () use ($hash) {
        $median = static function (callable $request, int $expected): float {
            $times = [];
            for ($i = 0; $i < 5; $i++) {
                $start = hrtime(true);
                $response = $request();
                $times[] = (hrtime(true) - $start) / 1e6;
                assertSame($expected, $response->status, $response->body);
            }
            sort($times);

            return $times[2];
        };

        authLogin(authCredentials(), '203.0.113.250'); // прогрів
        authFail('203.0.113.251', 5);

        // Еталон — одна перевірка пароля, виміряна напряму: не залежить від того, скільки разів її викликає контролер
        $bcrypt = $median(function () use ($hash) {
            password_verify(TEST_ADMIN_PASSWORD, $hash);

            return new Response(200, "");
        }, 200);
        $blocked = $median(fn () => authLogin(authCredentials(), '203.0.113.251'), 429);

        assertTrue($bcrypt > 5.0, sprintf('bcrypt cost 10 має бути помітним (%.1f мс), інакше перевірка беззуба', $bcrypt));
        assertTrue($blocked < 0.5 * $bcrypt, sprintf('заблокований запит %.1f мс, одна перевірка пароля %.1f мс', $blocked, $bcrypt));
        assertSame(5, authAttemptCount('203.0.113.251'), 'заблоковані запити нічого не записали');
    });
});

test('auth: пароль, токен, хеш, секрет і заголовок Authorization не потрапляють в error_log', function () {
    authClearAttempts();
    $config = authConfig();
    $secretsSeen = [];

    $log = authCaptureLog(function () use ($config, &$secretsSeen) {
        // Невдалі входи, успішні входи, прострочений токен, підроблений токен, некоректна конфігурація, ліміт
        authFail('203.0.113.230', 5, 'LEAKCHECK-wrong-password');
        authLogin(authCredentials(), '203.0.113.230'); // 429
        $token = authToken('203.0.113.231');
        $secretsSeen[] = $token;
        authAs($token, 'GET', '/api/admin/auth/me');
        $expired = forgeToken($config, authClaims(['exp' => time() - 10]));
        $secretsSeen[] = $expired;
        authAs($expired, 'GET', '/api/admin/auth/me');
        authAs('LEAKCHECK.garbage.token', 'GET', '/api/admin/auth/me');
        apiRequest('GET', '/api/admin/auth/me', [], ['authorization' => 'Basic LEAKCHECK-credentials']);
        withEnv(['TOKEN_SECRET' => 'LEAKCHECK-short-secret'], function () use ($token) {
            authLogin(authCredentials(), '203.0.113.232');
            authAs($token, 'GET', '/api/admin/auth/me');
        });
        withEnv(['ADMIN_PASSWORD_HASH' => 'LEAKCHECK-not-a-hash'], fn () => authLogin(authCredentials(), '203.0.113.233'));
    });

    assertTrue(str_contains($log, 'TOKEN_SECRET'), 'контроль: логування працює (назва змінної є)');
    $forbidden = array_merge($secretsSeen, [
        TEST_ADMIN_PASSWORD, 'LEAKCHECK-wrong-password', $config->passwordHash, TEST_TOKEN_SECRET,
        'LEAKCHECK-short-secret', 'LEAKCHECK-not-a-hash', 'LEAKCHECK.garbage.token', 'LEAKCHECK-credentials',
        'Bearer', 'Authorization',
    ]);
    foreach ($forbidden as $value) {
        assertTrue(!str_contains($log, $value), 'у лозі витік: ' . substr($value, 0, 12) . '…');
    }
});

test('auth: Cache-Control: no-store у відповідях логіну 200/400/401/422/429 і me', function () {
    authClearAttempts();
    $ip = '203.0.113.240';
    $responses = [
        'login 200' => [200, authLogin(authCredentials(), $ip)],
        'login 400' => [400, authLogin('not json', $ip)],
        'login 401' => [401, authLogin(['login' => TEST_ADMIN_LOGIN, 'password' => 'wrong-password-123'], $ip)],
        'login 422' => [422, authLogin('{}', $ip)],
    ];
    authFail($ip, 4); // разом з 401 вище — 5 невдач
    $responses['login 429'] = [429, authLogin(authCredentials(), $ip)];
    $responses['me 200'] = [200, authAs(authToken(), 'GET', '/api/admin/auth/me')];
    $responses['me 401'] = [401, apiRequest('GET', '/api/admin/auth/me')];

    foreach ($responses as $name => [$status, $response]) {
        assertSame($status, $response->status, "{$name}: " . $response->body);
        assertSame('no-store', $response->headers['Cache-Control'] ?? null, $name);
    }
});

test('auth: публічні GET /api/health і /api/message працюють без Authorization і зі сміттєвим Authorization', function () {
    foreach ([[], ['authorization' => 'garbage'], ['authorization' => 'Bearer a.b.c'], ['authorization' => 'Basic xxx']] as $headers) {
        $health = apiRequest('GET', '/api/health', [], $headers);
        assertSame(200, $health->status, json_encode($headers));

        $message = apiRequest('GET', '/api/message', ['lang' => 'lv'], $headers);
        assertSame(200, $message->status, json_encode($headers) . ' → ' . $message->body);
        assertSame(['id', 'lang', 'text'], array_keys(decode($message)));
    }
});
