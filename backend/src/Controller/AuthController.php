<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AuthConfig;
use App\Auth\LoginRateLimiter;
use App\Auth\TokenService;
use App\Http\Request;
use App\Http\Response;
use App\Support\ApiException;
use App\Support\Db;
use App\Support\Iso8601;

/**
 * Вхід адміна. Перевірку токена для решти /api/admin/* виконує AdminGuard у Kernel (D-20),
 * контролери її не повторюють.
 */
final class AuthController
{
    /** bcrypt усе одно ігнорує байти після 72-го, але довгі рядки не обробляємо взагалі. */
    private const MAX_PASSWORD_BYTES = 1024;

    /**
     * POST /api/admin/auth/login. Порядок кроків важливий (D-23): конфігурація → тіло (400) → валідація (422) →
     * ліміт (429) → пароль. Відмови на перших кроках у спроби не рахуються; заблокований запит нічого не пише
     * і не запускає password_verify (bcrypt ≈170 мс CPU на запит).
     */
    public function login(Request $request, array $params): Response
    {
        $config = AuthConfig::fromEnv();

        $data = $request->json(); // порожнє тіло, не-JSON, не-об'єкт → 400

        $login = $data['login'] ?? null;
        $password = $data['password'] ?? null;
        $fields = [];
        if (!is_string($login) || $login === '') {
            $fields['login'] = 'required';
        }
        if (!is_string($password) || $password === '') {
            $fields['password'] = 'required';
        } elseif (strlen($password) > self::MAX_PASSWORD_BYTES) {
            $fields['password'] = 'too_long';
        }
        if ($fields !== []) {
            throw ApiException::validation($fields);
        }

        $limiter = new LoginRateLimiter(Db::pdo());
        $limiter->purge();
        $retryAfter = $limiter->retryAfter($request->ip);
        if ($retryAfter !== null) {
            throw ApiException::rateLimited($retryAfter);
        }

        // Обидві перевірки виконуються завжди, без короткого замикання: інакше неправильний логін
        // відповідав би на ~170 мс швидше і час відповіді розкривав би, чи існує такий логін.
        $loginOk = hash_equals($config->login, $login);
        $passwordOk = password_verify($password, $config->passwordHash);
        if (!($loginOk && $passwordOk)) {
            $limiter->recordFailure($request->ip);

            throw ApiException::unauthorized('Invalid credentials'); // однакове повідомлення для обох причин
        }

        $issued = (new TokenService($config))->issue(time());

        return Response::json(
            ['token' => $issued['token'], 'expiresAt' => Iso8601::fromTimestamp($issued['expiresAt'])],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    /**
     * GET /api/admin/auth/me. Токен уже перевірено AdminGuard; сюди дійде лише дійсна сесія.
     */
    public function me(Request $request, array $params): Response
    {
        return Response::json(['login' => AuthConfig::fromEnv()->login], 200, ['Cache-Control' => 'no-store']);
    }
}
