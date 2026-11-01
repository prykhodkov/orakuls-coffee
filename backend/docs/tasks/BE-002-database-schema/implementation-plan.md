# BE-002 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та acceptance criteria — у [task.md](task.md); цей план їх не замінює, а деталізує. Якщо план суперечить `task.md` — зупинись і запитай користувача.

## 0. Перед початком

Прочитай (у цьому порядку):

1. `backend/CLAUDE.md`, `CLAUDE.md` у корені — правила роботи.
2. [task.md](task.md) — обсяг і acceptance criteria (AC).
3. `backend/docs/decisions.md` — рішення **D-11 … D-14** (не переглядати), а також D-01, D-04, D-08, D-09.
4. `backend/docs/database.md`, `testing.md`, `architecture.md` — що вже описано і що треба змінити після реалізації.
5. Код, на який спираєшся: `src/Support/Db.php`, `src/Support/Config.php`, `src/bootstrap.php`, `src/Http/ErrorHandler.php`, `tests/run.php`, `docker-compose.yml`.

Стан на старті: BE-001 виконано, контейнери `php` і `db` запущені (`docker compose ps` у `backend/`), `GET /api/health` → `200`. Робоча БД `orakuls` **порожня** (таблиць немає). Не коміть, доки користувач не попросить.

## 1. Підтверджені факти про середовище

Ці факти перевірені експериментами на реальному `mysql:5.7` і PHP 8.4 у Docker проєкту. На них спирається план — не перевіряй наново, але й не ігноруй.

| # | Факт | Наслідок для реалізації |
|---|------|-------------------------|
| 1 | `PDO::exec()` з кількома операторами **не кидає виняток, якщо помилка не в першому операторі**; наступні запити на цьому з'єднанні падають з `2014 Cannot execute queries while there are pending result sets` | Runner виконує операторів **по одному**. Потрібен `SqlSplitter` (крок 3). Ніколи не передавати файл міграції в `exec()` одним рядком |
| 2 | Рядок із 500 символів (`š` або 4-байтове `😀`) зберігається повністю (`CHAR_LENGTH = 500`) | `VARCHAR(500)` у utf8mb4 ок |
| 3 | 501-й символ у strict-режимі сесії → `PDOException`, `SQLSTATE 22001`, `errno 1406`; мовчки не обрізається | Зафіксувати тестом |
| 4 | `SELECT id, is_active` з нативними prepared statements повертає **`int`**, не рядок і не `bool`; `DATETIME` — рядок `'2026-11-01 13:12:09'` | `is_active` у JSON потрібно явно приводити `(bool)` (BE-005); дату форматує `Iso8601` |
| 5 | Сесія з нашими налаштуваннями: `@@time_zone = '+00:00'`, `UTC_TIMESTAMP() = NOW()` | Дати пишемо через `UTC_TIMESTAMP()` |
| 6 | `CREATE TABLE IF NOT EXISTS` при повторі не падає і нічого не змінює | Міграції пишемо ідемпотентно |
| 7 | `TRUNCATE TABLE` скидає `AUTO_INCREMENT` на 1; `GET_LOCK` / `RELEASE_LOCK` працюють (повертають `1`) | Сід дає детерміновані `id`; runner бере advisory-lock |
| 8 | Init-скрипт `.sql` у `/docker-entrypoint-initdb.d` виконується **після** створення `MYSQL_USER`; `CREATE DATABASE … ; GRANT ALL ON orakuls_test.* TO 'orakuls'@'%'` дає користувачу доступ до обох баз | Init-файл — `.sql`, не `.sh` (див. пастку CRLF) |
| 9 | `information_schema.COLUMNS / STATISTICS / TABLES` повертають тип, колацію, nullable, default, extra, індекси, рушій | Із них будується «підпис схеми» для перевірки паритету (крок 7) |
| 10 | Без `ENUM`: MySQL 5.7 ігнорує `CHECK` | Допустимість `category` перевіряє лише код (`Categories`) |
| 11 | Колація `utf8mb4_unicode_ci` робить `LIKE` нечутливим до регістру й діакритики; `ё`=`е`, `й`≠`и`; усі емодзі рівні між собою | Сід має містити відповідні випадки (крок 6); нічого вирішувати не треба, це D-13 |

## 2. Дизайн-рішення цього плану

Вони випливають з task.md і фактів вище. Якщо вважаєш, що якесь із них хибне, — скажи користувачу до реалізації, а не після.

1. **Кожен SQL-оператор виконується окремо.** Причина — факт 1. `SqlSplitter` розбиває файл на оператори, `Migrator` виконує їх у циклі.
2. **`schema.sql` генерується, не пишеться вручну.** `bin/build-schema.php` збирає його з `database/migrations/*.sql`. Тест перевіряє, що закомічений файл збігається з результатом генерації (ніякого розходження), а ще один тест — що застосування `schema.sql` дає ту саму схему, що й міграції (**паритет**).
3. **Таблиця `migrations` не створюється міграційним файлом.** Її створює сам runner (`CREATE TABLE IF NOT EXISTS`), а `schema.sql` містить той самий DDL плюс `INSERT IGNORE` застосованих імен — тому після ручного імпорту через phpMyAdmin runner не намагатиметься застосувати ці міграції вдруге.
4. **`created_at` / `updated_at` без `DEFAULT`.** Значення завжди передає код через `UTC_TIMESTAMP()`; strict-режим не дасть забути. Так часовий пояс сесії (наприклад, у phpMyAdmin) не підставить не-UTC мовчки.
5. **Імена в init-скрипті Docker зашиті** (`orakuls`, `orakuls_test`): `.sql` не вміє читати змінні оточення, а `.sh` ламається на CRLF (пастка 1). У файлі коментар: «змінюєш `DB_USER`/`DB_NAME` в `.env` — онови тут». Ім'я тестової БД у PHP — константа `TEST_DB_NAME = 'orakuls_test'`, не похідна від `DB_NAME`.
6. **Запобіжник дивиться на фактичну БД з'єднання** (`SELECT DATABASE()`), а не на конфіг: так він працює, навіть якщо хтось неправильно задав змінні.
7. **Код «стерти всі таблиці» живе лише в `tests/`**, не в `src/`: у продакшн-коді його бути не повинно.

## 3. Карта файлів

Створити:

```
backend/
  docker/mysql-init/01-create-test-database.sql
  database/
    migrations/001_create_phrases.sql
    schema.sql                                  ← генерується
    seed/phrases.php                            ← дані сіду
  bin/
    migrate.php
    seed.php
    build-schema.php
  src/
    Domain/Categories.php
    Domain/Languages.php
    Database/SqlSplitter.php
    Database/Migrator.php
    Database/SchemaBuilder.php
    Database/Seeder.php
    Database/DatabaseGuard.php
    Support/Iso8601.php
  tests/
    lib.php                                     ← спільні хелпери тестів (виносяться з run.php)
    db-lib.php                                  ← reset / reseed / підпис схеми
    api.php
    api/database.php
```

Змінити:

```
backend/docker-compose.yml        — монтування init-каталогу в сервіс db
backend/tests/run.php             — використати tests/lib.php + нові юніт-перевірки
docs: див. розділ 8
```

Не чіпати: `docs/API.md`, `src/routes.php` (нових ендпоінтів немає), `.env.example` (нових змінних немає), `frontend/`.

## 4. Кроки реалізації

Виконуй у цьому порядку: кожен крок завершується перевіркою, не переходь далі з червоними тестами.

### Крок 1 · Docker: тестова БД

**1.1.** Створи `docker/mysql-init/01-create-test-database.sql`:

```sql
-- Виконується один раз, при першому створенні volume db_data (механізм образу mysql).
-- Імена зашиті: .sql не читає змінні оточення. Якщо змінюєш DB_USER / DB_NAME в .env — онови тут.
CREATE DATABASE IF NOT EXISTS `orakuls_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `orakuls_test`.* TO 'orakuls'@'%';
FLUSH PRIVILEGES;
```

**1.2.** У `docker-compose.yml`, сервіс `db`, додай другий елемент у `volumes`:

```yaml
    volumes:
      - db_data:/var/lib/mysql
      - ./docker/mysql-init:/docker-entrypoint-initdb.d:ro
```

**1.3.** Застосуй. Init-скрипти працюють лише на **новому** volume, тож потрібне перестворення. Перед цим переконайся, що втрачати нічого:

```
docker compose exec -T db mysql -uorakuls -porakuls orakuls -e "SHOW TABLES;"
```

Порожній вивід = безпечно. **Якщо таблиці є — зупинись і спитай користувача**, не роби `down -v`. Далі:

```
docker compose down -v
docker compose up -d --wait
```

**Перевірка кроку 1:**

```
docker compose exec -T db mysql -uorakuls -porakuls -e "SHOW DATABASES;"      # є orakuls і orakuls_test
docker compose exec -T db mysql -uorakuls -porakuls orakuls_test -e "SELECT @@character_set_database, @@collation_database;"   # utf8mb4, utf8mb4_unicode_ci
curl -s http://localhost:8000/api/health                                        # {"status":"ok"}
```

### Крок 2 · Допоміжні класи: `Categories`, `Languages`, `Iso8601`

Усі файли — `declare(strict_types=1);`, `final`, коментарі українською про *чому*.

`src/Domain/Categories.php` — **єдиний** список категорій у коді (D-11):

```php
namespace App\Domain;

final class Categories
{
    /** Коди з API.md. Порядок фіксований: використовується для byCategory у статистиці. */
    public const CODES = ['love', 'money', 'luck', 'work', 'travel', 'mood'];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::CODES, true); // strict: 'LOVE' і 'love ' не проходять
    }
}
```

`src/Domain/Languages.php` — коди мов **і єдине місце, де код мови стає частиною SQL** (захист від ін'єкції через ім'я колонки, див. `database.md` → «Правила запитів»):

```php
namespace App\Domain;

use InvalidArgumentException;

final class Languages
{
    public const CODES = ['lv', 'en', 'ru'];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /** Колонка таблиці phrases для мови. Кидає виняток на невідомий код — ніколи не підставляє ввід у SQL. */
    public static function column(string $lang): string
    {
        if (!self::isValid($lang)) {
            throw new InvalidArgumentException("Unknown language code: {$lang}");
        }

        return 'text_' . $lang;
    }
}
```

`src/Support/Iso8601.php`:

```php
namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Iso8601
{
    /** '2026-10-26 18:30:00' (DATETIME з БД, UTC) → '2026-10-26T18:30:00Z' */
    public static function fromDb(string $datetime): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $datetime) {
            throw new InvalidArgumentException("Not a valid DATETIME value: {$datetime}");
        }

        return $parsed->format('Y-m-d\TH:i:s\Z');
    }
}
```

(Друга умова відсікає «переповзання» дат на кшталт `2026-02-31`.)

### Крок 3 · `SqlSplitter`

`src/Database/SqlSplitter.php`, один публічний метод:

```php
/** @return list<string> непорожні оператори без кінцевого «;» та без коментарів */
public static function split(string $sql): array
```

Потрібен через факт 1. Проста машина станів по символах, не регулярка. Стани та правила:

| Стан | Вихід зі стану | Нюанси |
|------|----------------|--------|
| звичайний | — | `;` завершує оператор; пробіли/переноси на початку оператора пропускаються |
| `'…'` і `"…"` | закриваюча така сама лапка | `\` екранує наступний символ; подвоєна лапка `''` залишається в рядку (достатньо просто закрити й відкрити стан) |
| `` `…` `` | закриваючий `` ` `` | без `\`-екранування; подвоєний `` `` `` теж працює природно |
| `-- …` | кінець рядка | коментар **лише** якщо `--` йде перед пробілом/табуляцією/переносом/кінцем тексту (правило MySQL); `5--3` — це вираз, не коментар |
| `# …` | кінець рядка | |
| `/* … */` | `*/` | **крім** `/*! … */` — це умовний коментар, який MySQL виконує: його лишаємо в тексті оператора як є |

Додатково:
- Коментарі в результат не потрапляють (рядкові та блокові), крім умовних `/*!`.
- Оператор без `;` в кінці файлу приймається.
- Порожні оператори (`;;`, лише коментарі) відкидаються.
- Якщо оператор починається з `DELIMITER` (нечутливо до регістру) → `RuntimeException('DELIMITER is not supported …')`. Процедури й тригери в міграціях не підтримуємо.
- Нормалізуй `\r\n` → `\n` до розбору.
- Незакритий рядок/блочний коментар → `RuntimeException` з позицією.

**Юніт-перевірки** (у `tests/run.php`, без БД) — мінімум:
1. `"A; B;"` → `['A','B']`; 2. без кінцевого `;`; 3. `;` усередині `'…'`, `"…"` і `` `…` ``; 4. `\'` і `''` усередині рядка; 5. `-- коментар; з крапкою з комою` не розриває оператор і зникає; 6. `# …`; 7. `/* ; */`; 8. `/*!40101 SET x = 1 */;` зберігається; 9. `SELECT 5--3;` → один оператор `SELECT 5--3`; 10. лише коментарі / `;;` → `[]`; 11. `DELIMITER //` → виняток; 12. незакритий `'abc` → виняток; 13. CRLF-вхід дає той самий результат, що й LF.

### Крок 4 · Міграція 001 і `Migrator`

**4.1.** `database/migrations/001_create_phrases.sql` — рівно цей зміст (перевірений на 5.7):

```sql
-- Таблиця фраз: один запис = одна фраза з трьома перекладами (API.md → Phrase).
-- category: перевіряється кодом (App\Domain\Categories), БД допустимість не гарантує — MySQL 5.7 ігнорує CHECK.
-- created_at/updated_at: без DEFAULT, завжди передаються як UTC_TIMESTAMP().
CREATE TABLE IF NOT EXISTS `phrases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(16) NOT NULL,
  `text_lv` VARCHAR(500) NOT NULL DEFAULT '',
  `text_en` VARCHAR(500) NOT NULL DEFAULT '',
  `text_ru` VARCHAR(500) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phrases_category` (`category`),
  KEY `idx_phrases_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Правила для **всіх** майбутніх міграцій (запиши їх у `docs/database.md`):
- ім'я файлу `NNN_snake_case.sql` (три цифри, порядок застосування);
- DDL у MySQL **неатомарний** (кожен `CREATE/ALTER` комітить неявно), тому міграція з кількома операторами має бути ідемпотентною посередині (`IF NOT EXISTS`), щоб повторний запуск після збою доробив решту;
- без `DELIMITER`, процедур, тригерів, `SELECT`-ів;
- застосовану міграцію **не редагувати** — додавати нову.

**4.2.** `src/Database/Migrator.php`:

```php
final class Migrator
{
    public const TABLE_DDL = "CREATE TABLE IF NOT EXISTS `migrations` (\n"
        . "  `name` VARCHAR(100) NOT NULL,\n"
        . "  `applied_at` DATETIME NOT NULL,\n"
        . "  PRIMARY KEY (`name`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public function __construct(private readonly PDO $pdo, private readonly string $directory) {}

    /** @return list<string> імена файлів у порядку застосування */
    public static function files(string $directory): array;

    /** Зміст файлу: BOM знято, \r\n → \n */
    public static function read(string $directory, string $name): string;

    /** @return array{applied: list<string>, pending: list<string>} — без побічних ефектів */
    public function status(): array;

    /** @return list<string> імена міграцій, застосованих саме цим запуском */
    public function up(): array;
}
```

Поведінка:

- `files()`: усі `*.sql` у каталозі; кожне ім'я має відповідати `/^\d{3}_[a-z0-9_]+\.sql$/`, інакше `RuntimeException` (опечатка в імені не має мовчки ігноруватись); два файли з однаковим числовим префіксом → виняток; сортування за іменем. Каталог порожній/відсутній → виняток.
- `status()`: **не створює** таблицю `migrations`. Якщо її ще немає (перевірка через `information_schema.TABLES`) — `applied = []`. `pending` = файли, яких немає в `applied`.
- `up()`:
  1. `SELECT GET_LOCK('orakuls_migrate', 10)`; не `1` → `RuntimeException('Another migration is running')`. Блок обгорнути в `try/finally` з `RELEASE_LOCK`.
  2. `exec(self::TABLE_DDL)`.
  3. Прочитати `applied`.
  4. Для кожної невиконаної міграції (у порядку): `SqlSplitter::split(read(...))`; кожен оператор — окремий `exec()`. Якщо оператор падає — кинути `RuntimeException("Migration {name} failed on statement #{n}: {повідомлення}", 0, $previous)` і **не** записувати міграцію в `migrations`.
  5. Після успіху: `INSERT INTO migrations (name, applied_at) VALUES (?, UTC_TIMESTAMP())` (prepared).
  6. Повернути список застосованих.
- Файл міграції, який не містить жодного оператора, — виняток (імовірно, помилка).
- Те, що міграція є в `migrations`, але її файлу вже немає, — ігнорується (історію не чіпаємо).

**4.3.** `bin/migrate.php`. Спільний каркас для всіх трьох скриптів у `bin/`:

```php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {   // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

try {
    // … робота …
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
```

`bootstrap.php` вішає HTTP-орієнтований `set_exception_handler`, тому в CLI-скриптах весь код — у `try/catch (Throwable)` з виводом у `STDERR` і кодом виходу `1`.

Вивід `migrate.php`: рядок `Applied: 001_create_phrases.sql` на кожну застосовану, або `Nothing to migrate.`. Прапорець `--status` друкує `applied:` і `pending:` зі списками (без змін у БД).

**Перевірка кроків 3–4 (ручна, до написання тестів):**

```
docker compose exec php php bin/migrate.php          # Applied: 001_create_phrases.sql
docker compose exec php php bin/migrate.php          # Nothing to migrate.
docker compose exec php php bin/migrate.php --status
docker compose exec -T db mysql -uorakuls -porakuls orakuls -e "SHOW TABLES; SELECT * FROM migrations;"
```

Очікуєш: таблиці `migrations` і `phrases`, один рядок у `migrations`. Після цього **залиш робочу БД `orakuls` у стані «міграції застосовані, даних немає»**.

### Крок 5 · `SchemaBuilder` і `schema.sql`

`src/Database/SchemaBuilder.php`: `public static function build(string $migrationsDir): string`.

Формат результату (рівно такий, LF, у кінці один перенос рядка, детермінований):

```sql
-- Orākuls Coffee · повна схема БД (MySQL 5.7)
-- ЗГЕНЕРОВАНО командою `php bin/build-schema.php` з database/migrations/*.sql. Вручну не редагувати.
-- Імпорт: phpMyAdmin → обрати порожню базу → вкладка «Імпорт» → цей файл.
-- Даних тут немає: сід на прод не потрапляє, фрази завантажує адмін через імпорт.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `migrations` (
  `name` VARCHAR(100) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 001_create_phrases.sql ----
CREATE TABLE IF NOT EXISTS `phrases` ( … );

INSERT IGNORE INTO `migrations` (`name`, `applied_at`) VALUES
  ('001_create_phrases.sql', UTC_TIMESTAMP());
```

Правила збирання:
- DDL `migrations` береться з `Migrator::TABLE_DDL` (одне джерело);
- кожну міграцію читати через `Migrator::read()`, розбивати `SqlSplitter::split()` і виводити **оператори** через `;\n\n` (коментарі з міграційних файлів у `schema.sql` не переносяться — це свідомо, зате файл гарантовано виконується тим самим розбирачем);
- заголовок `-- ---- {ім'я} ----` перед кожною міграцією;
- останній блок — один `INSERT IGNORE` з усіма іменами через кому, по рядку на ім'я; `UTC_TIMESTAMP()` у кожному.
- Тестові міграції для юніт-тесту — у тимчасовому каталозі.

`bin/build-schema.php`:
- без аргументів — записує `database/schema.sql`, друкує `Written database/schema.sql`;
- `--check` — нічого не пише; порівнює (після нормалізації `\r\n` → `\n`) зі збереженим файлом, при розбіжності друкує `schema.sql is out of date. Run: php bin/build-schema.php` і виходить із кодом `1`.

Запусти `php bin/build-schema.php`, переглянь файл очима, переконайся, що він читається як вище.

### Крок 6 · Сід і запобіжник

**6.1.** `src/Database/DatabaseGuard.php`:

```php
final class DatabaseGuard
{
    /** 'orakuls_test' / 'x_test' → true. '', null, 'orakuls', 'test', '_test', 'orakuls_test_x', 'orakuls-test' → false */
    public static function isTestDatabaseName(?string $name): bool
    {
        return $name !== null && preg_match('/^[A-Za-z0-9_]+_test$/', $name) === 1;
    }

    /** Дивиться на БД, до якої фактично підключено PDO (SELECT DATABASE()), а не на конфіг. */
    public static function assertTestDatabase(PDO $pdo): void
    {
        $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!self::isTestDatabaseName(is_string($name) ? $name : null)) {
            throw new RuntimeException(sprintf(
                "Refusing to run: database '%s' is not a test database (its name must end with _test).",
                is_string($name) ? $name : '(none)'
            ));
        }
    }
}
```

**6.2.** `database/seed/phrases.php` повертає масив рядків:

```php
return [
    ['key' => 'love_1', 'category' => 'love', 'lv' => '…', 'en' => '…', 'ru' => '…'],        // active за замовчуванням true
    ['key' => 'hidden', 'category' => 'love', 'active' => false, 'lv' => '…', 'en' => '…', 'ru' => '…'],
    …
];
```

Обов'язковий склад (30 рядків: 18 базових + 12 особливих випадків; тексти придумай осмислені, **унікальні між рядками**, щоб тести могли впізнавати фразу за текстом). Порядок у файлі = порядок `id` (1, 2, 3 …); не міняй порядок пізніше — тести задач BE-003 … BE-008 покладатимуться на нього.

| Ключ | Категорія | Призначення (хто використовує) |
|------|-----------|--------------------------------|
| `love_1` … `love_3`, `money_1` … `money_3`, `luck_1` … `luck_3`, `work_1` … `work_3`, `travel_1` … `travel_3`, `mood_1` … `mood_3` | відповідна | 18 активних фраз з усіма трьома мовами: рівномірність вибору, фільтр за категорією, статистика |
| `hidden` | love | `active = false`, усі мови заповнені: фраза не потрапляє в `/api/message`, фільтр `isActive` |
| `only_lv` | luck | заповнено лише `lv` (`en`, `ru` = `''`): фільтр `missing`, видача лише для `lv` |
| `only_en` | work | заповнено лише `en` |
| `no_ru` | travel | `lv` і `en` заповнені, `ru = ''` |
| `blank_en` | mood | `lv`, `ru` заповнені, `en = '   '` (лише пробіли, свідомо, імітує ручну вставку): «порожній після trim» |
| `diacritics` | mood | `lv`: `Šodien veiksme būs tavā pusē.`, `en`: `Luck is on your side today.`, `ru`: `Сегодня удача на твоей стороне.` — пошук `sodien`, `bus` |
| `cyrillic_case` | money | `ru` з великої літери (напр. `Тест: деньги придут`) — пошук `тест` |
| `emoji` | love | `en` з емодзі (напр. `🍀 Luck loves you 💛`) |
| `multiline` | work | `en` = `"Line one\nLine two"` (справжній `\n`) |
| `len500` | luck | `lv = str_repeat('š', 500)`, `en = str_repeat('😀', 500)` — рівно 500 символів, `ru` короткий |
| `html` | travel | `en = '<script>alert(1)</script> & <b>bold</b>'` — зберігається як є |
| `like_chars` | money | `en = '100% sure_thing \\ backslash'` (є `%`, `_`, `\`) — екранування в `LIKE` |

Усі рядки мають задовольняти: категорія ∈ `Categories::CODES`; кожен переклад ≤ 500 символів (`mb_strlen`, не `strlen`); ключі унікальні; у `lv/en/ru` можуть бути відсутні ключі — тоді це `''`.

**6.3.** `src/Database/Seeder.php`:

```php
final class Seeder
{
    public const CREATED_AT = '2026-10-26 18:30:00'; // фіксовано — відповідає прикладу в API.md і робить відповіді детермінованими

    public function __construct(private readonly PDO $pdo, private readonly string $seedFile) {}

    /** @return array<string, int> key → id */
    public function run(): array;
}
```

`run()`:
1. **Першою дією** `DatabaseGuard::assertTestDatabase($this->pdo)` — до будь-яких змін.
2. Завантажити й провалідувати дані (категорії, довжини `mb_strlen`, унікальність ключів, наявність обов'язкових ключів із таблиці вище); помилка → виняток, БД не чіпати.
3. `TRUNCATE TABLE phrases` (скидає `AUTO_INCREMENT`).
4. Для кожного рядка за порядком — prepared `INSERT INTO phrases (category, text_lv, text_en, text_ru, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)` з `CREATED_AT` в обох датах; `$map[$key] = (int) $pdo->lastInsertId()`.
5. Повернути `$map`.

`bin/seed.php`: каркас із кроку 4; `Db::pdo()` бере БД із `DB_NAME` (env/.env), `Seeder::run()` сам відмовиться, якщо вона не `*_test`. Вивід: `Seeded N phrases into <db>.` Корисно для ручного запуску на тестовій БД: `docker compose exec -e DB_NAME=orakuls_test php php bin/seed.php`.

### Крок 7 · Тестова інфраструктура

**7.1. Винеси спільне з `tests/run.php` у `tests/lib.php`** і підключи його з `run.php`: функції `test()`, `assertSame()`, `assertTrue()`, `decode()`, `kernelWith()`, цикл виконання (у вигляді функції `runTests(): never`, що друкує результат у тому ж форматі й завершує процес з кодом `0`/`1`). Додай `assertThrows(callable $fn, string $class = Throwable::class): Throwable`. Після рефакторингу `run.php` **має працювати так само**: перевір, що всі 21 попередній тест ще зелені, перш ніж додавати нові. `lib.php` не викликає `define('APP_ROOT')` і `bootstrap` — це робить скрипт, який його підключає.

**7.2. `tests/db-lib.php`** (функції для тестів із БД):

- `testPdo(): PDO` — `Db::pdo()`.
- `resetTestDatabase(PDO $pdo): void` — **спершу** `DatabaseGuard::assertTestDatabase($pdo)`; далі `SET FOREIGN_KEY_CHECKS = 0`, `DROP TABLE` для кожної базової таблиці поточної БД (список із `information_schema.TABLES … TABLE_TYPE = 'BASE TABLE'`), `SET FOREIGN_KEY_CHECKS = 1`.
- `reseed(): array<string,int>` — `(new Seeder(testPdo(), …/seed/phrases.php))->run()`; повертає карту `key → id`. Тест, що змінює дані, у кінці викликає `reseed()` (або на початку — щоб не залежати від попередніх).
- `schemaSignature(PDO $pdo): array` — масив із трьох відсортованих частин, зібраних із `information_schema` для поточної БД: **таблиці** (`TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`), **колонки** (`TABLE_NAME`, `ORDINAL_POSITION`, `COLUMN_NAME`, `COLUMN_TYPE`, `COLLATION_NAME`, `IS_NULLABLE`, `COLUMN_DEFAULT`, `EXTRA`), **індекси** (`TABLE_NAME`, `INDEX_NAME`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `NON_UNIQUE`). `AUTO_INCREMENT` лічильників у підпис **не** входить.

**7.3. `tests/api.php`**:

```php
<?php
declare(strict_types=1);

// Перевірки проти тестової БД. Запуск: docker compose exec php php tests/api.php
define('APP_ROOT', dirname(__DIR__));
const TEST_DB_NAME = 'orakuls_test';

// ДО bootstrap і до першого Db::pdo(): getenv() має пріоритет над .env, тож DB_NAME з .env ігнорується
putenv('DB_NAME=' . TEST_DB_NAME);

require APP_ROOT . '/src/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/db-lib.php';

$pdo = testPdo();
App\Database\DatabaseGuard::assertTestDatabase($pdo);   // не *_test → повідомлення в STDERR, код 1, нічого не змінено

resetTestDatabase($pdo);
(new App\Database\Migrator($pdo, APP_ROOT . '/database/migrations'))->up();
reseed();

foreach (glob(__DIR__ . '/api/*.php') as $file) {       // кожен файл реєструє свої test(...)
    require $file;
}
runTests();
```

Обгорни початок (до `runTests`) у `try/catch (Throwable)` з виводом у `STDERR` і `exit(1)` — як у `bin/*`. Якщо виняток — `PDOException` з `errorInfo[1]` `1044` (немає прав) або `1049` (БД не існує), додай до повідомлення підказку: «Тестова БД orakuls_test не створена. Один раз виконай: docker compose down -v && docker compose up -d --wait» — це найімовірніша помилка у користувача, який оновив проєкт, але не перестворив volume.

Конвенція для наступних задач: перевірки ендпоінтів лежать у `tests/api/<назва>.php` і не потребують змін у `api.php`.

**7.4. `tests/api/database.php`** — перевірки, що закривають AC цієї задачі (кожна — окремий `test()`):

| Назва перевірки | Що перевіряє | AC |
|-----------------|--------------|----|
| міграції створили `migrations` і `phrases`; у `migrations` є `001_create_phrases.sql` | базова робота runner'а | 1 |
| повторний `up()` повертає `[]`, кількість рядків у `phrases` не змінилась | ідемпотентність, без дублювання даних | 2 |
| `status()`: `pending = []`, `applied` містить 001 | | 2 |
| `phrases`: рушій InnoDB, колація таблиці й усіх текстових колонок `utf8mb4_unicode_ci` | кодування | 4 |
| типи колонок: `category` = `varchar(16)`, `text_*` = `varchar(500)`, `is_active` = `tinyint(1)` DEFAULT `1`, дати `datetime` NOT NULL **без** default | схема | 5, 6, 8 |
| діакритика, кирилиця, емодзі та `\n` записуються й читаються без змін (з рядків сіду `diacritics`, `emoji`, `multiline`) | кодування | 4 |
| `len500`: `CHAR_LENGTH(text_lv) = 500` і `CHAR_LENGTH(text_en) = 500` (4-байтові символи) | 500 символів | 5 |
| INSERT 501-го символу → `PDOException` з `errorInfo[1] === 1406`, рядок не з'являється | БД не обрізає мовчки | 5 |
| індекси на `category` і `is_active` існують (`information_schema.STATISTICS`) | індекси | 7 |
| `INSERT … UTC_TIMESTAMP()` дає час, що збігається з `gmdate('Y-m-d H:i:s')` ±5 с; `Iso8601::fromDb()` від нього закінчується на `Z` | UTC | 8 |
| `id` і `is_active` повертаються як `int` (регресія типів) | типи | — |
| сід: двічі `reseed()` → однакова карта `key → id` і однакова кількість рядків; `id` починаються з 1 у порядку файлу | детермінізм | 10 |
| сід: наявні всі обов'язкові ключі; усі 6 категорій мають ≥ 3 активні фрази з трьома мовами; є хоча б одна прихована, одна без `en`, одна без `ru`, одна з `blank_en` | склад сіду | 12 |
| **паритет схеми** (див. нижче) | `schema.sql` = міграції | 3 |

Паритет схеми — одна перевірка: (1) зняти `schemaSignature` після міграцій; (2) `resetTestDatabase`; (3) виконати `database/schema.sql` оператор за оператором через `SqlSplitter::split()` + `exec()`; (4) зняти підпис удруге; (5) `assertSame(перший, другий)`; (6) вміст `migrations` після імпорту `schema.sql` = список імен міграційних файлів, і `Migrator::up()` після цього повертає `[]` (імпортований дамп не призводить до повторного застосування). **У `finally` обов'язково** відновити стандартний стан: `resetTestDatabase` → `Migrator::up()` → `reseed()`, інакше наступні перевірки побачать порожню БД. Постав цю перевірку **останньою** у файлі.

**7.5. Нові юніт-перевірки в `tests/run.php`** (без БД), додатково до `SqlSplitter` з кроку 3:

- `Categories`: `CODES` рівно `['love','money','luck','work','travel','mood']` у цьому порядку; `isValid('love')` істина; `isValid('LOVE')`, `isValid('love ')`, `isValid('')`, `isValid('x')` — хиба.
- `Languages`: `CODES`; `column('lv') === 'text_lv'`; `column("lv; DROP TABLE phrases")` та `column('de')` кидають `InvalidArgumentException`.
- `Iso8601::fromDb('2026-10-26 18:30:00') === '2026-10-26T18:30:00Z'`; `'2026-02-31 00:00:00'` та `'abc'` кидають виняток.
- `DatabaseGuard::isTestDatabaseName`: істина для `orakuls_test`, `x_test`; хиба для `orakuls`, `''`, `null`, `test`, `_test`, `orakuls_test_x`, `orakuls-test`.
- `Migrator::files()` на тимчасовому каталозі: правильні імена сортуються; ім'я `1_x.sql`, `001-x.sql`, `001_X.sql` → виняток; два файли `002_a.sql` і `002_b.sql` → виняток; порожній каталог → виняток.
- `SchemaBuilder::build()` на тимчасовому каталозі з двома фейковими міграціями → точно очікуваний текст (включно з `INSERT IGNORE` на два імена).
- **`schema.sql` актуальний**: `SchemaBuilder::build(…/database/migrations)` збігається з `database/schema.sql` (обидва нормалізовані `\r\n` → `\n`).
- Дані сіду без БД: файл завантажується, ключі унікальні, категорії валідні, `mb_strlen` ≤ 500 у всіх перекладах, обов'язкові ключі присутні.

### Крок 8 · Документація

Онови в одному проході — документи мають описувати те, що **є**, а не те, що планувалось:

| Файл | Що змінити |
|------|-----------|
| `backend/docs/tasks/BE-002-database-schema/task.md` | Статус `Done (дата)`, відмітити виконані AC `[x]`, додати розділ «Як перевірити» з командами |
| `backend/docs/database.md` | Прибрати «⏳» зі схеми й тестової БД; додати правила міграцій з кроку 4.1; додати опис `Categories` / `Languages` / `Iso8601`; зазначити, що `is_active` з БД приходить `int` і приводиться `(bool)` |
| `backend/docs/testing.md` | Третій рівень стає фактом (не «⏳»): команда, структура `tests/api/*.php`, `lib.php`, `db-lib.php`, `reseed()`, паритет схеми; підсумок покриття |
| `backend/docs/development.md` | Прибрати «⏳»; команди `bin/migrate.php`, `bin/seed.php`, `bin/build-schema.php`, `tests/api.php`; одноразовий `down -v` |
| `backend/docs/architecture.md` | Дерево структури (`bin/`, `database/`, `docker/`, `src/Domain`, `src/Database`), таблиця компонентів, правила залежностей (`Database` залежить лише від `Support`/PDO; `Domain` ні від чого), прибрати BE-002 з «Що ще буде додано» |
| `backend/docs/security.md` | Запобіжник `_test` і `Languages::column()` → ✅ |
| `backend/docs/deployment.md` | Порядок розгортання схеми на хостингу: створити БД і користувача в cPanel → phpMyAdmin → вибрати БД → Import `database/schema.sql`; сід не імпортується; наступні зміни схеми — нові `NNN_*.sql` вручну |
| `backend/docs/decisions.md` | Додати **D-15** (оператори міграцій виконуються по одному, `SqlSplitter`; причина — факт 1) і **D-16** (`schema.sql` генерується з міграцій; два тести: актуальність і паритет) |
| `docs/BACKEND.md` (спільний) | Структура й команди (міграції, тест на БД), розділ «Розгортання схеми» коротко |
| `backend/docs/README.md` | Лише якщо з'являються нові документи (не очікується) |

Після правок перевір усі відносні посилання (скрипт перевірки посилань, як раніше) і що `D-xx`, на які посилаються документи, існують.

### Крок 9 · Фінальна перевірка

Виконай **усе** і наведи фактичний вивід у звіті:

```
cd backend
docker compose down -v && docker compose up -d --wait              # чиста перевірка init-скрипта
docker compose exec -T db mysql -uorakuls -porakuls -e "SHOW DATABASES;"      # orakuls + orakuls_test
docker compose exec php php bin/migrate.php                        # Applied: 001_create_phrases.sql
docker compose exec php php bin/migrate.php                        # Nothing to migrate.
docker compose exec php php bin/build-schema.php --check           # exit 0
docker compose run --rm --no-deps php php tests/run.php            # усі ok
docker compose exec php php tests/api.php                          # усі ok
docker compose exec php php tests/api.php                          # другий запуск — той самий результат
docker compose exec php php tests/smoke.php                        # health, 404 — ok
```

Запобіжник перевірити **вручну на порожній робочій БД** (безпечно саме зараз, поки в `orakuls` немає даних; тест запобіжника на рівні `isTestDatabaseName` уже є в `run.php`):

```
docker compose exec php php bin/seed.php; echo "exit=$?"
#   очікуєш: у STDERR "Refusing to run: database 'orakuls' is not a test database …", рядок exit=1
docker compose exec -T db mysql -uorakuls -porakuls orakuls -e "SELECT COUNT(*) FROM phrases;"
#   очікуєш: 0 — нічого не змінилось
```

(Зверни увагу: `docker compose exec` повертає код виходу команди всередині контейнера, тож `exit=1` тут — саме код `bin/seed.php`.)

Додатково:
- `git status` — лише очікувані файли (таблиця в розділі 3 і документи); `backend/.env` не змінений і в індекс не потрапив;
- дані робочої БД `orakuls` після всіх перевірок: таблиці є, у `phrases` 0 рядків, у `migrations` 1 рядок.

Таблиця відповідності AC → чим перевірено (додай у звіт):

| AC (за порядком у task.md) | Перевірка |
|----------------------------|-----------|
| 1 міграції на чистій 5.7 | `down -v` + `bin/migrate.php`, `tests/api.php` (скидає й накочує з нуля при кожному запуску) |
| 2 ідемпотентність | `bin/migrate.php` двічі; тест «повторний up()» |
| 3 `schema.sql` = міграції | тести «актуальний» і «паритет» |
| 4 кодування | тести колацій і round-trip |
| 5 500 символів | тести `len500` і 501 → 1406 |
| 6 `category` + список у коді | тест типу колонки; `Categories`; запис у `database.md` |
| 7 індекси | тест `STATISTICS` |
| 8 UTC / ISO 8601 | тест UTC; `Iso8601` |
| 9 обидві бази створюються | `SHOW DATABASES` після `down -v` |
| 10 `api.php` відтворюваний і не чіпає `orakuls` | два запуски поспіль; `COUNT(*)` у `orakuls` |
| 11 запобіжник | юніт-перевірка імені + ручна перевірка `seed.php` |
| 12 склад сіду | тест складу сіду |
| 13 документація розгортання | `deployment.md`, `docs/BACKEND.md` |
| 14 одноразовий `down -v` | `development.md`, `testing.md` |

## 5. Пастки

1. **CRLF на Windows.** У репозиторії `core.autocrlf` перетворює LF на CRLF у робочій копії. Тому: init-файл — `.sql`, не `.sh` (скрипт із `\r` не запуститься); при читанні міграцій і порівнянні `schema.sql` завжди нормалізуй `\r\n` → `\n`. Файли пиши з LF.
2. **Не вмикати `PDO::MYSQL_ATTR_MULTI_STATEMENTS`** і не міняти `Db.php` заради міграцій. Рішення — `SqlSplitter`, а не налаштування PDO.
3. **`putenv('DB_NAME=…')` у `tests/api.php` — до `require bootstrap` і до першого `Db::pdo()`.** `Db` тримає з'єднання статично; після першого виклику змінити БД неможливо.
4. **`ErrorHandler` з `bootstrap.php` відповідає JSON'ом** і не підходить для CLI: тому `try/catch` у `bin/*` і `tests/api.php`.
5. **`TRUNCATE` і `DROP TABLE` комітять неявно** — не загортай їх у транзакцію очікуючи відкат.
6. **`is_active` з БД — `int`.** Не додавай приведення в `Seeder` чи тестах «заради краси»: перетворення на `bool` — відповідальність серіалізатора `Phrase` (BE-005). Тут лише тест, що тип саме `int` (регресія).
7. **Не змінюй порядок рядків у сіді після створення** і не додавай нові рядки в середину — лише в кінець: інші задачі спираються на `id`.
8. **Не редагуй `schema.sql` руками** — тест «актуальний» це виявить; змінюй міграції й перегенеровуй.
9. **`mb_strlen` / `CHAR_LENGTH`, не `strlen` / `LENGTH`** — ліміт 500 символів, а не байт.
10. **Ім'я `Seeder::CREATED_AT` фіксоване** — не використовуй `UTC_TIMESTAMP()` у сіді: тести майбутніх задач порівнюють `createdAt` зі сталим значенням.
11. **Не запускай `down -v`, не переконавшись, що робоча БД порожня** (крок 1.3).

## 6. Чого не робити

- Не створювати `login_attempts` (BE-004) і не додавати ендпоінтів / маршрутів.
- Не писати реальні 600 фраз і не додавати сід у робочу БД.
- Не додавати Composer, PHPUnit чи інші залежності.
- Не міняти `docs/API.md`, `frontend/`, `.env.example`, `public/index.php`.
- Не вводити `ENUM`, `CHECK`, зовнішні ключі, значення за замовчуванням для дат.
- Не коміти без прохання користувача.

## 7. Звіт після завершення

Короткий звіт користувачу (українською чи російською, як у розмові): що створено; фактичний вивід кроку 9 (скорочено, але з підсумковими рядками «N тестів, 0 провалено»); таблиця AC → перевірка; **усе, що відрізняється від плану, і чому**; що користувачу робити далі (наприклад, одноразовий `down -v`, якщо його ще не виконано). Запропонуй розбиття на коміти (наприклад: Docker + init; `Domain`/`Support`; `Database` + міграція + `bin/`; сід + тести; документація), але не виконуй без прохання.
