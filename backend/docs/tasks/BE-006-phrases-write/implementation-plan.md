# BE-006 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та критерії приймання — у [task.md](task.md) (прочитай його першим: усі рішення там прийняті, не переглядай їх). Контракт — `docs/API.md` (не змінювати).

Ціль: `POST /api/admin/phrases`, `PUT /api/admin/phrases/{id}`, `DELETE /api/admin/phrases/{id}` + окремий валідатор фрази (`Domain`), який потім використає імпорт (BE-007).

Мова коментарів у коді і документації — українська, як у сусідніх файлах. Стиль — як у `PhraseController`/`PhraseRepository` (strict types, `final`, `readonly`, докблоки з типами).

## 0. Передумови

- Docker-контейнери `php` і `db` запущені. Усі команди — з `C:\rep\orakuls-coffee\backend`, з явним `-f docker-compose.yml` (робочий каталог інколи скидається — завжди `cd` явно).
- **Базова лінія** (до твоїх змін): `tests/run.php` — **92** тести, `tests/api.php` — **84**, `tests/smoke.php` — **20** перевірок; усе зелене. Перед початком запусти всі три й переконайся.
- PHP-файли пиши інструментами Write/Edit, а не heredoc через bash (зворотні слеші ламаються).
- Дев-БД `orakuls` не чіпати (там: `phrases` 0 рядків, `migrations` 2, `login_attempts` 0). Усі тести — лише на `orakuls_test`. Міграції і `schema.sql` **не змінюються**.
- Нічого не коміть (`git add/commit/stash/reset/checkout` заборонені). Файли `task.md` і `implementation-plan.md` не редагуй.

## 1. Перевірені факти (не перевіряй вдруге, спирайся на них)

| № | Факт | Наслідок |
|---|------|----------|
| 1 | PHP `trim("\u{00A0}x\u{00A0}")` нічого не обрізає (прибирає лише ` \t\n\r\0\x0B`); JS `trim()` обрізає NBSP, `U+FEFF`, `U+3000`, `U+2028/2029` та всі `Zs` | Для тексту потрібен власний юнікодний trim (§2.2). `U+0085`, `U+180E`, `U+200B` JS **не** обрізає — і ми теж не повинні |
| 2 | Явний клас `[\t\n\x0B\x0C\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]` з модифікатором `u` збігається з `String.prototype.trim` (10 перевірених випадків, зокрема `\u{200B}x`, `\u{0085}x`, `\u{180E}x` не змінюються) | Використовуй саме цей клас |
| 3 | `preg_replace(..., /u)` на невалідному UTF-8 повертає `NULL`; `json_decode` невалідний UTF-8 і lone surrogate (`\ud800`) відхиляє винятком (→ `400` від `Request::json()`) | У валідаторі, що може викликатись не лише з JSON (імпорт), перевіряй `mb_check_encoding` перед `preg_*` |
| 4 | `json_decode` порожнього об'єкта `{}`, порожнього масиву `[]`, списку і `{"0":"x"}` дає PHP-масив, для якого `array_is_list` = `true` (для `{}`/`[]` — порожній масив) | «Об'єкт» = `is_array` і (`=== []` або не список). Порожній `text: {}`/`[]` вважаємо порожнім об'єктом → `text.lv/en/ru = required`; `text: ["a","b","c"]` → `text = invalid` |
| 5 | `json_decode('{"a":"\u0000"}')` повертає рядок із NUL (не помилка) | NUL ловить перевірка керівних символів у валідаторі |
| 6 | `mb_strlen(str_repeat('😀', 500), 'UTF-8')` = 500, `strlen` = 2000; MySQL `CHAR_LENGTH` після запису = 500; рядок із 501 символа БД відхиляє помилкою 1406 (strict mode) | Довжина — `mb_strlen(..., 'UTF-8')` на trimmed; 501 має відсікти валідатор (422), а не БД (500) |
| 7 | Round-trip через БД не псує: `\r\n`, `\t`, `<script>alert('x')</script>`, `\`, `%`, `_`, 500 емодзі | Ніякого екранування/нормалізації тексту |
| 8 | `INSERT ... VALUES (..., UTC_TIMESTAMP(), UTC_TIMESTAMP())` дає `created_at = updated_at` | `createdAt = updatedAt` при створенні гарантовано без обчислень у PHP |
| 9 | `PDOStatement::rowCount()` після `UPDATE` без фактичних змін — **0** (як і для неіснуючого `id`); після `DELETE` — реальна кількість видалених (1 / 0) | `update()` не можна будувати на `rowCount()`. Алгоритм: `UPDATE`, потім `find($id)`; `null` → фрази немає. `delete()` повертає `rowCount() > 0` |
| 10 | `Response::noContent()` не приймає заголовків | `204` з `no-store`: `new Response(204, null, ['Cache-Control' => 'no-store'])`. `Response::send()` для `null`-тіла нічого не друкує |
| 11 | Помилки (`ApiException` → `ErrorHandler`) вже віддають `Cache-Control: no-store` | Для помилок нічого додавати не треба; для успішних відповідей — додавати явно |
| 12 | `Request::json()` кидає `400` для порожнього тіла, не-JSON, JSON не-об'єкта (число, рядок, список) і глибини > 32; `{}` повертає `[]` | Контролер не дублює цю перевірку |
| 13 | `AdminGuard` стоїть до роутера і не звертається до БД; тест `statementCount()` для відхилених запитів BE-005 давав 0 | Відхилений запис теж має давати 0 SQL-запитів. Успішні: `POST` 2 (INSERT + SELECT), `PUT` 2 (UPDATE + SELECT), `DELETE` 1 |
| 14 | `reseed()` (з `tests/db-lib.php`) робить `TRUNCATE`, тож повертає і дані, і `AUTO_INCREMENT` (id сіду 1…30) | Кожен тест, що пише в БД, на початку й у `finally` викликає `reseed()` (через новий `withFreshSeed`, §4.5) |
| 15 | Невідомий метод/шлях → `404 not_found` (роутер не знає `405`) | Окремої обробки `405` не додавати |

## 2. Проєктні рішення

### 2.1. Компоненти

- `Domain\PhraseInput` — незмінні нормалізовані дані для запису: `category`, `text` (`['lv','en','ru']`, trimmed), `isActive`. Не залежить від `Http`/`Repository`.
- `Domain\PhraseValidator` — статичний `validate(array $data): array{input: ?PhraseInput, fields: array<string,string>}`. Не кидає винятків на помилки даних: збирає **всі** `fields`. Для BE-007: той самий метод викликається для кожного елемента.
- `Repository\PhraseRepository` — `create(PhraseInput): Phrase`, `update(int, PhraseInput): ?Phrase`, `delete(int): bool`.
- `Controller\PhraseController` — `create`, `update`, `delete`; формат `id` перевіряє спільний приватний метод (його ж використовує `show`).

### 2.2. Правила валідатора (точно)

Поля у `fields` з'являються в порядку: `category`, потім або `text`, або `text.lv`/`text.en`/`text.ru`, потім `isActive`.

| Поле | Умова → код |
|------|-------------|
| `category` | ключа немає **або** `null` → `required`; не рядок, або не з `Categories::CODES` (`LOVE`, `love `, `5`, `["love"]`) → `invalid` |
| `text` | ключа немає → `required`; не масив, або непорожній список (`"abc"`, `5`, `null`, `true`, `["a","b","c"]`) → `invalid`; порожній масив (`{}`/`[]`) → трактується як об'єкт без ключів (див. нижче) |
| `text.lv/en/ru` (лише коли `text` — об'єкт) | ключа немає → `required`; значення не рядок (число, `null`, масив, bool), або невалідний UTF-8 → `invalid`; інакше: trim (§2.3) → якщо у результаті є керівні символи (§2.4) → `invalid`; якщо `mb_strlen > 500` → `too_long` |
| `text` (підсумок) | якщо `text` — об'єкт, **жодної** помилки `text.*` немає і всі три тексти порожні після trim → `text = empty` |
| `isActive` | ключа немає → `true`; не `bool` (`"yes"`, `1`, `0`, `null`, `"true"`) → `invalid` |

Невідомі ключі (верхній рівень і всередині `text`) мовчки ігноруються.

### 2.3. Юнікодний trim

`PhraseValidator::trim(string): string` — `public static`, тест-доступний. Реалізація: `preg_replace('/^[' . CLASS . ']+|[' . CLASS . ']+$/u', '', $s)`, де `CLASS` — рівно клас із факту 2 (константою). Модифікатор `D` не потрібен (немає `$` без `m` у ролі кінця рядка з `\n`: `\n` входить у клас, тож цикл обрізання з'їсть і його). Результат `null` (невалідний UTF-8) тут не очікується — валідатор перевіряє `mb_check_encoding` до виклику; якщо все ж `null` — поверни вхідний рядок.

### 2.4. Керівні символи

Заборонені після trim: `/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u` (константа). Дозволені `\t` (0x09), `\n` (0x0A), `\r` (0x0D). Не нормалізуй `\r\n`. C1-символи (`U+0080–U+009F`) **не** забороняємо.

### 2.5. Порядок кроків в обробниках

- `create`: `Request::json()` (400) → `validate` (422) → `repository->create` → `201`.
- `update`: формат `id` (404) → `Request::json()` (400) → `validate` (422) → `repository->update` (`null` → 404) → `200`.
- `delete`: формат `id` (404) → `repository->delete` (`false` → 404) → `204`.

Усі успішні відповіді мають `Cache-Control: no-store`.

## 3. Карта файлів

Створити:
- `src/Domain/PhraseInput.php`
- `src/Domain/PhraseValidator.php`
- `tests/api/phrases-write.php`

Змінити:
- `src/Repository/PhraseRepository.php` (`create`, `update`, `delete`)
- `src/Controller/PhraseController.php` (`create`, `update`, `delete`; спільний розбір `id`)
- `src/routes.php` (3 маршрути)
- `tests/db-lib.php` (`withFreshSeed`, `tableDump`)
- `tests/run.php` (юніт-тести валідатора)
- `tests/smoke.php` (+3 перевірки)
- Документація: `backend/docs/decisions.md`, `architecture.md`, `database.md`, `api-conventions.md`, `security.md`, `testing.md`, `docs/BACKEND.md`

Не чіпати: `frontend/`, `docs/API.md`, `database/`, `src/Auth`, `src/Http`, `MessageController`, `AuthController`, `docker-compose.yml`, `.env.example`.

## 4. Кроки

### Крок 1. `Domain\PhraseInput`

```php
final class PhraseInput
{
    /** @param array{lv: string, en: string, ru: string} $text вже trimmed */
    public function __construct(
        public readonly string $category,
        public readonly array $text,
        public readonly bool $isActive,
    ) {}
}
```

### Крок 2. `Domain\PhraseValidator`

Створи клас за правилами §2.2–2.4. Константи: `TEXT_MAX_CHARS = 500`, `TRIM_CLASS` (рядок класу без дужок), `CONTROL_PATTERN`. Публічні: `validate()`, `trim()`. Приватні допоміжні — за потреби. Послідовність у `validate()`:

1. `category` → `$fields['category']` за таблицею.
2. `text` → або `$fields['text']`, або цикл по `Languages::CODES` (порядок lv, en, ru) з `$fields["text.$lang"]`; після циклу — перевірка `empty`.
3. `isActive`.
4. Якщо `$fields === []` → `['input' => new PhraseInput(...), 'fields' => []]`, інакше `['input' => null, 'fields' => $fields]`.

Приклад обробки одного тексту:

```php
if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) { invalid }
$value = self::trim($raw);
if (preg_match(self::CONTROL_PATTERN, $value) === 1) { invalid }
elseif (mb_strlen($value, 'UTF-8') > self::TEXT_MAX_CHARS) { too_long }
```

Домен не залежить від `Http`/`Repository`. Коментар у класі: «валідація для BE-006 і BE-007; збирає всі помилки, винятків на помилках даних не кидає».

### Крок 3. `PhraseRepository`: `create`, `update`, `delete`

```php
public function create(PhraseInput $input): Phrase
// INSERT INTO `phrases` (`category`,`text_lv`,`text_en`,`text_ru`,`is_active`,`created_at`,`updated_at`)
// VALUES (?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
// PARAM_STR/PARAM_INT (is_active 1/0); id = (int) $this->pdo->lastInsertId(); return find($id) (null → RuntimeException)

public function update(int $id, PhraseInput $input): ?Phrase
// UPDATE `phrases` SET `category`=?,`text_lv`=?,`text_en`=?,`text_ru`=?,`is_active`=?,`updated_at`=UTC_TIMESTAMP() WHERE `id`=?
// created_at НЕ чіпати. Далі return $this->find($id) (rowCount() не використовувати — факт 9)

public function delete(int $id): bool
// DELETE FROM `phrases` WHERE `id` = ?  → rowCount() > 0
```

Усі значення — лише параметрами; колонки текстів — з `Languages::column($lang)` у циклі (або явно, як у `COLUMNS`). Рівно: `create` — 2 запити, `update` — 2, `delete` — 1.

### Крок 4. `PhraseController` + маршрути

- Винеси перевірку `id` з `show()` у `private static function parseId(string $id): int` (регулярка `/^[1-9][0-9]{0,9}$/D`, інакше `ApiException::notFound('Phrase not found')`); `show` використовує її (поведінка BE-005 не змінюється, його тести лишаються зеленими).
- `create`, `update`, `delete` за §2.5. Валідація: `$result = PhraseValidator::validate($request->json()); if ($result['fields'] !== []) throw ApiException::validation($result['fields']);`.
- `201` — `Response::json($phrase->toArray(), 201, ['Cache-Control' => 'no-store'])`; `204` — `new Response(204, null, ['Cache-Control' => 'no-store'])`.
- У `routes.php`: `$router->post('/api/admin/phrases', ...)`, `$router->put('/api/admin/phrases/{id}', ...)`, `$router->delete('/api/admin/phrases/{id}', ...)`. Зверни увагу: майбутній `POST /api/admin/phrases/import` (BE-007) — інший шлях, колізій немає; але літеральні маршрути реєструються **перед** `{id}`, коли вони з'являться.
- Контролер не відкриває БД до валідації (`Db::pdo()` лише після неї).

### Крок 5. Тестова обв'язка

У `tests/db-lib.php` додай:

```php
/** reseed() до і після $fn: тест, що пише в БД, не залежить від сусідніх і нічого їм не лишає. */
function withFreshSeed(callable $fn): void
{
    reseed();
    try { $fn(); } finally { reseed(); }
}

/** Усі рядки phrases за id (сирі значення колонок) — для перевірок «БД не змінилась». */
function tableDump(): array
{
    return testPdo()->query('SELECT * FROM `phrases` ORDER BY `id`')->fetchAll();
}
```

### Крок 6. Тести

**6.1. `tests/run.php` (юніт, без БД)** — розділ «BE-006: PhraseValidator». Табличні перевірки (вхід → точні `fields`, порядок ключів значущий; використовуй `assertSame`):

1. Валідне тіло → `fields === []`, `input` заповнений, тексти trimmed, `isActive` = `true` за замовчуванням.
2. `category`: немає / `null` → `required`; `LOVE`, `love `, `5`, `["love"]`, `""`, `true` → `invalid`.
3. `text`: немає → `required`; `"abc"`, `5`, `null`, `true`, `["a","b","c"]` → `invalid`; `[]` → `text.lv/en/ru = required` (рівно ці три ключі).
4. `text.xx`: немає ключа → `required`; `5`, `null`, `true`, `["x"]` → `invalid`; після trim 500 символів — ок, 501 → `too_long`; 500×`š`, 500×`я`, 500×`😀` — ок; 501×`😀` → `too_long`; пробіли навколо 500-символьного тексту не рахуються (`"  " . 500×a . "  "` — ок).
5. `text = empty`: усі три `""`; усі три з пробілів, `\n`, `\t`, `U+00A0`, `U+3000`, `U+FEFF`, `U+2028`; **не** `empty`, коли є `text.xx = required/invalid`; **не** `empty` для `\u{200B}`, `\u{0085}` (вони не обрізаються — це текст).
6. Керівні символи: `"a\0b"`, `"a\x01"`, `"a\x02"`, `"a\x7F"`, `"a\x0B"` усередині → `text.xx = invalid`; `"a\nb"`, `"a\r\nb"`, `"a\tb"`, `"š я 😀"`, `"\u{0080}"` — ок і не змінюються; керівний символ **на краю**, що не входить у клас trim (`"\x01hi"`), → `invalid`.
7. Невалідний UTF-8 (`"\xff"`) у `text.xx` → `invalid` без винятку та без `null`-помилки `preg_*`.
8. `isActive`: немає → `true`; `true`/`false` → як є; `"yes"`, `"true"`, `1`, `0`, `null`, `[]` → `invalid`.
9. Кілька помилок разом: `{category: "x", text: {lv: 5, en: "", ru: "y"}, isActive: "no"}` → рівно `category=invalid`, `text.lv=invalid`, `isActive=invalid` у такому порядку.
10. Зайві ключі (`id`, `createdAt`, `text.de`) не впливають на результат; `PhraseInput` їх не містить.
11. `PhraseValidator::trim`: NBSP, `U+3000`, `U+FEFF`, `U+2028`, `\r\n`, tab по краях прибираються; `U+200B`, `U+0085`, `U+180E` по краях лишаються; внутрішні пробіли не чіпаються; `😀 ` → `😀`.
12. Валідатор не залежить від `Http`/`Repository`: перевірка рефлексією/текстом файла, що в `src/Domain/PhraseValidator.php` немає `use App\Http`/`App\Repository` (перевірка простим `str_contains` на вмісті файла).

**6.2. `tests/api/phrases-write.php`** — `use App\Http\Response;`. Помічники у файлі: `writeRequest(string $method, string $path, array|string|null $body, ?string $token = null): Response` (заголовки `authorization: Bearer …` і `content-type: application/json`, масив → `json_encode(JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`, рядок як є), `validBody(array $override = []): array` (категорія `love`, три короткі тексти, `isActive` не задавати), `rowOf(int $id): ?array` (сирий рядок із БД). **Усі** тести, що пишуть, обгортаються у `withFreshSeed(function () { ... })`. Файл не залежить від `tests/api/phrases.php` (власні помічники; назви не повинні конфліктувати з наявними — перевір `grep "^function"` у `tests/api/*.php`).

Тести (назва → що перевіряє; AC — номер у task.md за порядком):

| № | Тест | Перевірки | AC |
|---|------|-----------|----|
| 1 | без токена всі три → `401` | `POST`, `PUT /1`, `DELETE /1` без `Authorization`, у тому числі з невалідним тілом (`{}`) → `401`, `unauthorized`, БД не змінилась (`tableDump` до/після) | 1 |
| 2 | `POST` валідне → `201` | тіло відповіді: рівно ключі `id, category, text, isActive, createdAt, updatedAt`; `id` — int (31 на свіжому сіді); `isActive === true` за замовч.; `createdAt === updatedAt`; дата — `Z`-формат і в межах ±10 с від `time()`; `Cache-Control: no-store`; рядок у БД існує | 2 |
| 3 | `POST` → з'являється у `GET` | `GET /api/admin/phrases/{id}` == відповідь `POST`; `GET /api/admin/phrases?perPage=1` → перший елемент == відповідь (найбільший `id`); `total` = 31 | 3 |
| 4 | `category` | немає / `null` → `422`, `fields = {category: required}`; `LOVE`, `love `, `5`, `["love"]` → `invalid`; усі 6 валідних кодів → `201` | 4 |
| 5 | `text` порожній | усі три `""`; усі з пробілів; з `U+00A0`/`U+3000`/`U+FEFF` → `422`, `fields = {text: empty}`; один непорожній — `201` | 5 |
| 6 | довжина | 500×`a`, 500×`š`, 500×`я`, 500×`😀` → `201` і в БД `CHAR_LENGTH` = 500, відповідь повертає той самий рядок; 501 → `422`, `{"text.lv": "too_long"}` (і для `en`, `ru`); не `500` | 6 |
| 7 | trim | `"  " . 500×a . "  "` → `201`, збережено рівно 500 (без пробілів); `"\u{00A0} hi \u{3000}"` → у відповіді й БД `hi`; `\n` усередині лишається | 7 |
| 8 | форма `text` | немає → `{text: required}`; `"abc"`, `5`, `null`, `["a","b","c"]` → `{text: invalid}`; `{}` → `text.lv/en/ru = required`; без ключа `en` → `{"text.en": required}`; `5`/`null`/`[]` у `text.ru` → `{"text.ru": invalid}` | 8 |
| 9 | `isActive` | `"yes"`, `1`, `0`, `null`, `"true"` → `422 {isActive: invalid}`; `false` → `201` з `isActive === false` у відповіді й `is_active = 0` у БД | 9 |
| 10 | кілька помилок | `{category: "x", text: {lv: 5, en: "", ru: "y"}, isActive: "no"}` → рівно три поля в порядку category, text.lv, isActive | 10 |
| 11 | керівні символи | `\0`, `\x01`, `\x02`, `\x7F` в `lv`/`en`/`ru` → `422 {"text.xx": invalid}`; `\n`, `\r\n`, `\t` → `201` і в БД байт у байт ті самі | 11 |
| 12 | поганий JSON → `400` | порожнє тіло, `not json`, `[1,2]`, `"str"`, `5`, `{"text":"\ud800"}`, байт `\xff` у рядку → `400 bad_request`, не `500`; БД не змінилась | 12 |
| 13 | зайві поля | `{id: 999, createdAt: "2000-01-01T00:00:00Z", text: {…, de: "x"}}` → `201`, `id` не 999, `createdAt` — поточний | 13 |
| 14 | HTML/спецсимволи | `<script>alert(1)</script>`, `'; DROP TABLE phrases; --`, `100% _ \`, емодзі, діакритика, `"` → `201`, у відповіді, `GET` і БД — байт у байт; таблиця `phrases` на місці, кількість рядків +1 | 14, 24 |
| 15 | `PUT` повна заміна | на фразі сіду (запам'ятати `created_at` з БД): нові `category/text/isActive`; `createdAt` не змінився (сид 2026-10-26…), `updatedAt` > старого і ≈ `time()`; відповідь == `GET`; решта 29 рядків у `tableDump` без змін | 15 |
| 16 | `PUT` без `isActive` | → `isActive === true`, навіть якщо фраза була прихована (id 19 із сіду) | 15 |
| 17 | `PUT` помилки | неіснуючий `id` (999) з валідним тілом → `404 not_found`; `007`, `abc`, `0`, `1.5`, `-1`, 11 цифр, `1%0A` → `404` (навіть із невалідним тілом); `422` для існуючого й для неіснуючого `id` — БД не змінилась (`tableDump` до/після); поганий JSON → `400` | 16 |
| 18 | видимість у `/api/message` | `keepOnlyActive($pdo, $id)`; `PUT isActive:false` → `GET /api/message?lang=en` → `404`; `PUT isActive:true` → `200` з цією фразою | 17 |
| 19 | очищення перекладу | фраза з `en/lv/ru`; `PUT text.en=""` → `/api/message?lang=en` → `404`, `?lang=lv` → `200`; `GET /api/admin/phrases?missing=en` містить цей `id` | 18 |
| 20 | `DELETE` | існуюча → `204`, тіло порожнє (`null` або `''`), `Cache-Control: no-store`; `GET /{id}` → `404`; повторний `DELETE` → `404`; решта 29 рядків без змін; `total` = 29 | 19, 20 |
| 21 | `DELETE` неіснуючий / неканонічний | `999`, `007`, `abc`, `0` → `404 not_found`, БД не змінилась | 21 |
| 22 | `no-store` | `201`, `200` (PUT), `204`, `422`, `400`, `404`, `401` — усі з `Cache-Control: no-store` | 22 |
| 23 | кількість SQL-запитів (`statementCount`) | відхилені: `422`, `400`, `404` за форматом `id` — **0**; `POST` 201 — **2**; `PUT` 200 — **2**; `PUT` неіснуючого з валідним тілом — **2**; `DELETE` 204 — **1**; `DELETE` неіснуючого — **1** | 24 |
| 24 | сид відновлюється | після виходу з `withFreshSeed` `phraseCount` = 30 і найбільший `id` = 30 (наступний `POST` у новому `withFreshSeed` знову дає 31) | 26 |

Додатково для «не лишає сміття»: `tests/api.php` двічі поспіль зелений.

**6.3. `tests/smoke.php`** (безпечно для прода — токена немає, нічого не пишеться): `POST /api/admin/phrases` з порожнім тілом → `401`; `PUT /api/admin/phrases/1` → `401`; `DELETE /api/admin/phrases/1` → `401`. Дотримуйся стилю наявних перевірок. Було 20 → 23.

### Крок 7. Документація (описує те, що **є** в коді)

| Файл | Що змінити |
|------|-----------|
| `decisions.md` | **D-27** · Валідатор фрази — `Domain\PhraseValidator`, повертає всі помилки, юнікодний trim як у JS (факт 1–2; чому не PHP `trim`), довжина в символах. **D-28** · Керівні символи в тексті заборонені (D-24: `0x01`/`0x02` — службові символи пошуку; дозволені `\t \n \r`), зайві ключі ігноруються, `PUT` без `isActive` = `true`, `text: {}`/`[]` = об'єкт без ключів. **D-29** · Запис: порядок перевірок (id → тіло → валідація → БД), відповідь — `find` після запису (`UPDATE` не покладається на `rowCount`, факт 9), `created_at = updated_at` від одного `UTC_TIMESTAMP()`, кількість запитів 2/2/1, `Cache-Control: no-store` на всьому |
| `architecture.md` | Структура: `PhraseInput`, `PhraseValidator`; таблиця компонентів; правило залежностей без змін; прибрати BE-006 із «Що ще буде додано» |
| `database.md` | Виправити примітки «BE-006 обрізає по-PHP-шному» → юнікодний trim (там, де вони є; знайди `grep "по-PHP"`); розділ «Запис фраз (BE-006)» із шаблонами `INSERT`/`UPDATE`/`DELETE` |
| `api-conventions.md` | Таблиця кодів `fields` для фрази (`required/invalid/too_long/empty` і коли кожен), порядок перевірок, зайві ключі ігноруються, `204` без тіла, `201` |
| `security.md` | Рядки ⏳ BE-006 → ✅ (текст як є, довжина/категорія/типи, керівні символи, prepared statements); рядки про імпорт лишаються ⏳ BE-007 |
| `testing.md` | Нові числа тестів, `withFreshSeed`, `tableDump`, правило «тест, що пише, обгортається в `withFreshSeed`» |
| `docs/BACKEND.md` | Таблиця статусу ендпоінтів: три нові — готово |

Перевір, що посилання між документами не зламані (`[D-27](decisions.md)` тощо) і що жоден документ не говорить «BE-006 ще не зроблено».

### Крок 8. Фінальна перевірка

1. `tests/run.php`, `tests/api.php` (двічі поспіль), `tests/smoke.php`, `php bin/build-schema.php --check`. Усе зелене; числа більші за базову лінію.
2. Дев-БД не змінена (`phrases` 0, `migrations` 2, `login_attempts` 0).
3. **Наскрізна перевірка по HTTP**: у контейнері `DB_NAME=orakuls_test php -S 127.0.0.1:8001 -t public` (у фоні, з `timeout 60`), токен через `\App\Auth\TokenService` з `AuthConfig::fromEnv()` (не друкуй секрети). `curl`: `POST` (201), `GET` цієї фрази, `PUT` (200, `createdAt` без змін), `PUT` з пустим тілом (400), з поганим (422), `DELETE` (204 без тіла), повторний `DELETE` (404), без токена (401). Після цього `reseed` тестової БД.
4. **Мутації** (кожну застосовуй скриптом із перевіркою, що файл справді змінився; після всіх — відновлення та `sha256sum` до/після; для кожної вкажи, які тести впали — жодна не повинна пройти непоміченою):

| # | Мутація | Має впасти |
|---|---------|-----------|
| а | `trim()` PHP замість `PhraseValidator::trim` | юніт 5/11, API 5, 7 |
| б | `strlen` замість `mb_strlen` | юніт 4, API 6 |
| в | прибрати перевірку керівних символів | юніт 6, API 11 |
| г | `UPDATE` без `WHERE` (або `WHERE` на `1=1`) | API 15 (решта рядків), 17 |
| д | `DELETE` без `WHERE` | API 20 (решта рядків) |
| е | `update()` також перезаписує `created_at` | API 15 |
| є | `isActive` за замовчуванням `false` | юніт 1/8, API 2, 16 |
| ж | валідація після звернення до БД (зайвий `SELECT 1` до `validate`) | API 23 |
| з | `POST` повертає `200` замість `201` | API 2 |
| и | прибрати `Cache-Control` з `204` | API 20, 22 |
| і | `Categories::isValid` не викликається (будь-яка категорія) | юніт 2, API 4 |
| ї | прибрати ознаку `empty` (завжди проходить порожній текст) | юніт 5, API 5 |
| й | `created_at` через `NOW()` у `INSERT` без `UTC_TIMESTAMP()` для `updated_at` або різні значення | API 2 (`createdAt = updatedAt`) |
| к | `update()` повертає `null` лише за `rowCount() === 0` | API 15 або 16 (повторний `PUT` тими самими даними в ту ж секунду дає хибний `404`) |
| л | `parseId` у `update` після `request->json()` | API 17 (`404` для поганого `id` з поганим тілом) |

Якщо мутація не червонить жодного тесту — додай/посили тест і повтори.

## 5. Пастки

- **Не покладайся на `rowCount()` у `update()`** (факт 9): повторне збереження тих самих даних у ту ж секунду дає 0 → хибний `404`.
- **Регулярки на невалідному UTF-8** повертають `null`; перевіряй `mb_check_encoding` до `preg_*` (факти 3, 12).
- **`assertSame` на масивах чутливий до порядку ключів** — у тестах `fields` порівнюй у тому порядку, який задає §2.2, або порівнюй відсортовані копії; не змінюй порядок у коді під тест.
- **`json_encode` у тестах**: для керівних символів і емодзі працює без змін; передавай `JSON_THROW_ON_ERROR`. Для «битого» JSON передавай рядок як є.
- **Не пиши `0x01` буквально в PHP-коді** через heredoc; у регулярках використовуй `\x01`-послідовності в одинарних лапках.
- **Порядок `reseed`**: не викликай `withFreshSeed` усередині `withBigTable` і навпаки (`TRUNCATE` комітить транзакцію).
- **Дати в тестах**: час сервера тестів і БД однаковий (UTC); дозволена похибка ±10 секунд; не покладайся на різницю `updatedAt` > `createdAt` усередині однієї секунди — порівнюй із датою сіду.
- **Тести BE-005 не повинні ламатись**: `show()` лише змінює спосіб розбору `id`, поведінка та сама.
- Маршрути `PUT`/`DELETE` мають параметр `{id}` у тому ж форматі, що й `GET` (див. наявні маршрути).

## 6. Чого не робити

- Не змінювати `API.md`, схему БД, міграції, `schema.sql`, `src/Http/*`, `src/Auth/*`.
- Не додавати імпорт (BE-007) і статистику (BE-008).
- Не вводити нових залежностей і Composer.
- Не нормалізувати `\r\n`, не екранувати HTML, не обрізати текст мовчки (довший за 500 → `422`).
- Не вживати `SELECT … FOR UPDATE`/транзакції без потреби: адмін один (рішення зафіксуй у D-29 одним реченням).
- Не лишати у БД `orakuls_test` тестового сміття між запусками (`withFreshSeed`).

## 7. Звіт

У кінці виклади звіт українською: (1) створені/змінені файли; (2) числа тестів (`run`, `api`, `smoke`) до та після; (3) результати кроку 8: HTTP-перевірка, таблиця мутацій із тим, що саме впало, `sha256sum` до/після; (4) відхилення від плану і причини; (5) сумніви та знахідки. Будь чесним: що не пройшло або не перевірено — так і пиши.
