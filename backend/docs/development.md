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
