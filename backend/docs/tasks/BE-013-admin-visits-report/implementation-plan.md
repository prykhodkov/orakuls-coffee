# BE-013 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та acceptance criteria (AC) — у [task.md](task.md) (прочитай його першим: усі рішення там прийняті, не переглядай їх). Контракт — запит F-005 у `docs/FRONT-TO-BACK.md` (там точний формат відповіді); `docs/API.md` не змінювати (його правку вносить фронт, правило 5).

Ціль: `GET /api/admin/visits?from=…&to=…` — тонкий `VisitReportController`, суворий валідатор періоду в домені, **один** `SELECT` з таблиці лічильників (його дає BE-011) і збірка відповіді в PHP: усі дні періоду з нулями, `byLang` завжди `lv, en, ru`, `total` як сума днів.

Мова коментарів у коді і документації — українська, стиль — як у сусідніх файлах (`StatsController`, `PhraseController`, `PhraseFilter`).

## Актуалізація після BE-011 і BE-012 (2026-11-03)

BE-011 і BE-012 виконано; план звірено з реальним кодом — припущення **підтверджені**: `DailyStatsRepository::rangeTotals()` повертає `list<{day: string, metric: string, count: int}>` (один запит, `ORDER BY day, metric`, кидає `InvalidArgumentException` на невалідні дати й `from > to`); `RigaClock::isDay()` (статичний), `RigaClock::TIMEZONE`, `(new RigaClock())->today()`; `StatMetrics::CODES`/`message()`; `reseed()` очищає `daily_stats` (`tests/db-lib.php`); `Seeder` BE-011 **не змінювався** (історію й `Seeder::loadStats()`/`runStats()` створює лише BE-013); `GET /api/message` уже рахує `message_<мова>` (BE-012), `POST /api/visit` — `visit`/`scan`. Що змінилось у деталях:
- Базова лінія **112 / 177 / 27** (`run` / `api` / `smoke`; у smoke ще 2 перевірки пропускаються без `SMOKE_WRITE=1`), `tests/api.php` ≈ 20 с; D-33 (BE-011) і D-34 (BE-012) зайняті — тут **D-35**.
- Паралельні процеси тут не потрібні. Пастки, відомі з BE-011/012: у `assertSame(..., "…" . stream_get_contents(...))` повідомлення обчислюється до перевірки; `array + array` не додає елемент із наявним числовим ключем.
- Вже зайняті префікси хелперів: `visit*`, `counter*`; у `tests/run.php` є `utcMoment()`.
- Розширення правила залежностей: `Domain\ReportPeriod` залежить від `Support\RigaClock` (`isDay()`), оновити `architecture.md`.
- Перевірка з фронтом у браузері (`VITE_API_MOCK=false`, крок 8 п. 4) в цьому середовищі недоступна: замінюється звіркою форми відповіді з кодом фронта (`mock/visits.js`, `admin/visits.js`, `dates.js`) і `curl`; результат записати чесно.

## 0. Передумови

- Задачі **BE-011 і BE-012 виконані** (таблиця `daily_stats`, `DailyStatsRepository`, `RigaClock`, `POST /api/visit`, лічильник у `GET /api/message`). Кроки 1–5 (домен, контролер, юніт-перевірки) можна робити вже після BE-011, але AC про наскрізний тест (`POST /api/visit` + `GET /api/message`) закривається лише коли є обидві.
- Docker-контейнери `php` і `db` запущені. Усі команди — з `C:\rep\orakuls-coffee\backend`, з абсолютним `-f C:\rep\orakuls-coffee\backend\docker-compose.yml` (робочий каталог інколи скидається — завжди `cd` явно).
- **Базова лінія:** перед початком запусти `tests/run.php`, `tests/api.php`, `tests/smoke.php`, `php bin/build-schema.php --check` і **запиши числа** (після BE-008 було 105 / 143 / 25, але BE-011 і BE-012 їх змінили — спирайся на фактичні). Усе має бути зеленим.
- PHP-код і тести пиши інструментами Write/Edit (не heredoc через bash: зворотні слеші ламаються).
- Дев-БД `orakuls` без потреби не чіпати. Усі тести — лише на `orakuls_test`. **Міграцій і змін `schema.sql` у цій задачі немає** (таблицю створив BE-011); `bin/build-schema.php --check` лишається зеленим без змін.
- Нічого не коміть (`git add/commit/stash/reset/checkout` заборонені). **Жоден `docs/tasks/**` файл не редагуй** — статуси задач оновить замовник.

## 1. Залежність від BE-011/BE-012

План спирається на інтерфейс, який створює BE-011 (не проєктується заново). Що саме береться звідти:

| Що | Де | Як використовує BE-013 |
|----|----|------------------------|
| Таблиця `daily_stats(day DATE, metric VARCHAR(16), count INT UNSIGNED)`, PK `(day, metric)`; метрики `visit`, `scan`, `message_lv`, `message_en`, `message_ru` | міграція і `schema.sql` BE-011 | Звіт читає лише її. Нової міграції немає. Імена метрик — єдине, чим обидві задачі «домовляються», тож їх фіксують юніт-перевірка `VisitReport::metrics()` (крок 3) і наскрізний тест (крок 6) |
| `DailyStatsRepository::rangeTotals(string $from, string $to): array` | BE-011 | Один `SELECT` за `day BETWEEN ? AND ?`; повертає рядки `day`, `metric`, `count`. Контролер викликає його рівно один раз. BE-013 клас **не змінює** |
| `DailyStatsRepository::increment(string $metric, ?\DateTimeImmutable $now = null): void` | BE-011 | Напряму не викликається. Дані для перевірок — або прямий `INSERT` у тесті (незалежний від коду BE-011), або справжні ендпоінти (наскрізний тест) |
| `RigaClock` (`App\Support`): `isDay(string): bool` (статичний), `TIMEZONE` (константа `'Europe/Riga'`), `today(): string` (на екземплярі) | BE-011 (розділ 3 його плану — **вже зафіксовано**) | `isDay()` — **єдина перевірка «рядок — реальна дата YYYY-MM-DD»**, яку використовує і `ReportPeriod::parseDate()`, і `rangeTotals()` (див. рішення нижче); `TIMEZONE` — значення `timezone` у відповіді; `today()` — **лише в тестах** (наскрізний тест звіряє «сьогодні»; у тестах `(new RigaClock())->today()`). Контролер годинника не потребує: період задає клієнт, а «майбутнє» дозволене |
| `POST /api/visit` (`204`, `{source: qr\|direct}`) | BE-011 | Наскрізний тест |
| Лічильник у `GET /api/message` (`message_<lang>` при `200`) | BE-012 | Наскрізний тест |
| `reseed()` очищає `daily_stats` | **BE-011 (вже зафіксовано в його плані, крок 5):** `tests/db-lib.php::reseed()` виконує `TRUNCATE TABLE daily_stats`; сіду з історією BE-011 **не** робить | Тести BE-013 розраховують на порожню таблицю після `reseed()`; свою історію вантажить лише `vrWithHistory()` (крок 5 цього плану) |

**Припущення, які треба звірити з реальним кодом BE-011 до початку (і зафіксувати розбіжність у звіті):**

1. `rangeTotals()` повертає **асоціативні** рядки `['day' => 'YYYY-MM-DD', 'metric' => 'visit', 'count' => 12]` (так само, як решта репозиторіїв проєкту). Збирач відповіді (`VisitReport::build`) усе одно приводить `day` до `string`, `count` до `int` і **додає** значення за однакову пару `(day, metric)`, тож не залежить ні від `GROUP BY`/`ORDER BY` у запиті, ні від того, що `SUM` повернувся рядком. Якщо BE-011 віддає інакше (позиційні масиви, `DateTime`) — адаптувати лише вхід `build()`, не форму відповіді.
2. `DailyStatsRepository` отримує `PDO` у конструкторі (D-17, як `PhraseRepository`), `Db::pdo()` викликає контролер.
3. `RigaClock::today()` — метод екземпляра (`(new RigaClock())->today()`), `RigaClock::isDay()` — статичний (за планом BE-011). У тестах виклик ізольований одним помічником `vrToday()`.
4. Пояс у відповіді — `RigaClock::TIMEZONE` (константа, що задана планом BE-011); літерал `'Europe/Riga'` у контролері **не пишеться**.
5. `reseed()` BE-011 очищає `daily_stats` (див. таблицю вище). Якщо виконавець бачить у реальному коді інше — додати один рядок `TRUNCATE TABLE daily_stats` у `reseed()` (`tests/db-lib.php`) і записати розбіжність у звіт.

## 2. Перевірені факти і що перевірити на кроці 1

Під час написання плану Docker був вимкнений, тож **експерименти нижче не виконувались** — це відомі властивості PHP/MySQL і код проєкту, прочитаний безпосередньо. Виконавець перевіряє позначене «перевірити» на кроці 1 до написання коду.

| № | Факт | Наслідок |
|---|------|----------|
| 1 | `AdminGuard` виконується в `Kernel` до маршрутизації (D-20): без токена будь-який `/api/admin/*` дає `401`, навіть із зламаними параметрами; невідомий метод/шлях → `404 not_found` (`405` немає); `Cache-Control: no-store` на помилках додає `ErrorHandler` | Для `POST /api/admin/visits` і `/api/admin/visits/1` додаткового коду не потрібно; `no-store` на `200` додавай явно |
| 2 | `Request::query($name)` повертає `string\|null`, на параметр-масив (`from[]=x`) кидає `400`; порожній параметр (`from=`) дає `''` | Відсутній і порожній обробляються однаково; масив — `400` без додаткового коду |
| 3 | `bootstrap.php` ставить `date_default_timezone_set('UTC')`, але це **глобальний** стан: тест чи хостинг може його змінити | Календарна арифметика явно задає `new DateTimeZone('UTC')` і не залежить від поясу за замовчуванням (тест перевіряє це змінюючи пояс) |
| 4 | `DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-30', UTC)` **не повертає `false`**: дата «переповзає» на `2026-03-02` (з попередженням у `getLastErrors()`); `m` і `d` приймають і одну цифру (`2026-1-5`). Той самий прийом уже в `Iso8601::fromDb()` | Спершу `preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', …)`, потім `createFromFormat` і порівняння `format('Y-m-d') === $input`. Лише регулярка пропустила б `2026-02-30`, лише `createFromFormat` — `2026-1-5`. Модифікатор `D` (інакше `"2026-01-01\n"` проходить), клас `[0-9]`, а не `\d` |
| 5 | Доба Риги в день переходу має **23 год** (2026-03-29, 03:00 → 04:00) або **25 год** (2026-10-25, 04:00 → 03:00). Підрахунок днів як `(ts_to − ts_from) / 86400` у поясі Риги дає для `2026-03-28 … 2026-03-30` 47 год → `floor(47/24) + 1 = 2` замість 3; цикл «`+86400` с від півночі Риги» на 2026-10-25 потрапляє на 23:00 того ж дня й **дублює** дату | Усю арифметику робимо над **календарними датами в UTC**: `diff()->days` між двома UTC-півночами і `modify('+N days')` від початкової дати (а не накопичення секунд). Перевіряється тестами на 2026-03-29 і 2026-10-25 та мутаціями |
| 6 | `DateTimeImmutable::diff()` між двома UTC-півночами дає точну кількість діб у `->days` (без впливу DST); порівняння `$a > $b` для `DateTimeImmutable` коректне | `from > to` і довжину періоду перевіряємо **до** побудови списку днів: запит `0001-01-01 … 9999-12-31` не повинен будувати 3,6 млн елементів |
| 7 | `DATE`-колонка через PDO (нативні prepared) повертається рядком `'2026-11-03'`, `INT UNSIGNED` — як `int`; `SUM` (якщо BE-011 його використав) — рядком `DECIMAL` | `build()` приводить типи явно (`(string)`, `(int)`) — як `stats()` у BE-008 |
| 8 | Рядок для `BETWEEN ? AND ?` — канонічний `YYYY-MM-DD`, який уже пройшов валідатор; ввід користувача в текст SQL не потрапляє | Додаткового екранування не треба |
| 9 | **Рішення (замінює експеримент про рік `0000`):** `ReportPeriod::parseDate()` спочатку викликає `RigaClock::isDay($value)` (BE-011: регулярка + реальність дати + **рік `0000` невалідний**), і лише потім будує `DateTimeImmutable`. Інакше дата, що проходить `parseDate`, але не проходить `isDay` (`0000-01-01`), дійшла б до `rangeTotals()`, який кидає `InvalidArgumentException` → `500` замість `400` | Одна перевірка дати на весь проєкт: `from`/`to` з роком `0000` → `400`, а не `500`. Тест `0000-01-01 … 0000-01-02` → `400` обов'язковий (у юніт-перевірках `ReportPeriod` і в тесті 9) |
| 10 | **Перевірити:** `createFromFormat('!Y-m-d', '0999-01-01', UTC)->format('Y-m-d') === '0999-01-01'` на PHP 8.4 (`Y` має давати 4 цифри) | Інакше рік до 1000 помилково відхилявся б |
| 11 | `Response::json` кодує асоціативні масиви з рядковими ключами як JSON-об'єкти; `byLang` і `total` завжди мають 3 ключі з констант (`Languages::CODES`), тож це об'єкти; `Json::object()` не потрібен, але сирий JSON перевіряється тестом (`"byLang":{`) | Захист від регресії «`[]` замість `{}`» |
| 12 | `statementCount()` (з `tests/db-lib.php`) рахує виконання prepared statements; `rangeTotals` має дати рівно **1**, відхилені `AdminGuard`/валідатором запити — 0 | Один запит на період: порожні дні доповнює PHP |
| 13 | `tests/api.php` підключає `tests/api/*.php` за алфавітом; наявні файли мають помічники з префіксами `stats*`, `phrases*`, `auth*`, `import*`, `write*`… Файл BE-011/BE-012 теж матиме свій | У новому файлі всі функції — з префіксом `vr` (`vrGet`, `vrOracle`…); перед завершенням `grep -n "^function" tests/api/*.php` — дублікатів немає |
| 14 | Фронт (`shared/dates.js`, `admin/visits.js`, мок `getVisits`): надсилає `from`/`to` завжди обидва, у форматі `YYYY-MM-DD`, період ≤ 366 днів, `to` ≤ сьогодні за Ригою; споживає `total.{visits,scans,messages,byLang}` і `days[].{date,visits,scans,messages,byLang}`, `from`/`to` для заголовка; мок віддає ключі дня в порядку `date, visits, scans, messages, byLang` і `total` — `visits, scans, messages, byLang`; помилка валідації в моку — `400 bad_request` | Форма відповіді збігається з контрактом F-005 без розбіжностей; зайвих полів не додавати |

## 3. Проєктні рішення

- **Шари (D-17):** `Controller\VisitReportController` (параметри, відповідь) → `Domain\ReportPeriod` (чисті дані + календарна арифметика, без БД і HTTP) → `Repository\DailyStatsRepository::rangeTotals()` (SQL, **BE-011**) → `Domain\VisitReport` (чиста збірка відповіді з рядків). Назва контролера — `VisitReportController`, а не `VisitController`, щоб не плутатися з публічним `POST /api/visit` BE-011.
- **Валідація повністю до БД:** відхилений запит (`400`) і `401` не виконують жодного SQL.
- **Суворий розбір (як D-25):** без `trim`, без пом'якшень; кожен параметр обов'язковий. Порядок перевірок: наявність `from` → наявність `to` → формат і реальність `from` → формат і реальність `to` → `from ≤ to` → довжина ≤ 366 днів. Повідомлення містять **назву параметра**, але не повторюють значення, `fields` немає (як у фільтрах BE-005).
- **Помилка домену — окремий `Domain\InvalidPeriodException` (extends `InvalidArgumentException`):** контролер перехоплює саме її й перетворює на `ApiException::badRequest`. Загальний `InvalidArgumentException` (баг у коді) лишається `500`, а не маскується під `400`.
- **Календарна арифметика — лише через `DateTimeImmutable` в UTC** (факти 3, 5, 6). Секунди, `strtotime` без явного поясу, `+86400` — заборонені.
- **Один запит на період, дні доповнює PHP:** порожніх днів у БД немає, а рекурсивних CTE у MySQL 5.7 теж немає — ряд дат у SQL не побудувати. `total` рахується **у тому ж проході**, що й `days`, тож збігається із сумою днів за побудовою (фронт не перераховує).
- **`byLang` завжди `lv, en, ru`** — ключі з `Languages::CODES`; ім'я метрики — `StatMetrics::message($lang)` (BE-011). `messages` дня = сума трьох мов (окремої метрики в БД немає).
- **Невідомі метрики й рядки поза періодом ігноруються** (захист від майбутніх метрик, які додасть інша задача); `count` додається, а не присвоюється.
- Усі числа — `int`; `Response::json(…, 200, ['Cache-Control' => 'no-store'])`; `Db::pdo()` викликається всередині `index`, не в конструкторі.
- Маршрут: `$router->get('/api/admin/visits', [new VisitReportController(), 'index']);` у `routes.php` після маршруту `stats`.
- **Історія для тестів — фікстура, що вантажиться на вимогу**, а не в `reseed()` (розбіжність із буквою AC «сід розширено» — див. крок 5 і відкрите питання 3): так тести BE-011/BE-012, які перевіряють точні лічильники й `2026-03-29`/`2026-10-25` з нуля, не бачать чужих рядків.
- Рішення в `decisions.md` — **наступний вільний номер** (очікувано D-35: D-33 і D-34 займають BE-011 і BE-012; перед записом переглянь файл).

## 4. Карта файлів

Створити:
- `src/Domain/ReportPeriod.php`
- `src/Domain/InvalidPeriodException.php`
- `src/Domain/VisitReport.php`
- `src/Controller/VisitReportController.php`
- `database/seed/daily_stats.php` (історія для тестів)
- `tests/api/visits-report.php`

Змінити:
- `src/routes.php` (1 маршрут)
- `src/Database/Seeder.php` (`loadStats()`, `runStats()`)
- `tests/run.php` (юніт-перевірки)
- `tests/smoke.php` (+2 перевірки)
- Документація: `backend/docs/decisions.md`, `architecture.md`, `api-conventions.md`, `database.md`, `testing.md`, `security.md`, `docs/BACKEND.md`

Не чіпати: `frontend/`, `docs/API.md`, `database/migrations`, `schema.sql`, `DailyStatsRepository`, `RigaClock`, `VisitController`/`MessageController` (BE-011/BE-012), `src/Http/*`, `src/Auth/*`, `PhraseRepository`, `docker-compose.yml`, `.env.example`, `docs/tasks/**`. `docs/FRONT-TO-BACK.md` (F-005) — відповідь пише BE-011; BE-013 доповнює її лише якщо в реалізації з'явилась розбіжність із запитом.

## 5. Кроки реалізації

### Крок 1 · Звірка з BE-011 і експерименти

1. Прочитай код BE-011/BE-012 (`DailyStatsRepository`, `RigaClock`, міграція, зміни в `reseed()`, тест-файл) і порівняй із припущеннями з п. 1. Розбіжності запиши.
2. Експеримент з факту 10 (одноразовим скриптом у `php -r` усередині контейнера, без змін у репозиторії): `createFromFormat('!Y-m-d', …)` на `0999-01-01` і `9999-12-31` дає канонічний рядок; `RigaClock::isDay` приймає `0999-01-01`, `9999-12-31` і відхиляє `0000-01-01`. Запит `rangeTotals` на `9999-12-31 … 9999-12-31` на `orakuls_test` — без помилки.
3. Результати — у звіт і, за потреби, у D-xx.

### Крок 2 · `Domain\ReportPeriod` і `Domain\InvalidPeriodException`

`src/Domain/ReportPeriod.php` — незмінний об'єкт (як `PhraseFilter`), без залежностей від `Http`/`Repository`; **єдина залежність — `Support\RigaClock::isDay()`** (одна перевірка дати на весь проєкт). Це розширює правило `Domain ──► Support\Iso8601 (лише Phrase)` в `architecture.md` — оновити його в кроці 7 (`Domain ──► Support\Iso8601 (Phrase), Support\RigaClock (ReportPeriod)`):

```php
final class ReportPeriod
{
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $from,       // канонічне 'YYYY-MM-DD'
        public readonly string $to,
        public readonly int $dayCount,      // включно з обома кінцями, 1…366
        private readonly DateTimeImmutable $start,   // UTC-північ дати from
    ) {}

    /** @throws InvalidPeriodException повідомлення з назвою параметра, без значення, що прийшло */
    public static function create(string $from, string $to): self;

    /** null, якщо не 'YYYY-MM-DD' або дата неіснуюча (2026-02-30, 2026-13-01, 2026-02-29 у невисокосному) */
    public static function parseDate(string $value): ?DateTimeImmutable;

    /** Усі дні періоду по зростанню, 'YYYY-MM-DD'. @return list<string> */
    public function days(): array;
}
```

- `parseDate`: `RigaClock::isDay($value)` (регулярка + реальність дати + рік ≥ 0001, одне місце правди з BE-011) → `DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'))`; повертає `null`, якщо `isDay` каже `false`. `!` скидає час до півночі. Власної регулярки в `ReportPeriod` **не дублювати** (факт 4 описує, чому потрібні обидві перевірки — вони вже всередині `isDay`).
- `create`: `parseDate($from)` (інакше `InvalidPeriodException("Parameter 'from' must be a real date in YYYY-MM-DD format")`), те саме для `to`; потім `$start > $end` → `"Parameter 'from' must not be later than 'to'"`; потім `$count = (int) $start->diff($end)->days + 1; $count > MAX_DAYS` → `'Period must not exceed 366 days'`. **Довжину перевіряй до будь-якої побудови списку.**
- `days()`: цикл `for ($i = 0; $i < $dayCount; $i++) $days[] = $this->start->modify("+{$i} days")->format('Y-m-d')`. Від початкової дати, а не накопиченням, і за кількістю, а не «до `$end`» — тож `to = 9999-12-31` не виходить за рік 9999.
- Коментар до коду: чому UTC і календарні дати (доба Риги в DST — 23/25 год), чому довжина перевіряється до побудови.

`InvalidPeriodException` — `final class … extends InvalidArgumentException {}` (один рядок + докблок).

**Юніт-перевірки** (`tests/run.php`, без БД) — таблиця «вхід → результат або виняток»:

| Вхід | Результат |
|------|-----------|
| `('2026-11-03', '2026-11-03')` | `dayCount 1`, `days() = ['2026-11-03']` |
| `('2026-10-05', '2026-11-03')` | `dayCount 30`, перший `2026-10-05`, останній `2026-11-03`, без дублів |
| `('2026-02-30', …)`, `('2026-13-01', …)`, `('2026-00-10', …)`, `('2026-04-31', …)`, `('2026-02-29', …)`, `('2100-02-29', …)` | `InvalidPeriodException`, повідомлення містить `from` і **не** містить введеного значення |
| `('2028-02-29', '2028-03-01')`, `('2000-02-29', '2000-02-29')` | ок (високосні); `days()` першого — 3 дні з `2028-02-29` |
| `('2026-1-5', …)`, `('26-01-01', …)`, `('2026/01/01', …)`, `('20260101', …)`, `('2026-01-01T00:00:00Z', …)`, `(' 2026-01-01', …)`, `('2026-01-01 ', …)`, `("2026-01-01\n", …)`, `('', …)`, `('+2026-01-01', …)`, `('٢٠٢٦-٠١-٠١', …)` | `InvalidPeriodException` |
| те саме у `to` | виняток із назвою `to` |
| `('2026-11-04', '2026-11-03')` | виняток («later than») |
| `('2026-01-01', '2027-01-01')` | ок, `dayCount 366` |
| `('2026-01-01', '2027-01-02')` | виняток («366 days») |
| `('2024-01-01', '2024-12-31')` (високосний, 366) | ок; `('2024-01-01', '2025-01-01')` (367) — виняток |
| `('2025-01-01', '2025-12-31')` | `dayCount 365` |
| `('0001-01-01', '9999-12-31')` | виняток **швидко** (до побудови списку; тест вимірює < 50 мс і пам'ять) |
| `('9999-12-31', '9999-12-31')` | ок, `['9999-12-31']` (без виходу за 9999) |
| `('0999-01-01', '0999-01-02')` | за результатом експерименту (факт 10) |
| `('2026-03-28', '2026-03-30')` | `['2026-03-28','2026-03-29','2026-03-30']` — **3** дні |
| `('2026-10-24', '2026-10-26')` | `['2026-10-24','2026-10-25','2026-10-26']` — 3 дні, `2026-10-25` один раз |
| `('2026-03-01', '2026-10-31')` | 245 днів, усі унікальні, послідовні (різниця між сусідніми — рівно 1 доба за незалежним оракулом на `gmmktime`) |
| `('2025-12-30', '2026-01-02')` | `['2025-12-30','2025-12-31','2026-01-01','2026-01-02']` |
| `('2026-02-27', '2026-03-02')` | `['2026-02-27','2026-02-28','2026-03-01','2026-03-02']` (29 лютого немає) |

Окремо: усі перевірки `days()` для DST-періодів повторюються **при зміненому поясі за замовчуванням** (`date_default_timezone_set('Europe/Riga')`, потім `'America/New_York'`; у `finally` повернути `UTC`) — результат ідентичний (захист від мутації «без явного UTC»).

### Крок 3 · `Domain\VisitReport`

Чиста збірка відповіді з рядків репозиторію; не залежить від `Http`/`Repository`. Залежить лише від `Languages` і `StatMetrics` (BE-011).

```php
final class VisitReport
{
    /** Метрики daily_stats, які читає звіт (імена задає BE-011). @return list<string> */
    public static function metrics(): array;   // = StatMetrics::CODES (visit, scan, message_lv, message_en, message_ru) — єдине джерело імен метрик, власного списку немає

    /**
     * @param list<string> $dates усі дні періоду по зростанню (ReportPeriod::days())
     * @param iterable<array<string, mixed>> $rows рядки rangeTotals(): day, metric, count
     * @return array{total: array, days: list<array>}
     */
    public static function build(array $dates, iterable $rows): array;
}
```

Алгоритм `build`:
1. `$counts = array_fill_keys($dates, [])` (день → метрика → кількість).
2. Для кожного рядка: `$day = (string) $row['day']`; якщо дня немає в `$counts` — пропустити; `$counts[$day][(string) $row['metric']] += (int) $row['count']` (**додавання**, не присвоєння; метрика, якої немає у `metrics()`, ігнорується).
3. Для кожної дати з `$dates` (у порядку списку) зібрати `['date' => …, 'visits' => …, 'scans' => …, 'messages' => …, 'byLang' => ['lv' => …, 'en' => …, 'ru' => …]]` — ключі в **цьому порядку**; `byLang` будується циклом по `Languages::CODES`, `messages` = сума трьох.
4. `total` — у тому ж проході додаванням полів днів; ключі `visits, scans, messages, byLang` (без `date`).
5. Повернути `['total' => …, 'days' => $days]` (`days` — список, `array_values`).

**Юніт-перевірки** (без БД):

| Перевірка | Що |
|-----------|----|
| порожні рядки, 3 дати | 3 дні, усі нулі `int`, `byLang` з трьома ключами в порядку `lv, en, ru`, `total` — нулі |
| порожні `$dates` | `days = []`, `total` — нулі (захист, у контролері такого не буває) |
| мапінг метрик | `visit → visits`, `scan → scans`, `message_lv/en/ru → byLang.lv/en/ru`; `messages` = сума; жодна метрика не потрапляє в чуже поле (різні числа для кожної, тож перестановка `en`↔`ru` або `scan`↔`visit` помітна) |
| типи | `count` рядком `"7"` і `day` типу `Stringable`/рядок → у виході `int` 7; `json_encode` дає `"visits":7`, а не `"7"`; `byLang` — `{…}`, не `[…]`; ключі дня й `total` у точному порядку |
| дні без рядків між днями з рядками | пропуски заповнені нулями, порядок за `$dates`, а не за порядком рядків (подати рядки в зворотному порядку) |
| дублікати `(day, metric)` | додаються (`5 + 3 = 8`) |
| рядок поза періодом і невідома метрика (`foo`) | ігноруються, `total` не змінюється |
| `total` | дорівнює сумі `days` за кожним полем, зокрема `byLang.*`; велике `count` (`4294967295` двічі) → `8589934590` як `int` |
| `metrics()` | дорівнює `StatMetrics::CODES` (BE-011): `['visit','scan','message_lv','message_en','message_ru']`; мапінг мови на метрику — `StatMetrics::message($lang)`, а не `'message_' . $lang` |

### Крок 4 · `VisitReportController` і маршрут

`src/Controller/VisitReportController.php` — `final class`, докблок: токен перевіряє `AdminGuard` (D-20), параметри `from`/`to` обов'язкові, відповідь не кешується.

```php
final class VisitReportController
{
    public function index(Request $request, array $params): Response
    {
        $period = self::parsePeriod($request);          // 400 до звернення до БД

        // Db::pdo() — тут, а не в конструкторі
        $rows = (new DailyStatsRepository(Db::pdo()))->rangeTotals($period->from, $period->to);
        $report = VisitReport::build($period->days(), $rows);

        return Response::json(
            ['from' => $period->from, 'to' => $period->to, 'timezone' => RigaClock::TIMEZONE, 'total' => $report['total'], 'days' => $report['days']],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    /** Публічний і статичний, щоб розбір тестувався без БД. Кидає ApiException 400. */
    public static function parsePeriod(Request $request): ReportPeriod;
}
```

`parsePeriod`: для `from` і `to` — `$v = $request->query('from')` (масив → `400` від `query()`); `null` або `''` → `ApiException::badRequest("Parameter 'from' is required: YYYY-MM-DD")`; далі `ReportPeriod::create()` в `try`, `InvalidPeriodException` → `ApiException::badRequest($e->getMessage())`. Порядок з п. 3. `from`/`to` у відповіді — канонічні рядки періоду (збігаються із запитом, бо розбір суворий).

`routes.php`: додай маршрут `GET /api/admin/visits` під коментарем «Звіт зі статистики (BE-013)», після `stats`; додай `use`.

**Юніт-перевірки `parsePeriod`** (`new Request('GET', '/api/admin/visits', $query)`):

| Вхід | Результат |
|------|-----------|
| `['from' => '2026-11-01', 'to' => '2026-11-03']` | період, 3 дні |
| `[]`, лише `from`, лише `to`, `['from' => '', 'to' => '']` | `400 bad_request` |
| `['from' => ['2026-11-01'], 'to' => '2026-11-03']` (масив) | `400` |
| погані значення з таблиці кроку 2 | `400`, `errorCode = bad_request`, `fields = []` |
| `['from' => '<script>', 'to' => '2026-11-03']` | `400`, повідомлення **не містить** `<script>` |
| зайві ключі (`x`, `category`, `lang`) | ігноруються |

### Крок 5 · Історія для тестів (`database/seed/daily_stats.php`, `Seeder`)

Фікстура — масив днів (відсутня метрика = рядка в БД немає; значення довільні, але **фіксовані**, `scan ≤ visit`):

```php
return [
    ['day' => '2025-12-30', 'visit' => 12, 'scan' => 9,  'message_lv' => 4,  'message_en' => 1, 'message_ru' => 3],
    ['day' => '2025-12-31', 'visit' => 20, 'scan' => 15, 'message_lv' => 7,  'message_en' => 2, 'message_ru' => 5],
    ['day' => '2026-01-01', 'visit' => 8,  'scan' => 3,  'message_lv' => 2,                     'message_ru' => 1],   // без en
    ['day' => '2026-01-03', 'visit' => 5,  'scan' => 5],                                                             // без послань
    ['day' => '2026-02-28', 'visit' => 30, 'scan' => 22, 'message_lv' => 10, 'message_en' => 4, 'message_ru' => 6],
    ['day' => '2026-03-01', 'visit' => 41, 'scan' => 33, 'message_lv' => 14, 'message_en' => 5, 'message_ru' => 9],
    ['day' => '2026-03-28', 'visit' => 25, 'scan' => 20, 'message_lv' => 8,  'message_en' => 3, 'message_ru' => 5],
    ['day' => '2026-03-29', 'visit' => 18, 'scan' => 14, 'message_lv' => 6,  'message_en' => 2, 'message_ru' => 4],   // перехід на літній час
    ['day' => '2026-03-30', 'visit' => 22, 'scan' => 17, 'message_lv' => 7,  'message_en' => 3, 'message_ru' => 5],
    ['day' => '2026-10-24', 'visit' => 35, 'scan' => 27, 'message_lv' => 11, 'message_en' => 4, 'message_ru' => 8],
    ['day' => '2026-10-25', 'visit' => 28, 'scan' => 21, 'message_lv' => 9,  'message_en' => 3, 'message_ru' => 6],   // перехід на зимовий час
    ['day' => '2026-10-26', 'visit' => 31, 'scan' => 24, 'message_lv' => 10, 'message_en' => 4, 'message_ru' => 7],
];
```

Усі дати — **у минулому** відносно сьогодні (2026-11-03) і не збігаються з `2026-11-03`, тож не перетинаються зі «сьогоднішніми» перевірками наскрізного тесту.

- `Seeder::loadStats(string $file): list<array{day: string, metric: string, count: int}>` — статичний, без БД, за зразком `load()`: кожен `day` — реальна дата (`ReportPeriod::parseDate`), без повторів, ключі лише з `day` і `VisitReport::metrics()`, значення — цілі `> 0` (рядок `count = 0` API створити не може), `scan ≤ visit`; порушення — `RuntimeException`. Повертає плоский список рядків для `INSERT`.
- `Seeder::runStats(string $file): int` — `DatabaseGuard::assertTestDatabase()` першою дією, `TRUNCATE TABLE daily_stats`, багаторядковий prepared `INSERT`, повертає кількість рядків.
- **`reseed()` і `Seeder::run()` лишаються без змін**: за замовчуванням `daily_stats` порожня (BE-011/BE-012 рахують з нуля). Історію підвантажує лише `vrWithHistory()` у тесті BE-013.
- Юніт-перевірка в `tests/run.php`: `Seeder::loadStats()` на справжній фікстурі проходить, на зіпсованих (неіснуюча дата, повтор дня, невідома метрика, `scan > visit`, від'ємне, нечислове) — кидає виняток; фіксує кількість рядків і суму `visit` (контрольні числа для оракула).

**Ручні контрольні числа з фікстури** (використовуються в тестах як константи, поряд з оракулом):

| Період | visits | scans | messages | lv | en | ru |
|--------|-------:|------:|---------:|---:|---:|---:|
| `2025-12-29 … 2026-01-03` (6 днів: перший і п'ятий — нулі) | 45 | 32 | 25 | 13 | 3 | 9 |
| `2026-02-27 … 2026-03-02` (4 дні, межа місяця) | 71 | 55 | 48 | 24 | 9 | 15 |
| `2026-03-28 … 2026-03-30` (DST) | 65 | 51 | 43 | 21 | 8 | 14 |
| `2026-10-24 … 2026-10-26` (DST) | 94 | 72 | 62 | 30 | 11 | 21 |

### Крок 6 · Тести ендпоінта (`tests/api/visits-report.php`)

Файл самодостатній, усі функції — з префіксом `vr` (факт 13). Помічники:

```php
function vrToken(): string;                       // один раз на процес (інакше вхід рахується в statementCount)
function vrGet(array $query = [], ?string $token = null): Response;   // GET /api/admin/visits з токеном
function vrAnonymous(array $query = []): Response;                    // без Authorization
function vrOf(string $from, string $to): array;   // розібране тіло 200
function vrToday(): string;                       // єдина точка виклику (new RigaClock())->today() (BE-011)
function vrOracle(string $from, string $to, array $rows): array;      // незалежне очікування (див. нижче)
function vrWithHistory(callable $fn): void;       // TRUNCATE + Seeder::runStats(...), $fn, у finally reseed()
function vrInsert(array $rows): void;             // прямий INSERT у daily_stats (day, metric, count)
function vrWithStableDay(callable $fn): void;     // повторює $fn, якщо vrToday() змінився за час виконання (північ за Ригою)
```

**Оракул** `vrOracle` не використовує `ReportPeriod`/`VisitReport`: ряд дат — `gmdate('Y-m-d', gmmktime(0, 0, 0, $m, $d, $y) + 86400 * $i)` (UTC, без DST), суми — прості цикли над рядками фікстури (`Seeder::loadStats()`). Додатково кілька перевірок звіряються з **ручними константами** з кроку 5 (щоб помилка в самому оракулі не лишилась непоміченою).

Тести, що пишуть у БД, — `vrWithHistory`/`withFreshSeed`; `withFreshSeed` не вкладати у `withBigTable` і навпаки (`TRUNCATE` комітить транзакцію). Кожен тест у `finally` повертає порожню `daily_stats` (через `reseed()`).

| № | Тест | Перевірки | AC |
|---|------|-----------|----|
| 1 | без токена → `401` | `unauthorized`; і з валідними, і з **поганими** параметрами (`?from=bad`) — guard раніше за валідацію; сміттєвий токен → `401`; `statementCount` = 0 | 1 |
| 2 | форма і типи | `200`; ключі відповіді рівно `from, to, timezone, total, days` у цьому порядку; `timezone === 'Europe/Riga'`; `from`/`to` повторюють запит; `total` — ключі `visits, scans, messages, byLang`; день — `date, visits, scans, messages, byLang`; `byLang` — рівно `lv, en, ru` у цьому порядку; усі числа `int`; сирий JSON містить `"byLang":{"lv":` і не містить `"byLang":[`; `"days":[`; `Content-Type` JSON; `Cache-Control: no-store` | 2, 3, 4, 10 |
| 3 | порожня таблиця | `daily_stats` порожня, період 5 днів → рівно 5 елементів `days`, усі числа `int 0` (не `null`, не рядок), `total` — нулі; сирий JSON `"visits":0` | 3, 4 |
| 4 | історія за оракулом | `vrWithHistory`: періоди з таблиці кроку 5 (чотири ручні), «весь діапазон фікстури» `2025-12-29 … 2026-10-27`, перехід року (`2025-12-31 … 2026-01-01`), по одному дню (день з даними, день без, день з частковими метриками): `days` і `total` збігаються з оракулом **і** з ручними константами | 3, 5, 9 |
| 5 | кожен день присутній | для N = 1, 2, 7, 30, 31, 365, 366: `count(days) === N`; `date` строго зростає, сусідні дні — рівно на добу; перший = `from`, останній = `to` | 3 |
| 6 | інваріанти | на історії: `total.* === Σ days.*` за кожним полем (включно з `byLang.*`); `messages === lv + en + ru` у кожному дні й у `total`; `scans ≤ visits` на фікстурі | 5 |
| 7 | один день, майбутнє | `from = to` → 1 день; `to` через рік від сьогодні (`vrToday()` + 365 діб за оракулом) → `200`, нулі; весь період у майбутньому (`2099-01-01 … 2099-01-03`) → `200`, 3 дні з нулями; `9999-12-31 … 9999-12-31` → `200`, 1 день ; `0000-01-01 … 0000-01-02` → `400` (рік `0000` відхиляє `RigaClock::isDay`, факт 9) | 8 |
| 8 | межі 366/367 | `2026-01-01 … 2027-01-01` → `200`, 366 днів; `… 2027-01-02` → `400`; високосний `2024-01-01 … 2024-12-31` → `200` (366), `… 2025-01-01` → `400`; `from` на 1 день пізніше за `to` → `400`; `0001-01-01 … 9999-12-31` → `400` швидко (< 200 мс, без запиту до БД) | 8 |
| 9 | погані значення → `400` | таблиця кроку 2 по обох параметрах + відсутні/порожні/масиви + ін'єкція (`2026-01-01'; DROP TABLE daily_stats;--`): `400 bad_request`, **без `fields`**, `no-store`, `statementCount` = 0, у тілі немає введеного значення; позитивні контролі: `2028-02-29`, `2000-02-29` → `200` | 9 |
| 10 | зайві параметри | `&x=1&category[]=y&lang=lv&perPage=5` → тіло байт у байт таке саме, як без них | 9 |
| 11 | методи | з дійсним токеном `POST`/`PUT`/`DELETE`/`PATCH` на `/api/admin/visits` і `GET /api/admin/visits/1` → `404 not_found`; без токена → `401` | 11 |
| 12 | DST і межі | `2026-03-28 … 2026-03-30`, `2026-10-24 … 2026-10-26` на історії (ручні константи): по 3 дні, `2026-03-29` і `2026-10-25` рівно по одному разу з правильними даними; `2026-03-01 … 2026-10-31` → 245 днів, усі дати унікальні, 31+30+… за місяцями; **те саме при `date_default_timezone_set('Europe/Riga')`** (у `finally` — `UTC`): тіло ідентичне; `2028-02-29` з прямим `INSERT` з'являється у звіті, а `2026-02-29` — `400` | 12 |
| 13 | невідомі й нульові дані | прямий `INSERT`: метрика `foo` (ігнорується), рядок `count = 0` (нулі), рядок поза періодом (не впливає), `count = 4294967295` у двох днях → `total` = `8589934590` як `int`, у JSON — число | 4, 5 |
| 14 | рівно один SQL-запит | `statementCount(fn () => vrGet(...))` = 1 для: одного дня, 30 днів, 366 днів, порожньої таблиці, історії; `400` і `401` = 0 | 14 |
| 15 | швидкодія | `vrInsert` 366 днів × 5 метрик (1830 рядків; значення — детерміновані, напр. `visit = номер дня + 1`): `200`, 366 днів, `total` за оракулом; медіана 15 запитів < 200 мс | 14 |
| 16 | **наскрізний** | `vrWithStableDay` + `withFreshSeed`: до — звіт за сьогодні нульовий (історії немає); потім через `apiRequest`: 5 × `POST /api/visit` `{"source":"qr"}`, 3 × `{"source":"direct"}`, 1 × `{"source":123}` (рахується як `direct`), 4 × `GET /api/message?lang=lv`, 2 × `lang=en`, 3 × `lang=ru` (один з викликів з `exclude`); звіт `[сьогодні, сьогодні]` → точно `visits 9, scans 5, messages 9, byLang {lv 4, en 2, ru 3}`; `total` = той самий день; звіт `[вчора, сьогодні]` → 2 дні, вчора — нулі; `scans ≤ visits`; **без кешу**: звіт одразу після кожного кроку змінюється | 6, 3 |
| 17 | наскрізний: що не рахується | у тому ж стилі: `GET /api/message?lang=de` (`400`), `POST /api/visit` з порожнім/не-JSON тілом (`400`), `GET /api/message?lang=lv` при порожній `phrases` (`DELETE FROM phrases` → `404`) — звіт не змінюється; метрики в БД відповідають іменам, які пише BE-011/BE-012 (прямий `SELECT metric FROM daily_stats` після кроку 16 — рівно `visit`, `scan`, `message_lv`, `message_en`, `message_ru`) | 6 |
| 18 | чистота | після тестів `daily_stats` порожня, `phrases` — 30 рядків, `max(id)` 30 | — |

Деталі виконання:
- `vrToken()` отримуй **до** `statementCount` і таймерів (вхід теж робить SQL).
- Наскрізний тест залежить від годинника: `vrWithStableDay` бере `vrToday()` до і після й, якщо день змінився (проміжок близько півночі за Ригою), повторює тіло тесту один раз. Фікстура історії в цьому тесті **не** вантажиться.
- Сирий JSON перевіряй з `$response->body`, а не з `decode()`.

**`tests/smoke.php`:** +2 (безпечні для прода, нічого не читають із БД): `GET /api/admin/visits?from=2026-11-01&to=2026-11-03` без токена → `401`, `no-store`; `GET /api/admin/visits?from=bad` без токена → `401` (guard раніше за валідацію). Було N → N + 2 (N — після BE-011/BE-012).

### Крок 7 · Документація (описує те, що **є** в коді)

| Файл | Що змінити |
|------|-----------|
| `decisions.md` | **D-35** (або наступний вільний) · Звіт зі статистики за період: суворий розбір `from`/`to` (формат + реальність дати + порядок + ≤ 366 днів, `400` без `fields`, повідомлення без значень); календарна арифметика над UTC-датами, а не секунди й не пояс Риги (доба 23/25 год — приклади 2026-03-29/10-25); довжина перевіряється до побудови списку; один `SELECT` без `GROUP BY`/CTE, дні й `total` будує PHP за один прохід; `byLang` завжди три мови; невідомі метрики ігноруються; відсутність кешу; історія в тестах — на вимогу. **Альтернативи:** ряд дат у SQL (у MySQL 5.7 немає рекурсивних CTE), `GROUP BY`/`WITH ROLLUP` для `total` (друге джерело правди), `DateTimeImmutable` у поясі Риги, пом'якшений розбір з `trim`, мовчазне обрізання періоду до 366 днів. **Наслідки:** нова метрика в `daily_stats` потрапляє у звіт лише після зміни `VisitReport` |
| `architecture.md` | `VisitReportController` у структурі й таблиці компонентів; `Domain\ReportPeriod`, `InvalidPeriodException`, `VisitReport` (чисті, без `Http`/`Repository`); рядок про `DailyStatsRepository` (BE-011) уточнити: `rangeTotals()` читає звіт; правило залежностей `Domain`: додати `Support\RigaClock` (лише `ReportPeriod`, для `isDay()`), решта без змін; `VisitReport` залежить від `Languages` і `StatMetrics` |
| `api-conventions.md` | Розділ `GET /api/admin/visits (BE-013)`: таблиця параметрів, форма відповіді й порядок ключів, `days` без пропусків, `byLang` завжди три мови, `int`, `400` за кожної умови без `fields`, майбутнє дозволене, зайві параметри ігноруються, інші методи — `404`, `no-store`, один SQL-запит |
| `database.md` | Розділ «Звіт за період (BE-013)»: `SELECT … FROM daily_stats WHERE day BETWEEN ? AND ?` (діапазонне читання по PK `(day, metric)`, ≤ 366 × 5 = 1830 рядків), чому порожні дні доповнює PHP, типи (`DATE` → рядок, `INT UNSIGNED` → `int`), продуктивність (виміряне число); у «Склад сіду» — фікстура `database/seed/daily_stats.php` (дні, чому вона не в `reseed()`) |
| `testing.md` | Рядок `tests/api/visits-report.php` у таблиці файлів; розділ «Що покриває `tests/api/visits-report.php`» (оракул на `gmmktime`, ручні константи, DST, наскрізний тест, `vrWithStableDay`); нові юніт-перевірки в `run.php` (`ReportPeriod`, `VisitReport`, `parsePeriod`, `Seeder::loadStats`); нові числа тестів і smoke; перелік мутацій |
| `security.md` | У таблицю «Ін'єкції та ввід»: `from`/`to` звіту — суворий розбір до БД, лише параметри `BETWEEN ? AND ?`, `400` без відображення вводу, гігантський період відхиляється до побудови списку, лише адмін → ✅ BE-013 |
| `docs/BACKEND.md` | Новий розділ «Звіт зі статистики (BE-013)» після BE-008: ендпоінт, статус «готово», приклад відповіді, правила (`days` повні, `total` від бекенда, три мови, майбутнє дозволене, `no-store`), `400`-таблиця (у UI не очікується), примітка «`API.md` оновить фронт разом із закриттям F-005», підсумок перевірки з фронтом (крок 8, п. 4) |

Перевір, що посилання між документами не зламані, що всі `D-xx` існують і жоден документ не називає звіт «майбутнім».

## 6. Крок 8. Фінальна перевірка

1. `tests/run.php`, `tests/api.php` (**двічі поспіль**), `tests/smoke.php`, `php bin/build-schema.php --check` — усе зелене; числа до/після записати. `grep -n "^function" tests/api/*.php` — дублікатів назв немає.
2. Дев-БД `orakuls` не змінена цією задачею; у `orakuls_test` після прогону: `phrases` 30 рядків, `max(id)` 30, `daily_stats` порожня.
3. **Наскрізна перевірка по справжньому HTTP** (як у BE-008): у контейнері `DB_NAME=orakuls_test timeout 300 php -S 127.0.0.1:8001 -t public` у фоні; історію завантаж одноразово `php -r` (`define('APP_ROOT', getcwd()); require …/src/bootstrap.php; (new App\Database\Seeder(App\Support\Db::pdo(), …/phrases.php))->runStats(…/daily_stats.php);` з `DB_NAME=orakuls_test` — `DatabaseGuard` захищає). Токен — через `\App\Auth\TokenService` з `AuthConfig::fromEnv()` (секрети не друкуй). `curl`: звіт за `2026-10-24 … 2026-10-26` (числа з таблиці кроку 5, `no-store`), без токена (`401`), `from=2026-02-30` (`400`), `from>to` (`400`), 367 днів (`400`), `POST` (`404`); `POST /api/visit` і `GET /api/message` → звіт за сьогодні змінився. Після — `reseed()` і зупинка сервера.
4. **З фронтом на `VITE_API_MOCK=false`** (AC): `frontend/.env.development.local` з `VITE_API_MOCK=false` і `API_PROXY_TARGET=http://localhost:8001` (сервер з кроку 3 на `orakuls_test` з історією; **файл у `frontend/` не комітити й після перевірки повернути як було** — це лише локальна конфігурація, код фронта не змінюється), `npm run dev` у `frontend/`. Перевір у браузері з відкритою консоллю: вхід; вкладка «Статистика»; періоди «Сегодня», «Вчера», «7 дней», «30 дней» (містить 24–26 жовтня з даними), «Этот месяц», «Прошлый месяц» (жовтень), довільні дати (в т. ч. рівно 366 днів); плитки, графік і таблиця з підсумковим рядком збігаються з числами `curl`; **жодних помилок у консолі й мережевих `4xx/5xx`**; порожні дні відображаються нулями; нова відкрита сторінка користувача з `?qr` (сканування +1) і видане послання з'являються після оновлення. Результат — у `docs/BACKEND.md`.
5. **Мутації** (скриптом із перевіркою, що файл справді змінився; після всіх — відновлення і `sha256sum` до/після; жодна не повинна пройти непоміченою):

| # | Мутація | Має впасти |
|---|---------|-----------|
| а | `days` будується лише з рядків БД (без заповнення порожніх днів) | 3, 4, 5, юніт `VisitReport` |
| б | `total` — не сума днів (напр. лише останній день) | 4, 6, юніт |
| в | `byLang` повертається списком (`array_values`) | 2, юніт |
| г | `messages` = `lv + en` (без `ru`) | 4, 6, юніт |
| д | прибрати `(int)` для `count` (віддавати рядки) | 2, 4 (типи), юніт |
| е | прибрати `Cache-Control` з `200` | 2 |
| є | межа довжини `>= 366` (відхиляє рівно 366) | 8, 5, юніт |
| ж | межа довжини `> 367` (пропускає 367) | 8, юніт |
| з | прибрати перевірку `from > to` | 8, юніт |
| и | лише регулярка, без `createFromFormat` і порівняння (пропускає `2026-02-30`) | 9, юніт |
| і | лише `createFromFormat`, без регулярки (пропускає `2026-1-5`) | 9, юніт |
| ї | регулярка без `D` | 9, юніт |
| й | кількість днів як `floor((ts_to − ts_from) / 86400) + 1` у поясі Риги | 12, юніт (DST) |
| к | `days()` накопиченням `+86400` с у поясі Риги | 12, юніт (дубль 2026-10-25) |
| л | `new DateTimeImmutable($value)` без явного UTC | 12 (зміна поясу), юніт |
| м | довжина перевіряється після побудови `days()` | 8 (швидкість `0001 … 9999`), юніт |
| н | зайвий `SELECT 1` до основного запиту | 14 |
| о | невідома метрика додається до `visits` | 13, юніт |
| п | `=` замість `+=` для `(day, metric)` | юніт (дублікати) |
| р | `message_en` і `message_ru` поміняні місцями | 4, 16, юніт |
| с | `scan` додається до `visits` замість `scans` | 4, 16, юніт |
| т | `timezone` = `'UTC'` | 2 |
| у | `InvalidArgumentException` перехоплюється замість `InvalidPeriodException` (маскує баги) | юніт |
| ф | валідація після `Db::pdo()`/запиту | 9, 14 (SQL на `400` ≠ 0) |

Якщо мутація не червонить жодного тесту — посилюй тест і повтори. Мутація «лише регулярка» перевіряє, що `2026-02-30` справді доходить до `createFromFormat`; поведінково еквівалентних мутацій очікується мало (одна можлива — `(int)` на `INT UNSIGNED`, якщо драйвер уже віддає `int`; її ловить лише юніт-перевірка `VisitReport` з рядковими `count`).

## 7. Пастки

- **Не рахуй дні секундами й не використовуй пояс Риги для арифметики** (факт 5): доба 23/25 год дає неправильну кількість днів і дубль `2026-10-25`. Лише `DateTimeImmutable` в UTC і календарні дати.
- **Не задавай пояс за замовчуванням неявно:** `new DateTimeImmutable($value)` бере `date_default_timezone_get()`; передавай `new DateTimeZone('UTC')` завжди.
- **Регулярка сама не відсікає неіснуючу дату, а `createFromFormat` — неканонічний формат** (факт 4): потрібні обидві перевірки і порівняння `format()` з вхідним рядком.
- **Модифікатор `D` і клас `[0-9]`** у регулярці (`"2026-01-01\n"`, арабсько-індійські цифри).
- **Довжину періоду перевіряй до `days()`:** інакше `0001-01-01 … 9999-12-31` з'їсть пам'ять.
- **`days()` будуй за кількістю, а не «до `to`»:** `to = 9999-12-31` не має виходити за рік 9999.
- **`from`/`to` у відповіді — канонічні рядки запиту**, а не результат форматування «сьогодні»; не додавай нових полів і не змінюй порядок ключів (фронт і мок на нього покладаються).
- **`byLang` і `total` — об'єкти** навіть із нулями; перевіряй сирий JSON, а не `decode()`.
- **Не вкладай `withFreshSeed` у `withBigTable`** і навпаки (`TRUNCATE` комітить транзакцію); `vrWithHistory` і `vrInsert` завжди завершуються `reseed()` у `finally`.
- **Наскрізний тест залежить від годинника** (північ за Ригою): `vrWithStableDay`; історію фікстури в ньому не вантажити (інакше «сьогодні» змішається з минулим у `[вчора, сьогодні]`, якщо дата фікстури колись збігнеться).
- **Фікстура не в `reseed()`:** інакше BE-011/BE-012 побачать чужі рядки на `2026-03-29`/`2026-10-25` і їх «+1» перестане бути точним.
- **Не змінюй наявні тести** (BE-003 … BE-012 лишаються зеленими без правок).
- **Не додавай кеш, нові поля, фільтри чи експорт** (поза обсягом).

## 8. Чого не робити

- Не змінювати `API.md`, схему БД, міграції, `schema.sql`, `DailyStatsRepository`, `RigaClock`, `VisitController`, `MessageController`, `src/Http/*`, `src/Auth/*`, жодних `docs/tasks/**`, код фронта.
- Не додавати індекси, кеш, `GROUP BY`/`ROLLUP`, нові метрики, відсотки конверсії, розбивки по категоріях чи годинах.
- Не вводити Composer і залежності; не використовувати можливості MySQL 8.0 (CTE, віконні функції).
- Не рахувати `scans`, `visits`, `messages` нічим, окрім рядків `daily_stats` (жодних запитів до `phrases`).
- Не залишати змінених файлів, даних у `orakuls_test`, тимчасових серверів чи правок `frontend/.env.development.local` після перевірок.

## 9. Відкриті питання (для замовника / виконавця BE-011)

1. ~~Форма рядків `rangeTotals()` і константи~~ — **закрито планом BE-011:** рядки `{day, metric, count}` (`string/string/int`), `StatMetrics::CODES`, `RigaClock::TIMEZONE`. Виконавець лише звіряє з реальним кодом (крок 1).
2. ~~Хто очищає `daily_stats` у `reseed()`~~ — **закрито:** BE-011 (`tests/db-lib.php`, крок 5 його плану).
3. ~~Буква AC «сід розширено історією»~~ — **вирішено замовником:** історія — окрема фікстура `database/seed/daily_stats.php` (файл і `Seeder::loadStats()`/`runStats()` створює **лише BE-013**), не в `reseed()`; AC у `task.md` уточнено.
4. ~~Рік `0000`~~ — **вирішено:** рік `0000` відхиляється через `RigaClock::isDay` (→ `400`); `0001…9999` приймаються (перевіряє експеримент кроку 1). `task.md` уточнено.
5. **Статус F-005** у `docs/FRONT-TO-BACK.md`: відповідь пише BE-011; хто переводить запис у `answered`/закриває після BE-013 — за домовленістю з BE-011 (BE-013 сам цей файл не чіпає, якщо розбіжностей із запитом немає).

## 10. Звіт

У кінці виклади звіт українською: (1) створені/змінені файли; (2) числа тестів (`run`, `api`, `smoke`) до та після; (3) результати кроку 8: HTTP-перевірка, перевірка з фронтом на `VITE_API_MOCK=false` (періоди, консоль), таблиця мутацій із тим, що саме впало, `sha256sum` до/після; (4) розбіжності з припущеннями про BE-011 і відхилення від плану та причини; (5) результати експериментів кроку 1; (6) сумніви та знахідки. Будь чесним: що не пройшло або не перевірено — так і пиши.
