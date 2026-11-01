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
    Http/                 — Request, Response, Router, Kernel, ErrorHandler, AdminGuard
    Controller/           — обробники ендпоінтів
    Auth/                 — AuthConfig, TokenService, LoginRateLimiter (токен адміна, ліміт спроб)
    Repository/           — SQL-запити до таблиць (PhraseRepository)
    Support/              — Config, Db, Json, Logger, ApiException, Iso8601, IdList
    Domain/               — Categories, Languages (єдині списки кодів)
    Database/             — Migrator, SqlSplitter, SchemaBuilder, Seeder, DatabaseGuard
  bin/                    — CLI: migrate.php, seed.php, build-schema.php, hash-password.php
  database/               — migrations/*.sql, schema.sql (генерується), seed/phrases.php (лише тест)
  docker/mysql-init/      — створення тестової БД orakuls_test
  tests/run.php           — юніт-перевірки (без БД)
  tests/api.php           — перевірки схеми й ендпоінтів на тестовій БД orakuls_test (+ tests/api/*.php)
  tests/smoke.php         — перевірка запущеного API по HTTP
  Dockerfile, docker-compose.yml, .env.example
```

## База даних

MySQL 5.7, `utf8mb4` / `utf8mb4_unicode_ci`. Таблиця `phrases` (фраза з трьома мовами, `category` — `VARCHAR(16)` з перевіркою в коді, а не `ENUM`), службові `migrations` і `login_attempts` (невдалі спроби входу адміна для ліміту, BE-004). Дати — UTC. Схема живе в нумерованих міграціях `backend/database/migrations/`, а `backend/database/schema.sql` — повна схема для ручного імпорту, яка **генерується** з міграцій. Докладно — [backend/docs/database.md](../backend/docs/database.md).

```
docker compose exec php php bin/migrate.php                  # застосувати міграції до робочої БД (ідемпотентно)
docker compose exec php php bin/build-schema.php             # перегенерувати schema.sql
docker compose exec php php tests/api.php                    # перевірки на тестовій БД orakuls_test
```

Тестова БД `orakuls_test` створюється разом з основною; сід (відомі дані для тестів) заливається лише в неї, а `bin/seed.php` і `tests/api.php` відмовляються працювати з БД, назва якої не закінчується на `_test`. Робоча `orakuls` після міграцій порожня: реальні фрази завантажує адмін через імпорт. **Одноразово** для вже створеного Docker-volume: `docker compose down -v`, `docker compose up -d --wait`, потім `bin/migrate.php` (інакше `orakuls_test` не з'явиться — init-скрипти MySQL працюють лише при створенні volume).

### Розгортання схеми на хостингу

Без CLI: створити БД і користувача в cPanel (MySQL Databases) → phpMyAdmin → обрати порожню БД → Import → `backend/database/schema.sql`. Сід не імпортується. Подальші зміни схеми — нові `backend/database/migrations/NNN_*.sql`, що виконуються вручну. Покроково — [backend/docs/deployment.md](../backend/docs/deployment.md).

## Локальний запуск

Потрібен Docker Desktop. Усі команди — з папки `backend/`.

```
cp .env.example .env                 # одноразово; .env у git не потрапляє
docker compose up -d --build         # PHP 8.4 на :8000 + MySQL 5.7 на :3307 (хост-порт)
docker compose exec php php tests/smoke.php        # перевірка по HTTP
docker compose run --rm --no-deps php php tests/run.php   # юніт-перевірки
docker compose exec php php bin/migrate.php        # одноразово: створити таблиці в робочій БД
docker compose logs -f php           # логи
docker compose down                  # зупинити (дані БД лишаються у volume; `down -v` — стерти)
```

Змінні оточення описані в `.env.example`; реальні значення (пароль БД, хеш пароля адміна, `TOKEN_SECRET`) — лише в `backend/.env`. Змінні процесу мають пріоритет над файлом.

**Підключення фронта до локального бекенда** (у `frontend/.env.development.local`, див. [FRONTEND.md](FRONTEND.md)):

```
VITE_API_MOCK=false
API_PROXY_TARGET=http://localhost:8000
```

## Автентифікація адміна (BE-004)

Контракт — [API.md](API.md) → «Авторизація». Що має знати фронт:

- **Вхід:** `POST /api/admin/auth/login` з `{ "login", "password" }` → `200 { "token", "expiresAt" }`. Токен передається заголовком `Authorization: Bearer <token>` (схема нечутлива до регістру). Формат токена фронт не розбирає.
- **Перевірка сесії:** `GET /api/admin/auth/me` → `200 { "login": "admin" }`.
- **Термін сесії — 7 діб** (`expiresAt` це відображає). Оновлення токена (refresh) і `logout` на бекенді немає: фронт просто видаляє токен локально. Зміна пароля або секрету на сервері анулює всі токени — тоді будь-який запит дасть `401`, і фронт повертає на форму входу.
- **Коди:**

  | Код | Коли |
  |-----|------|
  | `401 unauthorized` | неправильні логін/пароль (повідомлення однакове, не видно, що саме невірне); на будь-якому `/api/admin/*` — немає, неправильний чи прострочений токен |
  | `422 validation_error` | порожні чи нерядкові `login`/`password`, пароль довший за 1024 байти (`fields`: `login`/`password` → `required`/`too_long`). Такі запити не рахуються як спроби входу |
  | `400 bad_request` | порожнє тіло чи не-JSON |
  | `429 rate_limited` | 5 невдалих спроб з одного IP за 15 хв; заголовок `Retry-After` — ціле число секунд (1…900). Блок знімається сам; правильний пароль під час блоку теж дає `429` |
  | `500 server_error` | на сервері не налаштовано облікові дані (порожні `ADMIN_PASSWORD_HASH`/`TOKEN_SECRET` тощо) — на логіні й на будь-якому `/api/admin/*` |

- **Захист закритий за замовчуванням:** будь-який шлях під `/api/admin/` без дійсного токена дає `401` (навіть неіснуючий), окрім `POST /api/admin/auth/login`. Усі відповіді логіну й `me` мають `Cache-Control: no-store`.
- **Локальний вхід:** `ADMIN_LOGIN=admin`, а пароль кожен розробник задає у своєму `backend/.env` (`bin/hash-password.php`, процедура — [backend/docs/configuration.md](../backend/docs/configuration.md)). На машині розробника бекенда локальний пароль адмінки — `local-dev-password` (це лише приклад для локальної БД; на інших машинах і на проді пароль інший). Для прод-оточення — BE-009.
- Ліміт рахується за IP з'єднання (`REMOTE_ADDR`); спільний IP (офіс, NAT) ділить ліміт. Не надсилайте в автотестах неправильний пароль частіше 4 разів поспіль.

Рішення — [D-20 … D-23](../backend/docs/decisions.md), безпека — [backend/docs/security.md](../backend/docs/security.md).

## Службовий ендпоінт `GET /api/health`

Поза контрактом [API.md](API.md), фронт його не використовує. Призначення — моніторинг і перевірка розгортання (зовнішній монітор на кшталт UptimeRobot; вбудованого health check на cPanel-хостингу GoDaddy немає).

- `200 {"status":"ok"}` — застосунок працює і БД відповідає (`SELECT 1`);
- `503 {"status":"error"}` — БД недоступна (причина лише в логу PHP, без trace);
- версій PHP/MySQL у відповіді немає, `Cache-Control: no-store`.

## Прийняті рішення

- **Помилки.** Усі відповіді з помилкою — `{ "error": { "code", "message", "fields?" } }`. Необроблений виняток і PHP warning → `500 server_error` без stack trace, деталі — у лог. Deprecated-повідомлення PHP лише логуються й запит не ламають.
- **Логи — лише стандартний `error_log()`**, власних файлів логів застосунок не веде (клієнт хостинг не обслуговує, файл без ротації з часом переповнив би квоту). Куди потрапляє лог, визначає конфігурація PHP: у Docker — stderr (`docker compose logs php`), на хостингу — те, що налаштовано в BE-009. Пишемо лише несподівані події (500, падіння БД у health), тож обсяг малий.
- **Невідомий шлях і непідтримуваний метод** на існуючий шлях → `404 not_found` (коду 405 у контракті немає).
- **`GET /api/message`: `exclude` — підказка, а не вимога.** Нечислові, порожні, від'ємні, дробові й задовгі (понад 10 цифр) елементи мовчки пропускаються, повтори прибираються, враховуються перші 200 коректних ID, решта ігнорується; `exclude[]=…` трактується як відсутній. `400` на цьому ендпоінті дає лише `lang` (відсутній, порожній, невідомий, не в нижньому регістрі, масив). Спершу вибираються фрази поза `exclude`; якщо інших немає — повертається будь-яка доступна (`404` лише коли доступних фраз для мови немає взагалі). Відповідь `200` має рівно `id` (число), `lang`, `text` і `Cache-Control: no-store`.
- **Слеш у кінці шляху** ігнорується (`/api/health/` = `/api/health`).
- **Порожнє тіло, не-JSON, не-об'єкт** (число, рядок, список) там, де очікується JSON → `400 bad_request`.
- **Типи в JSON.** Числа числами (нативні prepared statements, `STRINGIFY_FETCHES=false`), порожні асоціативні масиви → `{}` через `Json::object()`. Кирилиця й латиська не екрануються.
- **Сесія MySQL** задається з коду, а не залежить від хостингу: `utf8mb4` / `utf8mb4_unicode_ci`, `time_zone = +00:00`, суворий `sql_mode` (`STRICT_ALL_TABLES`, `ONLY_FULL_GROUP_BY` …).
- **Заголовок `Authorization`** читається з `HTTP_AUTHORIZATION` і з `REDIRECT_HTTP_AUTHORIZATION` (Apache + mod_rewrite).

## Розгортання

_Буде описано в задачі BE-009: де живе, як віддається статика фронта (`dist/` на `/`, `dist/admin/` на `/admin/`), порядок оновлення._
