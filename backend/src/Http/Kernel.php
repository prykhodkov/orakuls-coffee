<?php

declare(strict_types=1);

namespace App\Http;

use Throwable;

final class Kernel
{
    public function __construct(private readonly Router $router)
    {
    }

    public static function create(): self
    {
        $router = new Router();
        (require APP_ROOT . '/src/routes.php')($router);

        return new self($router);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return ErrorHandler::toResponse($e);
        }
    }
}
