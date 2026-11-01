<?php

declare(strict_types=1);

// Готує рядок ADMIN_PASSWORD_HASH для .env. Пароль читається зі stdin, НІКОЛИ не з аргументів:
// аргумент лишився б в історії оболонки й у списку процесів.
//
// У терміналі: запитує пароль двічі, без відлуння.
//   docker compose exec php php bin/hash-password.php
// З pipe (скрипти, перевірки): перший рядок stdin.
//   printf '%s\n' 'мій-довгий-пароль' | docker compose exec -T php php bin/hash-password.php
//
// У STDOUT іде лише готовий рядок ADMIN_PASSWORD_HASH='…' (одинарні лапки обов'язкові: хеш містить $).
// Підказки й помилки — у STDERR. Скрипт нічого не пише у файли.

if (PHP_SAPI !== 'cli') { // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

const MIN_PASSWORD_CHARS = 12;
const MAX_PASSWORD_BYTES = 72; // bcrypt ігнорує все, що далі 72-го байта

/** Читає рядок у терміналі без відлуння. Відлуння вмикається назад за будь-яких обставин. */
function readHidden(string $prompt): string
{
    // Код завершення беремо з exec(): shell_exec() повертає null і при УСПІШНІЙ команді без виводу, тож за ним
    // не відрізнити «echo вимкнено» від «stty недоступний» (саме через це ця гілка колись завжди відмовляла).
    exec('stty -echo 2>/dev/null', $unused, $status);
    if ($status !== 0) {
        // Без stty пароль було б видно на екрані — краще відмовити
        throw new RuntimeException('Cannot disable terminal echo (stty is unavailable). Pipe the password via stdin instead.');
    }
    $restore = static function (): void {
        exec('stty echo 2>/dev/null');
    };
    register_shutdown_function($restore); // якщо процес перервано посеред введення

    try {
        fwrite(STDERR, $prompt);
        $line = fgets(STDIN);
        fwrite(STDERR, "\n"); // користувач не бачив власного Enter

        return $line === false ? '' : stripNewline($line);
    } finally {
        $restore();
    }
}

/** Прибирає лише кінцевий \r\n або \n: пробіли на краях можуть бути частиною пароля. */
function stripNewline(string $line): string
{
    return preg_replace('/\r?\n\z/', '', $line) ?? $line;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n"); // пароль у повідомленнях не повторюємо
    exit(1);
}

try {
    if (stream_isatty(STDIN)) {
        $password = readHidden('Password: ');
        if ($password !== readHidden('Repeat: ')) {
            fail('Passwords do not match.');
        }
    } else {
        $line = fgets(STDIN);
        $password = $line === false ? '' : stripNewline($line);
    }

    if ($password === '') {
        fail('Password is empty.');
    }
    if (str_contains($password, "\0")) {
        fail('Password must not contain NUL bytes.');
    }
    if (mb_strlen($password) < MIN_PASSWORD_CHARS) {
        fail('Password is too short: use at least ' . MIN_PASSWORD_CHARS . ' characters.');
    }
    if (strlen($password) > MAX_PASSWORD_BYTES) {
        fail('Password is too long: bcrypt ignores everything after ' . MAX_PASSWORD_BYTES . ' bytes, so the extra part would not protect anything.');
    }

    echo "ADMIN_PASSWORD_HASH='" . password_hash($password, PASSWORD_DEFAULT) . "'\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
