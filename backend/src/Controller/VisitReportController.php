<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\InvalidPeriodException;
use App\Domain\ReportPeriod;
use App\Domain\VisitReport;
use App\Http\Request;
use App\Http\Response;
use App\Repository\DailyStatsRepository;
use App\Support\ApiException;
use App\Support\Db;
use App\Support\RigaClock;

/**
 * Звіт зі статистики за період: GET /api/admin/visits?from=YYYY-MM-DD&to=YYYY-MM-DD (BE-013). Токен перевіряє AdminGuard
 * у Kernel (D-20), тут його не повторюємо. from і to обов'язкові (за ризьким часом, включно з обома кінцями); майбутні дати
 * дозволені (нулі); зайві параметри ігноруються. Відповідь не кешується (D-35).
 */
final class VisitReportController
{
    public function index(Request $request, array $params): Response
    {
        $period = self::parsePeriod($request); // 400 до звернення до БД

        // Db::pdo() тут, а не в конструкторі: створення контролера в routes.php БД не відкриває
        $rows = (new DailyStatsRepository(Db::pdo()))->rangeTotals($period->from, $period->to);
        $report = VisitReport::build($period->days(), $rows);

        return Response::json(
            [
                'from' => $period->from,
                'to' => $period->to,
                'timezone' => RigaClock::TIMEZONE,
                'total' => $report['total'],
                'days' => $report['days'],
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    /**
     * Публічний і статичний, щоб розбір тестувався без БД. Порядок: наявність from → наявність to → формат і реальність дат →
     * from ≤ to → довжина. Повідомлення не повторюють ввід, fields немає (як у фільтрах BE-005).
     *
     * @throws ApiException 400
     */
    public static function parsePeriod(Request $request): ReportPeriod
    {
        $values = [];
        foreach (['from', 'to'] as $name) {
            $value = $request->query($name); // параметр-масив → 400
            if ($value === null || $value === '') {
                throw ApiException::badRequest("Parameter '{$name}' is required: YYYY-MM-DD");
            }
            $values[$name] = $value;
        }

        try {
            return ReportPeriod::create($values['from'], $values['to']);
        } catch (InvalidPeriodException $e) {
            throw ApiException::badRequest($e->getMessage());
        }
    }
}
