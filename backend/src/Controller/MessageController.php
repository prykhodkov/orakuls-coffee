<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Languages;
use App\Http\Request;
use App\Http\Response;
use App\Repository\PhraseRepository;
use App\Support\ApiException;
use App\Support\Db;
use App\Support\IdList;

/**
 * Публічний ендпоінт GET /api/message: випадкове послання мовою користувача.
 * 400 дає лише lang; exclude — підказка «найкращого зусилля», сміття в ній ігнорується (D-18).
 */
final class MessageController
{
    public function show(Request $request, array $params): Response
    {
        $lang = $request->query('lang'); // масив → 400 (query() кидає виняток)
        if ($lang === null || !Languages::isValid($lang)) { // порожній, невідомий, 'LV' → 400
            throw ApiException::badRequest('Parameter lang is required: lv, en or ru');
        }

        // Не query(): масив (exclude[]=1) має ігноруватись, а не давати 400
        $raw = $request->rawQuery('exclude');
        $exclude = IdList::parse(is_string($raw) ? $raw : null);

        // Db::pdo() викликається тут, а не в конструкторі: створення контролера в routes.php БД не відкриває
        $row = (new PhraseRepository(Db::pdo()))->findRandom($lang, $exclude);
        if ($row === null) {
            throw ApiException::notFound('No phrases available for this language');
        }

        return Response::json(
            ['id' => $row['id'], 'lang' => $lang, 'text' => $row['text']], // порядок ключів фіксований
            200,
            ['Cache-Control' => 'no-store']
        );
    }
}
