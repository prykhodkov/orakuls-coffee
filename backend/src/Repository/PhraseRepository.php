<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Languages;
use PDO;

/**
 * Запити до таблиці phrases. SQL живе тут, а не в контролерах (D-17): ним користуватимуться
 * кілька ендпоінтів. PDO отримує ззовні, тож клас не залежить від Db і HTTP.
 */
final class PhraseRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * SQL-умова «переклад мовою $lang не порожній». Єдине визначення порожнього для всіх ендпоінтів
     * (BE-003, BE-005 `missing`, BE-008). TRIM у MySQL прибирає лише пробіли (на відміну від trim() у PHP,
     * що обрізає й табуляцію та переноси): BE-006 при записі обрізає всі пробільні символи,
     * тож розбіжність можлива лише для застарілих або внесених вручну даних.
     */
    public static function hasTextSql(string $lang): string
    {
        return 'TRIM(`' . Languages::column($lang) . '`) <> \'\'';
    }

    /**
     * Випадкова активна фраза з непорожнім перекладом. Фрази з $excludeIds вибираються лише тоді,
     * коли інших кандидатів немає: тоді `exclude` фактично ігнорується (D-19).
     *
     * @param list<int> $excludeIds
     * @return array{id: int, text: string}|null null — кандидатів немає
     */
    public function findRandom(string $lang, array $excludeIds = []): ?array
    {
        // Кидає виняток на невідомий код: ім'я колонки не можна передати параметром, тож лише білий список
        $column = Languages::column($lang);

        $sql = "SELECT `id`, `{$column}` AS `text` FROM `phrases` WHERE `is_active` = 1 AND " . self::hasTextSql($lang);
        if ($excludeIds === []) {
            // IN () з порожнім списком — синтаксична помилка MySQL, тож у цьому випадку його не додаємо
            $sql .= ' ORDER BY RAND() LIMIT 1';
        } else {
            // (id IN (…)) дає 0 для непереглянутих і 1 для виключених: спершу йдуть непереглянуті
            // у випадковому порядку, а якщо їх немає — виключені. Другого запиту не потрібно.
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql .= " ORDER BY (`id` IN ({$placeholders})) ASC, RAND() LIMIT 1";
        }

        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($excludeIds) as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT); // позиційні параметри нумеруються з 1
        }
        $stmt->execute();

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'text' => (string) $row['text']];
    }
}
