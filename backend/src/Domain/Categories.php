<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Єдиний список категорій у коді (D-11). БД допустимість не гарантує:
 * category — звичайний VARCHAR, а MySQL 5.7 ігнорує CHECK. Тому кожен шлях запису
 * (створення, оновлення, імпорт) і кожен фільтр мусить перевіряти код тут.
 * Нова категорія додається лише в цей список, міграція не потрібна.
 */
final class Categories
{
    /** Коди з API.md. Порядок фіксований: використовується для byCategory у статистиці. */
    public const CODES = ['love', 'money', 'luck', 'work', 'travel', 'mood'];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::CODES, true); // strict: 'LOVE' і 'love ' не проходять
    }
}
