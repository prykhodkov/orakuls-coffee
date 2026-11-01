<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\ApiException;

/**
 * Мінімальний роутер: метод + шаблон шляху з параметрами `{name}`.
 * Обробник: fn(Request $request, array $params): Response, де $params — значення з шляху.
 * Шлях без збігу або метод, якого немає для шляху, дають 404 not_found — у контракті немає коду 405.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $parts = preg_split('/\{(\w+)\}/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $i => $part) {
            $regex .= $i % 2 === 0 ? preg_quote($part, '#') : '(?P<' . $part . '>[^/]+)';
        }

        $this->routes[] = ['method' => strtoupper($method), 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method || !preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return ($route['handler'])($request, $params);
        }

        throw ApiException::notFound('Endpoint not found');
    }
}
