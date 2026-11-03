<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\RigaClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Період звіту зі статистики (GET /api/admin/visits): дві календарні дати включно. Чисті дані й календарна арифметика,
 * без БД і HTTP. Усе рахується над «чистими» датами в UTC (півночі), а не в поясі Риги: доба Риги в день переходу має
 * 23 або 25 год (2026-03-29, 2026-10-25), і підрахунок секундами дав би неправильну кількість днів чи дубль дати.
 */
final class ReportPeriod
{
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $from,                 // канонічне 'YYYY-MM-DD'
        public readonly string $to,
        public readonly int $dayCount,                // включно з обома кінцями, 1…366
        private readonly DateTimeImmutable $start,    // UTC-північ дати from
    ) {
    }

    /**
     * Повідомлення містять назву параметра, але не повторюють значення, що прийшло.
     *
     * @throws InvalidPeriodException
     */
    public static function create(string $from, string $to): self
    {
        $start = self::parseDate($from);
        if ($start === null) {
            throw new InvalidPeriodException("Parameter 'from' must be a real date in YYYY-MM-DD format");
        }
        $end = self::parseDate($to);
        if ($end === null) {
            throw new InvalidPeriodException("Parameter 'to' must be a real date in YYYY-MM-DD format");
        }
        if ($start > $end) {
            throw new InvalidPeriodException("Parameter 'from' must not be later than 'to'");
        }

        // Довжину перевіряємо ДО побудови списку днів: 0001-01-01 … 9999-12-31 не повинен будувати 3,6 млн елементів
        $count = (int) $start->diff($end)->days + 1;
        if ($count > self::MAX_DAYS) {
            throw new InvalidPeriodException('Period must not exceed ' . self::MAX_DAYS . ' days');
        }

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'), $count, $start);
    }

    /** UTC-північ дати або null, якщо це не канонічна YYYY-MM-DD чи дата неіснуюча. Перевірку веде RigaClock::isDay (одна на проєкт). */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        if (!RigaClock::isDay($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    /**
     * Усі дні періоду по зростанню. Будується від початкової дати й за кількістю днів (а не «до to» і не накопиченням секунд):
     * to = 9999-12-31 не виходить за рік 9999, доба 23/25 год нічого не ламає.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        for ($i = 0; $i < $this->dayCount; $i++) {
            $days[] = $this->start->modify("+{$i} days")->format('Y-m-d');
        }

        return $days;
    }
}
