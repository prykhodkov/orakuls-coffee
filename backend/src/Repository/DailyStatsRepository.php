<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\StatMetrics;
use App\Support\RigaClock;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Лічильники по днях (таблиця daily_stats, BE-011). Спільний для BE-011 (відкриття), BE-012 (видані послання)
 * і BE-013 (звіт): інтерфейс не змінювати без узгодження з усіма трьома.
 *
 * Лічильник збільшується одним атомарним INSERT … ON DUPLICATE KEY UPDATE: ні транзакції, ні «прочитав — записав»
 * (останнє губило б відкриття при паралельних запитах). Метрики одного виклику йдуть одним оператором,
 * тож scans ≤ visits не порушується навіть при збої посередині.
 */
final class DailyStatsRepository
{
    public function __construct(private readonly PDO $pdo, private readonly RigaClock $clock = new RigaClock())
    {
    }

    /** +1 до лічильника метрики за день моменту $now (за Ригою); null → поточний момент годинника. Невідома метрика → виняток. */
    public function increment(string $metric, ?DateTimeImmutable $now = null): void
    {
        $this->incrementMany([$metric], $now);
    }

    /**
     * Те саме для кількох метрик одним атомарним оператором. Повтори рахуються один раз; порожній список — нічого, без запиту.
     *
     * @param list<string> $metrics
     */
    public function incrementMany(array $metrics, ?DateTimeImmutable $now = null): void
    {
        foreach ($metrics as $metric) {
            // До будь-якого SQL: одна погана метрика не повинна записати решту
            if (!is_string($metric) || !StatMetrics::isValid($metric)) {
                throw new InvalidArgumentException('Unknown stat metric');
            }
        }

        $metrics = array_values(array_unique($metrics));
        if ($metrics === []) {
            return;
        }
        // Єдиний порядок захоплення рядкових замків для паралельних запитів
        sort($metrics);

        $day = $now !== null ? RigaClock::dayOf($now) : $this->clock->today();

        $values = implode(', ', array_fill(0, count($metrics), '(?, ?, 1)'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO `daily_stats` (`day`, `metric`, `count`) VALUES {$values} ON DUPLICATE KEY UPDATE `count` = `count` + 1"
        );
        $i = 1;
        foreach ($metrics as $metric) {
            $stmt->bindValue($i++, $day, PDO::PARAM_STR);
            $stmt->bindValue($i++, $metric, PDO::PARAM_STR);
        }
        $stmt->execute();
    }

    /**
     * Рядки таблиці за [from; to] включно (обидва 'YYYY-MM-DD'), один запит, ORDER BY day, metric.
     * Дні без жодного лічильника в результат не входять (порожні дні доповнює викликач).
     *
     * @return list<array{day: string, metric: string, count: int}>
     */
    public function rangeTotals(string $from, string $to): array
    {
        if (!RigaClock::isDay($from) || !RigaClock::isDay($to) || $from > $to) {
            throw new InvalidArgumentException('Invalid stats period');
        }

        $stmt = $this->pdo->prepare(
            'SELECT `day`, `metric`, `count` FROM `daily_stats` WHERE `day` BETWEEN ? AND ? ORDER BY `day`, `metric`'
        );
        $stmt->execute([$from, $to]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['day' => (string) $row['day'], 'metric' => (string) $row['metric'], 'count' => (int) $row['count']];
        }

        return $rows;
    }
}
