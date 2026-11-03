<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\StatMetrics;
use App\Http\Request;
use App\Http\Response;
use App\Repository\DailyStatsRepository;
use App\Support\Db;
use App\Support\RigaClock;

/**
 * Публічний ендпоінт POST /api/visit: фронт повідомляє «відкрили сайт» і чи це було сканування QR (BE-011).
 * Без авторизації. Рахуються лише кількості: source — єдине, що береться із запиту; IP, заголовки й query
 * не читаються (приватність, F-005). Збій БД → 500 через ErrorHandler (фронт відповідь ігнорує).
 * Захисту від накрутки свідомо немає (рішення F-005).
 */
final class VisitController
{
    public function __construct(private readonly RigaClock $clock = new RigaClock())
    {
    }

    public function record(Request $request, array $params): Response
    {
        $data = $request->json(); // порожнє/не-JSON/не-об'єкт → 400 до звернення до БД

        // Суворо 'qr' (регістр значущий): усе інше — звичайне відкриття, лічильник не форма
        $metrics = ($data['source'] ?? null) === 'qr'
            ? [StatMetrics::VISIT, StatMetrics::SCAN]
            : [StatMetrics::VISIT];

        // Db::pdo() тут, а не в конструкторі: створення контролера в routes.php БД не відкриває
        (new DailyStatsRepository(Db::pdo(), $this->clock))->incrementMany($metrics, $this->clock->now());

        return new Response(204, null, ['Cache-Control' => 'no-store']);
    }
}
