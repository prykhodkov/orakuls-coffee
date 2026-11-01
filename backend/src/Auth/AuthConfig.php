<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;
use App\Support\Config;
use App\Support\Logger;

/**
 * Облікові дані єдиного адміна й параметри токена. Будь-яка помилка конфігурації — 500 (fail-closed):
 * доступ до адмінки ніколи не відкривається «за відсутністю налаштувань».
 */
final class AuthConfig
{
    public const DEFAULT_TTL_HOURS = 168; // 7 діб — рішення користувача (D-21)
    public const MIN_TTL_HOURS = 1;
    public const MAX_TTL_HOURS = 720;
    public const MIN_SECRET_LENGTH = 32;

    public function __construct(
        public readonly string $login,
        public readonly string $passwordHash,
        public readonly string $secret,
        public readonly int $ttlSeconds,
    ) {
    }

    /**
     * Читає конфігурацію процесу/.env. Не кешує: викликається на кожен адмін-запит (це дешево),
     * а тести можуть змінювати змінні між викликами.
     * У лог іде лише назва змінної й що з нею не так — ніколи значення (пароль, хеш, секрет).
     */
    public static function fromEnv(): self
    {
        $login = Config::get('ADMIN_LOGIN');
        if ($login === null || $login === '') {
            self::invalid('ADMIN_LOGIN is missing or empty');
        }

        $hash = Config::get('ADMIN_PASSWORD_HASH');
        // Порожній рядок і «не хеш» дають algo === null; без цієї перевірки password_verify на сміттєвому
        // значенні просто повертав би false, а помилку конфігурації ніхто б не помітив.
        if ($hash === null || password_get_info($hash)['algo'] === null) {
            self::invalid('ADMIN_PASSWORD_HASH is missing or not a password_hash() value');
        }

        $secret = Config::get('TOKEN_SECRET');
        if ($secret === null || strlen($secret) < self::MIN_SECRET_LENGTH) {
            self::invalid('TOKEN_SECRET is missing or shorter than ' . self::MIN_SECRET_LENGTH . ' characters');
        }

        // Порожнє значення = за замовчуванням: Config::get() віддає '' як значення, а не як відсутність
        $ttl = Config::get('TOKEN_TTL_HOURS');
        if ($ttl === null || $ttl === '') {
            $hours = self::DEFAULT_TTL_HOURS;
        } elseif (preg_match('/^\d+$/D', $ttl) === 1
            && (int) $ttl >= self::MIN_TTL_HOURS
            && (int) $ttl <= self::MAX_TTL_HOURS
        ) {
            $hours = (int) $ttl;
        } else {
            self::invalid('TOKEN_TTL_HOURS must be an integer from ' . self::MIN_TTL_HOURS . ' to ' . self::MAX_TTL_HOURS);
        }

        return new self($login, $hash, $secret, $hours * 3600);
    }

    private static function invalid(string $problem): never
    {
        Logger::error('Auth config invalid: ' . $problem);

        throw ApiException::server();
    }
}
