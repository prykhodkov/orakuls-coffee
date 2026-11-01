<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Тонка обгортка над стандартним error_log(): місце призначення (stderr у Docker, лог сервера на хостингу)
 * визначає конфігурація PHP, а не застосунок. Власних файлів логів немає.
 */
final class Logger
{
    public static function error(string $message, ?Throwable $e = null): void
    {
        if ($e !== null) {
            $message .= sprintf(
                "\n%s: %s in %s:%d\n%s",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
        }

        error_log($message);
    }
}
