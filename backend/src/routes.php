<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Http\Router;

return static function (Router $router): void {
    // Службовий, поза контрактом API.md (див. docs/BACKEND.md)
    $router->get('/api/health', [new HealthController(), 'show']);
};
