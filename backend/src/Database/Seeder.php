<?php

declare(strict_types=1);

namespace App\Database;

use App\Domain\Categories;
use App\Domain\Languages;
use PDO;
use RuntimeException;

/**
 * Наповнює тестову БД відомими даними з database/seed/phrases.php.
 * Працює лише з базою *_test (DatabaseGuard): TRUNCATE на робочій БД знищив би реальні фрази.
 */
final class Seeder
{
    /** Фіксовано (збігається з прикладом в API.md): відповіді детерміновані, тести порівнюють createdAt зі сталим значенням. */
    public const CREATED_AT = '2026-10-26 18:30:00';

    /** Ключі, на які покладаються тести наступних задач; зникнення будь-якого — помилка сіду. */
    private const REQUIRED_KEYS = [
        'love_1', 'love_2', 'love_3', 'money_1', 'money_2', 'money_3',
        'luck_1', 'luck_2', 'luck_3', 'work_1', 'work_2', 'work_3',
        'travel_1', 'travel_2', 'travel_3', 'mood_1', 'mood_2', 'mood_3',
        'hidden', 'only_lv', 'only_en', 'no_ru', 'blank_en', 'diacritics',
        'cyrillic_case', 'emoji', 'multiline', 'len500', 'html', 'like_chars',
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $seedFile)
    {
    }

    /**
     * Дані без звернення до БД: валідація використовується і юніт-перевірками.
     *
     * @return list<array{key: string, category: string, active: bool, lv: string, en: string, ru: string}>
     */
    public static function load(string $seedFile): array
    {
        $raw = require $seedFile;
        if (!is_array($raw)) {
            throw new RuntimeException('Seed file must return an array');
        }

        $rows = [];
        $keys = [];
        foreach ($raw as $index => $row) {
            $key = $row['key'] ?? null;
            if (!is_string($key) || $key === '') {
                throw new RuntimeException("Seed row #{$index} has no key");
            }
            if (isset($keys[$key])) {
                throw new RuntimeException("Duplicate seed key: {$key}");
            }
            $keys[$key] = true;

            $category = $row['category'] ?? null;
            if (!is_string($category) || !Categories::isValid($category)) {
                throw new RuntimeException("Seed row '{$key}' has invalid category");
            }

            $texts = [];
            foreach (Languages::CODES as $lang) {
                $text = $row[$lang] ?? '';
                // Ліміт у символах, а не в байтах (utf8mb4)
                if (!is_string($text) || mb_strlen($text) > 500) {
                    throw new RuntimeException("Seed row '{$key}': text {$lang} is not a string of at most 500 characters");
                }
                $texts[$lang] = $text;
            }

            $rows[] = [
                'key' => $key,
                'category' => $category,
                'active' => (bool) ($row['active'] ?? true),
                'lv' => $texts['lv'],
                'en' => $texts['en'],
                'ru' => $texts['ru'],
            ];
        }

        $missing = array_diff(self::REQUIRED_KEYS, array_keys($keys));
        if ($missing !== []) {
            throw new RuntimeException('Seed is missing required keys: ' . implode(', ', $missing));
        }

        return $rows;
    }

    /**
     * @return array<string, int> key → id
     */
    public function run(): array
    {
        // Першою дією, до будь-яких змін
        DatabaseGuard::assertTestDatabase($this->pdo);

        $rows = self::load($this->seedFile);

        // TRUNCATE комітить неявно і скидає AUTO_INCREMENT: id сіду завжди 1, 2, 3 …
        $this->pdo->exec('TRUNCATE TABLE `phrases`');

        $insert = $this->pdo->prepare(
            'INSERT INTO `phrases` (`category`, `text_lv`, `text_en`, `text_ru`, `is_active`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $map = [];
        foreach ($rows as $row) {
            $insert->execute([
                $row['category'], $row['lv'], $row['en'], $row['ru'],
                $row['active'] ? 1 : 0, self::CREATED_AT, self::CREATED_AT,
            ]);
            $map[$row['key']] = (int) $this->pdo->lastInsertId();
        }

        return $map;
    }
}
