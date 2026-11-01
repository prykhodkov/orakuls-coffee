<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Logger;
use Throwable;

/**
 * Службовий ендпоінт для моніторингу та перевірки розгортання. Поза контрактом API.md —
 * фронт його не використовує. Версій PHP/MySQL і деталей помилки назовні не віддає.
 */
final class HealthController
{
    public function show(Request $request, array $params): Response
    {
        $headers = ['Cache-Control' => 'no-store'];

        try {
            Db::pdo()->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            // Без trace: монітор дзвонить регулярно, а при падінні БД кожен запит потрапляє сюди.
            Logger::error('Health check: database is unavailable: ' . $e->getMessage());

            return Response::json(['status' => 'error'], 503, $headers);
        }

        return Response::json(['status' => 'ok'], 200, $headers);
    }
}
