<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Єдиний білий список метрик таблиці daily_stats. Назва метрики завжди йде в SQL параметром,
 * але невідома метрика все одно відхиляється: це помилка програміста, а не вводу користувача.
 */
final class StatMetrics
{
    public const VISIT = 'visit';
    public const SCAN = 'scan';

    /** Порядок фіксований: на нього спираються звіти (BE-013). */
    public const CODES = ['visit', 'scan', 'message_lv', 'message_en', 'message_ru'];

    public static function isValid(string $metric): bool
    {
        return in_array($metric, self::CODES, true);
    }

    /** 'lv' → 'message_lv'. Невідома мова — виняток (ввід у метрику не потрапляє, лише код із Languages). */
    public static function message(string $lang): string
    {
        if (!Languages::isValid($lang)) {
            throw new InvalidArgumentException("Unknown language code: {$lang}");
        }

        return 'message_' . $lang;
    }
}
