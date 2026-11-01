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

Бекенд — stateless JSON API: жодних сесій і cookie, кожен запит самодостатній. Адмін автентифікується токеном у заголовку `Authorization` (планується, BE-004). Контракт — [../../docs/API.md](../../docs/API.md).

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
    Http/                   Request, Response, Router, Kernel, ErrorHandler
    Controller/             обробники ендпоінтів (зараз — HealthController)
    Support/                Config, Db, Json, Logger, ApiException
  tests/                    run.php (юніт-перевірки), smoke.php (по HTTP)
  Dockerfile, docker-compose.yml, .env.example
```

Простір імен `App\` відповідає папці `src/`: `App\Http\Router` → `src/Http/Router.php`. Новий клас = новий файл за цим правилом, більше нічого реєструвати не треба.

### Компоненти

| Клас | Відповідальність |
|------|------------------|
| `public/index.php` | Підключає `bootstrap.php`, створює `Kernel`, віддає відповідь. Жодної логіки |
| `bootstrap.php` | Автозавантаження (`spl_autoload_register`), часовий пояс UTC, читання `.env`, реєстрація `ErrorHandler`. Визначає `APP_ROOT` |
| `Http\Kernel` | Збирає роутер із `routes.php`, виконує запит, ловить **усі** `Throwable` і перетворює їх на відповідь |
| `Http\Router` | Зіставляє метод і шлях (з параметрами `{id}`) з обробником; немає збігу → `404 not_found` |
| `Http\Request` | Незмінний опис запиту: метод, шлях, query, заголовки, JSON-тіло. Некоректне тіло → `400` |
| `Http\Response` | Незмінний опис відповіді (статус, тіло, заголовки) і `send()` |
| `Http\ErrorHandler` | Реєструє глобальні обробники помилок; перетворює винятки на JSON-відповіді |
| `Controller\*` | Обробники ендпоінтів: `fn(Request, array $params): Response` |
| `Support\ApiException` | Очікувана помилка API (статус + код + повідомлення + `fields`) |
| `Support\Config` | `.env` + змінні процесу |
| `Support\Db` | Ліниве PDO-з'єднання з уніфікованими налаштуваннями сесії |
| `Support\Json` | Кодування JSON за правилами проєкту, `Json::object()` для порожніх об'єктів |
| `Support\Logger` | Обгортка над `error_log()` |

### Правила залежностей

```
Controller ──► Http, Support
Http       ──► Support
Support    ──► (нічого з Http / Controller)
```

`Support` не знає про HTTP: його можна використовувати з будь-якого місця (у т. ч. зі скриптів міграцій, BE-002) без запуску веб-шару.

## Життєвий цикл запиту

```
Apache / php -S
  └─ public/index.php
       ├─ bootstrap.php           автозавантаження → .env → ErrorHandler::register()
       ├─ Kernel::create()        Router + маршрути з routes.php
       ├─ Request::fromGlobals()  метод, шлях (без кінцевого «/»), query, заголовки, тіло
       └─ Kernel::handle()
            ├─ Router::dispatch() ──► Controller::метод(Request, params) ──► Response
            └─ будь-який Throwable ──► ErrorHandler::toResponse() ──► Response з помилкою
       └─ Response::send()        статус → заголовки → тіло
```

Шляхи в маршрутах пишуться **з префіксом `/api`** (`/api/message`). Так вони однаково працюють і локально (`php -S`), і на хостингу, де `.htaccess` лише спрямовує `/api/*` на точку входу, не змінюючи URL.

## Що ще буде додано

| Компонент | Задача |
|-----------|--------|
| Таблиця `phrases`, міграції, runner (`bin/migrate.php`), тестова БД `orakuls_test` зі сідом, `tests/api.php` | BE-002 |
| `MessageController` (`GET /api/message`) | BE-003 |
| Авторизація: `AuthController`, middleware для `/api/admin/*`, токен, таблиця `login_attempts` (власна міграція) | BE-004 |
| Контролери фраз, валідація фрази | BE-005 … BE-007 |
| `StatsController` | BE-008 |
| `.htaccess`, точка входу для хостингу | BE-009 |

Коли з'являться запити до таблиці `phrases`, SQL варто виносити з контролерів в окремі класи (напр. `Repository\PhraseRepository`), щоб контролер лишався тонким. Рішення зафіксуємо в [decisions.md](decisions.md) при реалізації BE-003/BE-005.
