# BE-008 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та критерії приймання — у [task.md](task.md) (прочитай його першим: усі рішення там прийняті, не переглядай їх). Контракт — `docs/API.md` (не змінювати).

Ціль: `GET /api/admin/stats` — один агрегуючий SQL-запит у `PhraseRepository` і тонкий `StatsController`.

Мова коментарів у коді і документації — українська, стиль — як у сусідніх файлах (`PhraseController`, `PhraseRepository`, `HealthController`).

## 0. Передумови

- Docker-контейнери `php` і `db` запущені. Усі команди — з `C:\rep\orakuls-coffee\backend`, з абсолютним `-f C:\rep\orakuls-coffee\backend\docker-compose.yml` (робочий каталог інколи скидається — завжди `cd` явно).
- **Базова лінія** (до твоїх змін): `tests/run.php` — **105** тестів, `tests/api.php` — **130**, `tests/smoke.php` — **24**; усе зелене. Перед початком запусти всі три й переконайся.
- PHP- і Python-код пиши інструментами Write/Edit (скрипти — у файл, не heredoc через bash: зворотні слеші ламаються). Не перезаписуй файли скриптами без резервної копії.
- Дев-БД `orakuls` не чіпати (там: `phrases` 0 рядків, `migrations` 2, `login_attempts` 0). Усі тести — лише на `orakuls_test`. Міграції і `schema.sql` не змінюються.
- Нічого не коміть (`git add/commit/stash/reset/checkout` заборонені). **Жоден `docs/tasks/**` файл не редагуй** — їх оновить замовник.

## 1. Перевірені факти (не перевіряй вдруге, спирайся на них)

| № | Факт | Наслідок |
|---|------|----------|
| 1 | Один запит `SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(category = 'love'), 0) AS cat_love, … , COALESCE(SUM(TRIM(text_lv) = ''), 0) AS miss_lv, … FROM phrases` на MySQL 5.7 працює; на сіді дає `total 30`, `active 29`, по `5` у кожній з 6 категорій, `missing`: `lv 1`, `en 2`, `ru 3` | Саме цей вигляд запиту |
| 2 | `COUNT(*)` повертається як `int`, а **усі `SUM(...)` — як рядки** (`"29"`, `"0"`) | Кожне значення приводиться `(int)`; без цього JSON міститиме `"29"` замість `29` |
| 3 | На порожній таблиці `COUNT(*)` = `0`, а `SUM` без `COALESCE` — `NULL` | `COALESCE(..., 0)` лишаємо (захист); `(int) null` теж `0`, тож мутація «прибрати `COALESCE`» функціонально еквівалентна і від тестів не очікується |
| 4 | Запит на 600 рядках виконується ≈ **0,8 мс** | Окремі індекси не потрібні |
| 5 | `PhraseRepository::lacksTextSql($lang)` повертає `` TRIM(`text_xx`) = '' `` (без параметрів) і є єдиним визначенням порожнього перекладу (BE-003/BE-005); `Languages::column()` — білий список колонок | Умову `missing` будувати лише через `lacksTextSql()`, не писати власну |
| 6 | `statementCount()` (з `tests/db-lib.php`) рахує виконання prepared statements (накладні заміру віднімаються); для відхилених `AdminGuard`-ом запитів — 0 | Успішний `stats` має дати рівно **1**, `401` — 0. Запит виконується через `prepare()`/`execute()` (або `query()`, що при вимкнених емульованих prepared теж іде через prepare) |
| 7 | `Response::json(...)` кодує асоціативні масиви з рядковими ключами як JSON-об'єкти; `Json::object()` гарантує `{}` для порожнього | `byCategory` і `missing` завжди непорожні (ключі з констант), тож це об'єкти; але перевір сирий JSON у тесті |
| 8 | Роутер: невідомий метод/шлях → `404 not_found` (`405` немає); `Cache-Control: no-store` на помилках додає `ErrorHandler` | Для `POST /api/admin/stats` додаткового коду не потрібно; `no-store` на `200` додавай явно |
| 9 | Сід (`database/seed/phrases.php`, 30 фраз) містить: 5 фраз у кожній категорії; одну приховану (`hidden`, `active => false`); фразу `blank_en` з англійським перекладом із трьох пробілів; ще кілька фраз із порожніми перекладами. `Seeder::load()` повертає рядки сіду (`key`, `category`, `active`, `lv`, `en`, `ru`) | Оракул у тесті обчислюється з цих рядків, а не з захардкожених чисел |
| 10 | `reseed()` робить `TRUNCATE` (повертає і дані, і `AUTO_INCREMENT`); `withFreshSeed()` викликає його до і після; `withBigTable()` додає 570 фраз у транзакції | Тести, що пишуть, — у `withFreshSeed`; **не** вкладати `withFreshSeed` у `withBigTable` і навпаки |
| 11 | Допоміжні функції, які можна використати: `authToken()` (`tests/auth-lib.php`), `tableDump()`, `withFreshSeed()`, `statementCount()`, `withBigTable()`, `keepOnlyActive()` (`tests/db-lib.php`); у `tests/api/phrases.php`/`phrases-write.php` є свої помічники (`phrasesGet`, `seedRows`, `adminWrite`, `validBody` …) — **файли тестів самодостатні**, тож у новому файлі пиши власні | Не залежати від порядку підключення тест-файлів; нові назви функцій не повинні конфліктувати (перевір `grep -n "^function" tests/api/*.php`) |
| 12 | `Request::query()` кидає `400` лише коли параметр — масив і лише коли його читають; `stats` параметрів не читає | `?category[]=x` теж ігнорується (`200`) — це нормально й свідомо |

## 2. Проєктні рішення

- `PhraseRepository::stats(): array` з формою `array{total: int, active: int, byCategory: array<string,int>, missing: array<string,int>}`. Ключі `byCategory` — рівно `Categories::CODES` у порядку констант, ключі `missing` — рівно `Languages::CODES`.
- Запит будується динамічно зі списків-констант: аліаси колонок — `cat_<код>` і `miss_<мова>`; значення категорій у SQL — лише з `Categories::CODES` (літерали з білого списку, ввід користувача сюди не потрапляє; можна додатково перевірити кожен код регуляркою `^[a-z]+$` або взяти з білого списку — не конкатенувати нічого іншого). Запит один, без параметрів.
- Усі значення — `(int)`.
- `StatsController::index(Request, array $params): Response` → `Response::json([...], 200, ['Cache-Control' => 'no-store'])`. `Db::pdo()` викликається всередині `index` (не в конструкторі).
- Маршрут: `$router->get('/api/admin/stats', [new StatsController(), 'index']);` у `routes.php` (після маршрутів фраз).
- Нова «сутність» `Domain\PhraseStats` **не потрібна** — форма масиву проста й використовується в одному місці.

## 3. Карта файлів

Створити:
- `src/Controller/StatsController.php`
- `tests/api/stats.php`

Змінити:
- `src/Repository/PhraseRepository.php` (`stats`)
- `src/routes.php` (1 маршрут)
- `tests/smoke.php` (+1 перевірка)
- Документація: `backend/docs/decisions.md`, `architecture.md`, `api-conventions.md`, `database.md`, `testing.md`, `docs/BACKEND.md`

Не чіпати: `frontend/`, `docs/API.md`, `docs/BACK-TO-FRONT.md`, `database/`, `src/Auth`, `src/Http`, `MessageController`, `AuthController`, `PhraseController`, `src/Domain/*`, `docker-compose.yml`, `.env.example`, `docs/tasks/**`.

## 4. Крок 1. `PhraseRepository::stats()`

```php
/**
 * Зведення для адмінки одним запитом (атомарний знімок, D-32) …
 * @return array{total: int, active: int, byCategory: array<string,int>, missing: array<string,int>}
 */
public function stats(): array
{
    $columns = ['COUNT(*) AS `total`', 'COALESCE(SUM(`is_active` = 1), 0) AS `active`'];
    foreach (Categories::CODES as $code) {
        $columns[] = "COALESCE(SUM(`category` = '{$code}'), 0) AS `cat_{$code}`";   // $code — з білого списку
    }
    foreach (Languages::CODES as $lang) {
        $columns[] = 'COALESCE(SUM(' . self::lacksTextSql($lang) . '), 0) AS `miss_' . $lang . '`';
    }
    $row = $this->pdo->query('SELECT ' . implode(', ', $columns) . ' FROM `phrases`')->fetch();
    …
}
```

Збери результат: `total` і `active` — `(int)`; `byCategory[$code] = (int) $row["cat_{$code}"]`; `missing[$lang] = (int) $row["miss_{$lang}"]`. Додай `use App\Domain\Categories;`. Коментар до коду: чому один запит, чому `(int)` (SUM → DECIMAL-рядок), що категорія поза списком у `byCategory` не потрапляє, але входить у `total`.

## 5. Крок 2. Контролер і маршрут

`StatsController` — за зразком `PhraseController::index`, без параметрів. `final class`, докблок: токен перевіряє `AdminGuard` (D-20), параметри ігноруються. Відповідь: `['total' => …, 'active' => …, 'byCategory' => …, 'missing' => …]` у цьому порядку ключів.

## 6. Крок 3. Тести

**`tests/api/stats.php`** — самодостатній файл (`use App\Database\Seeder; use App\Http\Response;`). Помічники (з унікальними назвами, напр. `statsGet(array $query = [], ?string $token = null): Response`, `statsOf(): array` — розпарсена відповідь `200`, `statsListTotal(array $query): int` — `total` з `GET /api/admin/phrases` з `perPage=1`). Усі тести, що пишуть, — у `withFreshSeed(function () { ... })`. Оракул сіду: `Seeder::load(APP_ROOT . '/database/seed/phrases.php')`; порожній переклад в оракулі = `trim((string) $text, " ") === ''` (лише пробіли, як `TRIM` у MySQL), активність = `$row['active'] ?? true`.

| № | Тест | Перевірки |
|---|------|-----------|
| 1 | без токена → `401` | `unauthorized`; і з query (`?category=love`); 0 SQL-запитів (`statementCount`) |
| 2 | форма і типи | рівно ключі `total, active, byCategory, missing` у цьому порядку; `total`/`active` — `int`; `byCategory` — рівно `love, money, luck, work, travel, mood` (порядок!), `missing` — рівно `lv, en, ru`, усі значення `int` (не рядки); сирий JSON містить `"byCategory":{` та `"missing":{`; заголовок `Cache-Control: no-store`; `Content-Type` JSON |
| 3 | сід за оракулом | `total`, `active`, кожна категорія, кожна мова збігаються з обчисленим із рядків сіду; додатково перевір, що в оракулі `active < total` (прихована фраза) і що є фраза з англійським із самих пробілів (тест перестане бути порожнім, якщо сід зміниться) |
| 4 | порожня БД | `DELETE FROM phrases` → `total 0`, `active 0`, усі 6 категорій `0`, усі 3 мови `0`; сирий JSON — об'єкти `{...}`, не `[]`; значення — `int` `0` |
| 5 | узгодженість зі списком | `total` == `total` списку без фільтрів; `active` == `?isActive=true`; кожна категорія == `?category=c`; кожна мова == `?missing=xx`; сума `byCategory` == `total` |
| 6 | переклад з пробілів | фраза `blank_en` входить у `missing.en`: `?missing=en` її містить і `missing.en` == його `total` |
| 7 | прихована фраза | `POST` приховану (`isActive:false`) фразу з `lv` і порожніми `en`/`ru` → `total`+1, `active` без змін, `byCategory[cat]`+1, `missing.en`+1, `missing.ru`+1, `missing.lv` без змін |
| 8 | миттєве відображення змін | послідовно (кожен крок — порівняння з точним очікуваним зсувом **і** з відповідями списку, як у тесті 5): `POST` нову фразу; `PUT` зі зміною категорії; `PUT` з `isActive:false`; `PUT` з очищенням `en`; `DELETE`; імпорт 3 елементів (один без `ru`) через `POST /api/admin/phrases/import`; жодного кешу між кроками |
| 9 | рівно один SQL-запит | `statementCount(fn () => statsGet())` == 1 на сіді й на порожній БД |
| 10 | швидкодія на 600 | у `withBigTable`: `total` 600, значення за оракулом (`love` = 575 …) узгоджені зі списком; медіана 20 запитів < 200 мс |
| 11 | зайві query-параметри ігноруються | `?category=love&isActive=false&missing=en&x=1&category[]=x` → тіло ідентичне відповіді без параметрів, `200` |
| 12 | інші методи → `404` | `POST`, `PUT`, `DELETE`, `PATCH` на `/api/admin/stats` з дійсним токеном → `404 not_found`; і `/api/admin/stats/1` → `404` |
| 13 | категорія поза списком | прямим `INSERT` (через `testPdo()`) вставити фразу з `category='other'` → `total`+1, сума `byCategory` лишається на 1 менше за `total`, помилки немає (документована поведінка) |

**`tests/smoke.php`:** +1: `GET /api/admin/stats` без токена → `401`, `no-store` (безпечно для прода). Було 24 → 25.

## 7. Крок 4. Документація (описує те, що **є** в коді)

| Файл | Що змінити |
|------|-----------|
| `decisions.md` | **D-32** · Статистика: один запит з умовною агрегацією (атомарний знімок; чому не `GROUP BY`/кілька запитів), `missing` — через `lacksTextSql()` і всі фрази (і приховані), `(int)` для `SUM`, категорії поза списком, відсутність кешу/параметрів |
| `architecture.md` | `StatsController` у структурі й таблиці компонентів; прибрати BE-008 з «Що ще буде додано» (якщо там нічого не лишилось — прибрати весь розділ або написати, що нових задач бекенда немає, окрім BE-009/010) |
| `api-conventions.md` | Опис відповіді `stats` (форма, порядок ключів, `int`, всі ключі завжди) |
| `database.md` | Розділ «Статистика (BE-008)»: шаблон запиту, типи `SUM`, продуктивність (≈ 1 мс на 600) |
| `testing.md` | Нові числа тестів, опис `tests/api/stats.php` і оракула сіду |
| `docs/BACKEND.md` | Ендпоінт `stats`: статус «готово», форма відповіді, примітка для фронта (числа збігаються зі списком з відповідним фільтром) |

Перевір, що посилання між документами не зламані і жоден документ не називає `stats` «майбутнім».

## 8. Крок 5. Фінальна перевірка

1. `tests/run.php` (без змін, 105), `tests/api.php` (двічі поспіль), `tests/smoke.php`, `php bin/build-schema.php --check`. Усе зелене.
2. Дев-БД не змінена (`phrases` 0, `migrations` 2, `login_attempts` 0); у `orakuls_test` після прогону: 30 фраз, `max(id)` 30.
3. **Наскрізна перевірка по HTTP** (як у BE-006/007): у контейнері `DB_NAME=orakuls_test timeout 90 php -S 127.0.0.1:8001 -t public` у фоні; токен через `\App\Auth\TokenService` з `AuthConfig::fromEnv()` (секрети не друкуй). `curl`: `GET /api/admin/stats` (значення, `no-store`), без токена (`401`), `POST /api/admin/stats` (`404`); після `POST /api/admin/phrases` числа змінились. Потім `reseed` тестової БД.
4. **Мутації** (скриптом із перевіркою, що файл справді змінився; після всіх — відновлення і `sha256sum` до/після; жодна не повинна пройти непоміченою):

| # | Мутація | Має впасти |
|---|---------|-----------|
| а | прибрати `(int)` для значень `SUM` (віддавати рядки) | 2, 3, 5 |
| б | `active` рахує `is_active = 0` | 3, 5, 7 |
| в | `missing` через `hasTextSql` (інвертоване) | 3, 5, 6 |
| г | `missing` рахує лише активні (`AND is_active = 1`) | 3, 5, 7 |
| д | `byCategory` без останньої категорії | 2, 3, 5 |
| е | `total` = `active` | 3, 5, 7 |
| є | прибрати `Cache-Control` з відповіді | 2 |
| ж | `missing` повертається як список (`array_values`) | 2, 4 |
| з | зайвий `SELECT 1` до основного запиту | 9 |
| и | ключі `missing.en` і `missing.ru` поміняні місцями | 3, 5 |
| і | контролер читає `category` з query і фільтрує | 11 |
| ї | `lacksTextSql` замінено на `= ''` без `TRIM` | 3, 5, 6 |

Якщо мутація не червонить жодного тесту — посилюй тест і повтори.

## 9. Пастки

- **`SUM` повертає рядок** (факт 2): забудеш `(int)` — JSON матиме `"29"`; перевіряй типи тестом, не оком.
- **Не вкладай `withFreshSeed` у `withBigTable`** і навпаки (`TRUNCATE` комітить транзакцію).
- **Тест 13 (`category='other'`)** пише в БД напряму: обов'язково в `withFreshSeed`.
- **Оракул порожнього перекладу** — лише пробіли (` `), як `TRIM` у MySQL; не використовуй `trim()` PHP у тесті, інакше табуляція/NBSP-фрази розійдуться з SQL (у сіді таких немає, але оракул має відповідати SQL).
- **`assertSame` на масивах чутливий до порядку ключів**: порядок `byCategory`/`missing` — з констант; перевіряй його явно (`array_keys`).
- **Не змінюй наявні тести** (BE-005/006/007 лишаються зеленими без правок).
- **Не додавай кеш і параметри** до ендпоінта.

## 10. Чого не робити

- Не змінювати `API.md`, схему БД, міграції, `schema.sql`, `src/Http/*`, `src/Auth/*`, `src/Domain/*`, `PhraseController`, жодних `docs/tasks/**`.
- Не додавати індекси, кеш, нові поля відповіді (`inactive`, розбивки) чи фільтри.
- Не вводити Composer і залежності.
- Не залишати змінених файлів, даних у `orakuls_test` чи тимчасових серверів після мутаційних прогонів.

## 11. Звіт

У кінці виклади звіт українською: (1) створені/змінені файли; (2) числа тестів (`run`, `api`, `smoke`) до та після; (3) результати кроку 5: HTTP-перевірка, таблиця мутацій із тим, що саме впало, `sha256sum` до/після; (4) відхилення від плану і причини; (5) сумніви та знахідки. Будь чесним: що не пройшло або не перевірено — так і пиши.
