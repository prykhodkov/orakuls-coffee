# Бекенд

_Цей файл веде бекенд-розробник (і його агент). Фронт тут нічого не пише — питання до бекенда йдуть у [FRONT-TO-BACK.md](FRONT-TO-BACK.md)._

Код бекенда — у папці `backend/`. Контракт, який має реалізувати бекенд, — [API.md](API.md). План робіт — `backend/docs/tasks/`. Докладна технічна документація (архітектура, помилки, БД, безпека, тести, розгортання, журнал рішень) — [backend/docs/README.md](../backend/docs/README.md).

## Стек

Хостинг — GoDaddy Web Hosting (cPanel, Apache). Версії обрані як найновіші, що підтримує цей хостинг (станом на 2026-11-01):

| Компонент | Версія | Примітка |
|-----------|--------|----------|
| PHP | **8.4** | На хостингу доступні 8.2 / 8.3 / 8.4, типова — 8.3, тож 8.4 треба вибрати вручну в cPanel («Select PHP Version»). PHP 8.1 і старіші — лише платний Extended Support |
| MySQL | **5.7** (5.7.38) | Найновіша для cPanel-хостингу GoDaddy. MySQL 8.0 є лише на Managed WordPress |
| Apache | з хостингу | `.htaccess` доступний, `mod_rewrite` працює |

Локальне оточення має збігатися з цими версіями (PHP 8.4, MySQL 5.7), щоб не ловити розбіжності вже на проді.

### Що випливає з MySQL 5.7

- Немає CTE (`WITH`) і віконних функцій (`ROW_NUMBER() OVER …`) — тільки класичний SQL.
- Кодування — `utf8mb4`, колація `utf8mb4_unicode_ci` (не `utf8mb4_0900_*` — вона з 8.0).
- `ORDER BY RAND() LIMIT 1` для вибору послання прийнятний: у базі ~600 фраз.
- MySQL 5.7 офіційно знято з підтримки (жовтень 2023). Якщо на хостингу з'явиться 8.0 — перейти без переписування, не використовуючи в коді нічого, що є лише в 8.x. Запасний варіант на тому ж хостингу — MariaDB 10.6.

### Джерело версій

Сторінка GoDaddy «Software versions installed on Web Hosting (cPanel)…» і довідка «PHP Extended Support». Автоматично прочитати сторінку не вдалося (403), версії взято зі знімків пошукової видачі, тому **перед стартом розробки перевірити реальні версії в cPanel акаунта** (PHP — «Select PHP Version», MySQL — `SELECT VERSION();` у phpMyAdmin) і за потреби виправити цей файл.

### Фреймворк

Без фреймворка і без Composer: власний мінімальний роутер (`src/Http/Router.php`) і автозавантаження класів через `spl_autoload_register` (`App\Foo\Bar` → `src/Foo/Bar.php`). Причина: ~10 ендпоінтів на shared-хостингу, `vendor/` не треба викладати на сервер.

## Структура

```
backend/
  public/index.php        — єдина точка входу (front controller)
  src/
    bootstrap.php         — автозавантаження, .env, логер, обробники помилок
    routes.php            — реєстрація маршрутів
    Http/                 — Request, Response, Router, Kernel, ErrorHandler
    Controller/           — обробники ендпоінтів
    Support/              — Config, Db, Json, Logger, ApiException
  tests/run.php           — юніт-перевірки (без БД)
  tests/smoke.php         — перевірка запущеного API по HTTP
  Dockerfile, docker-compose.yml, .env.example
```

## Локальний запуск

Потрібен Docker Desktop. Усі команди — з папки `backend/`.

```
cp .env.example .env                 # одноразово; .env у git не потрапляє
docker compose up -d --build         # PHP 8.4 на :8000 + MySQL 5.7 на :3307 (хост-порт)
docker compose exec php php tests/smoke.php        # перевірка по HTTP
docker compose run --rm --no-deps php php tests/run.php   # юніт-перевірки
docker compose logs -f php           # логи
docker compose down                  # зупинити (дані БД лишаються у volume; `down -v` — стерти)
```

Змінні оточення описані в `.env.example`; реальні значення (пароль БД, хеш пароля адміна, `TOKEN_SECRET`) — лише в `backend/.env`. Змінні процесу мають пріоритет над файлом.

**Підключення фронта до локального бекенда** (у `frontend/.env.development.local`, див. [FRONTEND.md](FRONTEND.md)):

```
VITE_API_MOCK=false
API_PROXY_TARGET=http://localhost:8000
```

## Службовий ендпоінт `GET /api/health`

Поза контрактом [API.md](API.md), фронт його не використовує. Призначення — моніторинг і перевірка розгортання (зовнішній монітор на кшталт UptimeRobot; вбудованого health check на cPanel-хостингу GoDaddy немає).

- `200 {"status":"ok"}` — застосунок працює і БД відповідає (`SELECT 1`);
- `503 {"status":"error"}` — БД недоступна (причина лише в логу PHP, без trace);
- версій PHP/MySQL у відповіді немає, `Cache-Control: no-store`.

## Прийняті рішення

- **Помилки.** Усі відповіді з помилкою — `{ "error": { "code", "message", "fields?" } }`. Необроблений виняток і PHP warning → `500 server_error` без stack trace, деталі — у лог. Deprecated-повідомлення PHP лише логуються й запит не ламають.
- **Логи — лише стандартний `error_log()`**, власних файлів логів застосунок не веде (клієнт хостинг не обслуговує, файл без ротації з часом переповнив би квоту). Куди потрапляє лог, визначає конфігурація PHP: у Docker — stderr (`docker compose logs php`), на хостингу — те, що налаштовано в BE-009. Пишемо лише несподівані події (500, падіння БД у health), тож обсяг малий.
- **Невідомий шлях і непідтримуваний метод** на існуючий шлях → `404 not_found` (коду 405 у контракті немає).
- **Слеш у кінці шляху** ігнорується (`/api/health/` = `/api/health`).
- **Порожнє тіло, не-JSON, не-об'єкт** (число, рядок, список) там, де очікується JSON → `400 bad_request`.
- **Типи в JSON.** Числа числами (нативні prepared statements, `STRINGIFY_FETCHES=false`), порожні асоціативні масиви → `{}` через `Json::object()`. Кирилиця й латиська не екрануються.
- **Сесія MySQL** задається з коду, а не залежить від хостингу: `utf8mb4` / `utf8mb4_unicode_ci`, `time_zone = +00:00`, суворий `sql_mode` (`STRICT_ALL_TABLES`, `ONLY_FULL_GROUP_BY` …).
- **Заголовок `Authorization`** читається з `HTTP_AUTHORIZATION` і з `REDIRECT_HTTP_AUTHORIZATION` (Apache + mod_rewrite).

## Розгортання

_Буде описано в задачі BE-009: де живе, як віддається статика фронта (`dist/` на `/`, `dist/admin/` на `/admin/`), порядок оновлення._
