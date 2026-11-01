<?php

declare(strict_types=1);

namespace App\Support;

use stdClass;

final class Json
{
    /**
     * Кирилиця й латиська не екрануються (читабельні логи), помилка кодування — виняток.
     */
    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Асоціативний масив, який у JSON має бути об'єктом навіть порожнім:
     * `json_encode([])` дає `[]`, а контракт вимагає `{}` (text, byCategory, missing, fields).
     *
     * @param array<string, mixed> $data
     */
    public static function object(array $data): array|stdClass
    {
        return $data === [] ? new stdClass() : $data;
    }
}
