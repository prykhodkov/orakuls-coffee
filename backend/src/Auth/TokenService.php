<?php

declare(strict_types=1);

namespace App\Auth;

use JsonException;

/**
 * Токен адміна — JWT HS256 без бібліотек (D-21): base64url(header).base64url(payload).base64url(підпис).
 *
 * Перевірка навмисно сувора й іде в такому порядку: форма → header як константа → підпис → лише тоді payload.
 * Так `alg: none`, інші алгоритми й атаки на JSON-парсер не мають шансу: header не розбирається взагалі,
 * а payload не читається, доки підпис не збігся.
 */
final class TokenService
{
    private const HEADER_JSON = '{"alg":"HS256","typ":"JWT"}';
    private const MAX_TOKEN_LENGTH = 2048;
    private const SIGNATURE_BYTES = 32; // SHA-256

    public function __construct(private readonly AuthConfig $config)
    {
    }

    /**
     * @param int $now поточний Unix-час (параметром, щоб термін дії перевірявся в тестах без очікування)
     * @return array{token: string, expiresAt: int}
     */
    public function issue(int $now): array
    {
        $expiresAt = $now + $this->config->ttlSeconds;
        // Порядок ключів фіксований: токени з однаковими даними збігаються байт у байт
        $payload = json_encode(
            ['sub' => $this->config->login, 'iat' => $now, 'exp' => $expiresAt],
            JSON_THROW_ON_ERROR
        );

        $signingInput = self::encode(self::HEADER_JSON) . '.' . self::encode($payload);

        return [
            'token' => $signingInput . '.' . self::encode($this->sign($signingInput)),
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Будь-яка невідповідність дає false; винятків не кидає.
     */
    public function verify(string $token, int $now): bool
    {
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            return false;
        }
        [$header, $payload, $signature] = $parts;

        // Header — константа: поле alg з токена ніколи не читається й не довіряється
        if ($header !== self::encode(self::HEADER_JSON)) {
            return false;
        }

        $given = self::decode($signature);
        if ($given === null || strlen($given) !== self::SIGNATURE_BYTES) {
            return false;
        }
        // hash_equals порівнює за сталий час; обидва аргументи — сирі 32 байти
        if (!hash_equals($this->sign($header . '.' . $payload), $given)) {
            return false;
        }

        // Підпис збігся — токен випущений нами, тільки тепер payload можна розбирати
        $json = self::decode($payload);
        if ($json === null) {
            return false;
        }
        try {
            $claims = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (!is_array($claims)
            || !isset($claims['sub'], $claims['iat'], $claims['exp'])
            || !is_string($claims['sub'])
            || !is_int($claims['iat'])
            || !is_int($claims['exp'])
        ) {
            return false;
        }

        return $claims['exp'] > $now && hash_equals($this->config->login, $claims['sub']);
    }

    /**
     * Ключ підпису виводиться і з секрету, і з хеша пароля: зміна будь-якого з них (а також нового
     * хеша при зміні пароля) анулює всі видані токени без списку відкликаних.
     */
    private function sign(string $data): string
    {
        $key = hash_hmac('sha256', $this->config->passwordHash, $this->config->secret, true);

        return hash_hmac('sha256', $data, $key, true);
    }

    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Канонічний base64url: base64_decode() сам приймає паддінг, стандартний алфавіт і ненульові зайві біти,
     * тож результат кодується назад і порівнюється з входом — будь-яка інша форма запису того ж значення відхиляється.
     */
    private static function decode(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        $raw = base64_decode(strtr($data, '-_', '+/'), true);
        if ($raw === false || self::encode($raw) !== $data) {
            return null;
        }

        return $raw;
    }
}
