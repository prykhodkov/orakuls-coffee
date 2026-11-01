<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\ApiException;
use JsonException;

final class Request
{
    /**
     * @param array<string, mixed>  $query   розібраний query string
     * @param array<string, string> $headers імена в нижньому регістрі
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly array $headers = [],
        private readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            $_GET,
            self::collectHeaders(),
            (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Скалярний query-параметр; відсутній → null. Масив (`?a[]=1`) — це помилка клієнта.
     */
    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::badRequest("Query parameter '{$name}' must be a string");
        }

        return $value;
    }

    /**
     * Сире значення параметра без перевірок: рядок, масив або null.
     * Для параметрів-підказок (exclude), де некоректний ввід має ігноруватись, а не давати 400.
     */
    public function rawQuery(string $name): mixed
    {
        return $this->query[$name] ?? null;
    }

    /**
     * Тіло запиту як JSON-об'єкт. Порожнє тіло, не-JSON і не-об'єкт (число, рядок, список) → 400.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if (trim($this->body) === '') {
            throw ApiException::badRequest('Request body is empty');
        }

        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiException::badRequest('Request body is not valid JSON');
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::badRequest('Request body must be a JSON object');
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        // Apache + mod_rewrite (RewriteRule … E=HTTP_AUTHORIZATION) інколи віддає заголовок з префіксом REDIRECT_.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }
}
