<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Languages;
use App\Domain\StatMetrics;
use App\Http\Request;
use App\Http\Response;
use App\Repository\DailyStatsRepository;
use App\Repository\PhraseRepository;
use App\Support\ApiException;
use App\Support\Db;
use App\Support\IdList;
use App\Support\Logger;
use App\Support\RigaClock;
use Throwable;

/**
 * Публічний ендпоінт GET /api/message: випадкове послання мовою користувача.
 * 400 дає лише lang; exclude — підказка «найкращого зусилля», сміття в ній ігнорується (D-18).
 *
 * Кожна успішна видача (200) збільшує лічильник message_<мова> за сьогоднішній день за Ригою (BE-012, D-34);
 * 400 і 404 не рахуються. Збій лічильника НЕ ламає видачу: помилка йде в лог, користувач отримує послання.
 */
final class MessageController
{
    /** RigaClock — шов для тестів (день за Ригою, збій годинника); у бойовому коді це реальний час, як у VisitController. */
    public function __construct(private readonly RigaClock $clock = new RigaClock())
    {
    }

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

        $response = Response::json(
            ['id' => $row['id'], 'lang' => $lang, 'text' => $row['text']], // порядок ключів фіксований
            200,
            ['Cache-Control' => 'no-store']
        );

        // Лише після підготовки відповіді й лише на 200; збій статистики не повинен вплинути на користувача (D-34)
        $this->countIssued($lang);

        return $response;
    }

    /**
     * +1 до message_<мова> за сьогодні (день за Ригою визначає DailyStatsRepository). Ловить саме \Throwable, а не лише
     * PDOException: TypeError чи інша помилка всередині статистики теж не повинна ламати видачу. Логуємо без IP, заголовків,
     * exclude і id фрази — лише мову (вона вже пройшла білий список).
     */
    private function countIssued(string $lang): void
    {
        try {
            (new DailyStatsRepository(Db::pdo(), $this->clock))->increment(StatMetrics::message($lang), $this->clock->now());
        } catch (Throwable $e) {
            Logger::error("Message counter failed (message_{$lang}): статистика втрачена, послання видано", $e);
        }
    }
}
