<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Iso8601;

/**
 * Фраза в тому вигляді, в якому її віддає API. Єдине місце, де рядок таблиці phrases стає об'єктом API
 * (isActive → bool, дати → ISO 8601, text → об'єкт з трьома ключами): ним користуються BE-005, BE-006 і BE-007.
 * Не залежить від Http і Repository.
 */
final class Phrase
{
    /**
     * @param array{lv: string, en: string, ru: string} $text
     * @param string $createdAt вже ISO 8601 UTC, '2026-10-26T18:30:00Z'
     */
    public function __construct(
        public readonly int $id,
        public readonly string $category,
        public readonly array $text,
        public readonly bool $isActive,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row рядок phrases з БД (колонки text_lv, is_active, created_at …)
     */
    public static function fromRow(array $row): self
    {
        // Порядок ключів text фіксований (lv, en, ru) і береться зі списку мов, а не з порядку колонок у запиті
        $text = [];
        foreach (Languages::CODES as $lang) {
            $text[$lang] = (string) $row[Languages::column($lang)];
        }

        return new self(
            (int) $row['id'],
            (string) $row['category'],
            $text,
            (bool) (int) $row['is_active'],
            Iso8601::fromDb((string) $row['created_at']),
            Iso8601::fromDb((string) $row['updated_at']),
        );
    }

    /**
     * Порядок ключів — як у docs/API.md. Службових імен колонок (text_lv, is_active) тут немає.
     *
     * @return array{id: int, category: string, text: array{lv: string, en: string, ru: string}, isActive: bool, createdAt: string, updatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'text' => $this->text,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
