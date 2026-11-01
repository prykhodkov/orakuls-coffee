<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\MessageController;
use App\Controller\PhraseController;
use App\Http\Router;

return static function (Router $router): void {
    // Службовий, поза контрактом API.md (див. docs/BACKEND.md)
    $router->get('/api/health', [new HealthController(), 'show']);

    // Публічна частина
    $router->get('/api/message', [new MessageController(), 'show']);

    // Адмінка. Захист /api/admin/* — у Kernel (AdminGuard), контролери перевірку токена не повторюють
    $router->post('/api/admin/auth/login', [new AuthController(), 'login']);
    $router->get('/api/admin/auth/me', [new AuthController(), 'me']);

    // Фрази (читання і запис). Захист — AdminGuard у Kernel.
    // Літеральні маршрути (імпорт, статистика) реєструвати ПЕРЕД маршрутами з {id}
    $router->get('/api/admin/phrases', [new PhraseController(), 'index']);
    $router->post('/api/admin/phrases', [new PhraseController(), 'create']);
    $router->post('/api/admin/phrases/import', [new PhraseController(), 'import']);
    $router->get('/api/admin/phrases/{id}', [new PhraseController(), 'show']);
    $router->put('/api/admin/phrases/{id}', [new PhraseController(), 'update']);
    $router->delete('/api/admin/phrases/{id}', [new PhraseController(), 'delete']);
};
