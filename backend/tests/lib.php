<?php

declare(strict_types=1);

// Спільна обв'язка для скриптів-перевірок (tests/run.php, tests/api.php) — PHPUnit у проєкті немає.
// Файл не визначає APP_ROOT і не підключає bootstrap: це робить скрипт, що його підключає.

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

/**
 * Реєстр перевірок. Статична змінна, а не глобальна: файли tests/api/*.php підключаються з різних місць.
 *
 * @return array<string, callable>
 */
function &testRegistry(): array
{
    static $tests = [];

    return $tests;
}

function test(string $name, callable $fn): void
{
    $tests = &testRegistry();
    // Повтор імені мовчки перезаписав би попередню перевірку, і вона б зникла з прогону
    if (isset($tests[$name])) {
        throw new LogicException("Duplicate test name: {$name}");
    }
    $tests[$name] = $fn;
}

function assertSame(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(
            ($note !== '' ? "{$note}: " : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $condition, string $note = ''): void
{
    assertSame(true, $condition, $note);
}

/** Перевіряє, що $fn кидає виняток заданого класу, і повертає його для подальших перевірок. */
function assertThrows(callable $fn, string $class = Throwable::class): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionError("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
        }

        return $e;
    }

    throw new AssertionError("expected {$class} to be thrown");
}

function decode(Response $response): array
{
    return json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
}

function kernelWith(callable $configure): Kernel
{
    $router = new Router();
    $configure($router);

    return new Kernel($router);
}

/** Один Kernel на процес: маршрути завантажуються раз, а не на кожен із тисяч запитів. */
function apiKernel(): Kernel
{
    static $kernel = null;

    return $kernel ??= Kernel::create();
}

/**
 * Виклик API в процесі, без HTTP-сервера.
 *
 * @param array<string, mixed>  $query   вже розібраний query ('exclude' => '1,2' або ['1', '2'])
 * @param array<string, string> $headers імена в нижньому регістрі
 */
function apiRequest(string $method, string $path, array $query = [], array $headers = [], string $body = ''): Response
{
    return apiKernel()->handle(new Request($method, $path, $query, $headers, $body));
}

/**
 * Перевіряє рівномірність розподілу вибірок: кожен із $candidates зустрівся, а лічильник кожного
 * у межах ±6σ від середнього. Смуга свідомо широка: шанс хибного спрацювання ~1e-8, тест не повинен «блимати».
 * Смугу не звужувати і кількість вибірок не зменшувати: від неї залежить і надійність, і чутливість
 * (на малій вибірці нижня межа ±6σ пішла б у мінус, і перевірка стала б беззубою).
 *
 * @param list<int>        $candidates
 * @param array<int, int>  $counts id → скільки разів випав
 */
function assertUniform(array $candidates, array $counts, int $draws): void
{
    $n = count($candidates);
    assertTrue($n > 0, 'assertUniform: порожня множина кандидатів');

    $extra = array_diff(array_keys($counts), $candidates);
    assertSame([], array_values($extra), 'випали id поза множиною кандидатів');

    $mean = $draws / $n;
    $sd = sqrt($draws * (1 / $n) * (1 - 1 / $n));
    $low = $mean - 6 * $sd;
    $high = $mean + 6 * $sd;

    foreach ($candidates as $id) {
        $count = $counts[$id] ?? 0;
        assertTrue(
            $count > 0 && $count >= $low && $count <= $high,
            sprintf('id=%d випав %d разів, очікувано в межах [%.1f; %.1f] (середнє %.1f, σ=%.2f)', $id, $count, $low, $high, $mean, $sd)
        );
    }
}

/** Виконує всі зареєстровані перевірки, друкує підсумок і завершує процес кодом 0/1. */
function runTests(): never
{
    $tests = testRegistry();
    $failed = 0;

    foreach ($tests as $name => $fn) {
        try {
            $fn();
            echo "  ok    {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL  {$name}\n        " . $e::class . ': ' . $e->getMessage() . "\n";
        }
    }

    echo sprintf("\n%d тестів, %d провалено\n", count($tests), $failed);
    exit($failed === 0 ? 0 : 1);
}
