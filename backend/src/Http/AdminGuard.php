<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthConfig;
use App\Auth\TokenService;
use App\Support\ApiException;
use Closure;

/**
 * Захист /api/admin/* за замовчуванням закритий (D-20): Kernel викликає check() ДО маршрутизації,
 * тож майбутній адмін-ендпоінт неможливо «забути» захистити, а невідомий адмін-шлях без токена дає 401, а не 404.
 * Єдиний виняток — POST /api/admin/auth/login (там токена ще немає).
 */
final class AdminGuard
{
    private const ADMIN_PREFIX = '/api/admin';
    private const LOGIN_PATH = '/api/admin/auth/login';

    /**
     * Строга форма: схема Bearer (регістр не важливий), рівно один пробіл, токен із трьох base64url-частин.
     * Модифікатор D: без нього `$` пропустив би «\n» наприкінці.
     */
    private const AUTHORIZATION_PATTERN = '/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD';

    /**
     * @param Closure(): AuthConfig $configProvider викликається ліниво: публічні шляхи конфігурацію автентифікації
     *                                              не читають, тож помилка в TOKEN_SECRET не кладе /api/message і /api/health
     */
    public function __construct(private readonly Closure $configProvider)
    {
    }

    /**
     * @throws ApiException 401 без дійсного токена; 500 при некоректній конфігурації (доступ не відкривається)
     */
    public function check(Request $request): void
    {
        if (!self::isAdminPath($request->path)) {
            return;
        }
        if ($request->method === 'POST' && $request->path === self::LOGIN_PATH) {
            return;
        }

        $config = ($this->configProvider)(); // може кинути 500

        $header = $request->header('Authorization');
        if ($header === null || preg_match(self::AUTHORIZATION_PATTERN, $header, $matches) !== 1) {
            throw ApiException::unauthorized();
        }

        // Повідомлення завжди однакове: причину відмови (формат, підпис, термін) клієнту не розкриваємо
        if (!(new TokenService($config))->verify($matches[1], time())) {
            throw ApiException::unauthorized();
        }
    }

    private static function isAdminPath(string $path): bool
    {
        return $path === self::ADMIN_PREFIX || str_starts_with($path, self::ADMIN_PREFIX . '/');
    }
}
