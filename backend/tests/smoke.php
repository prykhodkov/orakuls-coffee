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
function call(string $method, string $url): array
{
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 15]]);
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

echo $failed === 0 ? "\nвсе гаразд\n" : "\nпровалено: {$failed}\n";
exit($failed === 0 ? 0 : 1);
