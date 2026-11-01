<?php

declare(strict_types=1);

// Smoke-перевірка запущеного API по HTTP (потрібен працюючий сервер і БД).
// Запуск: docker compose exec php php tests/smoke.php [base-url]
// Для хостингу: php tests/smoke.php https://example.com

$base = rtrim($argv[1] ?? getenv('BASE_URL') ?: 'http://localhost:8000', '/');
$failed = 0;

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function call(string $method, string $url, ?string $body = null, ?string $contentType = null): array
{
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15];
    if ($body !== null) {
        $options['content'] = $body;
        $options['header'] = 'Content-Type: ' . ($contentType ?? 'application/json');
    }
    $context = stream_context_create(['http' => $options]);
    $body = (string) @file_get_contents($url, false, $context);

    $status = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function check(string $name, bool $ok, string $details = ''): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $details === '' ? '' : "\n        {$details}") . "\n";
}

$r = call('GET', "{$base}/api/health");
check('GET /api/health → 200', $r['status'] === 200, "status={$r['status']} body={$r['body']}");
check('health: Content-Type json utf-8', ($r['headers']['content-type'] ?? '') === 'application/json; charset=utf-8', $r['headers']['content-type'] ?? '(немає)');
check('health: Cache-Control no-store', ($r['headers']['cache-control'] ?? '') === 'no-store');
check('health: тіло {"status":"ok"}', $r['body'] === '{"status":"ok"}', $r['body']);

$r = call('GET', "{$base}/api/nope");
$json = json_decode($r['body'], true);
check('GET /api/nope → 404 JSON not_found', $r['status'] === 404 && ($json['error']['code'] ?? '') === 'not_found', "status={$r['status']} body={$r['body']}");
check('404: Content-Type json, а не HTML', str_starts_with($r['headers']['content-type'] ?? '', 'application/json'));

$r = call('POST', "{$base}/api/health");
check('POST /api/health → 404 not_found', $r['status'] === 404 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_found', "status={$r['status']}");

$r = call('GET', "{$base}/api/health/");
check('GET /api/health/ (слеш у кінці) → 200', $r['status'] === 200, "status={$r['status']}");

// ---------- GET /api/message (лише читання; робоча БД може бути порожньою → 404) ----------

/** 200 з рівно {id, lang, text} і no-store, або 404 not_found — усе інше (зокрема 500, 400) провал. */
function messageIsOkOrEmpty(array $r): bool
{
    $json = json_decode($r['body'], true);
    if ($r['status'] === 404) {
        return ($json['error']['code'] ?? '') === 'not_found';
    }

    return $r['status'] === 200
        && is_array($json)
        && array_keys($json) === ['id', 'lang', 'text']
        && is_int($json['id'])
        && ($r['headers']['cache-control'] ?? '') === 'no-store';
}

$r = call('GET', "{$base}/api/message");
check(
    'GET /api/message без lang → 400 bad_request, JSON',
    $r['status'] === 400
        && (json_decode($r['body'], true)['error']['code'] ?? '') === 'bad_request'
        && str_starts_with($r['headers']['content-type'] ?? '', 'application/json'),
    "status={$r['status']} body={$r['body']}"
);

$r = call('GET', "{$base}/api/message?lang=de");
check('GET /api/message?lang=de → 400', $r['status'] === 400, "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/message?lang=lv");
check('GET /api/message?lang=lv → 200 {id,lang,text} + no-store або 404 not_found', messageIsOkOrEmpty($r), "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/message?lang=lv&exclude=abc,-1,1.5");
check('GET /api/message з сміттям у exclude → 200/404, але не 400', messageIsOkOrEmpty($r), "status={$r['status']} body={$r['body']}");

$r = call('POST', "{$base}/api/message?lang=lv");
check('POST /api/message → 404 not_found', $r['status'] === 404 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_found', "status={$r['status']}");

// ---------- Адмінка без облікових даних (BE-004) ----------
// Неправильні логін/пароль СЮДИ НЕ ВІДПРАВЛЯТИ: вони рахуються в ліміт спроб, і на хостингу smoke заблокував би IP розробника.
// Усе нижче відхиляється до перевірки пароля й у ліміт не потрапляє.

$unauthorized = static fn (array $r): bool => $r['status'] === 401
    && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized'
    && str_starts_with($r['headers']['content-type'] ?? '', 'application/json');

$r = call('GET', "{$base}/api/admin/auth/me");
check('GET /api/admin/auth/me без токена → 401 unauthorized, JSON', $unauthorized($r), "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/admin/phrases");
check('GET /api/admin/phrases без токена → 401', $unauthorized($r), "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/admin/nope");
check('GET невідомого /api/admin/… без токена → 401, а не 404', $unauthorized($r), "status={$r['status']} body={$r['body']}");

$r = call('POST', "{$base}/api/admin/auth/login", '{}');
check(
    'POST /api/admin/auth/login з {} → 422 validation_error (не 500), no-store',
    $r['status'] === 422
        && (json_decode($r['body'], true)['error']['code'] ?? '') === 'validation_error'
        && ($r['headers']['cache-control'] ?? '') === 'no-store',
    "status={$r['status']} body={$r['body']}"
);

$r = call('POST', "{$base}/api/admin/auth/login", 'not json');
check('POST /api/admin/auth/login з not json → 400 bad_request', $r['status'] === 400 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'bad_request', "status={$r['status']} body={$r['body']}");

echo $failed === 0 ? "\nвсе гаразд\n" : "\nпровалено: {$failed}\n";
exit($failed === 0 ? 0 : 1);
