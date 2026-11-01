<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Нормалізовані дані фрази для запису (створення, повна заміна, імпорт): те, що лишається після PhraseValidator.
 * Не залежить від Http і Repository.
 */
final class PhraseInput
{
    /** @param array{lv: string, en: string, ru: string} $text вже trimmed */
    public function __construct(
        public readonly string $category,
        public readonly array $text,
        public readonly bool $isActive,
    ) {
    }
}
