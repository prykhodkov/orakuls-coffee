<?php

declare(strict_types=1);

// Генерує боєві облікові дані адміна для .env (BE-009): випадковий пароль, його bcrypt-хеш і TOKEN_SECRET.
// Запуск: docker compose exec php php bin/generate-admin-credentials.php   (локально або на сервері, де є php-cli)
//
// STDOUT — лише рядки для .env (хеш — в одинарних лапках, бо містить $); пароль НЕ потрапляє ні в STDOUT, ні у файли.
// STDERR — пароль у відкритому вигляді (показується один раз) і підказки. Пароль передати клієнту безпечним каналом
// (менеджер паролів або одноразове посилання), не поштою й не месенджером відкритим текстом, і не лишати в історії чатів.
// Скрипт нічого не пише у файли; хеш для вже відомого пароля готує bin/hash-password.php.

if (PHP_SAPI !== 'cli') { // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

const PASSWORD_LENGTH = 24;
const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'; // без неоднозначних символів (0/O, 1/l/I)

$password = '';
for ($i = 0; $i < PASSWORD_LENGTH; $i++) {
    $password .= PASSWORD_ALPHABET[random_int(0, strlen(PASSWORD_ALPHABET) - 1)];
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$secret = bin2hex(random_bytes(32)); // 64 символи; мінімум 32

echo "ADMIN_LOGIN=admin\n";
echo "ADMIN_PASSWORD_HASH='{$hash}'\n";
echo "TOKEN_SECRET='{$secret}'\n";
echo "TOKEN_TTL_HOURS=168\n";

fwrite(STDERR, "\n=== ПАРОЛЬ АДМІНА (показано один раз, у .env його немає — лише хеш) ===\n{$password}\n");
fwrite(STDERR, "Передайте його клієнту безпечним каналом і не зберігайте в історії терміналу чи чатів.\n");
fwrite(STDERR, "Рядки зі STDOUT вставте в серверний .env (не через echo/sed: оболонка розкриє \$ у хеші).\n");
