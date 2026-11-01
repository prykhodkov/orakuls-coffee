# Помилки та логування

## Формат відповіді

Усі помилки (4xx, 5xx) мають однакову структуру, як у [API.md](../../docs/API.md):

```json
{ "error": { "code": "validation_error", "message": "…", "fields": { "text.lv": "too_long" } } }
```

- `fields` є **лише** у `validation_error` і завжди об'єкт (`{}`, а не `[]`).
- Помилки віддаються з `Cache-Control: no-store`.
- Усі відповіді мають `Content-Type: application/json; charset=utf-8`, у тому числі `404` для неіснуючого `/api/…` — не HTML-сторінка Apache.

## Як кинути помилку з коду

`Support\ApiException` має фабрики на кожен код контракту:

| Фабрика | HTTP | `code` |
|---------|------|--------|
| `ApiException::badRequest()` | 400 | `bad_request` |
| `ApiException::unauthorized()` | 401 | `unauthorized` |
| `ApiException::notFound()` | 404 | `not_found` |
| `ApiException::validation(['text.lv' => 'too_long'])` | 422 | `validation_error` |
| `ApiException::rateLimited(?int $retryAfterSeconds = null)` | 429 | `rate_limited` |
| `ApiException::server()` | 500 | `server_error` |

```php
throw ApiException::validation(['category' => 'invalid']);
throw ApiException::rateLimited(120);   // 429 + Retry-After: 120
```

**Заголовки помилки.** Конструктор `ApiException` має необов'язковий останній параметр `$headers` (`array<string, string>`); `ErrorHandler` додає їх до відповіді. `Cache-Control: no-store` він ставить сам і **не дозволяє** заголовкам винятку його замінити — вони лише доповнюють. Зараз ним користується лише `rateLimited()`: `Retry-After` — ціле число секунд, мінімум `1` (`0` і від'ємні значення піднімаються до `1`); без аргументу заголовка немає.

**Помилки автентифікації (BE-004).**

| Ситуація | Відповідь |
|----------|-----------|
| Немає / неправильний / прострочений токен на `/api/admin/*` | `401 unauthorized`, `Unauthorized` — причина не розкривається |
| Неправильний логін **або** пароль | `401 unauthorized`, `Invalid credentials` — однаково для обох |
| Порожнє тіло / не-JSON логіну | `400 bad_request` |
| Порожні, нерядкові поля або пароль > 1024 байт | `422 validation_error`, `fields`: `login: required`, `password: required \| too_long` |
| 5 невдалих входів з IP за 15 хв | `429 rate_limited` + `Retry-After` (1…900 с) |
| Некоректна конфігурація автентифікації | `500 server_error` на логіні **і** на будь-якому адмін-шляху (доступ не відкривається); у `error_log` один рядок `Auth config invalid: <ЗМІННА> …` без значень |

Контролер просто кидає виняток; перетворення на відповідь робить `Kernel`. Ключі в `fields` — шлях до поля в тілі запиту (`category`, `text.lv`), значення — машинний код (`required`, `invalid`, `too_long`, `empty`).

## Що відбувається з іншими помилками

| Ситуація | Результат |
|----------|-----------|
| `ApiException` | Відповідь зі статусом і кодом винятку |
| Будь-який інший `Throwable` у обробнику (збій БД, баг) | `500 server_error`, текст `Internal server error`; деталі й trace — у лог |
| PHP warning / notice | Перетворюється на `ErrorException` → `500`. Помилка не лишається непоміченою |
| PHP deprecated | Лише пишеться в лог, запит не ламається (на хостингу може бути інша мінорна версія PHP) |
| Фатальна помилка PHP (поза `try/catch`) | Shutdown-обробник лог + `500`, якщо заголовки ще не відправлені |
| Виняток поза `Kernel` (напр. у `routes.php`) | `set_exception_handler` віддає JSON `500` |
| Невідомий шлях або метод, якого немає для шляху | `404 not_found` (коду 405 у контракті немає) |
| Порожнє тіло, не-JSON, не-об'єкт | `400 bad_request` (`Request::json()`) |
| Параметр запиту-масив (`?a[]=1`) | `400 bad_request` (`Request::query()`) |

Клієнт ніколи не бачить stack trace, шляхів до файлів, тексту SQL-помилок чи паролів. Це забезпечують: `display_errors=0`, `zend.exception_ignore_args=1` (у trace немає аргументів) і єдиний шлях `ErrorHandler::toResponse()`.

**Обмеження:** `ErrorHandler` реєструється наприкінці `bootstrap.php`. Збій у самому автозавантаженні чи читанні `.env` до цього моменту дасть стандартну відповідь PHP, а не JSON.

## Логування

Власних файлів логів застосунок **не веде** — лише стандартний `error_log()` (через `Support\Logger::error()`). Куди він пише, визначає конфігурація PHP:

- Docker: stderr → `docker compose logs -f php`;
- хостинг: налаштовується в BE-009 (мета — без публічного файлу й без безмежного росту).

Що пишемо: несподівані винятки (500), фатальні помилки, deprecated, збій БД у `/api/health` (одним рядком, без trace). Звичайні 4xx — не логуються. Тому обсяг логу малий і росте лише при реальних проблемах.

Не логувати: паролі, токени, хеші, `TOKEN_SECRET`, `Authorization`, тіла запитів (повідомлення про некоректну конфігурацію автентифікації містить лише назву змінної; невдалі входи — це 4xx, тож не логуються, а рахуються в `login_attempts`). Обґрунтування вибору — [decisions.md](decisions.md) (D-06).
