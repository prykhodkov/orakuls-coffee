<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Ліміт невдалих спроб входу за IP (D-22): LIMIT спроб за WINDOW_SECONDS. Лічильник — таблиця login_attempts,
 * час — UTC_TIMESTAMP() самої БД (усі записи й порівняння в одному годиннику, без розбіжностей PHP/MySQL).
 */
final class LoginRateLimiter
{
    public const LIMIT = 5;
    public const WINDOW_SECONDS = 900; // 15 хвилин

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Видаляє записи, що вийшли з вікна, тож таблиця не росте безмежно. */
    public function purge(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM `login_attempts` WHERE `attempted_at` < UTC_TIMESTAMP() - INTERVAL ? SECOND');
        $stmt->bindValue(1, self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * null — можна пробувати; інакше скільки секунд чекати (1…WINDOW_SECONDS).
     *
     * Один запит дає і кількість спроб у вікні, і вік п'ятої найновішої: коли вона вийде з вікна,
     * в ньому лишиться менше LIMIT спроб і блок знімається. Рахунок «вибрати останні LIMIT» (а не COUNT)
     * стійкий до одночасних запитів, що проскочили перевірку разом: зайві записи на розрахунок не впливають.
     */
    public function retryAfter(string $ip): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, `attempted_at`, UTC_TIMESTAMP()) AS `age` FROM `login_attempts`'
            . ' WHERE `ip` = ? AND `attempted_at` > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY `attempted_at` DESC LIMIT ' . self::LIMIT
        );
        $stmt->bindValue(1, $this->normalize($ip), PDO::PARAM_STR);
        $stmt->bindValue(2, self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
        $ages = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($ages) < self::LIMIT) {
            return null;
        }

        return max(1, self::WINDOW_SECONDS - (int) $ages[self::LIMIT - 1]);
    }

    public function recordFailure(string $ip): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO `login_attempts` (`ip`, `attempted_at`) VALUES (?, UTC_TIMESTAMP())');
        $stmt->execute([$this->normalize($ip)]);
    }

    /** Колонка VARCHAR(45): довший ввід у суворому режимі дав би помилку БД, а не відмову. */
    private function normalize(string $ip): string
    {
        return substr($ip, 0, 45);
    }
}
