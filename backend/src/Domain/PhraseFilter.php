<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Параметри списку фраз (GET /api/admin/phrases) — чисті дані без SQL і без HTTP.
 * Значення вже перевірені контролером; null означає «фільтра немає».
 */
final class PhraseFilter
{
    public function __construct(
        public readonly ?string $category = null,
        public readonly ?string $search = null,   // вже обрізаний; null = немає
        public readonly ?string $missing = null,  // lv|en|ru
        public readonly ?bool $isActive = null,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {
    }

    /** Скільки записів пропустити: сторінки нумеруються з 1. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
