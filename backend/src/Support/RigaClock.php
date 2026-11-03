<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * «День» статистики — календарна дата за часом Риги (клієнт у Латвії: «вчора» означає латвійське вчора, а не UTC).
 * Дату рахує PHP, а не MySQL: на shared-хостингу таблиці часових поясів MySQL можуть бути не завантажені
 * (CONVERT_TZ дав би NULL), а PHP має власну базу поясів. Тому тут жодних date()/time()/NOW()/CURDATE():
 * лише DateTimeImmutable з явною зоною (пояс за замовчуванням процесу — UTC, але він глобальний і не береться до уваги).
 * Джерело поточного моменту підміняється замиканням — так тести задають «зараз» без залежності від годинника.
 */
final class RigaClock
{
    /** Значення поля "timezone" у відповіді GET /api/admin/visits (BE-013). */
    public const TIMEZONE = 'Europe/Riga';

    /** @param ?Closure(): DateTimeImmutable $source підміна годинника в тестах; null → реальний час */
    public function __construct(private readonly ?Closure $source = null)
    {
    }

    /** Поточний момент у зоні Europe/Riga. */
    public function now(): DateTimeImmutable
    {
        if ($this->source === null) {
            $moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } else {
            $moment = ($this->source)();
            if (!$moment instanceof DateTimeImmutable) {
                throw new LogicException('RigaClock source must return DateTimeImmutable');
            }
        }

        return $moment->setTimezone(self::zone());
    }

    /** 'YYYY-MM-DD' за Ригою — «сьогодні». */
    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    /** Будь-який момент (у будь-якій зоні) → 'YYYY-MM-DD' за Ригою. */
    public static function dayOf(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(self::zone())->format('Y-m-d');
    }

    /**
     * Сувора перевірка рядка 'YYYY-MM-DD': канонічний формат і реальна дата (2026-02-30 → false). Рік 0000 — невалідний.
     * Регулярка сама не відсікає неіснуючу дату, а createFromFormat сам пропускає '2026-1-5' — потрібні обидві перевірки
     * і порівняння format() з вхідним рядком (як Iso8601::fromDb). Модифікатор D: без нього "2026-11-03\n" проходить.
     */
    public static function isDay(string $value): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 || str_starts_with($value, '0000')) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }
}
