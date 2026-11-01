# Архітектура бекенда

## Місце в системі

Фронт і бекенд живуть на одному домені, тому CORS не потрібен:

```
Браузер ──► https://<домен>/
              ├─ /          статика сторінки користувача   (frontend/dist)
              ├─ /admin/    статика адмінки                (frontend/dist/admin)
              └─ /api/*     PHP-бекенд (ця папка)
                               └──► MySQL 5.7
```

Бекенд — stateless JSON API: жодних сесій і cookie, кожен запит самодостатній. Адмін автентифікується токеном у заголовку `Authorization`, перевірка — в `Kernel` до маршрутизації ([D-20](decisions.md)). Контракт — [../../docs/API.md](../../docs/API.md).

## Стек і принципи

- PHP 8.4 + MySQL 5.7 (версії хостингу GoDaddy, див. [deployment.md](deployment.md)).
- **Без фреймворка і без Composer** — власний мінімальний роутер та автозавантаження. На хостинг не треба викладати `vendor/`, а код малий (~10 ендпоінтів). Обґрунтування — в [decisions.md](decisions.md).
- Кожен запит — окремий короткий PHP-процес: стану між запитами немає, з'єднання з БД відкривається лише коли потрібне.
- Усі відповіді — JSON у єдиному форматі, помилки теж ([error-handling.md](error-handling.md)).

## Структура коду

```
backend/
  public/index.php          єдина точка входу
  src/
    bootstrap.php           автозавантаження, .env, UTC, обробники помилок
    routes.php              реєстрація маршрутів
    Http/                   Request, Response, Router, Kernel, ErrorHandler, AdminGuard
    Controller/             обробники ендпоінтів (HealthController, MessageController, AuthController)
    Auth/                   AuthConfig, TokenService, LoginRateLimiter (токен адміна й ліміт спроб)
    Repository/             SQL-запити до таблиць (PhraseRepository)
    Support/                Config, Db, Json, Logger, ApiException, Iso8601, IdList
    Domain/                 Categories, Languages — єдині списки кодів (без залежностей)
    Database/               Migrator, SqlSplitter, SchemaBuilder, Seeder, DatabaseGuard
  bin/                      CLI-скрипти: migrate.php, seed.php, build-schema.php, hash-password.php
  database/
    migrations/             NNN_*.sql — міграції
    schema.sql              повна схема для ручного імпорту (генерується)
    seed/phrases.php        дані сіду (лише для тестової БД)
  docker/mysql-init/        створення БД orakuls_test при першому запуску MySQL
  tests/                    run.php (юніт-перевірки), api.php + api/*.php (тестова БД),
                            smoke.php (по HTTP), lib.php, db-lib.php (спільні хелпери)
  Dockerfile, docker-compose.yml, .env.example
```

Простір імен `App\` відповідає папці `src/`: `App\Http\Router` → `src/Http/Router.php`. Новий клас = новий файл за цим правилом, більше нічого реєструвати не треба.

### Компоненти

| Клас | Відповідальність |
|------|------------------|
| `public/index.php` | Підключає `bootstrap.php`, створює `Kernel`, віддає відповідь. Жодної логіки |
| `bootstrap.php` | Автозавантаження (`spl_autoload_register`), часовий пояс UTC, читання `.env`, реєстрація `ErrorHandler`. Визначає `APP_ROOT` |
| `Http\Kernel` | Збирає роутер із `routes.php`, спершу пропускає запит через `AdminGuard`, потім виконує його; ловить **усі** `Throwable` і перетворює їх на відповідь |
| `Http\AdminGuard` | Захист `/api/admin/*` за замовчуванням закритий (D-20): перевіряє `Bearer`-токен до маршрутизації для будь-якого адмін-шляху, окрім `POST /api/admin/auth/login`. Конфігурацію бере ліниво (замиканням): публічні шляхи її не читають |
| `Http\Router` | Зіставляє метод і шлях (з параметрами `{id}`) з обробником; немає збігу → `404 not_found` |
| `Http\Request` | Незмінний опис запиту: метод, шлях, query, заголовки, JSON-тіло, `ip` (лише `REMOTE_ADDR`, інакше `unknown`). Некоректне тіло → `400` |
| `Http\Response` | Незмінний опис відповіді (статус, тіло, заголовки) і `send()` |
| `Http\ErrorHandler` | Реєструє глобальні обробники помилок; перетворює винятки на JSON-відповіді |
| `Controller\*` | Обробники ендпоінтів: `fn(Request, array $params): Response`. Контролер тонкий: розбирає ввід, викликає репозиторій, формує відповідь |
| `Controller\MessageController` | `GET /api/message`: перевіряє `lang`, розбирає `exclude`, повертає випадкове послання (BE-003) |
| `Controller\AuthController` | `POST /api/admin/auth/login` (порядок: конфігурація → 400 → 422 → 429 → 401/200) і `GET /api/admin/auth/me`; токен не перевіряє — це робить guard (BE-004) |
| `Auth\AuthConfig` | Читає `ADMIN_LOGIN`, `ADMIN_PASSWORD_HASH`, `TOKEN_SECRET`, `TOKEN_TTL_HOURS`; некоректна конфігурація → лог (лише назва змінної) і `500` (fail-closed) |
| `Auth\TokenService` | Випуск і сувора перевірка JWT HS256 без бібліотек (D-21); «поточний час» приходить параметром |
| `Auth\LoginRateLimiter` | Ліміт невдалих спроб входу за IP на таблиці `login_attempts` (D-22) |
| `Repository\PhraseRepository` | Єдине місце SQL до `phrases` (D-17): `findRandom()`, `hasTextSql()`. Отримує `PDO` ззовні |
| `Support\ApiException` | Очікувана помилка API (статус + код + повідомлення + `fields` + додаткові заголовки, напр. `Retry-After`) |
| `Support\Config` | `.env` + змінні процесу |
| `Support\Db` | Ліниве PDO-з'єднання з уніфікованими налаштуваннями сесії |
| `Support\Json` | Кодування JSON за правилами проєкту, `Json::object()` для порожніх об'єктів |
| `Support\Logger` | Обгортка над `error_log()` |
| `Support\Iso8601` | `DATETIME` з БД (UTC) і Unix-час → ISO 8601 з `Z` |
| `Support\IdList` | Розбір списку ID з query (`exclude`): сміття пропускається, повтори прибираються, максимум 200 ID; ніколи не кидає винятків (D-18) |
| `Domain\Categories` | Єдиний список категорій і сувора перевірка коду |
| `Domain\Languages` | Коди мов і білий список для імені колонки `text_*` |
| `Database\Migrator` | Застосовує міграції по одному оператору, веде таблицю `migrations` |
| `Database\SqlSplitter` | Розбиває SQL-файл на оператори (D-15) |
| `Database\SchemaBuilder` | Збирає `schema.sql` з міграцій (D-16) |
| `Database\Seeder` | Заливає сід у тестову БД |
| `Database\DatabaseGuard` | Запобіжник: дозволяє руйнівні дії лише в БД `*_test` |
| `bin/*.php` | CLI-обгортки над цими класами; через веб не виконуються. `hash-password.php` готує `ADMIN_PASSWORD_HASH` зі stdin |

### Правила залежностей

```
Controller ──► Repository, Auth, Http, Support, Domain
Repository ──► Domain, Support, PDO  (нічого з Http)
Http       ──► Auth, Support
Auth       ──► Support, PDO  (нічого з Http / Controller)
Database   ──► Support, Domain, PDO
Support    ──► (нічого з Http / Controller / Repository / Database)
Domain     ──► (ні від чого)
```

`Support` не знає про HTTP: його можна використовувати з будь-якого місця (у т. ч. зі скриптів `bin/`) без запуску веб-шару. `Repository` не знає про HTTP і сам не відкриває з'єднання: `PDO` передає контролер (`Db::pdo()`), тож репозиторій можна запускати і з тестів, і зі скриптів. `Database` не залежить від `Http` і `Controller`, а `Domain` — звичайні константи й чисті функції без залежностей.

Ланцюжок `Support` ← `Auth` ← `Http` без циклів: `Auth` (конфігурація, токен, ліміт) залежить лише від `Support` і `PDO`, тож його можна перевіряти без HTTP, а `Http\AdminGuard` використовує `Auth`, але не навпаки. Контролер авторизації (`AuthController`) бере з `Auth` усе потрібне для входу.

## Життєвий цикл запиту

```
Apache / php -S
  └─ public/index.php
       ├─ bootstrap.php           автозавантаження → .env → ErrorHandler::register()
       ├─ Kernel::create()        Router + маршрути з routes.php
       ├─ Request::fromGlobals()  метод, шлях (без кінцевого «/»), query, заголовки, тіло
       └─ Kernel::handle()
            ├─ AdminGuard::check()    шлях під /api/admin/ (окрім POST …/auth/login) без дійсного токена → 401
            ├─ Router::dispatch() ──► Controller::метод(Request, params) ──► Response
            └─ будь-який Throwable ──► ErrorHandler::toResponse() ──► Response з помилкою
       └─ Response::send()        статус → заголовки → тіло
```

Шляхи в маршрутах пишуться **з префіксом `/api`** (`/api/message`). Так вони однаково працюють і локально (`php -S`), і на хостингу, де `.htaccess` лише спрямовує `/api/*` на точку входу, не змінюючи URL.

## Що ще буде додано

| Компонент | Задача |
|-----------|--------|
| Контролери фраз, валідація фрази (нові методи в `PhraseRepository`) | BE-005 … BE-007 |
| `StatsController` | BE-008 |
| `.htaccess`, точка входу для хостингу | BE-009 |

SQL до таблиць живе в `Repository\*`, а не в контролерах ([D-17](decisions.md)); нові запити до `phrases` додаються в `PhraseRepository`.
