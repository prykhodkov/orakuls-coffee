<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\ApiException;
use App\Support\Json;
use App\Support\Logger;
use ErrorException;
use Throwable;

final class ErrorHandler
{
    public static function register(): void
    {
        ini_set('display_errors', '0');
        ini_set('zend.exception_ignore_args', '1'); // у trace не потрапляють аргументи (паролі, токени)
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // Deprecated не повинен ламати запит (інша мінорна версія PHP на хостингу) — лише в лог.
            if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
                Logger::error("Deprecated: {$message} in {$file}:{$line}");

                return true;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // Виняток поза Kernel (напр. збій у bootstrap) теж віддається як JSON, а не порожня сторінка.
        set_exception_handler(static function (Throwable $e): void {
            self::toResponse($e)->send();
        });

        // Фатальні помилки не проходять через try/catch.
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            Logger::error("Fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
            if (!headers_sent()) {
                self::build(500, 'server_error', 'Internal server error')->send();
            }
        });
    }

    public static function toResponse(Throwable $e): Response
    {
        if ($e instanceof ApiException) {
            return self::build($e->status, $e->errorCode, $e->getMessage(), $e->fields);
        }

        // Деталі лише в лог — клієнту без stack trace і внутрішніх шляхів.
        Logger::error('Unhandled exception', $e);

        return self::build(500, 'server_error', 'Internal server error');
    }

    /**
     * @param array<string, string> $fields
     */
    private static function build(int $status, string $code, string $message, array $fields = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($code === 'validation_error') {
            $error['fields'] = Json::object($fields);
        }

        return Response::json(['error' => $error], $status, ['Cache-Control' => 'no-store']);
    }
}
