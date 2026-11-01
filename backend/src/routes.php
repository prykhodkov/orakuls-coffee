<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\MessageController;
use App\Http\Router;

return static function (Router $router): void {
    // Службовий, поза контрактом API.md (див. docs/BACKEND.md)
    $router->get('/api/health', [new HealthController(), 'show']);

    // Публічна частина
    $router->get('/api/message', [new MessageController(), 'show']);

    // Адмінка. Захист /api/admin/* — у Kernel (AdminGuard), контролери перевірку токена не повторюють
    $router->post('/api/admin/auth/login', [new AuthController(), 'login']);
    $router->get('/api/admin/auth/me', [new AuthController(), 'me']);
};
