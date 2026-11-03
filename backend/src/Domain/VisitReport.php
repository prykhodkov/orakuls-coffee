<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Збірка відповіді GET /api/admin/visits з рядків daily_stats: усі дні періоду (порожні — нулі), byLang завжди lv, en, ru,
 * total — сума днів, порахована в одному проході з days (тож збігаються за побудовою). Чиста, без БД і HTTP.
 */
final class VisitReport
{
    /**
     * Метрики, які читає звіт: єдине джерело імен — StatMetrics (їх пишуть BE-011 і BE-012).
     *
     * @return list<string>
     */
    public static function metrics(): array
    {
        return StatMetrics::CODES;
    }

    /**
     * @param list<string> $dates усі дні періоду по зростанню (ReportPeriod::days())
     * @param iterable<array<string, mixed>> $rows рядки rangeTotals(): day, metric, count
     * @return array{total: array<string, mixed>, days: list<array<string, mixed>>}
     */
    public static function build(array $dates, iterable $rows): array
    {
        $counts = array_fill_keys($dates, []);
        foreach ($rows as $row) {
            $day = (string) $row['day'];
            $metric = (string) $row['metric'];
            // Рядки поза періодом і невідомі метрики ігноруються (захист від майбутніх метрик іншої задачі)
            if (!array_key_exists($day, $counts) || !in_array($metric, self::metrics(), true)) {
                continue;
            }
            // Додавання, а не присвоєння: однакова пара (day, metric) не губиться
            $counts[$day][$metric] = ($counts[$day][$metric] ?? 0) + (int) $row['count'];
        }

        $total = ['visits' => 0, 'scans' => 0, 'messages' => 0, 'byLang' => array_fill_keys(Languages::CODES, 0)];
        $days = [];
        foreach ($dates as $date) {
            $byLang = [];
            foreach (Languages::CODES as $lang) {
                $byLang[$lang] = $counts[$date][StatMetrics::message($lang)] ?? 0;
            }
            $messages = array_sum($byLang);
            $day = [
                'date' => $date,
                'visits' => $counts[$date][StatMetrics::VISIT] ?? 0,
                'scans' => $counts[$date][StatMetrics::SCAN] ?? 0,
                'messages' => $messages,
                'byLang' => $byLang,
            ];
            $days[] = $day;

            $total['visits'] += $day['visits'];
            $total['scans'] += $day['scans'];
            $total['messages'] += $messages;
            foreach ($byLang as $lang => $count) {
                $total['byLang'][$lang] += $count;
            }
        }

        return ['total' => $total, 'days' => $days];
    }
}
