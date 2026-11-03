<?php

declare(strict_types=1);

// ТИМЧАСОВИЙ діагностичний скрипт для першого запуску на хостингу (BE-009). НЕ входить у public_html за замовчуванням.
// Порядок: 1) вписати нижче випадковий DIAG_KEY; 2) залити як public_html/diag.php; 3) відкрити
//   https://<домен>/diag.php?key=<DIAG_KEY> — з компʼютера і з телефона через мобільну мережу (різні IP!);
// 4) ЗАПИСАТИ результат у docs/deployment.md; 5) ОДРАЗУ ВИДАЛИТИ файл із сервера.
// Скрипт друкує версії, ліміти й заголовки; секрети (паролі, TOKEN_SECRET) не друкує. Без ключа — 403.

const DIAG_KEY = ''; // ← вписати випадковий рядок перед завантаженням; порожній ключ = скрипт відмовляється працювати

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (DIAG_KEY === '' || !hash_equals(DIAG_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden: set DIAG_KEY in the file and pass ?key=…\n";
    exit;
}

function line(string $name, mixed $value): void
{
    echo str_pad($name . ' ', 42) . (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) . "\n";
}

echo "=== Сервер і PHP ===\n";
line('PHP_VERSION', PHP_VERSION);
line('PHP_SAPI', PHP_SAPI);
line('SERVER_SOFTWARE', $_SERVER['SERVER_SOFTWARE'] ?? '(немає)');
line('HTTPS', $_SERVER['HTTPS'] ?? '(не задано)');
line('SERVER_PROTOCOL', $_SERVER['SERVER_PROTOCOL'] ?? '');
foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'sodium'] as $ext) {
    line("extension {$ext}", extension_loaded($ext));
}
line('timezone_version_get()', timezone_version_get());
try {
    $riga = new DateTimeImmutable('2026-10-25 00:30:00 UTC');
    line('Europe/Riga: 2026-10-25 00:30Z', $riga->setTimezone(new DateTimeZone('Europe/Riga'))->format('Y-m-d H:i T') . '  (очікується 2026-10-25 03:30 EEST)');
} catch (Throwable $e) {
    line('Europe/Riga', 'ПОМИЛКА: ' . $e->getMessage());
}

echo "\n=== Ліміти PHP (які реально діють для цього файлу) ===\n";
foreach (['post_max_size', 'upload_max_filesize', 'memory_limit', 'max_execution_time', 'max_input_vars', 'display_errors', 'log_errors', 'error_log', 'user_ini.filename', 'user_ini.cache_ttl', 'date.timezone'] as $key) {
    line("ini {$key}", var_export(ini_get($key), true));
}
$errorLog = ini_get('error_log');
line('error_log: файл існує і доступний на запис', $errorLog ? (is_file($errorLog) && is_writable($errorLog)) : '(не задано: PHP пише в лог сервера)');
if ($errorLog && str_starts_with($errorLog, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '/nonexistent'))) {
    echo "!!! error_log лежить усередині DOCUMENT_ROOT — перевірити, що він недоступний по HTTP (403/404), або перенаправити поза веб-корінь\n";
}

echo "\n=== Клієнт: REMOTE_ADDR і заголовки проксі (порівняти зі справжньою публічною адресою клієнта) ===\n";
foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST', 'HTTP_VIA'] as $key) {
    line($key, $_SERVER[$key] ?? '(немає)');
}
echo "Якщо REMOTE_ADDR НЕ збігається з вашою справжньою адресою (або однаковий з двох різних мереж) — вхід адміна ділить ліміт спроб\n"
    . "з усіма відвідувачами: ЗУПИНИТИСЬ і звернутись до користувача (довіряти X-Forwarded-For без перевірки не можна).\n";

echo "\n=== Authorization доходить до PHP? Виклик: curl -H 'Authorization: Bearer test' '…/diag.php?key=…' ===\n";
line('HTTP_AUTHORIZATION', isset($_SERVER['HTTP_AUTHORIZATION']) ? 'присутній (довжина ' . strlen((string) $_SERVER['HTTP_AUTHORIZATION']) . ')' : '(немає)');
line('REDIRECT_HTTP_AUTHORIZATION', isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? 'присутній' : '(немає)');
echo "(Тут .htaccess з правилом E=HTTP_AUTHORIZATION діє, якщо файл лежить у public_html; остаточна перевірка — login → me в tests/hosting-check.php.)\n";

echo "\n=== MySQL (параметри з .env застосунку; значення не друкуються) ===\n";
$root = getenv('ORAKULS_ROOT') ?: dirname(__DIR__) . '/orakuls';
$envFile = $root . '/.env';
line('.env знайдено', is_file($envFile));
if (is_file($envFile)) {
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
        $row = trim($row);
        if ($row === '' || $row[0] === '#' || !str_contains($row, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $row, 2);
        $env[trim($k)] = trim(trim($v), "'\"");
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'ADMIN_LOGIN', 'ADMIN_PASSWORD_HASH', 'TOKEN_SECRET'] as $key) {
        line("env {$key} задано", isset($env[$key]) && $env[$key] !== '');
    }
    line('TOKEN_SECRET ≥ 32 символів', strlen($env['TOKEN_SECRET'] ?? '') >= 32);
    line('ADMIN_PASSWORD_HASH схожий на bcrypt', preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $env['ADMIN_PASSWORD_HASH'] ?? '') === 1);
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', $env['DB_PORT'] ?? '3306', $env['DB_NAME'] ?? ''),
            $env['DB_USER'] ?? '',
            $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        line('Підключення до БД', 'ok');
        line('VERSION()', $pdo->query('SELECT VERSION()')->fetchColumn());
        line('max_allowed_packet', $pdo->query('SELECT @@max_allowed_packet')->fetchColumn() . ' (імпорт: найгірший пакет ≈ 6 МБ)');
        line('sql_mode', $pdo->query('SELECT @@sql_mode')->fetchColumn());
        line('character_set_database / collation', $pdo->query('SELECT @@character_set_database')->fetchColumn() . ' / ' . $pdo->query('SELECT @@collation_database')->fetchColumn());
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        line('Таблиці', implode(', ', $tables) . ' (очікується: daily_stats, login_attempts, migrations, phrases)');
        line('Міграції застосовано', implode(', ', $pdo->query('SELECT name FROM migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        line('Підключення до БД', 'ПОМИЛКА: ' . get_class($e) . ' (текст помилки не друкуємо, може містити хост)');
    }
}

echo "\n!!! ВИДАЛІТЬ цей файл із сервера одразу після зняття результатів.\n";
