# Локальна розробка

## Вимоги

Лише **Docker Desktop**. PHP і MySQL на машині встановлювати не треба: в образах ті ж версії, що на хостингу (PHP 8.4, MySQL 5.7).

## Перший запуск

Усі команди — з папки `backend/`.

```
cp .env.example .env              # одноразово; .env у git не потрапляє
docker compose up -d --build      # збирає образ PHP, піднімає php і db, чекає на готовність БД
```

Після цього API доступне на `http://localhost:8000`:

```
curl http://localhost:8000/api/health        # {"status":"ok"}
```

## Щоденні команди

| Що | Команда |
|----|---------|
| Запустити / зупинити | `docker compose up -d` / `docker compose down` |
| Логи PHP (`error_log`) | `docker compose logs -f php` |
| Юніт-перевірки | `docker compose run --rm --no-deps php php tests/run.php` |
| Перевірки на тестовій БД | `docker compose exec php php tests/api.php` |
| Smoke по HTTP | `docker compose exec php php tests/smoke.php` |
| Хеш пароля адміна для `.env` | `docker compose exec php php bin/hash-password.php` (пароль запитується, не аргумент) |
| Shell у PHP-контейнері | `docker compose exec php bash` |
| SQL-консоль | `docker compose exec db mysql -uorakuls -porakuls orakuls` |
| Стерти БД повністю | `docker compose down -v` (видаляє volume `db_data`) |

Код змонтовано в контейнер (`.:/app`), а `php -S` читає файли при кожному запиті — **перезапускати нічого не треба**. Перебудова образу (`--build`) потрібна лише при зміні `Dockerfile`.

## Міграції, сід і тестова БД

| Що | Команда |
|----|---------|
| Застосувати міграції до робочої БД | `docker compose exec php php bin/migrate.php` |
| Показати застосовані й очікувані міграції (без змін) | `docker compose exec php php bin/migrate.php --status` |
| Перегенерувати `database/schema.sql` з міграцій | `docker compose exec php php bin/build-schema.php` |
| Перевірити, що `schema.sql` актуальний | `docker compose exec php php bin/build-schema.php --check` |
| Залити сід у тестову БД вручну | `docker compose exec -e DB_NAME=orakuls_test php php bin/seed.php` (спершу `bin/migrate.php` з тим самим `-e`) |
| Перевірки схеми й ендпоінтів на тестовій БД | `docker compose exec php php tests/api.php` |

Після `docker compose up` у робочій БД таблиць ще немає — один раз виконай `bin/migrate.php`. Робоча БД лишається без фраз: реальні фрази туди завантажує адмін через імпорт (BE-007). Сід заливається тільки в `*_test`; `bin/seed.php` на `orakuls` відмовиться.

Нова міграція: файл `database/migrations/NNN_snake_case.sql` (правила — [database.md](database.md)), потім `bin/migrate.php` і `bin/build-schema.php`.

Тестова БД `orakuls_test` створюється разом з основною init-скриптом `docker/mysql-init/`. **Одноразово**, якщо Docker-volume створено до BE-002: `docker compose down -v`, потім `docker compose up -d --wait` — init-скрипти MySQL виконуються лише при створенні volume. `down -v` видаляє всі дані локальної БД, тож спершу переконайся, що там немає потрібного (`docker compose exec -T db mysql -uorakuls -porakuls orakuls -e "SHOW TABLES;"`), а після — знову `bin/migrate.php`. Докладніше — [database.md](database.md) і [testing.md](testing.md).

## Вхід адміна локально (BE-004)

Адмінські ендпоінти (`/api/admin/*`) закриті токеном, а без налаштованих облікових даних відповідають `500` (fail-closed). Тому після `cp .env.example .env` один раз налаштуй свої локальні дані (повна процедура й причини — [configuration.md](configuration.md)):

```
printf '%s\n' 'мій-локальний-пароль' | docker compose exec -T php php bin/hash-password.php   # друкує ADMIN_PASSWORD_HASH='…'
docker compose exec php php -r "echo bin2hex(random_bytes(32));"                               # значення для TOKEN_SECRET
```

Рядки `ADMIN_PASSWORD_HASH='…'` (одинарні лапки!) і `TOKEN_SECRET='…'` впиши в `backend/.env` редактором, а не через `echo`/`sed` (оболонка розкриє `$`); `ADMIN_LOGIN=admin`. На машині розробника бекенда локальний пароль — `local-dev-password`: це лише приклад для локальної БД, у `.env` кожного розробника свій, у git він не потрапляє. Для прод-оточення використовується процедура з BE-009.

Отримати токен і викликати адмін-ендпоінт:

```
curl -s -X POST localhost:8000/api/admin/auth/login -H 'Content-Type: application/json' -d '{"login":"admin","password":"мій-локальний-пароль"}'
# → {"token":"…","expiresAt":"…"}   (термін — 7 діб)
curl -s localhost:8000/api/admin/auth/me -H "Authorization: Bearer <token>"       # {"login":"admin"}
```

Не надсилай неправильний пароль більше 4 разів поспіль: на 5-й невдачі IP блокується на 15 хвилин (`429`). Зняти блок локально: `docker compose exec db mysql -uorakuls -porakuls orakuls -e "DELETE FROM login_attempts"`. Усі запити з хоста приходять до контейнера з однієї адреси (шлюз Docker), тож ліміт спільний для всього, що ти запускаєш локально.

## Порти

| Порт | Що |
|------|----|
| `8000` | API (`php -S`) |
| `3307` | MySQL з хоста (`mysql -h127.0.0.1 -P3307 -uorakuls -porakuls orakuls`). Усередині Docker-мережі БД доступна як `db:3306` |

Якщо порт зайнятий — змінити зліва в `docker-compose.yml` (`"8001:8000"`).

## Підключення фронта

У `frontend/.env.development.local`:

```
VITE_API_MOCK=false
API_PROXY_TARGET=http://localhost:8000
```

Деталі — в `docs/FRONTEND.md` → «Робота з локальним бекендом».

## Правила коду

- Кожен PHP-файл починається з `declare(strict_types=1);`.
- Простір імен `App\…` відповідає шляху в `src/` (автозавантаження без Composer): `App\Http\Router` → `src/Http/Router.php`.
- Класи — `final`, якщо не задумано успадкування. Властивості — `readonly`, де можливо.
- Типи параметрів і значень, що повертаються, вказуємо завжди.
- Використовуємо лише можливості PHP 8.4 та MySQL 5.7 (без CTE, віконних функцій).
- SQL — тільки prepared statements ([database.md](database.md)).
- Коментарі — про *чому*, а не *що*; мова коментарів — українська, як у решті проєкту.

## Типові проблеми

| Симптом | Причина й рішення |
|---------|-------------------|
| `error during connect … dockerDesktopLinuxEngine` | Docker Desktop не запущений. Запустити й дочекатися «Engine running» |
| `/api/health` → `503` | БД не піднялась або в `.env` неправильні `DB_*`. Причина — в `docker compose logs php` |
| `Missing required config value: DB_HOST` у логу | Немає `backend/.env` (`cp .env.example .env`) |
| `port is already allocated` | Порт 8000 або 3307 зайнятий — змінити в `docker-compose.yml` |
| Зміни в `.env` не діють | Змінні читаються при кожному запиті, але `DB_*` для контейнера `db` застосовуються лише при створенні volume: `docker compose down -v` і знову `up` |
