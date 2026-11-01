<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\PhraseRepository;
use App\Support\Db;

/**
 * Зведення для адмінки: GET /api/admin/stats (BE-008). Токен перевіряє AdminGuard у Kernel (D-20), тут його
 * не повторюємо. Параметрів немає: зайві query-параметри ігноруються. Кешу немає (D-32).
 */
final class StatsController
{
    public function index(Request $request, array $params): Response
    {
        // Db::pdo() викликається тут, а не в конструкторі: створення контролера в routes.php БД не відкриває
        $stats = (new PhraseRepository(Db::pdo()))->stats();

        return Response::json(
            [
                'total' => $stats['total'],
                'active' => $stats['active'],
                'byCategory' => $stats['byCategory'],
                'missing' => $stats['missing'],
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }
}
