<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Коди мов і єдине місце, де код мови стає частиною SQL (ім'я колонки).
 * Імена колонок не можна передати параметром prepared statement, тому ввід користувача
 * ніколи не підставляється в запит напряму — лише через білий список звідси.
 */
final class Languages
{
    public const CODES = ['lv', 'en', 'ru'];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /** Колонка таблиці phrases для мови. Кидає виняток на невідомий код — ніколи не підставляє ввід у SQL. */
    public static function column(string $lang): string
    {
        if (!self::isValid($lang)) {
            throw new InvalidArgumentException("Unknown language code: {$lang}");
        }

        return 'text_' . $lang;
    }
}
