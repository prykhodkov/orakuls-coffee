<?php

declare(strict_types=1);

// Робочий процес для перевірки паралельних запитів POST /api/visit (BE-011, tests/api/visit.php). Не тест сам по собі:
// лежить НЕ в tests/api/, бо tests/api.php підключає як тести кожен файл звідти.
// Запуск (лише з батьківського тесту): php tests/workers/visit-worker.php <момент ISO 8601 Z> <source>
// Протокол: друкує "ready", блокується на читанні STDIN (бар'єр), після рядка від батька робить один запит.
// Код виходу 0 — лише якщо відповідь 204.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Controller\VisitController;
use App\Database\DatabaseGuard;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Router;
use App\Support\Db;
use App\Support\RigaClock;

define('APP_ROOT', dirname(__DIR__, 2));
// DB_NAME успадковується від батьківського тесту (orakuls_test); тут не задається, а лише перевіряється нижче
require APP_ROOT . '/src/bootstrap.php';

[, $instant, $source] = $argv + [null, null, null];
if ($instant === null || $source === null) {
    fwrite(STDERR, "usage: visit-worker.php <instant ISO 8601 Z> <source>\n");
    exit(2);
}

try {
    DatabaseGuard::assertTestDatabase(Db::pdo()); // скрипт не повинен випадково писати в робочу БД

    $router = new Router();
    $moment = new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    $router->post('/api/visit', [new VisitController(new RigaClock(static fn () => $moment)), 'record']);
    $kernel = new Kernel($router);

    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN); // бар'єр: усі воркери стартують одночасно за сигналом батька

    $response = $kernel->handle(new Request('POST', '/api/visit', [], [], json_encode(['source' => $source], JSON_THROW_ON_ERROR)));
    if ($response->status !== 204) {
        fwrite(STDERR, "unexpected status {$response->status}: {$response->body}\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
