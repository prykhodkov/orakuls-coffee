<?php

declare(strict_types=1);

// Робочий процес для паралельних видач GET /api/message (BE-012, tests/api/message-counter.php). Не тест сам по собі:
// лежить у tests/workers/, бо tests/api.php підключає як тести кожен файл із tests/api/.
// Запуск (лише з батьківського тесту): php tests/workers/message-worker.php <lang> [<момент ISO 8601 Z>]
//   без моменту — бойовий Kernel::create() із реальним годинником; з моментом — той самий контролер із RigaClock на цей момент.
// Протокол: друкує "ready", блокується на читанні STDIN (бар'єр), після рядка від батька робить один запит.
// Код виходу 0 — лише якщо відповідь 200.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Controller\MessageController;
use App\Database\DatabaseGuard;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Router;
use App\Support\Db;
use App\Support\RigaClock;

define('APP_ROOT', dirname(__DIR__, 2));
// DB_NAME успадковується від батьківського тесту (orakuls_test); тут він лише перевіряється нижче
require APP_ROOT . '/src/bootstrap.php';

[, $lang, $instant] = $argv + [null, null, null];
if ($lang === null) {
    fwrite(STDERR, "usage: message-worker.php <lang> [<instant ISO 8601 Z>]\n");
    exit(2);
}

try {
    DatabaseGuard::assertTestDatabase(Db::pdo()); // скрипт не повинен випадково писати в робочу БД

    if ($instant === null) {
        $kernel = Kernel::create();
    } else {
        $moment = new DateTimeImmutable($instant, new DateTimeZone('UTC'));
        $router = new Router();
        $router->get('/api/message', [new MessageController(new RigaClock(static fn () => $moment)), 'show']);
        $kernel = new Kernel($router);
    }

    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN); // бар'єр: усі воркери стартують одночасно за сигналом батька

    $response = $kernel->handle(new Request('GET', '/api/message', ['lang' => $lang]));
    if ($response->status !== 200) {
        fwrite(STDERR, "unexpected status {$response->status}: {$response->body}\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
