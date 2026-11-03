# BE-011 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та критерії приймання — у [task.md](task.md) (прочитай його першим: усі рішення там прийняті, не переглядай їх). Контракт — запит F-005 у `docs/FRONT-TO-BACK.md`; `docs/API.md` **не змінювати** (правка за правилом 5 — на боці фронта).

Ціль: таблиця лічильників `daily_stats` + спільний клас доступу до неї `DailyStatsRepository` + визначення «дня за Ригою» в PHP (`RigaClock`) + публічний `POST /api/visit` (`VisitController`). Таблицю і клас **спільно використовують BE-012 і BE-013** — їхню форму фіксує цей план (розділ 3).

Мова коментарів у коді і документації — українська, стиль — як у сусідніх файлах (`MessageController`, `LoginRateLimiter`, `PhraseRepository`, `Iso8601`).

## 0. Передумови

- Docker-контейнери `php` і `db` запущені. Усі команди — з `C:\rep\orakuls-coffee\backend`, з абсолютним `-f C:\rep\orakuls-coffee\backend\docker-compose.yml` (робочий каталог інколи скидається — завжди `cd` явно).
- **Базова лінія** (до твоїх змін): зафіксуй фактичні числа `tests/run.php`, `tests/api.php`, `tests/smoke.php` (за `docs/testing.md` після BE-008: 105 / 143 / 25). BE-012 і BE-013 пишуться паралельно й можуть змінити ці числа раніше за тебе — рахуй дельту від того, що побачиш сам, і додавай її до чисел у `testing.md`, а не підміняй їх.
- PHP-код пиши інструментами Write/Edit (не heredoc через bash: зворотні слеші ламаються).
- Нічого не коміть (`git add/commit/stash/reset/checkout` заборонені). **Жоден `docs/tasks/**` файл не редагуй** — їх оновить замовник. Не чіпай `frontend/`, `docs/API.md`.
- Нових змінних оточення немає (`.env.example`, `configuration.md` не змінюються).
- Дев-БД `orakuls` (там: `phrases` 0 рядків, `migrations` 2, `login_attempts` 0): єдина дозволена зміна — застосувати нову міграцію `bin/migrate.php` (створює порожню таблицю; цього вимагає «міграція застосовується поверх поточної БД»). Даних туди не писати: наскрізні перевірки — на `orakuls_test`.

## 1. Перевірені факти (не перевіряй вдруге, спирайся на них)

Позначка «перевірити» — очікування, яке варто підтвердити одним експериментом на кроці 0 (скрипт у scratchpad, не в репозиторій).

| № | Факт | Наслідок |
|---|------|----------|
| 1 | `Request::json()` кидає `400` для порожнього/пробільного тіла, не-JSON, JSON-не-об'єкта (число, рядок, `null`, `true`, **непорожній** список). `json_decode` не відрізняє `{}` від `[]`: обидва дають `[]`, а перевірка `$data !== [] && array_is_list($data)` пропускає порожній масив | `{}` **і** `[]` — порожній об'єкт → `204` як `direct` (так само, як `items: {}` у BE-007, D-31). Це відхилення від «JSON-масив → 400» вже **погоджене замовником і внесене в AC `task.md`**: `["qr"]`, `[1]` дають `400`, а `[]` — `204`. Записати в D-33, `api-conventions.md`; тест фіксує поведінку |
| 2 | `Request::json()` ігнорує `Content-Type` | `sendBeacon`/`text/plain` теж працюють; окремої перевірки типу вмісту не робимо |
| 3 | Роутер: невідомий метод/шлях → `404 not_found` (D-05); `AdminGuard` діє лише на `/api/admin*` | `GET/PUT/DELETE/PATCH/HEAD /api/visit` → `404` без коду; токен для `POST /api/visit` не потрібен і не перевіряється |
| 4 | `Response::json()` не додає `Cache-Control`; `ErrorHandler` додає `no-store` усім помилкам; `Response::noContent()` не має заголовків (D-29 вже використовує `new Response(204, null, ['Cache-Control' => 'no-store'])`) | `204` будуємо як `new Response(204, null, ['Cache-Control' => 'no-store'])` |
| 5 | `bootstrap.php` ставить `date_default_timezone_set('UTC')`; сесія MySQL — `time_zone = '+00:00'` (`Db::pdo()`) | День за Ригою **ніколи** не береться з `date()`/`NOW()`/`CURDATE()`: лише `DateTimeImmutable` з явною `DateTimeZone('Europe/Riga')` |
| 6 | Часові пояси PHP (`Europe/Riga`) беруться з бази tzdata самого PHP (timelib), а не з ОС і не з MySQL `mysql.time_zone*` | Працює на shared-хостингу без налаштувань; розбіжність можлива лише при зміні правил DST, яку старий PHP не знає (ризик 9.6) |
| 7 | Riga: зима EET = UTC+2, літо EEST = UTC+3; переходи — остання неділя березня/жовтня о 01:00 UTC. 2026-03-29 (неділя): 03:00 → 04:00 (доба 23 год); 2026-10-25 (неділя): 04:00 → 03:00 (доба 25 год, година 03:xx повторюється) | Межі дня в UTC: зима `22:00Z` попереднього дня, літо `21:00Z`. Таблиця тестових моментів — крок 6 (R1/V11). **Перевірити** скриптом на PHP 8.4 з контейнера |
| 8 | Lone `INSERT … ON DUPLICATE KEY UPDATE` на PK без AUTO_INCREMENT атомарний (рядковий замок InnoDB), працює на MySQL 5.7. Багаторядкова версія `VALUES (?, ?, 1), (?, ?, 1)` теж. **Перевірити** | Один оператор на запит `/api/visit` (і `visit`, і `scan` разом) — `scans ≤ visits` не порушується навіть при збої посередині |
| 9 | PDO (нативні prepared, `STRINGIFY_FETCHES=false`): `DATE` повертається рядком `'2026-11-03'`, `INT UNSIGNED` — `int`. **Перевірити** | `rangeTotals()` віддає `day` як `string`, `count` як `int` (все одно `(int)`) |
| 10 | `count` — не зарезервоване слово MySQL, але назва функції | У всіх запитах брати ім'я колонки в зворотні лапки: `` `count` `` (як у решті проєкту) |
| 11 | `tests/api.php` кожного разу скидає БД (`resetTestDatabase` → `Migrator::up()` → `reseed()`), `reseed()` лише `TRUNCATE phrases` + сід; `Seeder::run()` повертає `key → id` | Нову таблицю тести отримають автоматично (міграція); `reseed()` додатково чистить `daily_stats` (крок 5). Сіду з історією тут **немає** (рішення замовника: історія для BE-013 — окрема фікстура на вимогу, її робить BE-013) |
| 12 | `tests/api/database.php:31` жорстко перевіряє список таблиць `['login_attempts', 'migrations', 'phrases']`; є тести паритету `schema.sql` ↔ міграції і `up()` | Рядок оновити (+ `daily_stats`), додати тест структури таблиці (крок 1) |
| 13 | `kernelWith(fn (Router $r) => …)` створює `Kernel` з довільними маршрутами **без** `AdminGuard`; `apiKernel()` — боєві маршрути | Контролер із підміненим годинником тестується через `kernelWith`; боєвий маршрут — через `apiRequest()` |
| 14 | `statementCount()` рахує виконання prepared statements; відхилені до БД запити дають `0` | `POST /api/visit`: успіх — рівно **1** запит (і для `qr`, і для `direct`), `400` — **0** |
| 15 | Тестовий `php -S` однопотоковий; в одному PHP-процесі «паралельних» запитів не буває | 50 паралельних запитів — 50 окремих CLI-процесів (`proc_open`) зі спільним бар'єром (крок 6, V12) |
| 16 | `Logger::error()` пише повідомлення, клас, текст винятку, файл/рядок і trace — **без** IP, заголовків і тіла запиту | Успішний шлях нічого не логує; на `500` у лог іде лише виняток. Тест V14 це закріплює |

## 2. Проєктні рішення

- **Таблиця `daily_stats`** — рівно пропозиція F-005: `day DATE`, `metric VARCHAR(16)`, `count INT UNSIGNED NOT NULL DEFAULT 0`, `PRIMARY KEY (day, metric)`, InnoDB, `utf8mb4_unicode_ci`. Метрики: `visit`, `scan`, `message_lv`, `message_en`, `message_ru`. Міграція `003_create_daily_stats.sql`. Окремих індексів немає: діапазон за `day` обслуговує префікс PK.
- **Лічильник атомарний, один оператор на запит:** `INSERT INTO daily_stats (day, metric, count) VALUES (?, ?, 1)[, (?, ?, 1)] ON DUPLICATE KEY UPDATE count = count + 1`. Метрики в багаторядковому варіанті сортуються за назвою (єдиний порядок захоплення замків), повтори прибираються. Транзакції, `SELECT … FOR UPDATE`, читання-потім-запис — **заборонені** (губили б відкриття при паралельних запитах).
- **«День» = `Y-m-d` за `Europe/Riga`, обчислений у PHP.** Клас `App\Support\RigaClock` (джерело часу — замикання, за прикладом «поточний час приходить параметром» у `TokenService`). Нічого в БД не залежить від часового поясу сесії MySQL.
- **Метрики — у `App\Domain\StatMetrics`** (єдиний білий список, за зразком `Categories`/`Languages`): `DailyStatsRepository` не приймає невідомої метрики (`InvalidArgumentException` — це помилка програміста, а не вводу, тож `500`, а не `400`). Рядок метрики в SQL завжди йде **параметром**, не конкатенацією.
- **`source`:** береться лише з тіла запиту (`$data['source'] ?? null`); `=== 'qr'` — сканування (суворо, з урахуванням регістру: `"QR"`, `" qr"` — це `direct`), усе інше — `direct`. Адреса запиту (`?qr`), `Referer`, `User-Agent`, IP **не читаються**.
- **Порядок у контролері:** `Request::json()` (`400` до звернення до БД) → `Db::pdo()` → `incrementMany()` → `204`. Збій БД не ловиться: `ErrorHandler` віддає `500 server_error`, деталі в лог. Жодного власного `try/catch`, жодного логування на успішному шляху.
- **Відповідь:** `new Response(204, null, ['Cache-Control' => 'no-store'])`.
- **Захисту від накрутки, ліміту за IP, кешу немає** (рішення F-005 і task.md). Повторного retry на deadlock не робимо (ризик 9.4).
- **Сіду `daily_stats` немає.** Після `reseed()` таблиця **порожня**: `reseed()` в `tests/db-lib.php` додатково виконує `TRUNCATE TABLE daily_stats` (це тестовий помічник на `orakuls_test`, `Seeder::run()` і `bin/seed.php` не змінюються — інакше `bin/seed.php` стирав би статистику дев-БД). Історію днів для звіту BE-013 вантажить власна фікстура BE-013 (`database/seed/daily_stats.php`, `Seeder::runStats()`), **BE-011 цього файлу не створює** — інакше два виконавці створили б один шлях із різними форматами. Тести BE-011 однаково порівнюють знімки «до/після» і не залежать від вмісту таблиці.
- **Нове D-33** у `decisions.md` (розділ 7). Якщо номер уже зайнято іншим агентом (BE-012/BE-013 пишуть паралельно) — візьми наступний вільний і виправ посилання.

## 3. Інтерфейс для BE-012/BE-013 (спільний — не змінювати без узгодження)

Розміщення й імена збігаються з пропозицією координатора; **розбіжності: (а) додано `StatMetrics` і `RigaClock::isDay()`; (б) додано `incrementMany()` — `increment()` її делегує; (в) `RigaClock` лежить у `App\Support`, а не в `Domain`.** Усі три — тому що вимагає проєктна конвенція (білі списки в `Domain`, «чисті» утиліти без залежностей у `Support`, `Repository` отримує `PDO` ззовні).

```php
namespace App\Support;

final class RigaClock                                   // src/Support/RigaClock.php
{
    public const TIMEZONE = 'Europe/Riga';              // значення поля "timezone" у відповіді BE-013

    /** @param ?\Closure(): \DateTimeImmutable $source  підміна годинника в тестах; null → реальний час */
    public function __construct(?\Closure $source = null);

    public function now(): \DateTimeImmutable;          // поточний момент у зоні Europe/Riga
    public function today(): string;                    // 'YYYY-MM-DD' за Ригою — «сьогодні»
    public static function dayOf(\DateTimeImmutable $moment): string;  // будь-яка зона → 'YYYY-MM-DD' за Ригою
    public static function isDay(string $value): bool;  // сувора перевірка 'YYYY-MM-DD' + реальна дата (2026-02-30 → false)
}

namespace App\Domain;

final class StatMetrics                                 // src/Domain/StatMetrics.php
{
    public const VISIT = 'visit';
    public const SCAN = 'scan';
    public const CODES = ['visit', 'scan', 'message_lv', 'message_en', 'message_ru'];  // порядок фіксований

    public static function isValid(string $metric): bool;       // сувора (регістр, пробіли)
    public static function message(string $lang): string;       // 'lv' → 'message_lv'; невідома мова → InvalidArgumentException
}

namespace App\Repository;

final class DailyStatsRepository                        // src/Repository/DailyStatsRepository.php
{
    public function __construct(\PDO $pdo, RigaClock $clock = new RigaClock());

    /** +1 до лічильника метрики за день моменту $now (за Ригою); null → $clock->now(). Невідома метрика → InvalidArgumentException, БД не чіпається. */
    public function increment(string $metric, ?\DateTimeImmutable $now = null): void;

    /** Те саме для кількох метрик одним атомарним оператором (повтори — один раз; порожній список — нічого, 0 запитів). */
    public function incrementMany(array $metrics, ?\DateTimeImmutable $now = null): void;

    /**
     * Рядки таблиці за [from; to] включно (обидва 'YYYY-MM-DD'), один запит, ORDER BY day, metric.
     * Дні без жодного лічильника в результат НЕ входять (порожні дні доповнює викликач).
     * Невалідна дата (RigaClock::isDay) або from > to → InvalidArgumentException.
     * @return list<array{day: string, metric: string, count: int}>
     */
    public function rangeTotals(string $from, string $to): array;
}
```

Як цим користуватись:

- **BE-012** (`GET /api/message`, успіх): `(new DailyStatsRepository(Db::pdo()))->increment(StatMetrics::message($lang))` усередині власного `try/catch (Throwable)` + `Logger::error()` — збій не ламає видачу (це правило BE-012, не цього класу: `increment()` кидає виняток). Для тестів «день за Ригою» BE-012 передає свій `RigaClock` у контролер так само, як це робить `VisitController` (конструктор `__construct(private readonly RigaClock $clock = new RigaClock())`, у `increment()` передається `$this->clock->now()`).
- **BE-013** (`GET /api/admin/visits`): «сьогодні» — `(new RigaClock())->today()`; валідація `from`/`to` — `RigaClock::isDay()` (до звернення до БД), поле `timezone` — `RigaClock::TIMEZONE`; читання — `rangeTotals($from, $to)` (рівно 1 запит). **Арифметику днів (послідовність від `from` до `to`, довжина періоду ≤ 366) робити на «чистих» датах у UTC** (`new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'))`, `+1 day`), а не в зоні Риги: це виключає будь-які сюрпризи на добах 23/25 годин. Метрики з рядків групувати за `StatMetrics::CODES`; `messages` = сума `message_*`.
- **Не змінювати:** назви класів/методів, формат рядка `{day, metric, count}`, назви метрик, назву таблиці й колонок. Потрібна нова операція (наприклад, `decrement`, видалення) — окрема домовленість.
- **Імена в тестах:** `tests/api/visit.php` (цей план) визначає глобальні функції з префіксом `visit*` і константу `VISIT_PATH`; BE-012/BE-013 у своїх тест-файлах беруть інші префікси (`msgCount*`, `report*`) — дублікат імені функції в PHP фатальний, а `test()` кидає `LogicException` на повтор назви тесту.

## 4. Карта файлів

Створити:
- `database/migrations/003_create_daily_stats.sql`
- `src/Support/RigaClock.php`
- `src/Domain/StatMetrics.php`
- `src/Repository/DailyStatsRepository.php`
- `src/Controller/VisitController.php`
- `tests/api/visit.php`
- `tests/workers/visit-worker.php` (робочий процес для паралельного тесту; **не** в `tests/api/`, бо `tests/api.php` підключає всі файли звідти; каталог `tests/workers/` спільний із BE-012 — `message-worker.php`)

Змінити:
- `database/schema.sql` (лише перегенерацією `php bin/build-schema.php`)
- `src/routes.php` (1 маршрут)
- `tests/db-lib.php` (`reseed()` після `Seeder::run()` виконує `TRUNCATE TABLE daily_stats`)
- `tests/api/database.php` (список таблиць + структура `daily_stats` + міграція поверх)
- `tests/run.php` (нові юніт-тести, розділ 6)
- `tests/smoke.php` (+4 перевірки)
- Документація: `backend/docs/decisions.md`, `architecture.md`, `api-conventions.md`, `database.md`, `testing.md`, `security.md`, `error-handling.md` (рядок), `docs/BACKEND.md`, `docs/FRONT-TO-BACK.md` (відповідь на F-005)

Не чіпати: `frontend/`, `docs/API.md`, `docs/BACK-TO-FRONT.md`, `src/Http/*`, `src/Auth/*`, `MessageController` (його змінює BE-012), `PhraseController`, `PhraseRepository`, існуючі міграції `001`, `002`, `docker-compose.yml`, `.env.example`, `docs/tasks/**`.

## 5. Реалізація

### Крок 1. Міграція і схема

`database/migrations/003_create_daily_stats.sql` (стиль — як `002`: коментар зверху, `IF NOT EXISTS`, один оператор):

```sql
-- Лічильники по днях (BE-011): лише кількості, без персональних даних.
-- day: календарна дата за Europe/Riga, її рахує PHP (RigaClock), а не MySQL.
-- metric: visit | scan | message_lv | message_en | message_ru (білий список — Domain\StatMetrics).
CREATE TABLE IF NOT EXISTS `daily_stats` (
  `day` DATE NOT NULL,
  `metric` VARCHAR(16) NOT NULL,
  `count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Далі: `php bin/build-schema.php` (перегенерація `schema.sql`), `php bin/build-schema.php --check` — зелений. `php bin/migrate.php` на дев-БД (див. §0). У `tests/api/database.php`: (1) у тесті про список таблиць додати `daily_stats` (порядок `ORDER BY TABLE_NAME`: `daily_stats, login_attempts, migrations, phrases`) і поправити назву тесту; (2) новий тест структури `daily_stats` за зразком тесту `login_attempts`: колонки рівно `day, metric, count`; типи `date`, `varchar(16)`, `int(10) unsigned`; усі `NOT NULL`; `count` має `DEFAULT 0`, інші — без; PK рівно `(day, metric)` у цьому порядку; InnoDB; `utf8mb4_unicode_ci`; жодних інших індексів; (3) новий тест «003 застосовується поверх БД із 001+002»: `DROP TABLE daily_stats` + `DELETE FROM migrations WHERE name = '003_…'` → `testMigrator()->up()` повертає `['003_create_daily_stats.sql']`, таблиця є і порожня, повторний `up()` → `[]`; сам SQL міграції виконується двічі без помилки (`IF NOT EXISTS`). У кінці тесту стан відновлено (таблиця є, запис у `migrations` є) і викликано `reseed()`.

### Крок 2. `RigaClock` і `StatMetrics`

`RigaClock`:
- `private static function zone(): DateTimeZone` → `new DateTimeZone(self::TIMEZONE)` (не кешувати в статичному полі без потреби; створення дешеве).
- `now()`: `($this->source ?? static fn () => new DateTimeImmutable('now', new DateTimeZone('UTC')))()` → `->setTimezone(self::zone())`. Замикання, що повертає не `DateTimeImmutable`, — `LogicException` (помилка тесту).
- `today()` = `now()->format('Y-m-d')`; `dayOf($m)` = `$m->setTimezone(self::zone())->format('Y-m-d')`.
- `isDay($v)`: `preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)` і `DateTimeImmutable::createFromFormat('!Y-m-d', $v, UTC)` з перевіркою round-trip `format('Y-m-d') === $v` (як `Iso8601::fromDb`): відсікає `2026-02-30`, `2026-13-01`, `2026-1-1`, `"2026-11-03\n"`. Рік `0000` — вважати невалідним.
- Жодних `date()`, `time()`, `date_default_timezone_get()` у класі.
- Не залежить від `Http`/`Repository`/`Domain`.

`StatMetrics`: константи й методи з розділу 3; `message($lang)` будується через `Languages::isValid()`. `isValid` — `in_array(…, true)`.

### Крок 3. `DailyStatsRepository`

- `increment($m, $now)` = `incrementMany([$m], $now)`.
- `incrementMany(array $metrics, ?DateTimeImmutable $now)`: перевірити **кожну** метрику (`StatMetrics::isValid`, інакше `InvalidArgumentException` до будь-якого SQL; нерядкові елементи — теж), `array_unique` + `sort`; порожній → `return` без запиту; `$day = $now !== null ? RigaClock::dayOf($now) : $this->clock->today()` (один раз на виклик); SQL з `N` блоків `(?, ?, 1)`; `bindValue` для дня й метрики як `PARAM_STR`; `execute()`.
- `rangeTotals($from, $to)`: перевірка `isDay` для обох і `$from <= $to` (рядкове порівняння коректне для `YYYY-MM-DD`); `SELECT `day`, `metric`, `count` FROM `daily_stats` WHERE `day` BETWEEN ? AND ? ORDER BY `day`, `metric``; результат — список рядків з ключами **рівно** `day`, `metric`, `count` (`(string)`, `(string)`, `(int)`).
- Усе — prepared statements, значення лише параметрами (D-17: SQL лише в `Repository`).

### Крок 4. `VisitController` і маршрут

```php
final class VisitController
{
    public function __construct(private readonly RigaClock $clock = new RigaClock()) {}

    public function record(Request $request, array $params): Response
    {
        $data = $request->json();                       // 400 до звернення до БД
        $metrics = ($data['source'] ?? null) === 'qr'
            ? [StatMetrics::VISIT, StatMetrics::SCAN]
            : [StatMetrics::VISIT];

        // Db::pdo() тут, а не в конструкторі: створення контролера в routes.php БД не відкриває
        (new DailyStatsRepository(Db::pdo(), $this->clock))->incrementMany($metrics, $this->clock->now());

        return new Response(204, null, ['Cache-Control' => 'no-store']);
    }
}
```

Докблок класу: публічний, без авторизації; рахує лише кількості; `source` — єдине, що береться із запиту; `Request::ip`, заголовки, query не використовуються (приватність, F-005); збій БД → `500` через `ErrorHandler`; захисту від накрутки свідомо немає. У `routes.php` у блоці «Публічна частина»: `$router->post('/api/visit', [new VisitController(), 'record']);` (+ `use App\Controller\VisitController;`).

### Крок 5. `reseed()` очищає `daily_stats`

Сіду з історією немає (див. розділ 2). У `tests/db-lib.php`:

```php
function reseed(): array
{
    $ids = (new Seeder(testPdo(), APP_ROOT . '/database/seed/phrases.php'))->run();
    testPdo()->exec('TRUNCATE TABLE daily_stats');   // BE-011: лічильники теж стартують з нуля
    return $ids;
}
```

`Seeder`, `bin/seed.php` і `database/seed/` не змінюються. `TRUNCATE` — DDL, комітить неявно, як і `TRUNCATE phrases` у `Seeder::run()` (не викликати всередині `withBigTable`, див. пастки). BE-013 спирається на те, що після `reseed()` `daily_stats` порожня; свою історію вона вантажить окремо (`Seeder::runStats()`).

## 6. Тести

Загальні правила: назви тестів унікальні; помічники в `tests/api/visit.php` — з префіксом `visit*`; файл самодостатній (не покладається на інші тест-файли); усі тести, що пишуть у БД, — у `withFreshSeed(function () { … })` (він викликає `reseed()`, тож `daily_stats` теж стає порожньою — `reseed()` її очищає, крок 5); **не** вкладати `withFreshSeed` у `withBigTable` і навпаки.

**Підміна годинника.** `visitKernel(string $instantUtc): Kernel` → `kernelWith(fn (Router $r) => $r->post(VISIT_PATH, [new VisitController(new RigaClock(fn () => new DateTimeImmutable($instantUtc, new DateTimeZone('UTC')))), 'record']))`. Момент задається рядком ISO 8601 з `Z`. Реальний час у тестах використовують лише тести з боєвим маршрутом (V10, V12-контроль) і лише з перевірками, нечутливими до півночі (сума по всіх днях, а не конкретна дата).

**Знімки.** `visitSnapshot(): array<string,int>` — `SELECT day, metric, count FROM daily_stats` у карту `'2026-11-03|visit' => n`; `visitDiff($before, $after): array<string,int>` — відсортована (`ksort`) карта зміщень, рядок без змін не потрапляє, **зменшення = провал**. Типове очікування: `assertSame(['2026-11-03|scan' => 1, '2026-11-03|visit' => 1], visitDiff(…))` — одразу перевіряє і що зросло, і що **більше нічого** не змінилось, і що зайвих рядків (наприклад, `scan` з нулем) не з'явилось.

### 6.1. `tests/run.php` (без БД)

| № | Тест | Перевірки |
|---|------|-----------|
| R1 | `RigaClock::dayOf`: момент UTC → день за Ригою | таблиця: `2026-11-02T22:30:00Z` → `2026-11-03`; `2026-11-02T21:59:59Z` → `2026-11-02`; зима `…T22:00:00Z` = півночі; літо `2026-06-30T20:59:59Z` → `06-30`, `21:00:00Z` → `07-01`; **2026-03-29**: `03-28T21:59:59Z` → `03-28`, `03-28T22:00:00Z` → `03-29`, `03-29T00:59:59Z` і `01:00:00Z` (момент зсуву) → `03-29`, `03-29T20:59:59Z` → `03-29`, `03-29T21:00:00Z` → `03-30`; **2026-10-25**: `10-24T20:59:59Z` → `10-24`, `10-24T21:00:00Z` → `10-25`, повторена година `10-25T00:30:00Z` і `01:30:00Z` → обидва `10-25`, `10-25T21:59:59Z` → `10-25`, `10-25T22:00:00Z` → `10-26`; межа року `2026-12-31T21:59:59Z` → `2026-12-31`, `22:00:00Z` → `2027-01-01`; високосний `2028-02-29T22:00:00Z` → `2028-03-01`; вхідна зона не UTC (`2026-11-03T01:30:00+03:00`, `America/New_York`) дає той самий день, що й еквівалентний UTC-момент |
| R2 | `RigaClock`: підміна і реальний час | `now()` має зону `Europe/Riga`; `today()` з підміною = `dayOf`; без підміни `today()` відповідає `^\d{4}-\d{2}-\d{2}$` і дорівнює UTC-даті або наступній (Рига попереду UTC на 2–3 год); замикання не-`DateTimeImmutable` → виняток |
| R3 | `RigaClock::isDay` | валідні: `2026-11-03`, `2028-02-29`; невалідні: `2026-02-30`, `2027-02-29`, `2026-13-01`, `2026-1-1`, `20261103`, `''`, ` 2026-11-03`, `"2026-11-03\n"`, `2026-11-03T00:00:00`, `0000-00-00` |
| R4 | `StatMetrics` | `CODES` рівно `visit, scan, message_lv, message_en, message_ru` у цьому порядку; `isValid` суворий (`Visit`, `visit `, `messages`, `message_de`, `''`); `message('lv')` = `message_lv`, `message('LV')`/`message('de')` → `InvalidArgumentException`; усі `message(<мова>)` входять у `CODES` |
| R5 | `reseed()` очищає `daily_stats` | (в `tests/api/visit.php`, не в `run.php`; див. V16) — рядок зарезервовано, окремого юніт-тесту без БД немає |
| R6 | `POST /api/visit`: тіло розбирається до звернення до БД | через `visitKernel`-подібне ядро **без БД** (`--no-deps`): тіла `''`, `'   '`, `not json`, `{`, `5`, `"qr"`, `null`, `true`, `[1]`, `["qr"]` → `400 bad_request`, лише `code`/`message` (без `fields`), `Cache-Control: no-store`; `GET/PUT/DELETE/PATCH/HEAD` на `/api/visit` → `404 not_found`. Якби контролер раніше звертався до БД, відповіддю було б `500`, тому `400` без БД доводить порядок |

### 6.2. `tests/api/visit.php` (тестова БД)

| № | Тест | Перевірки |
|---|------|-----------|
| V2 | `increment`: арифметика | у `withFreshSeed`: на новому дні рядок створюється з `count = 1`, повторно `2`, `3`; різні метрики одного дня й різні дні однієї метрики незалежні; нічого зайвого (через `visitDiff`) |
| V3 | `increment`: день за `$now` і за годинником | `$now` у різних зонах (UTC `2026-11-02T22:30:00Z`, те саме як `+02:00`, `America/New_York`) дає рядок `2026-11-03`; `null` → день реального `RigaClock::today()` (допускаються два значення: «сьогодні» до і після виклику — захист від півночі); репозиторій із власним `RigaClock` з фіксованим часом використовує його |
| V4 | `increment`/`incrementMany`: захист | невідома метрика (`'Visit'`, `'visit '`, `'x'`, `''`, `"visit'; DROP TABLE daily_stats;--"`) → `InvalidArgumentException`, `statementCount` = 0, таблиця не змінилась; у `incrementMany` одна погана серед добрих — нічого не записано; дублікати (`['visit','visit']`) дають `+1`, не `+2`; порожній список — 0 запитів |
| V5 | `rangeTotals` | форма рядка — рівно ключі `day, metric, count`, типи `string/string/int` (**і** сирі `assertSame`, не лише значення); порядок `day, metric`; межі включно (`from == to`; рядки на `from` і на `to` входять, на день до/після — ні); дні без рядків відсутні; порожня таблиця → `[]`; невалідні дати/`from > to` → `InvalidArgumentException` і 0 запитів; рівно **1** запит (`statementCount`), у тому числі для 366-денного діапазону |
| V6 | `POST /api/visit` `qr` | `204`, `body === null`, заголовок `Cache-Control: no-store`; `visitDiff` = `['2026-11-03|scan' => 1, '2026-11-03|visit' => 1]` (годинник `2026-11-02T22:30:00Z`) |
| V7 | `direct` | `204`; diff = лише `visit` +1; **рядок `scan` за цей день не створюється** (якщо його не було — не з'являється навіть з нулем) |
| V8 | «дивний» `source` → `direct` | таблиця тіл: `{}`, `[]` (факт 1), `{"source":null}`, `{"source":"abc"}`, `""`, `"QR"`, `" qr"`, `"qr "`, `"qr\u0000"`, `123`, `0`, `1.5`, `true`, `false`, `["qr"]`, `{"qr":true}`, `{"source":{"a":1}}`, `{"x":1}` → `204`, diff = лише `visit` +1. Контроль: `{"source":"qr","utm":"x","extra":[1]}` → `qr` (зайві ключі ігноруються) |
| V9 | `400`: нічого не рахується | ті самі тіла, що в R6, на ядрі з БД: `400`, `visitSnapshot()` до/після рівні, `statementCount` = 0 |
| V10 | боєвий маршрут, авторизація, джерела | `apiRequest('POST', '/api/visit', …)` без `Authorization` → `204` (не `401`); зі сміттєвим `Authorization: Bearer x` → теж `204`; кінцевий слеш `/api/visit/` → `204`; сума `visit` по всій таблиці +1 за кожен запит (нечутливо до півночі). **Адреса не рахується:** `POST /api/visit?qr=1&source=qr` з тілом `{"source":"direct"}` і заголовками `Referer: …?qr`, `User-Agent: qr` → лише `visit` |
| V11 | Межі дня через ендпоінт | кожен момент із R1 (мінімум: `2026-11-02T22:30Z`, `…21:59:59Z`; 03-28/29/30; 10-24/25/26; `2026-12-31T22:00Z`) → рівно один новий/збільшений рядок з очікуваною датою, UTC-дата не з'являється; **без дублювання дня:** два запити в повторену годину `2026-10-25T00:30Z` і `01:30Z` → один рядок `10-25` зі значенням `+2`; доба 23 год (03-29): запити о `…T22:00:00Z` (03-28 → 03-29) і `…T20:59:59Z` дають один і той самий день `03-29` (`count` 2) |
| V12 | 50 паралельних запитів | `withFreshSeed`; `tests/workers/visit-worker.php` (нижче); 50 процесів: 30 `qr` + 20 `direct`, один фіксований момент `2026-11-02T22:30:00Z`; усі виходять з кодом `0`; diff = `['2026-11-03|scan' => 30, '2026-11-03|visit' => 50]` рівно |
| V13 | Інваріанти на випадковій серії | `mt_srand(42)`; 120 запитів на 5 фіксованих днях/різних годинах з `qr`/`direct`/сміттєвим `source`; оракул — підрахунок у PHP; таблиця = оракул, `scan ≤ visit` у кожному дні, сума `visit` = 120 |
| V14 | Персональних даних немає | у `withFreshSeed`: запити з `ip = '203.0.113.7'` (4-й параметр `apiRequest`), `User-Agent: SENTINEL-UA-7f3a`, `Referer: https://SENTINEL-REF-9c1/`, `X-Forwarded-For: 198.51.100.77`; `SHOW COLUMNS` — лише `day, metric, count`; `SELECT *` — рядки лише з цими ключами; жоден рядок будь-якої таблиці (`phrases`, `login_attempts`, `daily_stats`, `migrations`) не містить сентинелів (пошук по текстових колонках); `login_attempts` не змінилась; `authCaptureLog()` навколо успішних запитів і `400` повертає **порожній** рядок |
| V15 | Збій БД → `500` | у `try/finally` `RENAME TABLE daily_stats TO daily_stats_off` (відновлення — у `finally`, і перевірка, що таблиця знову є): `POST` (`qr` і `direct`) через `authCaptureLog()` → `500`, `server_error`, `Cache-Control: no-store`, тіло без `daily_stats`, `SQL`, `PDO`, `Table`; лог **не порожній** і містить виняток, але не містить сентинелів із V14 (передати їх і тут); після відновлення таблиці — `204` і diff без залишків від невдалих спроб |
| V16 | `reseed()` очищає `daily_stats` | після запису кількох рядків (`increment`) `reseed()` → `SELECT COUNT(*) FROM daily_stats` = 0; `phrases` — 30 рядків, як раніше |
| V17 | Кількість запитів | `POST` `qr` — рівно 1, `direct` — рівно 1, `400` — 0 |

**`tests/workers/visit-worker.php`** (CLI; `PHP_SAPI !== 'cli'` → `404`, як `bin/*`): аргументи `<instant ISO 8601 Z> <source>`; `define('APP_ROOT', …)`; **до** bootstrap — `DB_NAME` береться з успадкованого середовища (батьківський тест задає `DB_NAME=orakuls_test` явно в масиві `env` `proc_open`); після bootstrap — `DatabaseGuard::assertTestDatabase(Db::pdo())` (відмова на не-`_test` БД: скрипт не повинен випадково писати в робочу); будує `kernelWith` з `VisitController(new RigaClock(fixed))`; друкує `ready\n`, **блокується на `fgets(STDIN)`** (бар'єр), далі `handle(new Request('POST', '/api/visit', [], [], json_encode(['source' => $source])))`; код виходу `0` лише якщо статус `204`, інакше `1` і пояснення в STDERR. Батьківський тест: `proc_open([PHP_BINARY, worker, instant, source], [0 pipe r, 1 pipe w, 2 pipe w], …)` ×50; читає `ready` з усіх (з таймаутом 30 с, інакше провал), потім пише `go\n` у всі `stdin`, чекає всі процеси (`proc_close`), перевіряє коди виходу. Перед запуском воркерів тест звільняє власне з'єднання від відкритих транзакцій (нічого не тримає).

### 6.3. `tests/smoke.php` (+4)

Нижче — по HTTP на запущений сервер:
1. `POST /api/visit` з порожнім тілом → `400 bad_request`, `no-store` (не пише).
2. `POST /api/visit` з `not json` → `400 bad_request` (не пише).
3. `GET /api/visit` → `404 not_found` (не пише).
4. `POST /api/visit` з `{"source":"direct"}` → `204`, порожнє тіло, `Cache-Control: no-store`. **Пише у статистику** (+1 `visit` за день, без персональних даних), тому за замовчуванням **пропускається** (рішення замовника): запускається лише з `SMOKE_WRITE=1` (без нього виводиться рядок `skip …`, у лічильник перевірок не йде). **Цей механізм (`SMOKE_WRITE`) вводить BE-011**; BE-012 під цим самим прапорцем ховає дві перевірки `GET /api/message` з `200`, що теж пишуть лічильник. У `testing.md`: «smoke за замовчуванням нічого не пише; для перевірки запису — `SMOKE_WRITE=1` (не проти прода перед друком QR)». Перевірка вимагає застосованої міграції 003 на тій БД, яку обслуговує сервер (інакше `500` — це правильний сигнал).

## 7. Документація (описує те, що **є** в коді)

Спільні файли редагують і BE-012/BE-013: **перед кожною правкою перечитуй файл**, вноси точкові `Edit` у свої розділи, не перезаписуй файл цілком.

| Файл | Що змінити |
|------|-----------|
| `decisions.md` | **D-33** · Облік відкриттів: агрегати замість журналу подій; `daily_stats(day, metric, count)`, PK `(day, metric)`; день за Ригою обчислює PHP (`RigaClock`), не MySQL (на shared-хостингу tz-таблиць може не бути; PHP має власну базу поясів); годинник підміняється замиканням; атомарний `INSERT … ON DUPLICATE KEY UPDATE` одним оператором на запит (`visit`+`scan` разом); `source` лише з тіла, суворо `=== 'qr'`; `[]` ≡ `{}` → `204`; рішення без ліміту й без IP (F-005); білий список метрик `StatMetrics`; збій БД → `500` (не маскується: фронт сам ігнорує). Альтернативи: журнал подій (потрібні IP/UA, чистка, ПД), сторонню аналітику (cookie-банер у ЄС), `CONVERT_TZ`/`CURDATE()` (залежать від tz-таблиць), `SELECT`-потім-`UPDATE` (губить паралельні відкриття), два окремі оператори `visit` і `scan` (при збої посередині `scans > visits`), транзакція з `FOR UPDATE` (зайве). Наслідки: накрутка можлива; лічильники безстрокові (~1800 рядків/рік); відкат лічильника неможливий (інструмента скидання немає — враховано в BE-014) |
| `architecture.md` | У структурі: `Support/` (+ `RigaClock`), `Domain/` (+ `StatMetrics`), `Repository/` (+ `DailyStatsRepository`), `Controller/` (+ `VisitController`), `tests/workers/` (+ `visit-worker.php`); рядки в таблиці компонентів (`VisitController`, `DailyStatsRepository`, `RigaClock`, `StatMetrics`); правила залежностей: `Repository ──► Domain (+StatMetrics), Support (+RigaClock)`, `RigaClock` не залежить ні від чого; згадка в життєвому циклі не потрібна |
| `api-conventions.md` | Розділ «`POST /api/visit` (BE-011)»: таблиця відповідей з task.md, правило `source` (суворо `qr`, регістр значущий), `[]` ≡ `{}` → `204`, `Cache-Control: no-store` на `204` і помилках, без авторизації, адреса/заголовки не читаються, кількість SQL-запитів (успіх 1, `400` — 0), інші методи `404`; у «Додати ендпоінт» не змінювати. У «Запит» уточнити, що `Request::json()` ігнорує `Content-Type` (за потреби одним реченням) |
| `database.md` | Таблиця схеми: рядок `daily_stats` (BE-011 ✅); розділ «`daily_stats` (BE-011)»: колонки, PK, запити (`incrementMany`, `rangeTotals`), типи при читанні (`DATE` → рядок, `count` → `int`), чому день рахує PHP, ідемпотентність міграції 003, що **інтерфейс спільний із BE-012/BE-013**; уточнити в «Дати», що `DATE` у `daily_stats` — це **календарна дата за Ригою, а не UTC**; у «Склад сіду» — одне речення: `daily_stats` після `reseed()` порожня (історію для звіту вантажить BE-013); у «`schema.sql`» — згадка, що `daily_stats` (міграція `003`) там є |
| `testing.md` | Нові числа (дельта від базової лінії), рядок `tests/api/visit.php` у таблиці файлів, опис `tests/workers/visit-worker.php` (бар'єр, `DatabaseGuard`), розділ «Що покриває `tests/api/visit.php`» (підміна годинника через `kernelWith` + `RigaClock`, знімки/`visitDiff`, 50 паралельних процесів, `RENAME TABLE` для збою БД, перелік мутацій), `Smoke`: нові перевірки і `SMOKE_WRITE=1` як **єдиний спосіб** змусити smoke писати в статистику (за замовчуванням smoke нічого не пише) |
| `security.md` | Нова група рядків «Облік відкриттів (BE-011)»: зберігаються лише `(day, metric, count)`, IP/User-Agent/Referer/cookies не читаються й не логуються (✅, тест V14); `source` — єдине значення з запиту, невідоме → `direct`; метрика в SQL — лише параметром + білий список; **у «Що навмисно не робимо»**: ліміт/захист від накрутки на `/api/visit` (рішення F-005; якщо клієнт захоче — окремо і без довгого зберігання IP) |
| `error-handling.md` | Рядок у «Що відбувається з іншими помилками»/окреме речення: `POST /api/visit` — `400` (порожнє/не-JSON/не-об'єкт), `500` при збої БД; успішний шлях нічого не логує |
| `docs/BACKEND.md` | Структура (нові класи/файли), «База даних» (+ `daily_stats`), новий розділ «Облік відкриттів (BE-011)»: що має знати фронт (ендпоінт готовий, `204`, `source`, `[]` ≡ `{}`, день за Ригою, `GET /api/admin/visits` і лічильник послань — BE-012/BE-013, **статус не «готово»**), «Локальний запуск»: нагадування, що для `POST /api/visit` потрібна міграція (`bin/migrate.php`) |
| `docs/FRONT-TO-BACK.md` | У розділі «Відповідь» під F-005 — відповідь (чернетка нижче); статус `open` → `answered` (правило 3 з `docs/README.md`). Чужий текст запиту не редагувати |

**Чернетка відповіді на F-005** (заповнює саме BE-011; BE-012/013 її не пишуть — інакше конфлікт; дату поставити фактичну):

> **<дата>.** Погоджуємо формат і поведінку без змін; заперечень проти підрахунку дати в PHP за `Europe/Riga` немає (PHP має власну базу часових поясів, MySQL не залучається).
> - **Зберігання:** беремо вашу пропозицію як є — `daily_stats(day, metric, count)`, PK `(day, metric)`, метрики `visit`, `scan`, `message_lv|en|ru`, атомарний `INSERT … ON DUPLICATE KEY UPDATE`. Без журналу подій, без IP/User-Agent/cookies; зберігаємо безстроково.
> - **`POST /api/visit`:** `204` без тіла + `Cache-Control: no-store`; `source: "qr"` → `visits+1`, `scans+1`; будь-що інше (відсутній, `null`, невідомий рядок, не рядок, інший регістр `"QR"`) → `direct`; порожнє/не-JSON/не-об'єкт → `400 bad_request`; збій БД → `500` (у лог). Нюанс: порожній JSON-масив `[]` PHP не відрізняє від `{}`, тож це `204` як `direct` (`["qr"]` — `400`). Ліміту за IP немає.
> - **`GET /api/message` (`messages`, `byLang`) і `GET /api/admin/visits`:** формат підходить; реалізуємо окремими задачами **BE-012** (збій лічильника не ламає видачу) і **BE-013** (усі дні періоду, `byLang` завжди з трьома ключами, `400` за таблицею з запиту, максимум 366 днів). Реалізація `POST /api/visit` — BE-011.
> - `API.md` правите ви (правило 5), у рядку версії згадайте F-005.

## 8. Порядок кроків

1. Крок 0 (базова лінія, експерименти за фактами 7–9, 15) → 2. Крок 1 (міграція, `schema.sql`, `database.php`) → 3. Крок 2 (`RigaClock`, `StatMetrics`) і R1–R4 → 4. Крок 3 (`DailyStatsRepository`) → 5. Крок 5 (`reseed()` очищає `daily_stats`) → 6. Крок 4 (контролер, маршрут) і R6 → 7. `tests/api/visit.php` (V2–V17) і `tests/workers/visit-worker.php` → 8. `smoke.php` → 9. документація → 10. фінальна перевірка (розділ 9).

Після кроків 1–3 усі наявні тести мають лишатися зеленими (нова таблиця їх не торкається).

## 9. Фінальна перевірка

1. `tests/run.php`, `tests/api.php` (**двічі поспіль**), `tests/smoke.php` (проти локального сервера, після `bin/migrate.php` на дев-БД), `php bin/build-schema.php --check`. Усе зелене; `schema.sql` не правився руками.
2. Дев-БД `orakuls`: `migrations` 3, `daily_stats` 0 рядків, `phrases` і `login_attempts` без змін. У `orakuls_test` після прогону: 30 фраз, `max(id)` 30, `daily_stats` порожня.
3. **Наскрізна перевірка по HTTP** (як у BE-006/007/008): у контейнері `DB_NAME=orakuls_test timeout 90 php -S 127.0.0.1:8001 -t public` у фоні; `curl -i`: `POST /api/visit {"source":"qr"}` → `204`, `no-store`, порожнє тіло; `direct`; сміттєвий `source`; порожнє тіло/`not json`/`[1]` → `400`; `GET /api/visit` → `404`. Перевірити `SELECT * FROM daily_stats` після кожної групи; **день у таблиці = сьогодні за Ригою** (порівняти з `TZ=Europe/Riga date +%F`). Потім `reseed`.
4. **Ручна перевірка «міграція поверх поточної»:** на дев-БД до застосування — `migrations` 2; `bin/migrate.php` → застосовано лише 003; повторний запуск — «нічого». На чистій тестовій БД — `schema.sql` імпортується (паритет, тест у `database.php`).
5. **Мутації** (скриптом із перевіркою, що файл справді змінився; після всіх — відновлення і `sha256sum` до/після; жодна не повинна пройти непоміченою):

| # | Мутація | Має впасти |
|---|---------|-----------|
| а | `dayOf`/`today` без `setTimezone` (день за UTC) | R1, V3, V6, V11 |
| б | фіксований зсув `+02:00` замість `Europe/Riga` | R1 (літні дати), V11 |
| в | `direct` теж збільшує `scan` | V7, V8, V10 |
| г | порівняння `source` без урахування регістру (`strcasecmp`) / `trim` | V8 |
| д | `source` береться з query (`?qr`) | V10 |
| е | `SELECT` + `UPDATE`/`INSERT` замість `ON DUPLICATE KEY UPDATE` | V12 (імовірнісно: повторити 3 рази) |
| є | `ON DUPLICATE KEY UPDATE count = 1` або `VALUES(count)` | V2, V6, V12 |
| ж | прибрати `Cache-Control` з `204` | V6 (`204` без БД у `run.php` неможливий) |
| з | `200` з тілом замість `204` | V6 |
| и | `Db::pdo()`/запис до `Request::json()` | R6, V9 |
| і | `try/catch` навколо запису → `204` при збої БД | V15 |
| ї | `Logger::error()` з IP/User-Agent у шляху успіху | V14 |
| й | `incrementMany` без перевірки метрики | V4 |
| к | без `array_unique` (дублікати +2) | V4 |
| л | `rangeTotals`: `<` замість включення верхньої межі | V5 |
| м | `rangeTotals`: `count` як рядок | V5 |
| н | `isDay` приймає `2026-02-30` | R3, V5 |
| о | контролер сам відхиляє порожній масив тіла (`$data === []` → `400`) | V8 |
| п | `visit` і `scan` пишуться двома окремими операторами | V17 (очікується рівно 1 запит) |

Якщо мутація не червонить жодного тесту — посилюй тест і повтори (V12 — імовірнісний: мутацію «е» проганяти кілька разів, при потребі збільшити кількість воркерів до 80).

## 10. Пастки

- **Час ніколи не береться з `date()`/`NOW()`/`CURDATE()`** (факт 5): тут усе в PHP з явною зоною. Забудеш — день за UTC (тести R1/V11 це ловлять).
- **Північ у тестах:** тести з реальним годинником мають перевіряти суму по таблиці або допускати два значення дня. Конкретні дати — лише з підміною.
- **`[]` ≡ `{}`** (факт 1): не «виправляй» `Request::json()` заради цього (він спільний для BE-004…BE-007; змінить поведінку імпорту).
- **`withFreshSeed` ⊄ `withBigTable`** і навпаки (`TRUNCATE` комітить транзакцію). `reseed()` тепер чистить і `daily_stats`.
- **`RENAME TABLE` (V15) — DDL**, комітить неявно; відновлення — у `finally`, інакше всі наступні тести впадуть. Перевір відновлення явно.
- **Воркер не має писати в не-тестову БД:** `DatabaseGuard` у воркері обов'язковий.
- **Імена функцій у тест-файлах глобальні:** перед створенням `visit*` перевір `grep -n "^function" tests/api/*.php tests/*.php`.
- **Не змінюй наявні тести**, окрім одного рядка списку таблиць у `database.php` (факт 12).
- **Не змінюй `MessageController`:** лічильник послань — BE-012; цей план лише надає йому клас.

## 11. Ризики

1. **Спільні файли між трьома агентами** (`decisions.md`, `architecture.md`, `database.md`, `testing.md`, `api-conventions.md`, `BACKEND.md`, `routes.php`, `Seeder`/`db-lib.php`): конфлікти редагування. Перечитуй перед правкою, правки точкові, номери D-xx й числа тестів — за фактом. BE-012 і BE-013 спираються на інтерфейс розділу 3: якщо виконавець BE-011 змушений його змінити — одразу повідомити замовника (він узгодить з іншими агентами) і зафіксувати зміну в `database.md`.
2. **Відповідь на F-005 пише лише BE-011** (інакше дві відповіді в одному розділі). Якщо BE-012/BE-013 завершаться раніше й уже дописали — об'єднати.
3. **Smoke і запис у БД** (рішення замовника): перевірка 4 пише +1 `visit`, тому за замовчуванням вимкнена й вмикається `SMOKE_WRITE=1`; запуск проти прода без прапорця статистику не псує (важливо перед друком QR — BE-014, стерти лічильники нічим). Якщо `SMOKE_WRITE=1` вмикають на проді — ці одиниці треба врахувати як «тестові».
4. **Deadlock під високим паралелізмом** (теоретично `1213` на `ON DUPLICATE KEY`): замки беруться в єдиному порядку, і V12 (50 воркерів) це перевіряє; повторної спроби не додаємо. Якщо V12 колись «блимне» `1213` — додати один повтор для `SQLSTATE 40001` усередині `incrementMany` і записати в D-33.
5. **`[]` → `204`** — погоджене замовником відхилення від «JSON-масив → 400» (зафіксовано в AC `task.md`, факт 1); тест фіксує обидва боки (`["qr"]` → `400`, `[]` → `204`). Якщо замовник заперечить — змінювати `Request::json()` можна лише окремою задачею.
6. **Зміна правил переходу на літній час** (теоретично: ЄС обговорює скасування): PHP хостингу з застарілою tzdata дасть зсув до оновлення PHP; за поточних правил Латвії (2026) нічого не змінюється. Перевірити версію tzdata на хостингу в BE-009 (`phpinfo()`/`timezone_version_get()`).
7. **Міграція 003 на проді** вручну (phpMyAdmin): `schema.sql` уже її містить для свіжої БД; для БД, імпортованої до BE-011, — виконати SQL міграції окремо і додати запис у `migrations`. Врахувати в BE-009/`deployment.md` (не правити їх у цій задачі, лише згадати у звіті).
8. **Накрутка:** будь-хто може слати `POST /api/visit` (рішення F-005); цифри «сканувань» не можна вважати захищеними від підробки.
9. **`INT UNSIGNED` переповнення** (~4,29 млрд) — нереалістично для цього проєкту; у суворому режимі дало б `500`, а не мовчазне спотворення.

## 12. Чого не робити

- Не змінювати `API.md`, `Request`/`Response`/`Router`/`Kernel`/`ErrorHandler`, `MessageController`, `PhraseRepository`, існуючі міграції, жодних `docs/tasks/**`.
- Не додавати ліміт запитів, кеш, читання IP/заголовків, окремі таблиці подій, індекси понад PK, нові змінні оточення, Composer.
- Не логувати нічого на успішному шляху; не ловити виняток БД у контролері.
- Не залишати змінених файлів, тимчасових серверів, воркерів-процесів, перейменованих таблиць чи даних у `orakuls_test` після мутаційних прогонів.
- Не писати у `daily_stats` дев-БД `orakuls` (лише схема).

## 13. Звіт

У кінці виклади звіт українською: (1) створені/змінені файли; (2) числа тестів (`run`, `api`, `smoke`) до та після; (3) результати розділу 9: HTTP-перевірка (з порівнянням дня з `TZ=Europe/Riga`), міграція поверх поточної, таблиця мутацій із тим, що саме впало, `sha256sum` до/після; (4) відхилення від плану і причини (зокрема від розділу 3); (5) відповіді на відкриті питання (smoke-запис, `[]` ≡ `{}`); (6) сумніви та знахідки. Будь чесним: що не пройшло або не перевірено — так і пиши.
