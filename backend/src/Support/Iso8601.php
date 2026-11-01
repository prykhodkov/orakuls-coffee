<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Єдине місце перетворення дат БД на формат API, щоб він не розходився між ендпоінтами.
 * DATETIME у БД зберігається в UTC (сесія працює з time_zone=+00:00).
 */
final class Iso8601
{
    /** '2026-10-26 18:30:00' (DATETIME з БД, UTC) → '2026-10-26T18:30:00Z' */
    public static function fromDb(string $datetime): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, new DateTimeZone('UTC'));
        // Повторне форматування відсікає «переповзання» дат: 2026-02-31 PHP тихо перетворив би на 03-03.
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $datetime) {
            throw new InvalidArgumentException("Not a valid DATETIME value: {$datetime}");
        }

        return $parsed->format('Y-m-d\TH:i:s\Z');
    }
}
