<?php

declare(strict_types=1);

// Smoke-перевірка запущеного API по HTTP (потрібен працюючий сервер і БД).
// Запуск: docker compose exec php php tests/smoke.php [base-url]
// Для хостингу: php tests/smoke.php https://example.com

$base = rtrim($argv[1] ?? getenv('BASE_URL') ?: 'http://localhost:8000', '/');
$failed = 0;
// За замовчуванням smoke НІЧОГО не пише в статистику (на проді перед друком QR зайві одиниці стерти нічим): перевірки, що пишуть
// (POST /api/visit → 204, GET /api/message → 200), вмикаються явно — SMOKE_WRITE=1.
$writes = getenv('SMOKE_WRITE') === '1';

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

if ($writes) {
    // Успішна видача збільшує message_lv (BE-012), тому за замовчуванням ці дві перевірки пропускаються
    $r = call('GET', "{$base}/api/message?lang=lv");
    check('GET /api/message?lang=lv → 200 {id,lang,text} + no-store або 404 not_found (SMOKE_WRITE=1: +1 message_lv)', messageIsOkOrEmpty($r), "status={$r['status']} body={$r['body']}");

    $r = call('GET', "{$base}/api/message?lang=lv&exclude=abc,-1,1.5");
    check('GET /api/message з сміттям у exclude → 200/404, але не 400 (SMOKE_WRITE=1: +1 message_lv)', messageIsOkOrEmpty($r), "status={$r['status']} body={$r['body']}");
} else {
    echo "  skip  GET /api/message?lang=lv → 200 і з сміттям у exclude (рахують видачу; вмикаються SMOKE_WRITE=1)
";
}

$r = call('POST', "{$base}/api/message?lang=lv");
check('POST /api/message → 404 not_found', $r['status'] === 404 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_found', "status={$r['status']}");

// ---------- POST /api/visit (BE-011) ----------
// Перевірка, що пише (204), вмикається SMOKE_WRITE=1 (див. початок файлу). Решта (400, 404) нічого не рахує.


$r = call('POST', "{$base}/api/visit", '');
check(
    'POST /api/visit з порожнім тілом → 400 bad_request, no-store',
    $r['status'] === 400 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'bad_request' && ($r['headers']['cache-control'] ?? '') === 'no-store',
    "status={$r['status']} body={$r['body']}"
);

$r = call('POST', "{$base}/api/visit", 'not json');
check('POST /api/visit з not json → 400 bad_request', $r['status'] === 400 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'bad_request', "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/visit");
check('GET /api/visit → 404 not_found', $r['status'] === 404 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_found', "status={$r['status']}");

if ($writes) {
    // Пише +1 visit за сьогодні (без персональних даних). Потребує застосованої міграції 003 (інакше 500 — правильний сигнал).
    $r = call('POST', "{$base}/api/visit", '{"source":"direct"}');
    check(
        'POST /api/visit {"source":"direct"} → 204, порожнє тіло, no-store (SMOKE_WRITE=1: +1 visit у статистиці)',
        $r['status'] === 204 && $r['body'] === '' && ($r['headers']['cache-control'] ?? '') === 'no-store',
        "status={$r['status']} body={$r['body']}"
    );
} else {
    echo "  skip  POST /api/visit → 204 (пише в статистику; вмикається SMOKE_WRITE=1)\n";
}

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

$r = call('GET', "{$base}/api/admin/phrases/1");
check('GET /api/admin/phrases/1 без токена → 401', $unauthorized($r), "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/admin/phrases?perPage=0");
check('GET /api/admin/phrases?perPage=0 без токена → 401 (guard раніше за валідацію)', $unauthorized($r), "status={$r['status']} body={$r['body']}");

// Запис фраз (BE-006): без токена всі три відхиляються guard'ом ще до тіла й БД — на хостингу нічого не пишеться
$r = call('POST', "{$base}/api/admin/phrases", '{}');
check('POST /api/admin/phrases без токена → 401', $unauthorized($r), "status={$r['status']} body={$r['body']}");

$r = call('PUT', "{$base}/api/admin/phrases/1", '{}');
check('PUT /api/admin/phrases/1 без токена → 401', $unauthorized($r), "status={$r['status']} body={$r['body']}");

$r = call('DELETE', "{$base}/api/admin/phrases/1");
check('DELETE /api/admin/phrases/1 без токена → 401', $unauthorized($r), "status={$r['status']} body={$r['body']}");

// Імпорт фраз (BE-007): без токена відхиляється guard'ом ще до розбору тіла — на хостингу нічого не пишеться
$r = call('POST', "{$base}/api/admin/phrases/import", '');
check('POST /api/admin/phrases/import без токена, з порожнім тілом → 401', $unauthorized($r), "status={$r['status']} body={$r['body']}");

// Зведення (BE-008): без токена відхиляється guard'ом ще до звернення до БД
$r = call('GET', "{$base}/api/admin/stats");
check('GET /api/admin/stats без токена → 401, no-store', $unauthorized($r) && ($r['headers']['cache-control'] ?? '') === 'no-store', "status={$r['status']} body={$r['body']}");

// Звіт зі статистики (BE-013): без токена; нічого не читає з БД й нічого не пише
$r = call('GET', "{$base}/api/admin/visits?from=2026-11-01&to=2026-11-03");
check('GET /api/admin/visits без токена → 401, no-store', $unauthorized($r) && ($r['headers']['cache-control'] ?? '') === 'no-store', "status={$r['status']} body={$r['body']}");

$r = call('GET', "{$base}/api/admin/visits?from=bad");
check('GET /api/admin/visits?from=bad без токена → 401 (guard раніше за валідацію)', $unauthorized($r), "status={$r['status']} body={$r['body']}");

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
