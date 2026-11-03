<?php

declare(strict_types=1);

// Smoke-перевірка запущеного API по HTTP (потрібен працюючий сервер і БД).
// Запуск: docker compose exec php php tests/smoke.php [base-url]
// Для хостингу: php tests/smoke.php https://example.com
//
// Змінні оточення (усі необов'язкові):
//   SMOKE_ADMIN_LOGIN, SMOKE_ADMIN_PASSWORD — ПРАВИЛЬНІ облікові дані адміна: вмикають перевірки авторизованих ендпоінтів
//       (типи полів за API.md). Неправильні дані скрипт НІКОЛИ не шле: вони рахуються в ліміт спроб входу.
//   SMOKE_WRITE=1 — перевірки, що пишуть у БД: POST /api/visit і GET /api/message (лічильники) та повний цикл CRUD однієї
//       тимчасової фрази (створити → прочитати → змінити → видалити; наприкінці вона видаляється). На проді перед друком QR не вмикати.

$base = rtrim($argv[1] ?? getenv('BASE_URL') ?: 'http://localhost:8000', '/');
$failed = 0;
// За замовчуванням smoke НІЧОГО не пише в статистику (на проді перед друком QR зайві одиниці стерти нічим): перевірки, що пишуть
// (POST /api/visit → 204, GET /api/message → 200), вмикаються явно — SMOKE_WRITE=1.
$writes = getenv('SMOKE_WRITE') === '1';

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function call(string $method, string $url, ?string $body = null, ?string $contentType = null, array $extraHeaders = []): array
{
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15];
    $headerLines = [];
    if ($body !== null) {
        $options['content'] = $body;
        $headerLines[] = 'Content-Type: ' . ($contentType ?? 'application/json');
    }
    foreach ($extraHeaders as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }
    if ($headerLines !== []) {
        $options['header'] = implode("\r\n", $headerLines);
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

// ---------- Адмінка з обліковими даними: типи полів за API.md (BE-010) ----------
// Вмикається лише з правильними SMOKE_ADMIN_LOGIN / SMOKE_ADMIN_PASSWORD. Без SMOKE_WRITE=1 нічого не пише в БД.

$adminLogin = getenv('SMOKE_ADMIN_LOGIN') ?: '';
$adminPassword = getenv('SMOKE_ADMIN_PASSWORD') ?: '';

$iso = static fn (mixed $v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $v) === 1;
$json = static fn (array $r): array => is_array($d = json_decode($r['body'], true)) ? $d : [];
$isObject = static fn (mixed $v): bool => is_array($v) && ($v === [] || !array_is_list($v));
$allInts = static fn (array $a): bool => $a !== [] && count(array_filter($a, 'is_int')) === count($a);
$isPhrase = static function (mixed $p) use ($iso): bool {
    return is_array($p)
        && array_keys($p) === ['id', 'category', 'text', 'isActive', 'createdAt', 'updatedAt']
        && is_int($p['id']) && is_string($p['category']) && is_bool($p['isActive']) && $iso($p['createdAt']) && $iso($p['updatedAt'])
        && is_array($p['text']) && array_keys($p['text']) === ['lv', 'en', 'ru'] && count(array_filter($p['text'], 'is_string')) === 3;
};

if ($adminLogin === '' || $adminPassword === '') {
    echo "  skip  перевірки авторизованих ендпоінтів (задайте SMOKE_ADMIN_LOGIN і SMOKE_ADMIN_PASSWORD)\n";
} else {
    $r = call('POST', "{$base}/api/admin/auth/login", json_encode(['login' => $adminLogin, 'password' => $adminPassword]));
    $login = $json($r);
    $loggedIn = $r['status'] === 200 && is_string($login['token'] ?? null) && $iso($login['expiresAt'] ?? null) && array_keys($login) === ['token', 'expiresAt'];
    check('POST /api/admin/auth/login → 200 {token: string, expiresAt: ISO 8601 UTC}', $loggedIn, "status={$r['status']} (пароль не виводиться)");

    if (!$loggedIn) {
        echo "  skip  решта авторизованих перевірок (вхід не вдався)\n";
    } else {
        $auth = ['Authorization' => 'Bearer ' . $login['token']];
        $get = static fn (string $path): array => call('GET', $base . $path, null, null, $auth);

        $r = $get('/api/admin/auth/me');
        check('GET /api/admin/auth/me → 200 {login: string}', $r['status'] === 200 && array_keys($json($r)) === ['login'] && $json($r)['login'] === $adminLogin, "status={$r['status']} body={$r['body']}");

        $r = call('GET', "{$base}/api/admin/auth/me", null, null, ['Authorization' => 'Bearer garbage.token.value']);
        check('Сміттєвий токен → 401 unauthorized', $unauthorized($r), "status={$r['status']}");

        // --- Список фраз ---
        $r = $get('/api/admin/phrases?perPage=2');
        $list = $json($r);
        check(
            'GET /api/admin/phrases → 200 {items: Phrase[], total: int, page: int, perPage: int}, ключі за порядком',
            $r['status'] === 200 && array_keys($list) === ['items', 'total', 'page', 'perPage'] && is_array($list['items']) && array_is_list($list['items'])
                && is_int($list['total']) && $list['page'] === 1 && $list['perPage'] === 2 && count($list['items']) <= 2,
            "status={$r['status']} body=" . substr($r['body'], 0, 200)
        );
        $sample = $list['items'][0] ?? null;
        if ($sample === null) {
            echo "  skip  типи полів Phrase (у БД немає жодної фрази)\n";
        } else {
            check('Phrase: id/category/text{lv,en,ru}/isActive/createdAt/updatedAt — типи за API.md (id число, isActive boolean, дати ISO UTC)', $isPhrase($sample), json_encode($sample, JSON_UNESCAPED_UNICODE));
            $r = $get('/api/admin/phrases/' . $sample['id']);
            check('GET /api/admin/phrases/{id} → 200 Phrase того ж id', $r['status'] === 200 && $isPhrase($json($r)) && $json($r)['id'] === $sample['id'], "status={$r['status']}");
        }

        foreach (['perPage=0' => 'perPage=0', 'perPage=101' => 'perPage=101', 'page=0' => 'page=0', 'category=love%20' => 'category з пробілом', 'isActive=yes' => 'isActive=yes', 'missing=de' => 'missing=de'] as $query => $label) {
            $r = $get('/api/admin/phrases?' . $query);
            check("GET /api/admin/phrases?{$label} → 400 bad_request", $r['status'] === 400 && ($json($r)['error']['code'] ?? '') === 'bad_request', "status={$r['status']} body={$r['body']}");
        }
        $r = $get('/api/admin/phrases/999999999');
        check('GET /api/admin/phrases/{неіснуючий} → 404 not_found', $r['status'] === 404 && ($json($r)['error']['code'] ?? '') === 'not_found', "status={$r['status']}");
        $r = $get('/api/admin/phrases/abc');
        check('GET /api/admin/phrases/abc → 404 not_found (не 500)', $r['status'] === 404, "status={$r['status']}");

        // --- Помилки запису без жодного запису в БД ---
        $r = call('POST', "{$base}/api/admin/phrases", '{}', null, $auth);
        $err = $json($r)['error'] ?? [];
        check('POST /api/admin/phrases з {} → 422 validation_error, fields — об\'єкт', $r['status'] === 422 && ($err['code'] ?? '') === 'validation_error' && $isObject($err['fields'] ?? null) && ($err['fields'] ?? []) !== [], "status={$r['status']} body={$r['body']}");

        $r = call('PUT', "{$base}/api/admin/phrases/999999999", json_encode(['category' => 'love', 'text' => ['lv' => 'x', 'en' => '', 'ru' => '']]), null, $auth);
        check('PUT /api/admin/phrases/{неіснуючий} → 404 not_found', $r['status'] === 404, "status={$r['status']} body={$r['body']}");

        $r = call('DELETE', "{$base}/api/admin/phrases/999999999", null, null, $auth);
        check('DELETE /api/admin/phrases/{неіснуючий} → 404 not_found', $r['status'] === 404, "status={$r['status']} body={$r['body']}");

        $r = call('POST', "{$base}/api/admin/phrases/import", json_encode(['items' => []]), null, $auth);
        $imp = $json($r);
        check('POST /api/admin/phrases/import з порожнім items → 200 {created: 0, errors: []}', $r['status'] === 200 && array_keys($imp) === ['created', 'errors'] && $imp['created'] === 0 && $imp['errors'] === [], "status={$r['status']} body={$r['body']}");

        $r = call('POST', "{$base}/api/admin/phrases/import", '{}', null, $auth);
        check('POST /api/admin/phrases/import без items → 422 validation_error {items: required}', $r['status'] === 422 && ($json($r)['error']['fields']['items'] ?? '') === 'required', "status={$r['status']} body={$r['body']}");

        // --- Зведення ---
        $r = $get('/api/admin/stats');
        $stats = $json($r);
        $categories = ['love', 'money', 'luck', 'work', 'travel', 'mood'];
        check(
            'GET /api/admin/stats → 200 {total, active: int, byCategory: {6 категорій: int}, missing: {lv, en, ru: int}} — числа, а не рядки',
            $r['status'] === 200 && array_keys($stats) === ['total', 'active', 'byCategory', 'missing'] && is_int($stats['total']) && is_int($stats['active'])
                && array_keys($stats['byCategory']) === $categories && $allInts($stats['byCategory'])
                && array_keys($stats['missing']) === ['lv', 'en', 'ru'] && $allInts($stats['missing']),
            "status={$r['status']} body={$r['body']}"
        );
        $r = $get('/api/admin/phrases?perPage=1');
        check('stats.total збігається з total списку', ($json($r)['total'] ?? null) === ($stats['total'] ?? -1), "список={$json($r)['total']} stats=" . ($stats['total'] ?? '?'));

        // --- Звіт зі статистики відвідувань (BE-013) ---
        $today = gmdate('Y-m-d');
        $r = $get("/api/admin/visits?from={$today}&to={$today}");
        $rep = $json($r);
        $dayOk = static fn (mixed $d): bool => is_array($d) && array_keys($d) === ['date', 'visits', 'scans', 'messages', 'byLang'] && is_string($d['date'])
            && is_int($d['visits']) && is_int($d['scans']) && is_int($d['messages']) && array_keys($d['byLang']) === ['lv', 'en', 'ru'] && $allInts($d['byLang']);
        check(
            'GET /api/admin/visits → 200 {from, to, timezone: Europe/Riga, total, days[]}; числа int, byLang — об\'єкт lv/en/ru',
            $r['status'] === 200 && array_keys($rep) === ['from', 'to', 'timezone', 'total', 'days'] && $rep['timezone'] === 'Europe/Riga'
                && array_keys($rep['total']) === ['visits', 'scans', 'messages', 'byLang'] && is_int($rep['total']['visits']) && $allInts($rep['total']['byLang'])
                && count($rep['days']) >= 1 && $dayOk($rep['days'][0]),
            "status={$r['status']} body=" . substr($r['body'], 0, 250)
        );
        $r = $get('/api/admin/visits?from=2026-02-30&to=2026-03-01');
        check('GET /api/admin/visits з неіснуючою датою → 400 bad_request', $r['status'] === 400 && ($json($r)['error']['code'] ?? '') === 'bad_request', "status={$r['status']}");
        $r = $get('/api/admin/visits?from=2025-01-01&to=2026-01-02');
        check('GET /api/admin/visits довше за 366 днів → 400 bad_request', $r['status'] === 400, "status={$r['status']}");

        // --- Повний цикл CRUD однієї тимчасової фрази (лише SMOKE_WRITE=1) ---
        if (!$writes) {
            echo "  skip  CRUD тимчасової фрази: POST → GET → PUT → DELETE (пише в БД; вмикається SMOKE_WRITE=1)\n";
        } else {
            $createdId = null;
            try {
                $body = ['category' => 'mood', 'text' => ['lv' => 'smoke-lv', 'en' => 'smoke-en', 'ru' => 'smoke-ru'], 'isActive' => false];
                $r = call('POST', "{$base}/api/admin/phrases", json_encode($body), null, $auth);
                $created = $json($r);
                $createdId = is_int($created['id'] ?? null) ? $created['id'] : null;
                check('POST /api/admin/phrases → 201 Phrase (isActive=false збережено, id — число)', $r['status'] === 201 && $isPhrase($created) && $created['isActive'] === false && $created['text']['en'] === 'smoke-en', "status={$r['status']} body={$r['body']}");

                if ($createdId !== null) {
                    $r = $get("/api/admin/phrases/{$createdId}");
                    check('GET створеної фрази → 200', $r['status'] === 200 && $json($r)['id'] === $createdId, "status={$r['status']}");

                    $r = call('PUT', "{$base}/api/admin/phrases/{$createdId}", json_encode(['category' => 'luck', 'text' => ['lv' => 'smoke-lv2', 'en' => '', 'ru' => 'smoke-ru'], 'isActive' => true]), null, $auth);
                    $updated = $json($r);
                    check('PUT /api/admin/phrases/{id} → 200 Phrase зі зміненими полями', $r['status'] === 200 && $isPhrase($updated) && $updated['category'] === 'luck' && $updated['isActive'] === true && $updated['text']['en'] === '' && $updated['text']['lv'] === 'smoke-lv2', "status={$r['status']} body={$r['body']}");

                    $r = call('DELETE', "{$base}/api/admin/phrases/{$createdId}", null, null, $auth);
                    check('DELETE /api/admin/phrases/{id} → 204 без тіла', $r['status'] === 204 && $r['body'] === '', "status={$r['status']} body={$r['body']}");
                    $createdId = null;

                    $r = $get("/api/admin/phrases/" . $created['id']);
                    check('GET видаленої фрази → 404', $r['status'] === 404, "status={$r['status']}");
                }
            } finally {
                if ($createdId !== null) { // тест перервався посередині: не лишаємо тимчасову фразу в БД
                    call('DELETE', "{$base}/api/admin/phrases/{$createdId}", null, null, $auth);
                }
            }
        }
    }
}

echo $failed === 0 ? "\nвсе гаразд\n" : "\nпровалено: {$failed}\n";
exit($failed === 0 ? 0 : 1);
