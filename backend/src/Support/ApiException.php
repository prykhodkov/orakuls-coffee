<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Очікувана помилка API: перетворюється на відповідь у форматі з API.md.
 * Коди відповідають таблиці «Формат помилок».
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $fields  лише для validation_error
     * @param array<string, string> $headers додаткові заголовки відповіді (напр. Retry-After для 429)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message = 'Bad request'): self
    {
        return new self(400, 'bad_request', $message);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    /**
     * @param array<string, string> $fields ключ — шлях до поля в тілі запиту (`text.lv`), значення — код (`too_long`)
     */
    public static function validation(array $fields, string $message = 'Validation failed'): self
    {
        return new self(422, 'validation_error', $message, $fields);
    }

    /**
     * @param ?int $retryAfterSeconds скільки клієнт має почекати; ставиться заголовок Retry-After (мінімум 1 с)
     */
    public static function rateLimited(?int $retryAfterSeconds = null, string $message = 'Too many requests'): self
    {
        $headers = $retryAfterSeconds === null ? [] : ['Retry-After' => (string) max(1, $retryAfterSeconds)];

        return new self(429, 'rate_limited', $message, [], $headers);
    }

    public static function server(string $message = 'Internal server error'): self
    {
        return new self(500, 'server_error', $message);
    }
}
