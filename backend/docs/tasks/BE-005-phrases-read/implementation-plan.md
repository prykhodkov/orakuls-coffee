# BE-005 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та acceptance criteria (AC) — у [task.md](task.md); цей план їх деталізує. Якщо план суперечить `task.md` або реальності — зупинись і запитай користувача, не вигадуй мовчки.

Це задача про читання даних для адмінки: два ендпоінти, пошук і фільтри. Складність не в обсязі, а в дрібницях: екранування в `LIKE`, строгий розбір параметрів, коректні типи та узгодженість `total` зі списком.

## 0. Перед початком

Прочитай (у цьому порядку):

1. `CLAUDE.md` (корінь), `backend/CLAUDE.md`.
2. [task.md](task.md) — обсяг і AC. Рішення в ньому прийняті (строгі явні фільтри, пошук через `LIKE … ESCAPE` зі службовим символом, `Domain\Phrase`) — не переглядати.
3. `backend/docs/decisions.md` — D-01, D-04, D-09, D-11, D-13, D-17, D-18, D-20; `architecture.md` (правила залежностей), `database.md` (розділ про пошук і правила запитів), `api-conventions.md`, `error-handling.md`, `testing.md`, `security.md`.
4. `docs/API.md`, розділи `Phrase`, `GET /api/admin/phrases`, `GET /api/admin/phrases/{id}` (контракт — **не змінювати**).
5. Код, на який спираєшся: `src/Repository/PhraseRepository.php`, `src/Controller/MessageController.php`, `src/Domain/Categories.php`, `src/Domain/Languages.php`, `src/Support/Iso8601.php`, `src/Http/Request.php`, `src/Http/Response.php`, `src/routes.php`, `tests/lib.php`, `tests/db-lib.php`, `tests/api.php`, `tests/api/auth.php`, `tests/api/message.php`, `tests/api/database.php`, `tests/run.php`, `tests/smoke.php`, `database/seed/phrases.php`.

Стан на старті: BE-001 … BE-004 виконано й закомічено (BE-005-документи — окремо); контейнери `php` і `db` запущені; `tests/run.php` — 75 перевірок, `tests/api.php` — 61, усе зелене; робоча БД `orakuls`: 2 міграції, 0 фраз. У цій задачі **нових міграцій і змін схеми немає**, робоча БД не змінюється. **Не коміть**, доки користувач не попросить.

## 1. Підтверджені факти

Перевірено експериментами на `mysql:5.7` і PHP 8.4 у Docker проєкту (на сіді `orakuls_test`). Не перевіряй наново, але й не ігноруй.

| # | Факт | Наслідок |
|---|------|----------|
| 1 | `LIKE '%\\%' ESCAPE '\'` (пошук **одного** символа `\`) нічого не знаходить, хоча фрази з `\` є. Те саме з будь-яким символом, що водночас є екранувальним: при екранувальній вертикальній рисці не знаходиться сама риска, при `ESCAPE '!'` не знаходиться `!`. Довші підрядки (`sure_thing \`) працюють | Не можна використовувати символ, який користувач може шукати, як екранувальний |
| 2 | `LIKE ? ESCAPE x'01'` (керівний символ `0x01`) знаходить як звичайні символи `%` → [30,33], `_` → [30,33], `\` → [30,32], `!`, вертикальна риска, `100%`, `sure_thing`; діакритика зберігається: `sodien` → 4 фрази, `bus` → 3. Працює і як літерал `x'01'`, і як `CHAR(1)`, і як параметр `ESCAPE ?` | Екранувальний символ — `"\x01"`; з ввідного рядка його вилучаємо, а `%` і `_` екранюємо ним. Тоді «дірку» з пункту 1 неможливо відтворити. **Уточнення після реалізації:** для вводу, що починається з `%`, це не вистачає (вада колації `utf8mb4_unicode_ci`), реалізація додатково підміняє `%` на `0x02` через `REPLACE` — див. D-24 |
| 3 | `LOCATE(?, col)` не підходить: регістр ігнорує, **діакритику не ігнорує** (`sodien` не знаходить `Šodien`, `BUS` не знаходить `būs`), хоч `ё` = `е` | Суперечить D-13, відкинуто |
| 4 | Порожній `LIKE '%%'` знаходить усе (30 з 30) | Порожній (після `trim`) `search` — це «фільтра немає», обробляється в PHP |
| 5 | `LIMIT ? OFFSET ?` з `bindValue(…, PDO::PARAM_INT)` працює при нативних prepared statements; `page=2, perPage=10` → ID 20…11; `OFFSET = 99 999 999 900` (`999 999 999 × 100`) повертає `[]` без помилки; значення вміщується в 64-бітний `int` | Верхня межа `page` — 9 цифр, переповнення неможливе |
| 6 | Невалідний UTF-8 (`\xff`, обірвана послідовність, сурогат) у параметрі `LIKE` **не дає помилки**: просто 0 збігів. Байт `\0` теж | 500 з цієї причини не буде; перевірка кодування не потрібна, але тест «не 500» потрібен |
| 7 | `PDO::query()` при вимкненій емуляції теж іде через prepared statement, тому **будь-який замір** сесійного лічильника `Com_stmt_execute` сам додає 1: дельта двох послідовних замірів = 1. Два прикладні запити дали дельту 3 | Кількість SQL-запитів = `(після − до) − накладні (1)`. Перевіряється: список — рівно 2, `GET /{id}` — рівно 1, відхилені запити (`400`) — 0 |
| 8 | Комбінований фільтр на сіді: `category=mood` + `isActive` + пропущений `en` → `total = 1`, `id = [23]`; `COUNT(*)` і вибірка з однаковим `WHERE` узгоджені | Один побудований `WHERE` для обох запитів |
| 9 | Сід (`orakuls_test`), 30 фраз: ID 1…3 love, 4…6 money, 7…9 luck, 10…12 work, 13…15 travel, 16…18 mood, 19 `hidden` (love, неактивна), 20 `only_lv` (luck), 21 `only_en` (work), 22 `no_ru` (travel), 23 `blank_en` (mood), 24 `diacritics` (mood), 25 `cyrillic_case` (money), 26 `emoji` (love), 27 `multiline` (work), 28 `len500` (luck), 29 `html` (travel), 30 `like_chars` (money). У **кожній** із 6 категорій по 5 фраз | Очікування в тестах виводь із даних сіду (`Seeder::load` + карта `key → id`), а не хардкодь числа |
| 10 | Очікувані результати на сіді (за спаданням `id`): `missing=lv` → [21]; `missing=en` → [23, 20]; `missing=ru` → [22, 21, 20]; `isActive=false` → [19]; `search=sodien` → [24, 17, 10, 5]; `search=bus` → [24, 14, 10]; `search=тест` → [25]; `search=LUCK` → [26, 24]; `search=100%` → [30]; `search=_` → [30]; `search=%` → [30]; `search=\` → [30]; `category=mood&search=sodien` → [24, 17]; `category=luck&missing=ru` → [20]; `category=mood&missing=en&isActive=true` → [23] | Використовуй як перехресну перевірку своїх виведених очікувань |
| 11 | Фронт: `perPage=50` фіксовано; рахує сторінки як `ceil(total / perPage)`; якщо сторінка після видалення порожня (`items: []`, `page > 1`), сам зменшує номер сторінки й питає знову | Сторінка за межами діапазону — `200` і порожній `items`, **не** `400`; `perPage` у відповіді завжди ≥ 1 |
| 12 | `Request::query()` (з BE-001) кидає `400` на параметр-масив і повертає `string\|null` | Використовується для всіх параметрів списку: масив → `400` |
| 13 | Допоміжні функції входу для тестів (`authToken`, `authAs`, …) лежать у `tests/api/auth.php`; допоміжні `withBigTable`, `bytesSent`, `keepOnlyActive` — у `tests/api/message.php`. Файли підключаються за алфавітом (`glob`) | Спільні хелпери виносяться, інакше нові тести залежатимуть від порядку підключення |

## 2. Дизайн-рішення цього плану

1. **Шари** (D-17): `Controller\PhraseController` розбирає й валідує ввід → `Domain\PhraseFilter` (чисті дані) → `Repository\PhraseRepository` (SQL) → `Domain\Phrase` (відображення рядка БД у відповідь API). Контролер не містить SQL.
2. **`Domain\Phrase` — єдине місце** перетворення «рядок БД → об'єкт API». Його перевикористають BE-006 і BE-007, тому він не залежить від `Http`.
3. **Один побудований `WHERE`** для вибірки сторінки й для `COUNT(*)`: гарантує, що `total` завжди відповідає фільтрам. Побудова повертає SQL і типізований список параметрів.
4. **Рівно 2 запити на список, 1 на фразу.** `SQL_CALC_FOUND_ROWS` не використовуємо (застарів у MySQL 8.0, а ми хочемо переносимості).
5. **Валідація до БД.** Відхилені запити (`400`) не виконують жодного SQL. Це перевіряється лічильником (факт 7).
6. **Строгі явні фільтри** (на відміну від `exclude` у BE-003, D-18): невідоме значення `category`/`missing`/`isActive`, некоректні `page`/`perPage`, задовгий `search` — `400`. Порожній рядок = параметра немає.
7. **`GET /{id}` приймає лише канонічне ціле** (`^[1-9][0-9]{0,9}$`), решта — `404`. Для всіх регулярних виразів у вході користувача **обов'язковий модифікатор `D`** (без нього `$` пропускає перенос рядка в кінці).
8. **Очікування в тестах — з даних сіду**; лише для пошуку (де потрібна логіка колації) очікування — явні списки ключів із факту 10.

## 3. Карта файлів

Створити:

```
backend/
  src/
    Domain/Phrase.php
    Domain/PhraseFilter.php
    Controller/PhraseController.php
  tests/
    auth-lib.php                  ← хелпери входу, винесені з tests/api/auth.php
    api/phrases.php
```

Змінити:

```
backend/src/Repository/PhraseRepository.php   — search(), find(), lacksTextSql(), escapeLike()
backend/src/routes.php                        — два маршрути
backend/tests/api.php                         — підключити auth-lib.php
backend/tests/api/auth.php                    — прибрати хелпери (перенесені в auth-lib.php); тести лишаються
backend/tests/api/message.php                 — прибрати withBigTable/bytesSent/keepOnlyActive (перенесені в db-lib.php)
backend/tests/db-lib.php                      — withBigTable(), bytesSent(), keepOnlyActive(), statementCount()
backend/tests/run.php                         — юніт-перевірки Phrase, escapeLike, розбору параметрів
backend/tests/smoke.php                       — безпечна перевірка нових шляхів
docs                                          — див. розділ 6
```

Не чіпати: `docs/API.md`, `frontend/`, `public/index.php`, `docker-compose.yml`, `database/` (схема й сід), `src/Controller/MessageController.php`, `src/Controller/AuthController.php`, `src/Auth/`, `src/Http/AdminGuard.php`, `.env.example`.

## 4. Кроки реалізації

Кожен крок завершується перевіркою; не переходь далі з червоними тестами. PHP-файли пиши інструментами Write/Edit, **не через heredoc у Bash**: оболонка зменшує `\\` до `\` і ламає регулярні вирази й рядки з `\x01`.

### Крок 1 · Рефакторинг тестової обв'язки (без зміни поведінки)

Мета: нові тести не залежать від порядку підключення файлів.

**1.1.** Створи `tests/auth-lib.php` і **перенеси** туди з `tests/api/auth.php` (разом з їх `use`, константами `AUTH_UNAUTHORIZED_BODY`, `AUTH_INVALID_CREDENTIALS_BODY` і докблоками) усі допоміжні функції, що не є тестами: `authLogin`, `authCredentials`, `authToken`, `authAs`, `authClearAttempts`, `authAttemptCount`, `authFail`, `forgeToken`, `authConfig`, `authClaims`, `authCaptureLog`, `authRetryAfter`, `authAssertUnauthorized`. У `tests/api/auth.php` лишаються лише заголовок-коментар і виклики `test(...)`. Тест-файли посилаються на константи `TEST_ADMIN_*` у момент виклику (вони визначені в `tests/api.php`), тому порядок визначення не критичний.

**1.2.** У `tests/api.php` підключи `require __DIR__ . '/auth-lib.php';` одразу після `db-lib.php`.

**1.3.** Перенеси з `tests/api/message.php` у `tests/db-lib.php` функції `keepOnlyActive`, `bytesSent`, `withBigTable` (без змін коду; `message.php` лишає `availableIds` і `draw`, бо вони специфічні для нього). Дублікатів функцій лишатися не повинно (PHP не дозволяє повторне оголошення: скрипт упаде).

**1.4.** Додай у `tests/db-lib.php`:

```php
/**
 * Скільки SQL-запитів (виконань prepared statement) зробив $fn на цьому з'єднанні.
 * Накладні самого заміру (PDO::query при вимкненій емуляції теж іде через prepare) віднімаються.
 */
function statementCount(callable $fn): int
{
    $pdo = testPdo();
    $read = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch(PDO::FETCH_NUM)[1];

    $a = $read();
    $b = $read();
    $overhead = $b - $a;      // = 1: кожен замір сам виконує один prepared statement

    $before = $read();
    $fn();
    $after = $read();

    return ($after - $before) - $overhead;
}
```

**Перевірка кроку 1:** `docker compose run --rm --no-deps php php tests/run.php` і `docker compose exec php php tests/api.php` — **усі 75 і 61 тест зелені**, кількість тестів не змінилася. Це контрольна точка: рефакторинг не повинен змінити жодного результату.

### Крок 2 · `Domain\Phrase` і `Domain\PhraseFilter`

`src/Domain/Phrase.php` — незмінний об'єкт, не залежить від `Http`/`Repository`:

```php
final class Phrase
{
    /** @param array{lv: string, en: string, ru: string} $text */
    public function __construct(
        public readonly int $id,
        public readonly string $category,
        public readonly array $text,
        public readonly bool $isActive,
        public readonly string $createdAt,   // вже ISO 8601 UTC, '2026-10-26T18:30:00Z'
        public readonly string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row рядок phrases з БД (колонки text_lv, is_active, created_at …) */
    public static function fromRow(array $row): self;

    /** @return array{id: int, category: string, text: array{lv: string, en: string, ru: string}, isActive: bool, createdAt: string, updatedAt: string} */
    public function toArray(): array;
}
```

- `fromRow`: `(int) id`, `(string) category`, `text` з ключами **в порядку `lv`, `en`, `ru`** (через `Languages::CODES` і `Languages::column()`), `isActive = (bool) (int) $row['is_active']`, дати — `Iso8601::fromDb((string) …)`.
- `toArray`: ключі рівно в порядку `id, category, text, isActive, createdAt, updatedAt` (порядок з `API.md`). Службових імен колонок у результаті немає.

`src/Domain/PhraseFilter.php` — чисті дані параметрів списку:

```php
final class PhraseFilter
{
    public function __construct(
        public readonly ?string $category = null,
        public readonly ?string $search = null,      // вже обрізаний; null = немає
        public readonly ?string $missing = null,     // lv|en|ru
        public readonly ?bool $isActive = null,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {}

    public function offset(): int;                   // ($page - 1) * $perPage
}
```

**Юніт-перевірки** (`tests/run.php`, без БД): `Phrase::fromRow` з типовим рядком (`is_active` = `1`/`0`, дати `'2026-10-26 18:30:00'`) → `toArray()` дорівнює очікуваному масиву **і** `json_encode` дає ключі в порядку з `API.md`; `is_active` `'1'`/`'0'` (рядки) теж приводяться; текст із діакритикою, кирилицею та `\n` збережений; некоректна дата → виняток; `offset()` для `page=1,perPage=50` → 0, `page=3,perPage=10` → 20.

### Крок 3 · `PhraseRepository`: `search()`, `find()`, `lacksTextSql()`, `escapeLike()`

Додай до наявного `PhraseRepository` (наявні `hasTextSql` і `findRandom` не чіпай):

```php
/** SQL-умова «переклад мовою $lang порожній». Поряд із hasTextSql: єдине визначення порожнього для всіх ендпоінтів. */
public static function lacksTextSql(string $lang): string
{
    return 'TRIM(`' . Languages::column($lang) . '`) = \'\'';
}

/**
 * Готує підрядок для LIKE ... ESCAPE x'01': службовий символ вилучається з вводу (його не можна набрати,
 * тож він не може бути шуканим), а % і _ екрануються ним. Зворотний слеш і решта символів лишаються звичайними.
 */
public static function escapeLike(string $needle): string
{
    $needle = str_replace("\x01", '', $needle);

    return strtr($needle, ['%' => "\x01%", '_' => "\x01_"]);
}

/**
 * @return array{items: list<Phrase>, total: int}
 */
public function search(PhraseFilter $filter): array;

public function find(int $id): ?Phrase;
```

`search()`:
1. Побудуй `WHERE` **один раз** приватним методом, що повертає `[string $sql, list<array{0: mixed, 1: int}> $binds]` (значення і тип `PDO::PARAM_*`). Умови додаються через `AND`, лише для заданих полів:
   - `category` → `` `category` = ? `` (`PARAM_STR`);
   - `isActive` → `` `is_active` = ? `` (`PARAM_INT`, `1`/`0`);
   - `missing` → `self::lacksTextSql($lang)` (без параметрів);
   - `search` → `(` + для кожного з `text_lv`, `text_en`, `text_ru` фрагмент `` `col` LIKE ? ESCAPE x'01' `` через `OR` + `)`; тричі один і той самий параметр `'%' . self::escapeLike($search) . '%'` (`PARAM_STR`). Назви колонок — **лише** з `Languages::column()`.
   - Якщо умов немає, `WHERE` не додається.
2. Рядок `ESCAPE x'01'` записуй у PHP-рядок у подвійних лапках або через конкатенацію, **без** фактичного керівного символа в коді.
3. Запит сторінки: `SELECT `id`, `category`, `text_lv`, `text_en`, `text_ru`, `is_active`, `created_at`, `updated_at` FROM `phrases` {where} ORDER BY `id` DESC LIMIT ? OFFSET ?` — параметри `WHERE`, потім `perPage` і `offset` як `PARAM_INT`. Позиційні параметри нумеруються **з 1** і наскрізно.
4. Запит `total`: `SELECT COUNT(*) FROM `phrases` {where}` з тими самими параметрами `WHERE`.
5. Повертає `['items' => list<Phrase>, 'total' => (int) …]`; `items` — перенумерований список (`array_map` / `array_values`), порожній — `[]`.

`find($id)`: `SELECT <ті самі колонки> FROM phrases WHERE id = ?` (`PARAM_INT`), `null`, якщо рядка немає.

**Юніт-перевірки `escapeLike`** (`tests/run.php`): `escapeLike('100%')` → `"100\x01%"`; `escapeLike('a_b')` → `"a\x01_b"`; `escapeLike('\\')` (один зворотний слеш) лишається `'\\'`; `escapeLike("x\x01y")` → `'xy'` (службовий символ вилучено); `escapeLike('Šodien')` без змін; порожній рядок → порожній; `lacksTextSql('en')` → ``TRIM(`text_en`) = ''``, невідома мова → `InvalidArgumentException`.

### Крок 4 · `PhraseController` і маршрути

`src/Controller/PhraseController.php`:

```php
final class PhraseController
{
    private const MAX_SEARCH_CHARS = 200;
    private const MAX_PER_PAGE = 100;

    public function index(Request $request, array $params): Response;
    public function show(Request $request, array $params): Response;

    /** Публічний і статичний, щоб розбір параметрів тестувався без БД. Кидає ApiException 400. */
    public static function parseFilter(Request $request): PhraseFilter;
}
```

`parseFilter` — для кожного параметра `$v = $request->query('name')` (масив → `400` від `query()`); `null` **або** `''` → параметра немає:
- `category`: `Categories::isValid($v)` інакше `400` (`Invalid category`);
- `missing`: `Languages::isValid($v)` інакше `400`;
- `isActive`: рівно `'true'` → `true`, `'false'` → `false`, інше → `400`;
- `search`: `trim($v)`; порожній → немає; `mb_strlen > 200` → `400`;
- `page`: `preg_match('/^[1-9][0-9]{0,8}$/D', $v)` → `(int)`; інакше `400`; немає → `1`;
- `perPage`: `preg_match('/^[1-9][0-9]{0,2}$/D', $v)` і `(int) <= 100`; інакше `400`; немає → `50`.

Повідомлення помилок містять **назву параметра**, але не повторюють значення (щоб не віддзеркалювати ввід).

`index`: `$filter = self::parseFilter($request); $result = (new PhraseRepository(Db::pdo()))->search($filter);` → `Response::json(['items' => array_map(fn (Phrase $p) => $p->toArray(), $result['items']), 'total' => $result['total'], 'page' => $filter->page, 'perPage' => $filter->perPage], 200, ['Cache-Control' => 'no-store'])`.

`show`: `$id = $params['id']`; якщо не `preg_match('/^[1-9][0-9]{0,9}$/D', $id)` → `ApiException::notFound()`; `find((int) $id)`; `null` → `404`; інакше `Response::json($phrase->toArray(), 200, ['Cache-Control' => 'no-store'])`.

`src/routes.php`, після маршрутів авторизації:

```php
// Фрази (читання). Захист — AdminGuard у Kernel
$router->get('/api/admin/phrases', [new PhraseController(), 'index']);
$router->get('/api/admin/phrases/{id}', [new PhraseController(), 'show']);
```

**Юніт-перевірки `parseFilter`** (`tests/run.php`, через `new Request('GET', '/api/admin/phrases', $query)`): таблиця «query → результат або `400`»:

| Вхід | Результат |
|------|-----------|
| `[]` | усі `null`, `page=1`, `perPage=50` |
| `['category' => 'love']` | `category='love'` |
| `['category' => 'LOVE']`, `'x'`, `'love '` | `400` |
| `['category' => '', 'search' => '', 'missing' => '', 'isActive' => '', 'page' => '', 'perPage' => '']` | як `[]` |
| `['missing' => 'en']`; `'de'`, `'EN'` | `en`; `400`, `400` |
| `['isActive' => 'true']` / `'false'` | `true` / `false` |
| `['isActive' => '1']`, `'True'`, `'yes'`, `'TRUE'` | `400` |
| `['search' => '  foo  ']` | `'foo'` |
| `['search' => "  \n "]` | `null` |
| `['search' => str_repeat('a', 200)]` / `201` | ок / `400`; для кирилиці — рахунок у **символах** (200 літер `я` = ок) |
| `['page' => '1']`, `'999999999'` | `1`, `999999999` |
| `['page' => '0']`, `'-1'`, `'1.5'`, `'abc'`, `'1000000000'` (10 цифр), `'01'`, `"1\n"`, `' 1'` | `400` |
| `['perPage' => '100']`; `'1'` | `100`; `1` |
| `['perPage' => '0']`, `'101'`, `'1000'`, `'-5'`, `'abc'`, `'050'` | `400` |
| `['category' => ['love']]` (масив) | `400` |

**Перевірка кроків 2–4 вручну:** тести з'являться в кроці 5. Поки що переконайся лише, що `docker compose run --rm --no-deps php php tests/run.php` зелений і `php -l` чистий для нових файлів.

### Крок 5 · Тести ендпоінтів (`tests/api/phrases.php`)

Допоміжне в цьому файлі:

```php
/** GET з дійсним токеном; $query — вже розібраний query. */
function phrasesGet(string $path, array $query = [], ?string $token = null): Response
{
    return apiRequest('GET', $path, $query, ['authorization' => 'Bearer ' . ($token ?? authToken())]);
}

/** @return array<string, array{id:int, ...}> key → рядок сіду з id */
function seedRows(): array;       // Seeder::load() + reseed()-карта: key → ['id' => …, 'category' => …, 'lv' => …, 'en' => …, 'ru' => …, 'active' => …]

/** Список ID (за спаданням) з відповіді. */
function listIds(Response $response): array;

/** ID за ключами сіду, відсортовані за спаданням — для явних очікувань пошуку. */
function idsOf(array $keys, array $ids): array;
```

Очікування для фільтрів `category`, `isActive`, `missing`, пагінації виводь із `seedRows()` (незалежний «оракул» у PHP: фільтр масиву сіду, `rsort` за `id`). Для `search` очікування — явні списки ключів із факту 10 через `idsOf`.

Перелік перевірок (кожна — окремий `test()`, назви українською, префікс `phrases:`):

| # | Що перевіряє | Як | AC |
|---|--------------|----|----|
| 1 | Захист | без токена: `GET /api/admin/phrases`, `/api/admin/phrases/1`, `/api/admin/phrases?category=love` → `401` (формат помилки без `fields`) | 1 |
| 2 | Список без параметрів | `200`; `page = 1`, `perPage = 50`; на сіді (30 фраз) `total = 30`, 30 елементів, ID від 30 до 1; відсортовані за спаданням | 2 |
| 3 | Типи й форма | ключі відповіді рівно `items, total, page, perPage` в цьому порядку; `total/page/perPage` — `int`; кожна фраза має рівно `id, category, text, isActive, createdAt, updatedAt`; `id` — `int`; `isActive` — `bool`; `text` — об'єкт з ключами `lv, en, ru` (порядок), порожній переклад `""`; дати відповідають `/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D`; у сирому тілі `"isActive":true`/`false`, а не `1`/`0`; службових імен колонок немає; повна фраза `diacritics` збігається з сідом байт у байт (діакритика не екранується в сирому тілі) | 3 |
| 4 | `no-store` | `Cache-Control: no-store` у відповідях `200` і `400` та `404` обох ендпоінтів | 4 |
| 5 | `category` | для кожного з 6 кодів результат = оракул (5 фраз кожна); `category=LOVE`, `category=x`, `category=love%20` → `400 bad_request` | 5 |
| 6 | `isActive` | `false` → рівно [19]; `true` → 29 фраз без 19; `1`, `True`, `yes` → `400` | 6 |
| 7 | `missing` | `lv` → [21]; `en` → [23, 20] (зокрема `blank_en` з самих пробілів); `ru` → [22, 21, 20]; `de`, `EN` → `400` | 7 |
| 8 | `search`: регістр | `LUCK` → [26, 24]; `luck` → те саме; кирилиця `ТЕСТ` і `тест` → [25] | 8 |
| 9 | `search`: діакритика | `sodien` → [24, 17, 10, 5]; `bus` → [24, 14, 10]; `ŠODIEN` теж | 9 |
| 10 | `search`: спецсимволи | `100%` → [30]; `_` → [30]; `%` → [30]; `\` (один зворотний слеш) → [30]; `%_` не діє як шаблон (порожньо або лише точні входження); `sure_thing` → [30]; `emoji` 🍀 → містить 26 | 10 |
| 11 | `search`: службові випадки | `'  sodien  '` = `sodien`; `''` і `'   '` → як без пошуку (30); рядок із 200 символів ок, 201 → `400` (рахунок у символах, 200×`я` ок); ін'єкції `' OR '1'='1`, `%'; DROP TABLE phrases; --`, `1); DELETE FROM phrases; --` → `200`, нічого не зламано, `COUNT(*)` фраз = 30; невалідний UTF-8 (`"\xff"`) і `"\0"` → `200`, не `500`; службовий символ `"\x01"` у вводі не дає помилки | 11 |
| 12 | Порожні параметри | `category=&missing=&isActive=&search=&page=&perPage=` (усі порожні) = список без параметрів | 12 |
| 13 | Комбінації | `category=mood&search=sodien` → [24, 17]; `category=luck&missing=ru` → [20]; `category=mood&missing=en&isActive=true` → [23]; `category=love&isActive=false` → [19]; `search=sodien&isActive=false` → []; усі чотири одразу; результат кожної = перетин окремих фільтрів (AND, не OR) | 13 |
| 14 | `total` і сторінки | на сіді: `perPage=10`: `page=1` → ID 30…21, `page=2` → 20…11, `page=3` → 10…1, `total` скрізь 30; з фільтром (`category=love`): `total = 5`, а не 30; на таблиці в 600 фраз (`withBigTable`): `perPage` за замовч. 50 → сторінка 1 = ID 600…551, сторінка 12 = ID 50…1 (50 елементів), `total = 600` | 14 |
| 15 | За межами | сторінка 13 (при 600/50), `page=999999999` → `200`, `items: []` (масив у сирому тілі `"items":[]`), `total` правильний, `page` той самий; `perPage=100` → 100 елементів, сторінка 6 = ID 100…1; `perPage=1` → 1 елемент | 15 |
| 16 | Некоректні `page`/`perPage` | `perPage=0/101/1000/-5/abc/1.5/050`, `page=0/-1/1.5/abc/01/1000000000` → `400 bad_request`; відповідь без `fields`; параметр-масив `page[]=1`, `category[]=love` → `400` | 16 |
| 17 | Порожній результат | `search=zzzz-немає` → `200`, `"items":[]`, `total = 0`; `total` — число | 17 |
| 18 | Фраза за ID | `GET /api/admin/phrases/24` → `200`, тіло = елемент зі списку для ID 24; кожна з 30 фраз за ID збігається з елементом списку; `404 not_found` для: `31`, `99999`, `0`, `-1`, `abc`, `1.5`, `007`, `1e3`, `4294967296`, `99999999999` (11 цифр), `1%20` | 18 |
| 19 | Кількість запитів | `statementCount`: список (з усіма фільтрами) = 2; сторінка за межами = 2; `GET /{id}` = 1; відхилені (`400`: `category=x`, `perPage=0`, `isActive=1`, довгий `search`, `page[]=1`) і `404` за форматом `id` = **0** (валідація до БД) | 19 |
| 20 | Швидкодія | `withBigTable` (600 фраз): медіана з 30 запитів зі `search=a` + `category=love` + `page=2` < 300 мс; окремо без фільтрів | 19 |
| 21 | Пошук на великій таблиці | на 600 фразах `search` за унікальним словом з одного з 570 доданих рядків (напр. `номер 417.`) знаходить рівно одну фразу; `total = 1` | 11, 19 |

Деталі виконання:
- `authToken()` викликай **до** вимірювань `statementCount` та таймерів (вхід теж робить SQL).
- Тести 14–15, 20–21 використовують `withBigTable` (транзакція, відкат, `reseed()` у `finally`); тести, що змінюють дані (`UPDATE`), у `finally` викликають `reseed()`.
- Тест 3: «сире тіло» бери з `$response->body`, а не з `decode()` (інакше `1` і `true` не розрізнити).
- Для тесту 19 `GET /{id}` з ID, якого немає, теж виконує 1 запит; тест 404-за-форматом — 0.
- Ліміт `perPage=100` на сіді віддасть 30 елементів — для перевірки «до 100» використовуй `withBigTable`.

### Крок 6 · Smoke і документація

**6.1. `tests/smoke.php`** (лише читання, безпечно для прода; облікові дані не відправляти): `GET /api/admin/phrases` без токена → `401`; `GET /api/admin/phrases/1` без токена → `401`; `GET /api/admin/phrases?perPage=0` без токена → `401` (guard раніше за валідацію).

**6.2. Документація.** Онови в одному проході — документи описують те, що **є** в коді:

| Файл | Що змінити |
|------|-----------|
| `backend/docs/tasks/BE-005-phrases-read/task.md` | Статус `Done (дата)`, відмітити AC `[x]`, розділ «Як перевірити» |
| `backend/docs/decisions.md` | **D-24** пошук: `LIKE ? ESCAPE x'01'`, чому не `ESCAPE '\'` (дірка пошуку одного символа, факт 1), чому не `LOCATE` (діакритика, факт 3), службовий символ вилучається з вводу. **D-25** строгі явні фільтри (`400`) на відміну від `exclude` (D-18); порожній параметр = немає; межі `page` (9 цифр) і `perPage` (≤ 100), `search` ≤ 200 символів; ціна — вгадування помилки клієнта вголос. **D-26** `Domain\Phrase` (єдине відображення рядка БД у API) і `Domain\PhraseFilter`; рівно 2 запити на список, `SQL_CALC_FOUND_ROWS` не використовується |
| `backend/docs/architecture.md` | Структура (`Domain/Phrase.php`, `PhraseFilter.php`, `Controller/PhraseController.php`), таблиця компонентів, правила залежностей (`Controller → Repository, Domain`; `Repository → Domain, Support`; `Domain` ні від чого), прибрати BE-005 з «Що ще буде додано» |
| `backend/docs/database.md` | Шаблон запитів списку (`WHERE` будується один раз, `LIMIT ? OFFSET ?`, `COUNT(*)`); пошук (`ESCAPE x'01'`) — оновити розділ; `lacksTextSql` поряд із `hasTextSql` |
| `backend/docs/api-conventions.md` | Таблиця параметрів `GET /api/admin/phrases` (допустимі значення, межі, `400`), форма відповіді, поведінка «сторінка за межами»; принцип «явні фільтри строгі, підказки лояльні» |
| `backend/docs/security.md` | Рядки про `search` (параметризований `LIKE`, службовий символ екранування, ін'єкції), строгий розбір параметрів → ✅ |
| `backend/docs/testing.md` | Спільні хелпери (`auth-lib.php`, `db-lib.php`: `withBigTable`, `statementCount`), «оракул» із сіду, як рахується число SQL-запитів (накладні = 1) |
| `docs/BACKEND.md` (спільний) | Для фронта: параметри й коди `400` списку; сторінка за межами → `200` і порожній `items`; `perPage ≤ 100`; `search` ≤ 200 символів і нечутливий до регістру та діакритики |

Після правок перевір посилання між документами і що всі `D-xx` існують.

### Крок 7 · Фінальна перевірка

Виконай **усе** і наведи фактичний вивід:

```
cd backend
docker compose run --rm --no-deps php php tests/run.php            # усі ok
docker compose exec php php tests/api.php                          # усі ok; запусти двічі підряд
docker compose exec php php tests/smoke.php                        # усі ok
docker compose exec php php bin/build-schema.php --check           # schema.sql без змін
```

**Наскрізна перевірка по справжньому HTTP** (сервер на :8000 працює з **робочою** БД, де фраз немає; заповнювати її не можна, тому для `200` з даними підніми тимчасовий сервер на тестовій БД так само, як у BE-003: `docker compose exec -d -e DB_NAME=orakuls_test php timeout 60 php -S 127.0.0.1:8001 -t public public/index.php`; усі облікові дані — з локального `.env` (`ADMIN_LOGIN`, пароль `local-dev-password`), але оскільки сервер на 8001 бере `TOKEN_SECRET`/хеш із того ж `.env`, вхід працює). Запити виконуй зсередини контейнера (`docker compose exec php php -r …` або невеликий скрипт-файл):
- логін → токен; `GET /api/admin/phrases` → `200`, `total = 30`, 30 фраз, `Cache-Control: no-store`;
- `?category=mood&missing=en` → [23]; `?search=sodien` → 4 фрази; `?search=%25` (закодований `%`) → лише фраза з `%`; `?perPage=10&page=2` → ID 20…11; `?page=999` → `200` і `items: []`; `?perPage=0` → `400`; `GET /api/admin/phrases/24` → `200`; `/api/admin/phrases/abc` → `404`; те саме без токена → `401`.
Сервер на 8001 вимкни (`timeout` сам його завершить; переконайся, що на :8000 усе працює).

**Мутаційна перевірка** (тимчасово ламаєш код, відповідний тест має впасти, відкочуєш; перед початком запиши `sha256sum` змінюваних файлів, після кожної мутації відновлюй з копії, наприкінці суми мають збігтися):

| Мутація | Що має впасти |
|---------|---------------|
| (а) У `search()` прибрати `ESCAPE x'01'` і `escapeLike` (шукати сирий ввід у `LIKE`) | тест 10 (`%`, `_`, `100%`), юніт-перевірки `escapeLike` |
| (б) Замінити `ORDER BY id DESC` на `ASC` | тести 2, 14 |
| (в) Запит `COUNT(*)` без `WHERE` | тести 13, 14, 17 |
| (г) `offset = page * perPage` (замість `(page − 1) * perPage`) | тест 14 |
| (д) Для `missing` використати `hasTextSql` замість `lacksTextSql` | тест 7 |
| (е) Інвертувати фільтр `isActive` | тест 6 |
| (є) Дозволити `perPage` до 1000 | тест 16, юніт-перевірки `parseFilter` |
| (ж) Послабити регулярний вираз `id` у `show` до `^\d+$` (без `D`) | тест 18 |
| (з) У `Phrase::fromRow` прибрати приведення `isActive` до `bool` (віддавати `int`) | тест 3 |
| (и) Прибрати `trim` у `search` | тест 11, юніт-перевірки |
| (і) Прибрати `Cache-Control: no-store` з `index` | тест 4 |
| (ї) З'єднати умови `WHERE` через `OR` замість `AND` | тест 13 |
| (й) Прибрати ліміт 200 символів у `search` | тест 11, юніт-перевірки |
| (к) На початку `index` додати зайвий запит до БД до розбору параметрів (напр. `Db::pdo()->query('SELECT 1')`) | тест 19 (відхилені запити мають давати 0 запитів) |

Якщо якась мутація **не** валить жодного тесту — це знахідка: посиль тест і опиши в звіті. Після мутацій переконайся (контрольні суми), що тимчасових змін у коді не лишилось.

Таблиця відповідності AC → перевірка (додай у звіт):

| AC (за порядком у task.md) | Перевірка |
|----------------------------|-----------|
| 1 без токена → 401 | тести 1; smoke |
| 2 список без параметрів | тест 2 |
| 3 типи й форма | тест 3; юніт-перевірки `Phrase` |
| 4 `no-store` | тест 4 |
| 5 `category` | тест 5; юніт-перевірки `parseFilter` |
| 6 `isActive` | тест 6 |
| 7 `missing` | тест 7 |
| 8 регістр пошуку | тест 8 |
| 9 діакритика | тест 9 |
| 10 спецсимволи | тест 10; юніт-перевірки `escapeLike` |
| 11 `search`: пробіли, довжина, ін'єкції | тести 11, 21 |
| 12 порожні параметри | тест 12; юніт-перевірки `parseFilter` |
| 13 комбінації (AND) | тест 13 |
| 14 `total` і сторінки | тест 14 |
| 15 за межами, `perPage=100` | тест 15 |
| 16 некоректні `page`/`perPage` → 400 | тест 16; юніт-перевірки `parseFilter` |
| 17 порожній результат | тест 17 |
| 18 фраза за ID | тест 18 |
| 19 2 запити, < 300 мс | тести 19, 20, 21 |
| 20 хелпери винесено, тести зелені | крок 1; повний прогін |
| 21 рішення й документація | крок 6.2, перевірка посилань |

## 5. Пастки

1. **Не використовуй `ESCAPE '\'` чи будь-який друкований символ як екранувальний** — пошук одного такого символа перестає працювати (факт 1). Тільки `x'01'` і вилучення цього символа з вводу.
2. **`LOCATE` не замінює `LIKE`**: діакритика (факт 3).
3. **Регулярні вирази на вході — з модифікатором `D`** (`/…$/D`), інакше `"1\n"` проходить як число.
4. **`COUNT(*)` і вибірка мають спільний `WHERE`** і однаковий порядок параметрів; `LIMIT`/`OFFSET` біндуються окремо, після параметрів `WHERE`.
5. **Позиційні параметри нумеруються з 1**, наскрізно по всьому запиту (у пошуку тричі один і той самий рядок).
6. **Назви колонок — лише з `Languages::column()`**; ввід користувача ніколи не потрапляє в текст SQL.
7. **`search` порожній після `trim` — це «немає»**, а не `LIKE '%%'` (знайшов би все).
8. **Сторінка за межами діапазону — `200`**, не `400` (фронт на цьому тримається); `perPage` у відповіді не може бути `0`.
9. **`items` — завжди список** (`[]`, а не `{}`/`null`); після фільтрації масиву використовуй `array_values`.
10. **Не повертай службових імен колонок** (`text_lv`, `is_active`) і не віддзеркалюй ввід користувача в повідомленнях `400`.
11. **Накладні заміру `Com_stmt_execute` = 1** (факт 7): без віднімання «два запити» виглядатимуть як три.
12. **Порядок підключення тестових файлів не повинен мати значення**: після кроку 1 жоден тест-файл не викликає функцій, визначених в іншому `tests/api/*.php`.
13. **Heredoc у Bash з `\\`/`\x01`** псує код — пиши файли через Write/Edit; керівний символ у коді записуй як `"\x01"`, не літерально.
14. **`reseed()` у `finally`** кожного тесту, що змінив дані; `withBigTable` це робить сам.
15. **Не змінюй `Seeder`, сід і `schema.sql`** — нових даних для тестів не потрібно (факти 9–10).

## 6. Чого не робити

- Не додавати створення, оновлення, видалення, імпорт, статистику (BE-006 … BE-008).
- Не додавати міграції, індекси, нові змінні оточення, залежності.
- Не робити `search` швидшим «фултекстом» чи кешем; 600 рядків — це ~1 мс.
- Не змінювати `API.md`, `frontend/`, `MessageController`, авторизацію.
- Не повертати `category`-назви чи переклади назв — лише коди.
- Не заповнювати робочу БД `orakuls` фразами.
- Не коміти без прохання користувача.

## 7. Звіт після завершення

Короткий звіт користувачу: що створено і змінено; фактичний вивід кроку 7 (скорочено, з підсумковими рядками «N тестів, 0 провалено»); результат наскрізної перевірки по HTTP; результат кожної мутації; таблиця AC → перевірка; **усі відхилення від плану і чому**; що лишилось сумнівним. Підтверди, що робоча БД `orakuls` не змінилась (2 міграції, 0 фраз, 0 спроб входу). Запропонуй розбиття на коміти (напр.: рефакторинг тестових хелперів; `Domain\Phrase` і `PhraseFilter`; `PhraseRepository`; `PhraseController` і маршрути; тести; документація), але не виконуй без прохання.
