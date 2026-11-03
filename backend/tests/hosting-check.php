<?php

declare(strict_types=1);

// Перевірка розгортання по HTTP: правила .htaccess, статика, /api/*, заголовок Authorization, службові файли, редиректи (BE-009, BE-014).
// Працює й локально (Apache-контейнер docker-compose.hosting.yml), і проти справжнього хостингу — той самий скрипт.
//
// Локально (з контейнера php, Apache — сервіс apache):
//   docker compose exec php php tests/hosting-check.php http://apache --local
// На хостингу (з контейнера, бо PHP на машині немає):
//   SMOKE_ADMIN_LOGIN=admin SMOKE_ADMIN_PASSWORD='…' docker compose run --rm --no-deps php php tests/hosting-check.php https://<домен>
//
// --local: запити йдуть із X-Forwarded-Proto: https (інакше Apache без TLS перенаправляє все на https), перевірки, що потребують
//          справжнього HTTPS, пропускаються. Логін для перевірки Authorization — SMOKE_ADMIN_LOGIN / SMOKE_ADMIN_PASSWORD
//          (у --local за замовчуванням тестові з deploy/apache-test/test.env). Скрипт НІКОЛИ не шле неправильних облікових даних:
//          вони рахуються в ліміт спроб входу й на хостингу заблокували б IP розробника. Нічого не пише в БД (крім одного
//          успішного входу, який не змінює даних).

$args = array_slice($argv, 1);
$local = in_array('--local', $args, true);
$positional = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
$base = rtrim($positional[0] ?? getenv('BASE_URL') ?: 'http://localhost:8080', '/');
$failed = 0;
$total = 0;

$login = getenv('SMOKE_ADMIN_LOGIN') ?: ($local ? 'test-admin' : '');
$password = getenv('SMOKE_ADMIN_PASSWORD') ?: ($local ? 'hosting-test-pass-123456' : '');

/**
 * Запит без автоматичних редиректів.
 *
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function req(string $method, string $url, array $headers = [], ?string $body = null, bool $forwardHttps = true): array
{
    global $local;
    if ($local && $forwardHttps) {
        $headers += ['X-Forwarded-Proto' => 'https'];
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }
    if ($body !== null) {
        $lines[] = 'Content-Type: application/json';
    }
    $context = stream_context_create([
        'http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0, 'max_redirects' => 0, 'header' => implode("\r\n", $lines), 'content' => $body ?? ''],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $content = (string) @file_get_contents($url, false, $context);

    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
            $status = (int) $m[1]; // з кількох відповідей (редирект) лишається остання
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $out, 'body' => $content];
}

function check(string $name, bool $ok, string $details = ''): void
{
    global $failed, $total;
    $total++;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $details === '' ? '' : "\n        {$details}") . "\n";
}

function skip(string $name, string $reason): void
{
    echo "  skip  {$name} ({$reason})\n";
}

function json(array $r): array
{
    $data = json_decode($r['body'], true);

    return is_array($data) ? $data : [];
}

function isJson(array $r): bool
{
    return str_starts_with($r['headers']['content-type'] ?? '', 'application/json');
}

function brief(array $r): string
{
    return 'status=' . $r['status'] . ' type=' . ($r['headers']['content-type'] ?? '-') . ' body=' . substr(preg_replace('/\s+/', ' ', $r['body']) ?? '', 0, 120);
}

// ---------- Статика ----------

echo "Базова адреса: {$base}" . ($local ? ' (--local)' : '') . "\n\n";

$r = req('GET', "{$base}/");
check('GET / → 200 HTML (статика фронта, не PHP)', $r['status'] === 200 && str_contains($r['headers']['content-type'] ?? '', 'text/html') && str_contains($r['body'], '<html'), brief($r));
$index = $r['body'];

$r = req('GET', "{$base}/admin/");
check('GET /admin/ → 200 HTML (адмінка)', $r['status'] === 200 && str_contains($r['headers']['content-type'] ?? '', 'text/html') && str_contains($r['body'], '<html'), brief($r));

$r = req('GET', "{$base}/admin");
check('GET /admin (без слеша) → 301 на /admin/ або 200', in_array($r['status'], [200, 301], true), brief($r));

if (preg_match('#(?:src|href)="(/assets/[^"]+\.(?:js|css))"#', $index, $m)) {
    $r = req('GET', $base . $m[1]);
    check("Статичний файл {$m[1]} віддається напряму (200, не JSON від API)", $r['status'] === 200 && !isJson($r) && strlen($r['body']) > 100, brief($r));
} else {
    skip('Статичний файл із /assets/', 'у index.html не знайдено посилань на /assets/*.js|css');
}

$r = req('GET', "{$base}/assets/");
check('Лістинг каталогу /assets/ вимкнено (403/404, не список файлів)', in_array($r['status'], [403, 404], true) && !str_contains($r['body'], 'Index of'), brief($r));

// ---------- /api/* ----------

$r = req('GET', "{$base}/api/health");
check('GET /api/health → 200 {"status":"ok"} JSON', $r['status'] === 200 && isJson($r) && $r['body'] === '{"status":"ok"}', brief($r));

$r = req('GET', "{$base}/api/health/");
check('GET /api/health/ (слеш у кінці) → 200', $r['status'] === 200, brief($r));

$r = req('GET', "{$base}/api/message?lang=lv");
check('GET /api/message?lang=lv → 200 {id,lang,text} або 404 not_found, JSON', isJson($r) && (($r['status'] === 200 && array_keys(json($r)) === ['id', 'lang', 'text']) || ($r['status'] === 404 && (json($r)['error']['code'] ?? '') === 'not_found')), brief($r));

$r = req('GET', "{$base}/api/nope");
check('GET /api/nope → 404 JSON not_found (а не HTML-сторінка Apache)', $r['status'] === 404 && isJson($r) && (json($r)['error']['code'] ?? '') === 'not_found', brief($r));

// Без слеша Apache (mod_dir, бо api/ — каталог) переадресує на /api/; кінцева відповідь — JSON 404 від роутера
$r = req('GET', "{$base}/api");
check('GET /api → 301 на /api/ (mod_dir) або 404 JSON', ($r['status'] === 301 && str_ends_with($r['headers']['location'] ?? '', '/api/')) || ($r['status'] === 404 && isJson($r)), brief($r));

$r = req('GET', "{$base}/api/");
check('GET /api/ → 404 JSON not_found', $r['status'] === 404 && isJson($r) && (json($r)['error']['code'] ?? '') === 'not_found', brief($r));

$r = req('POST', "{$base}/api/health", [], '{}');
check('POST /api/health → 404 JSON not_found', $r['status'] === 404 && isJson($r), brief($r));

$r = req('GET', "{$base}/api/message?lang[]=lv");
check('GET /api/message?lang[]=lv → 400 bad_request JSON (помилка клієнта, не 500)', $r['status'] === 400 && isJson($r), brief($r));

// Довгий exclude: 100 і 200 ID по 10 цифр (≈ 1300 і ≈ 2600 символів у query; кома кодується як %2C)
foreach ([100, 200] as $n) {
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $ids[] = (string) (1000000000 + $i);
    }
    $query = 'lang=lv&exclude=' . implode('%2C', $ids);
    $r = req('GET', "{$base}/api/message?{$query}");
    check("Довгий exclude ({$n} ID, " . strlen($query) . ' символів у query) → 200 або 404 not_found у JSON, не 414/400/HTML', isJson($r) && (($r['status'] === 200) || ($r['status'] === 404 && (json($r)['error']['code'] ?? '') === 'not_found')), brief($r));
}

// ---------- Authorization (login → me) ----------

$r = req('GET', "{$base}/api/admin/auth/me");
check('GET /api/admin/auth/me без токена → 401 unauthorized JSON', $r['status'] === 401 && isJson($r) && (json($r)['error']['code'] ?? '') === 'unauthorized', brief($r));

if ($login === '' || $password === '') {
    skip('login → me з Bearer-токеном (заголовок Authorization доходить до PHP)', 'задайте SMOKE_ADMIN_LOGIN і SMOKE_ADMIN_PASSWORD');
} else {
    $r = req('POST', "{$base}/api/admin/auth/login", [], json_encode(['login' => $login, 'password' => $password]));
    $data = json($r);
    $loginOk = $r['status'] === 200 && isset($data['token'], $data['expiresAt']);
    check('POST /api/admin/auth/login з правильними даними → 200 {token, expiresAt}', $loginOk, 'status=' . $r['status'] . ' (пароль не виводиться)');

    if ($loginOk) {
        $ttl = strtotime((string) $data['expiresAt']) - time();
        check('Термін токена ≈ 7 діб (TOKEN_TTL_HOURS=168): ' . round($ttl / 3600) . ' год', $ttl > 167 * 3600 && $ttl <= 168 * 3600 + 60, "expiresAt={$data['expiresAt']}");

        $r = req('GET', "{$base}/api/admin/auth/me", ['Authorization' => 'Bearer ' . $data['token']]);
        check('GET /api/admin/auth/me з Bearer-токеном → 200 {login} (заголовок Authorization дійшов до PHP)', $r['status'] === 200 && (json($r)['login'] ?? null) === $login, brief($r));

        $r = req('GET', "{$base}/api/admin/stats", ['Authorization' => 'Bearer ' . $data['token']]);
        check('GET /api/admin/stats з токеном → 200 (таблиці БД на місці)', $r['status'] === 200 && isset(json($r)['total']), brief($r));
    }
}

// ---------- Службові файли недоступні ----------

foreach (['/.env', '/.htaccess', '/.git/config', '/.git/HEAD', '/api/.user.ini', '/database/migrations/001_create_phrases.sql', '/database/schema.sql', '/schema.sql', '/src/bootstrap.php', '/bin/migrate.php', '/orakuls/.env', '/orakuls/src/bootstrap.php', '/.env.example', '/README.md'] as $path) {
    $r = req('GET', $base . $path);
    $leak = preg_match('/DB_PASS|TOKEN_SECRET|CREATE TABLE|<\?php|RewriteEngine/', $r['body']) === 1;
    check("{$path} недоступний (403/404, без вмісту)", in_array($r['status'], [403, 404], true) && !$leak, brief($r));
}

// ---------- HTTPS і редиректи (query string, зокрема ?qr, зберігається) ----------

$parts = parse_url($base);
$host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
$httpBase = 'http://' . $host;

$expectRedirect = static function (string $name, string $url, array $headers, string $expectedLocation) : void {
    $r = req('GET', $url, $headers, null, false);
    check($name, in_array($r['status'], [301, 308], true) && ($r['headers']['location'] ?? '') === $expectedLocation, 'status=' . $r['status'] . ' Location=' . ($r['headers']['location'] ?? '(немає)') . ", очікувалось {$expectedLocation}");
};

$expectRedirect('http://' . $host . '/?qr → 301 на https://' . $host . '/?qr (?qr збережено)', $httpBase . '/?qr', [], "https://{$host}/?qr");
$expectRedirect('http://' . $host . '/?qr=1&x=2 → query зберігається повністю', $httpBase . '/?qr=1&x=2', [], "https://{$host}/?qr=1&x=2");
$bareHost = preg_replace('/^www\./i', '', $host);
$expectRedirect("http://www.{$bareHost}/?qr → 301 на https://{$bareHost}/?qr (без www, ?qr збережено)", $httpBase . '/?qr', ['Host' => "www.{$bareHost}"], "https://{$bareHost}/?qr");

if ($local) {
    skip('https://www…/?qr → https://… (потрібен справжній сертифікат)', '--local');
    skip('Відповідь по https без редиректів (остаточна адреса QR)', '--local: емулюється заголовком X-Forwarded-Proto');
    $r = req('GET', "{$base}/?qr");
    check('Остаточна адреса /?qr з X-Forwarded-Proto: https → 200 без редиректу', $r['status'] === 200, brief($r));
} else {
    $r = req('GET', "{$base}/?qr", [], null, false);
    check('Остаточна адреса ' . $base . '/?qr → 200 без редиректів (її друкуємо в QR)', $r['status'] === 200, brief($r));
    $expectRedirect("https://www.{$bareHost}/?qr → 301 на https://{$bareHost}/?qr", "https://www.{$bareHost}/?qr", [], "https://{$bareHost}/?qr");
}

echo sprintf("\n%d перевірок, %d провалено\n", $total, $failed);
exit($failed === 0 ? 0 : 1);
