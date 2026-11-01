<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthConfig;
use Throwable;

final class Kernel
{
    /**
     * @param ?AdminGuard $guard без guard'а Kernel не захищає нічого: так збираються тестові ядра
     *                           з довільними маршрутами; боєве ядро (create()) завжди з guard'ом
     */
    public function __construct(private readonly Router $router, private readonly ?AdminGuard $guard = null)
    {
    }

    public static function create(): self
    {
        $router = new Router();
        (require APP_ROOT . '/src/routes.php')($router);

        return new self($router, new AdminGuard(static fn (): AuthConfig => AuthConfig::fromEnv()));
    }

    public function handle(Request $request): Response
    {
        try {
            // До маршрутизації: невідомий адмін-шлях без токена має дати 401, а не 404 (D-20)
            $this->guard?->check($request);

            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return ErrorHandler::toResponse($e);
        }
    }
}
