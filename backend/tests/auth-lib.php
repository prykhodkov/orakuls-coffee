<?php

declare(strict_types=1);

// Допоміжні функції входу й токенів для перевірок адмінки (BE-004, BE-005 …). Підключається з tests/api.php
// після db-lib.php, тож жоден тест-файл у tests/api/ не залежить від порядку підключення інших.
// Облікові дані (TEST_ADMIN_*, TEST_TOKEN_SECRET) задає tests/api.php через putenv; на момент виклику функцій вони визначені.

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
