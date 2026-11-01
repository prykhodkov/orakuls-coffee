# BE-004 · Технічний план реалізації

Документ для агента-виконавця. Описує **що саме** створити, **в якому порядку**, **як перевірити** і **на які пастки не наступити**. Опис задачі та acceptance criteria (AC) — у [task.md](task.md); цей план їх деталізує. Якщо план суперечить `task.md` або реальності — зупинись і запитай користувача, не вигадуй мовчки.

Це задача про безпеку: помилка тут — це відкритий доступ до адмінки. Тому вимоги до тестів жорсткіші, ніж у попередніх задачах: кожне правило перевіряється і «позитивним», і «негативним» тестом, а наприкінці — мутаційна перевірка (крок 9).

## 0. Перед початком

Прочитай (у цьому порядку):

1. `CLAUDE.md` (корінь), `backend/CLAUDE.md`.
2. [task.md](task.md) — обсяг і AC. Рішення в ньому прийнято користувачем (токен **7 діб**; fail-closed guard; ліміт за IP; `422` для порожніх полів) — не переглядати.
3. `backend/docs/decisions.md` — D-01, D-04, D-06, D-09, D-17, D-18; `architecture.md` (правила залежностей), `error-handling.md`, `security.md`, `configuration.md`, `testing.md`, `database.md`, `development.md`.
4. `docs/API.md`, розділ «Адмінка» → «Авторизація» (контракт — **не змінювати**).
5. Код, на який спираєшся: `src/Http/Kernel.php`, `Request.php`, `Response.php`, `Router.php`, `ErrorHandler.php`, `src/Support/ApiException.php`, `Config.php`, `Db.php`, `Iso8601.php`, `Logger.php`, `src/routes.php`, `src/Controller/MessageController.php`, `src/Database/Migrator.php`, `database/migrations/001_create_phrases.sql`, `bin/migrate.php`, `bin/build-schema.php`, `tests/lib.php`, `tests/db-lib.php`, `tests/api.php`, `tests/api/database.php`, `tests/api/message.php`, `tests/run.php`, `tests/smoke.php`, `.env.example`.

Стан на старті: BE-001 … BE-003 виконано й закомічено; контейнери `php` і `db` запущені (`docker compose ps` у `backend/`); `tests/run.php` — 49 перевірок, `tests/api.php` — 33, усе зелене; робоча БД `orakuls` має 1 міграцію (`001`), 0 фраз. Зміни в робочій БД у цій задачі **очікувані**: застосується міграція `002` (крок 7). **Не коміть**, доки користувач не попросить.

## 1. Підтверджені факти

Перевірено експериментами на `mysql:5.7` і PHP 8.4 у Docker проєкту. Не перевіряй наново, але й не ігноруй.

| # | Факт | Наслідок |
|---|------|----------|
| 1 | PHP 8.4: `PASSWORD_DEFAULT` = bcrypt (`$2y$`), **cost за замовчуванням 12**; `password_verify` ≈ 163 мс, `password_hash` ≈ 165 мс. З `['cost' => 4]` — 1 мс. Argon2id у Docker є, на хостингу не гарантований | Лімітування перевіряється **до** `password_verify`, заблоковані запити його не запускають. Тести використовують cost 4. `password_verify` розпізнає алгоритм з самого хеша, тож хеш argon2id теж працюватиме |
| 2 | bcrypt ігнорує байти після 72-го (`password_verify` на перших 72 байтах дає `true` для довшого пароля) | `bin/hash-password.php` відмовляє в паролях > 72 байт. Ендпоінт логіну приймає до 1024 байт (щоб не розкривати політику), зайве просто не впливає |
| 3 | `password_get_info('')['algo']` і `password_get_info('not-a-hash')['algo']` — `null` | Так перевіряється, що в конфігурації справжній хеш |
| 4 | base64url у PHP: `base64_decode(strtr($s,'-_','+/'), true)` **приймає** паддінг (`YQ==`), стандартний алфавіт (`+/`, якщо їх підставити) і не відсікає ненульові зайві біти у хвості. Перевірка «після декодування закодувати назад і порівняти з вхідним рядком» відсікає **все це**: `YQ==`, `+/8`, `YR` (зайві біти), пробіл, `\n` дають `null`; порожній рядок декодується в `''` (його треба відхилити окремо) | Декодер токена — строгий і канонічний; порожні частини відхиляються |
| 5 | `hash_hmac('sha256', …, true)` → 32 байти; `hash_equals` вимагає двох рядків і порівнює за сталий час | Підпис порівнюється лише через `hash_equals` |
| 6 | Запит для `Retry-After` перевірений на 5.7: `SELECT TIMESTAMPDIFF(SECOND, attempted_at, UTC_TIMESTAMP()) AS age FROM login_attempts WHERE ip = ? AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND ORDER BY attempted_at DESC LIMIT 5` (параметр у `INTERVAL ? SECOND` працює). Якщо рядків 5, блок знімається, коли найстаріша з цих п'яти вийде з вікна: `retryAfter = window − age(п'ятого)`. На даних із віками 800/600/300/100/10 с і вікні 900 це дало 100 с | Один запит одночасно дає і кількість (< ліміту → не заблоковано), і час до розблокування. Стійко й до «перестрибування» ліміту при одночасних запитах |
| 7 | `DELETE FROM … WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL ? SECOND` з прив'язаним параметром працює | Очищення прострочених записів |
| 8 | У контейнері є `/usr/bin/stty`; `stream_isatty(STDIN)` без TTY — `false` | `bin/hash-password.php` вміє і приховане введення (TTY), і читання зі stdin (pipe) |
| 9 | `docker compose` у подвійних лапках трактує `$…` у `.env` як змінну (сиплє попередження); в одинарних — ні | Хеш у `.env` — лише в одинарних лапках. Власний парсер `Config` обидва варіанти читає однаково |
| 10 | `Request` зараз не знає IP клієнта; `ApiException` не вміє нести заголовки; `ErrorHandler::build()` ставить лише `Cache-Control` | Потрібні зміни спільного коду (крок 1) |
| 11 | Наявні тести жорстко прописують: список таблиць `['migrations','phrases']`, єдину міграцію `001…`, `COUNT(*) = 1`, `applied == ['001…']`; тест невдалої міграції використовує `002_probe.sql` | Міграція `002` їх зламає — оновити (крок 2) |
| 12 | Фронт: при `401` на будь-якому запиті очищає токен і показує форму входу; на логіні розрізняє `401` і `429`; порожні поля не відправляє | Відповіді `401`/`429`/`422` відповідають очікуванням фронта; змін у `frontend/` не треба |
| 13 | `Config::get()` повертає змінну процесу (`getenv`) раніше за `.env`; порожній рядок `''` — це **значення**, а не відсутність | Для `TOKEN_TTL_HOURS` порожнє значення трактуємо як «за замовчуванням» явно, у коді |

## 2. Дизайн-рішення цього плану

1. **Guard у `Kernel`, до маршрутизації** (D-20). Будь-який шлях, що дорівнює `/api/admin` або починається з `/api/admin/`, окрім `POST /api/admin/auth/login`, вимагає дійсного токена; інакше `401`. Тож майбутні адмін-ендпоінти (BE-005 … BE-008) захищені автоматично, а невідомий адмін-шлях без токена дає `401`, не `404`.
2. **Публічні ендпоінти не залежать від адмін-конфігурації.** `AuthConfig` читається ліниво, лише для адмін-шляхів. Помилково налаштований `TOKEN_SECRET` не повинен «класти» `/api/message` і `/api/health` (тест на це є).
3. **Токен — JWT HS256 без бібліотек** (D-21). Ключ підпису = `HMAC-SHA256(TOKEN_SECRET, ADMIN_PASSWORD_HASH)` (сирі 32 байти): зміна пароля або секрету анулює всі токени. Перевірка строга: header порівнюється як **константа-рядок**, а не розбирається; payload розбирається **лише після** успішної перевірки підпису.
4. **Пакет `App\Auth`** (`AuthConfig`, `TokenService`, `LoginRateLimiter`) залежить лише від `Support` і PDO — не від `Http`. `Http\AdminGuard` залежить від `Auth`. Це зберігає правило «`Support` ← `Auth` ← `Http`» без циклів.
5. **Ліміт за IP, не за логіном** (D-22). IP — лише `REMOTE_ADDR` (валідований `filter_var`); `X-Forwarded-For` ігнорується. Заблоковані запити не записуються й не запускають `password_verify`.
6. **Однакова робота при будь-якій невдачі** (D-23). `password_verify` виконується **завжди**, навіть при неправильному логіні; умова невдачі обчислюється без короткого замикання.
7. **Конфігурація — fail-closed.** Некоректна → `500` і на логіні, і в guard'і; доступ не відкривається. У лог — одна строка з **назвою** проблеми, без значень.
8. **Час у токені — `time()` PHP, час спроб — `UTC_TIMESTAMP()` MySQL.** Обидва UTC. `TokenService` приймає «зараз» параметром (для тестів).

## 3. Карта файлів

Створити:

```
backend/
  database/migrations/002_create_login_attempts.sql
  bin/hash-password.php
  src/
    Auth/AuthConfig.php
    Auth/TokenService.php
    Auth/LoginRateLimiter.php
    Http/AdminGuard.php
    Controller/AuthController.php
  tests/api/auth.php
```

Змінити:

```
backend/database/schema.sql                — перегенерувати (bin/build-schema.php)
backend/src/Support/ApiException.php       — заголовки; rateLimited(?int $retryAfter)
backend/src/Http/ErrorHandler.php          — додавати заголовки з ApiException
backend/src/Http/Request.php               — поле ip
backend/src/Http/Kernel.php                — guard (необов'язковий параметр конструктора)
backend/src/Support/Iso8601.php            — fromTimestamp(int)
backend/src/routes.php                     — два маршрути
backend/tests/api.php                      — тестові облікові дані (putenv)
backend/tests/lib.php                      — apiRequest(): параметр $ip
backend/tests/api/database.php             — оновити жорстко прописані списки (крок 2)
backend/tests/run.php                      — нові юніт-перевірки
backend/tests/smoke.php                    — безпечні перевірки адмін-ендпоінтів
backend/.env                               — ЛОКАЛЬНО (у git не потрапляє): dev-облікові дані (крок 7)
docs                                       — див. розділ 8
```

Не чіпати: `docs/API.md`, `frontend/`, `public/index.php`, `docker-compose.yml`, `database/seed/`, `database/migrations/001_*`, `tests/api/message.php`, `src/Controller/MessageController.php`, `src/Repository/`.

## 4. Кроки реалізації

Кожен крок завершується перевіркою; не переходь далі з червоними тестами. PHP-файли пиши інструментами Write/Edit, **не через heredoc у Bash**: оболонка зменшує `\\` до `\` і ламає регулярні вирази (`\d`, `\.`) та рядки з зворотними слешами.

### Крок 1 · Спільний код: `ApiException`, `ErrorHandler`, `Request::ip`, `Iso8601`

**1.1. `ApiException` з заголовками.** Додай необов'язковий останній параметр конструктора `public readonly array $headers = []` (`array<string,string>`) і зміни `rateLimited`:

```php
public static function rateLimited(?int $retryAfterSeconds = null, string $message = 'Too many requests'): self
{
    $headers = $retryAfterSeconds === null ? [] : ['Retry-After' => (string) max(1, $retryAfterSeconds)];

    return new self(429, 'rate_limited', $message, [], $headers);
}
```

Виклик `ApiException::rateLimited()` без аргументів (у `tests/run.php`) лишається коректним.

**1.2. `ErrorHandler`.** У `toResponse()` передавай `$e->headers` у `build()`; `build()` додає їх до відповіді: `['Cache-Control' => 'no-store'] + $headers` (заголовки винятку не можуть прибрати `no-store`, лише доповнити). Решта поведінки — без змін.

**1.3. `Request::ip`.** Додай **останнім** параметром конструктора `public readonly string $ip = 'unknown'`. У `fromGlobals()`:

```php
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ip = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';   // макс. 45 символів, що вміщується в VARCHAR(45)
```

`X-Forwarded-For` та інші заголовки **не читати**.

**1.4. `Iso8601::fromTimestamp(int $timestamp): string`** → `gmdate('Y-m-d\TH:i:s\Z', $timestamp)`. Додай юніт-перевірку: `fromTimestamp(0) === '1970-01-01T00:00:00Z'`, `fromTimestamp(1793500000)` → відповідний рядок із `Z`.

**Юніт-перевірки кроку 1** (у `tests/run.php`, без БД):
- `ApiException::rateLimited(120)` має заголовок `Retry-After: 120`; `rateLimited(0)` і `rateLimited(-5)` дають `1`; `rateLimited()` — без заголовків;
- відповідь `ErrorHandler::toResponse(ApiException::rateLimited(37))`: статус `429`, `Retry-After: 37`, `Cache-Control: no-store`, тіло `{"error":{"code":"rate_limited","message":"Too many requests"}}`;
- `Request::fromGlobals()` з `$_SERVER['REMOTE_ADDR']` = `203.0.113.5` → `ip === '203.0.113.5'`; `2001:db8::1` приймається; `not-an-ip`, `''`, відсутній ключ → `'unknown'`; заголовок `HTTP_X_FORWARDED_FOR` на `ip` не впливає (перед тестом збережи й після відновлення `$_SERVER`).

### Крок 2 · Міграція `002` і оновлення наявних тестів

**2.1.** `database/migrations/002_create_login_attempts.sql` — рівно цей зміст:

```sql
-- Невдалі спроби входу адміна для обмеження частоти (BE-004). Прострочені записи видаляє код під час перевірки.
-- attempted_at: UTC, без DEFAULT, завжди передається як UTC_TIMESTAMP().
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip_time` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**2.2.** `docker compose exec php php bin/build-schema.php` → оновлений `database/schema.sql`; переглянь, що в ньому з'явились `login_attempts` і другий рядок `INSERT IGNORE INTO migrations`.

**2.3. Оновити `tests/api/database.php`** (рівно ці місця; решта лишається):
- перший тест (таблиці й облік): очікувані таблиці `['login_attempts', 'migrations', 'phrases']`; очікуваний список застосованих міграцій — `Migrator::files(APP_ROOT . '/database/migrations')`, а не жорстко прописані; оновити назву тесту;
- тест «повторний up()»: `COUNT(*) FROM migrations` порівнювати з `count(Migrator::files(...))`;
- тест `status()`: `applied` дорівнює `Migrator::files(...)`, `pending` порожній;
- тест невдалої міграції: файл-зонд перейменуй на `900_probe.sql` (усі згадки в цьому тесті, включно з `DELETE FROM migrations WHERE name = …`); твердження про `applied` порівнюють з реальним списком `Migrator::files(APP_ROOT . '/database/migrations')`, а не з `['001…']`;
- додай тест схеми `login_attempts`: `ip` = `varchar(45)` NOT NULL; `attempted_at` = `datetime` NOT NULL **без** default; є індекс по `(ip, attempted_at)` у цьому порядку (через `information_schema.STATISTICS`); таблиця InnoDB, колація `utf8mb4_unicode_ci`.

**Перевірка кроку 2:** `docker compose run --rm --no-deps php php tests/run.php` (актуальність `schema.sql`) і `docker compose exec php php tests/api.php` — **усі наявні тести зелені**, зокрема паритет `schema.sql`. Це контрольна точка: якщо щось інше, окрім переліченого, впало — зупинись і розберись, чому.

### Крок 3 · `AuthConfig`, `TokenService`, `LoginRateLimiter`

#### 3.1 `src/Auth/AuthConfig.php`

```php
final class AuthConfig
{
    public const DEFAULT_TTL_HOURS = 168;      // 7 діб — рішення користувача
    public const MIN_SECRET_LENGTH = 32;

    public function __construct(
        public readonly string $login,
        public readonly string $passwordHash,
        public readonly string $secret,
        public readonly int $ttlSeconds,
    ) {}

    /** Читає конфігурацію процесу/.env. Некоректна → лог (лише назва проблеми) і ApiException::server(). */
    public static function fromEnv(): self;
}
```

`fromEnv()` перевіряє по порядку (кожна невдача: `Logger::error('Auth config invalid: <ІМ'Я_ЗМІННОЇ> <що не так>')` **без значень** і `throw ApiException::server()`):
1. `ADMIN_LOGIN` — непорожній рядок;
2. `ADMIN_PASSWORD_HASH` — `password_get_info($hash)['algo'] !== null` (порожній/«не хеш» відхиляється);
3. `TOKEN_SECRET` — `strlen >= 32`;
4. `TOKEN_TTL_HOURS` — `Config::get`; `null` або `''` → `DEFAULT_TTL_HOURS`; інакше має відповідати `/^\d+$/` і бути в межах 1…720; `ttlSeconds = hours * 3600`.

Клас не кешує значення: `fromEnv()` викликається на кожен адмін-запит (це дешево, і тести можуть змінювати змінні процесу між викликами).

#### 3.2 `src/Auth/TokenService.php`

```php
final class TokenService
{
    private const HEADER_JSON = '{"alg":"HS256","typ":"JWT"}';
    private const MAX_TOKEN_LENGTH = 2048;

    public function __construct(private readonly AuthConfig $config) {}

    /** @return array{token: string, expiresAt: int} */
    public function issue(int $now): array;

    public function verify(string $token, int $now): bool;
}
```

- `base64url encode`: `rtrim(strtr(base64_encode($s), '+/', '-_'), '=')`.
- **Ключ**: `hash_hmac('sha256', $config->passwordHash, $config->secret, true)` (сирі 32 байти).
- `issue($now)`: payload `json_encode(['sub' => login, 'iat' => $now, 'exp' => $now + ttlSeconds], JSON_THROW_ON_ERROR)` (порядок ключів фіксований); `token = header64 . '.' . payload64 . '.' . sig64`, де `sig64 = b64url(hash_hmac('sha256', header64.'.'.payload64, key, true))`; `expiresAt = $now + ttlSeconds`.
- `verify($token, $now)` — **будь-яка** невідповідність → `false`, ніколи не кидає винятків:
  1. довжина `<= MAX_TOKEN_LENGTH`; `explode('.', $token)` дає **рівно 3** непорожні частини;
  2. частина 0 `===` `b64url(HEADER_JSON)` (константа; `alg` з токена не читається). Додай юніт-перевірку, що це дорівнює `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9` (відомий JWT-заголовок HS256);
  3. частина 2 → строгий канонічний декодер (факт 4) → рівно 32 байти → `hash_equals($expected, $given)`; **невдача → `false` до будь-якого розбору payload**;
  4. частина 1 → той самий декодер → `json_decode($raw, true, 4, JSON_THROW_ON_ERROR)` у `try/catch (JsonException)`;
  5. payload — масив, `sub` — рядок, `iat` і `exp` — `int` (не рядок, не float); `exp > $now` (суворо); `hash_equals($config->login, $payload['sub'])`.
- Приватний `decodeBase64Url(string $s): ?string`: порожній рядок → `null`; `base64_decode(strtr($s,'-_','+/'), true)`; `false` → `null`; закодувати назад і порівняти з `$s` — не збігається → `null`.

#### 3.3 `src/Auth/LoginRateLimiter.php`

```php
final class LoginRateLimiter
{
    public const LIMIT = 5;
    public const WINDOW_SECONDS = 900;   // 15 хвилин

    public function __construct(private readonly PDO $pdo) {}

    public function purge(): void;                 // видаляє записи старші за вікно (факт 7)
    public function retryAfter(string $ip): ?int;  // null — можна пробувати; інакше секунди 1…900
    public function recordFailure(string $ip): void;
}
```

- `retryAfter`: запит із факту 6 (`LIMIT 5`, параметри `ip` і `WINDOW_SECONDS`, `bindValue` з `PDO::PARAM_STR`/`PARAM_INT`). Якщо рядків < `LIMIT` → `null`; інакше `max(1, WINDOW_SECONDS - (int) $ages[LIMIT - 1])` (останній елемент списку — найстаріший із п'яти найновіших).
- `recordFailure`: prepared `INSERT INTO login_attempts (ip, attempted_at) VALUES (?, UTC_TIMESTAMP())`, `ip` обрізати до 45 символів.
- `purge`: `DELETE … WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL ? SECOND`.

**Юніт-перевірки `TokenService` і `AuthConfig`** (у `tests/run.php`, без БД; об'єкти створюються напряму з тестовими значеннями, `AuthConfig::fromEnv` — через `putenv` з відновленням у `finally`):
- токен проходить `verify` у момент випуску і за 1 с до закінчення; **не** проходить рівно в момент `exp` і пізніше (`exp > now` суворо); має три частини; `expiresAt = now + ttl`;
- ключ залежить від хеша пароля: токен, виданий для хеша A, не проходить для конфігурації з хешем B (і з іншим секретом, і з іншим логіном);
- мутації підпису (змінений будь-який символ у кожній із трьох частин), підміна payload з новим `exp` при старому підписі, усічений токен, токен з 2 і 4 частинами, порожні частини (`a..b`, `.a.b`), роздільник-пробіл — усе `false`;
- підміна header: `{"alg":"none","typ":"JWT"}` без підпису та з підписом, `HS512`, header із зайвим полем, header у стандартному base64 з паддінгом — `false` (навіть коли підпис пораховано «правильно» над підміненим header);
- неканонічний base64url: паддінг `=`, символи `+` `/`, пробіл, `\n`, зайві біти — `false`;
- токен > 2048 символів — `false` без винятків; нерядкові значення в payload (`exp` рядком `"9999999999"`, `exp` float, відсутній `sub`) — `false` (токени для цих випадків підписуй «вручну» тим самим ключем у тесті);
- `AuthConfig::fromEnv`: коректна → поля; кожна з некоректних (немає логіна, порожній хеш, `not-a-hash`, секрет 31 символ, порожній секрет, `TOKEN_TTL_HOURS` = `0`, `721`, `abc`, `-1`, `1.5`) → `ApiException` зі статусом 500; порожнє й відсутнє `TOKEN_TTL_HOURS` → 168 год; `'24'` → 24 год. У журналі (тимчасовий `error_log`, див. `run.php`) є назва змінної і **немає** значень секрету/хеша.

### Крок 4 · `AdminGuard`, `Kernel`, `AuthController`, маршрути

**4.1. `src/Http/AdminGuard.php`:**

```php
final class AdminGuard
{
    /** @param callable(): AuthConfig $configProvider ліниво: публічні шляхи конфігурацію не читають */
    public function __construct(private readonly \Closure $configProvider) {}

    public function check(Request $request): void;   // кидає ApiException; нічого не повертає
}
```

`check()`:
1. Якщо шлях **не** `/api/admin` і не починається з `/api/admin/` → повернутись (публічний).
2. Якщо `$request->method === 'POST' && $request->path === '/api/admin/auth/login'` → повернутись.
3. `$config = ($this->configProvider)()` (може кинути `500`).
4. Заголовок `Authorization`: рівно один регістронезалежний збіг за **строгим** виразом `/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/i`. Немає заголовка, інша схема (`Basic …`), порожній токен, два пробіли, пробіли на початку/в кінці, `Bearertoken` → `ApiException::unauthorized()`.
5. `(new TokenService($config))->verify($token, time())`; `false` → `ApiException::unauthorized()`. Повідомлення завжди однакове (`Unauthorized`), причину не розкривати.

**4.2. `Kernel`:** конструктор `__construct(private readonly Router $router, private readonly ?AdminGuard $guard = null)`; у `create()` — `new self($router, new AdminGuard(static fn (): AuthConfig => AuthConfig::fromEnv()))`. У `handle()` **всередині `try`** і **перед** `dispatch`: `$this->guard?->check($request);`. Виняток guard'а проходить звичайним шляхом `ErrorHandler::toResponse`. `kernelWith()` у тестах (без guard'а) лишається працювати без змін.

**4.3. `src/Controller/AuthController.php`:**

`login(Request $request, array $params): Response`:
1. `$config = AuthConfig::fromEnv();`
2. `$data = $request->json();` (порожнє/не-JSON/не-об'єкт → `400`).
3. Валідація: `login` і `password` — непорожні **рядки**; `strlen($password) <= 1024`. Інакше `ApiException::validation([...])` з ключами `login` / `password` і кодами `required` (відсутнє, не рядок, порожнє) або `too_long`. Ці запити в `login_attempts` не потрапляють.
4. `$limiter = new LoginRateLimiter(Db::pdo()); $limiter->purge(); $retry = $limiter->retryAfter($request->ip); if ($retry !== null) throw ApiException::rateLimited($retry);`
5. **Без короткого замикання:**
   ```php
   $loginOk = hash_equals($config->login, $login);
   $passwordOk = password_verify($password, $config->passwordHash);
   if (!($loginOk && $passwordOk)) {
       $limiter->recordFailure($request->ip);
       throw ApiException::unauthorized('Invalid credentials');
   }
   ```
6. `$issued = (new TokenService($config))->issue(time());` → `Response::json(['token' => …, 'expiresAt' => Iso8601::fromTimestamp($issued['expiresAt'])], 200, ['Cache-Control' => 'no-store'])`.

`me(Request $request, array $params): Response`: токен уже перевірено guard'ом; повертає `Response::json(['login' => AuthConfig::fromEnv()->login], 200, ['Cache-Control' => 'no-store'])`.

**4.4. `src/routes.php`:**

```php
// Адмінка. Захист /api/admin/* — у Kernel (AdminGuard), контролери перевірку токена не повторюють
$router->post('/api/admin/auth/login', [new AuthController(), 'login']);
$router->get('/api/admin/auth/me', [new AuthController(), 'me']);
```

### Крок 5 · `bin/hash-password.php`

Каркас скрипта такий самий, як у `bin/migrate.php` (CLI-guard, `APP_ROOT`, `bootstrap`, `try/catch (Throwable)` з виводом у `STDERR`, коди виходу). Поведінка:

1. Пароль **не** береться з аргументів командного рядка (він лишився б в історії оболонки й у списку процесів).
2. Якщо `stream_isatty(STDIN)`: запитати «Password: » і «Repeat: » з вимкненим відлунням (`shell_exec('stty -echo')`, у `finally` і `register_shutdown_function` обов'язково `stty echo`); паролі мають збігатися. Інакше (pipe) — прочитати **перший рядок** stdin, відкинути лише кінцевий `\r\n`.
3. Відхилити (код виходу `1`, повідомлення у `STDERR`, **пароль у повідомленні не повторювати**): довжина < 12 символів (`mb_strlen`); > 72 **байти** (`strlen`) з поясненням про обмеження bcrypt; порожній ввід.
4. `password_hash($password, PASSWORD_DEFAULT)`; у `STDOUT` лише готовий рядок: `ADMIN_PASSWORD_HASH='<hash>'` (одинарні лапки обов'язкові).
5. Нічого не пише у файли.

Вручну (TTY-гілку агент перевірити не зможе — зазнач це у звіті): `printf '%s\n' 'correct-horse-battery' | docker compose exec -T php php bin/hash-password.php`.

**Юніт-перевірка** (у `run.php`, через `proc_open` з `php bin/hash-password.php` і stdin): пароль із 14 символів → вихід `0`, рядок відповідає `/^ADMIN_PASSWORD_HASH='(\$2y\$[^']+)'$/`, `password_verify` на ньому з тим самим паролем — `true`, а з іншим — `false`; пароль із 11 символів → код `1`, у `STDOUT` порожньо, у `STDERR` немає самого пароля; пароль 73 байти → код `1`; порожній stdin → код `1`.

**Перевірка кроків 1–5 (до тестів auth):** `tests/run.php` і `tests/api.php` зелені.

### Крок 6 · Тести авторизації

**6.1. Тестові облікові дані у `tests/api.php`.** До `require bootstrap` (поруч із `putenv('DB_NAME=…')`):

```php
const TEST_ADMIN_LOGIN = 'test-admin';
const TEST_ADMIN_PASSWORD = 'test-password-123456';
putenv('ADMIN_LOGIN=' . TEST_ADMIN_LOGIN);
putenv('ADMIN_PASSWORD_HASH=' . password_hash(TEST_ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));   // cost 4: 1 мс замість 165 мс
putenv('TOKEN_SECRET=' . str_repeat('0123456789abcdef', 4));                                         // 64 символи
putenv('TOKEN_TTL_HOURS=');                                                                           // порожнє = за замовчуванням (168 год)
```

`getenv` має пріоритет над `.env` (факт 13), тож тести не залежать від того, що лежить у `backend/.env`.

**6.2. `apiRequest()`** у `tests/lib.php` отримує останній параметр `string $ip = 'unknown'`, який передається в `new Request(..., $ip)`.

**6.3. `tests/api/auth.php`.** Допоміжні функції (глобальні, з префіксом `auth`):
- `authLogin(array|string $body, string $ip = '203.0.113.10'): Response` — `POST /api/admin/auth/login` з тілом (`array` → `json_encode`, `string` → як є);
- `authToken(string $ip = '203.0.113.99'): string` — успішний вхід, повертає токен;
- `authAs(string $token, string $method, string $path): Response` — запит із `['authorization' => "Bearer {$token}"]`;
- `authClearAttempts(): void` — `DELETE FROM login_attempts`;
- `withEnv(array $vars, callable $fn): void` — `putenv` кількох змінних, виклик, у `finally` відновлення **попередніх** значень (`getenv`) або видалення; ключ `null` = прибрати змінну;
- `forgeToken(AuthConfig $config, array $payload, ?string $headerJson = null): string` — «ручний» випуск токена тим самим ключем для негативних тестів (header за замовчуванням коректний).

Кожен тест, що створює невдалі спроби, починає з `authClearAttempts()` і користується власними IP з діапазону `203.0.113.0/24` (документаційний TEST-NET-3), щоб тести не заважали один одному.

Перелік перевірок (кожна — окремий `test()`, назви українською, префікс `auth:`):

| # | Що перевіряє | Як | AC |
|---|--------------|----|----|
| 1 | Успішний вхід | `200`; ключі рівно `['token','expiresAt']` у цьому порядку; токен — 3 частини; `expiresAt` відповідає `/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/`, у майбутньому, і `strtotime(expiresAt) − time()` ≈ 168·3600 (±5 с); `Cache-Control: no-store` | 1 |
| 2 | Термін із конфігурації | `withEnv(['TOKEN_TTL_HOURS' => '2'])` → ≈ 2 год; `''` → 168 год; значення `0`, `721`, `abc`, `-1`, `1.5` → `500 server_error` | 1, 2 |
| 3 | Невдача однакова | неправильний пароль; неправильний логін; обидва неправильні; логін в іншому регістрі (`Test-Admin`) → усі `401`, **байт у байт однакове тіло** (`{"error":{"code":"unauthorized","message":"Invalid credentials"}}`) | 2 |
| 4 | Валідація | не JSON; порожнє тіло; JSON-рядок/число → `400 bad_request`. `{}`, відсутній `login`, відсутній `password`, порожні рядки, `123`, `null`, масив, пароль 1025 байт → `422 validation_error` з відповідним ключем у `fields` (`required`/`too_long`) | 3 |
| 5 | Валідація не рахується | після 12 таких запитів з IP A правильний вхід з A → `200`, а `SELECT COUNT(*) FROM login_attempts` = 0 | 3, 11, 12 |
| 6 | Межа пароля | пароль рівно 1024 байти (неправильний) → `401`, не `422` | 3 |
| 7 | `me` | з дійсним токеном → `200`, тіло `{"login":"test-admin"}`, `Cache-Control: no-store` | 4, 18 |
| 8 | Guard закритий | без заголовка → `401` (формат помилки, без `fields`) для: `GET /api/admin/auth/me`, `GET /api/admin/phrases`, `GET /api/admin/stats`, `POST /api/admin/phrases/import`, `DELETE /api/admin/phrases/1`, `GET /api/admin/взагалі-щось`, `GET /api/admin`, `GET /api/admin/`, `GET /api/admin/auth/login`, `PUT`/`DELETE`/`HEAD` на `/api/admin/auth/login`. З дійсним токеном невідомий шлях → `404`, а `POST /api/admin/auth/login` не вимагає токена | 5 |
| 9 | Обхід шляхом | без токена жоден із цих шляхів не повертає `200` (допустимі лише `401`/`404`): `/api/admin/../admin/auth/me`, `/api//admin/auth/me`, `//api/admin/auth/me`, `/api/%61dmin/auth/me`, `/api/admin%2Fauth/me`, `/API/admin/auth/me`, `/api/admin/auth/me/`, `/api/admin/auth/me%00`, `/api/admin/auth/ME` | 5 |
| 10 | Підроблені токени | для `GET /api/admin/auth/me` усі → `401`: змінений останній символ підпису; змінений символ у payload; підміна payload з `exp` на 10 років при старому підписі; `alg:none` (з порожнім підписом і з реальним); `HS512`; header з зайвим полем; 2 частини; 4 частини; порожня частина; сміття; `=`-паддінг; `+`/`/` замість `-`/`_`; токен 3000 символів | 6, 7 |
| 11 | Прострочений і чужий | `forgeToken` з `exp = time() − 1` → `401`; токен, підписаний іншим `TOKEN_SECRET` → `401`; токен без `sub`; `exp` як рядок → `401` | 6 |
| 12 | Схема заголовка | `Basic <токен>`, `Bearer` (без токена), `Bearer ` (з пробілом, порожній токен), `Bearer  <токен>` (два пробіли), ` Bearer <токен>`, `Bearer <токен> ` (пробіл у кінці), `Bearertoken` → `401`; `bearer <токен>` і `BEARER <токен>` → `200` | 8 |
| 13 | Ротація анулює токени | валідний токен → `200`; після `withEnv(['ADMIN_PASSWORD_HASH' => іншийХеш])` → `401`; після зміни `TOKEN_SECRET` → `401`; після зміни `ADMIN_LOGIN` → `401`; після відновлення змінних токен знову `200` | 9 |
| 14 | Некоректна конфігурація | `withEnv` по черзі: порожній `ADMIN_PASSWORD_HASH`; `not-a-hash`; `TOKEN_SECRET` 31 символ; порожній `TOKEN_SECRET`; порожній `ADMIN_LOGIN` → `POST /login` з правильними даними **і** `GET /api/admin/auth/me` (з будь-яким токеном та без) повертають `500 server_error`, у тілі немає значень секретів; **водночас** `GET /api/message?lang=lv` і `GET /api/health` відповідають `200` | 10 |
| 15 | Ліміт: блокування | IP A: 5 неправильних → п'ять `401`; 6-та з **правильним** паролем → `429` із `Retry-After` — ціле число в 1…900; у `login_attempts` для A усе ще 5 рядків; 7-ма → знову `429`, рядків 5; інший IP B із правильним паролем → `200` | 11 |
| 16 | Ліміт: час | ті самі 5 рядків A переписати прямим `UPDATE` на віки 800/600/300/100/10 с → `Retry-After` ≈ 100 (±1); потім усі на 901 с → вхід `200`, а прострочені рядки видалено | 11 |
| 17 | Успіх не збільшує | 3 невдачі + 1 успіх → 3 рядки; ще 2 невдачі → 5; наступний (навіть правильний) → `429` | 12 |
| 18 | `X-Forwarded-For` не діє | заблокований IP A + заголовок `x-forwarded-for: 198.51.100.7` → усе одно `429`; запит з чистого IP D із заголовком `x-forwarded-for: <A>` → `200` | 12 |
| 19 | Очищення | вставити 3 рядки віку 2000 с для різних IP → після будь-якого запиту логіну їх немає | 14 |
| 20 | Час однаковий | `ADMIN_PASSWORD_HASH` з cost 10 (≈40 мс): медіани по 5 запитів — «неправильний логін» не менше ніж 50 % від «неправильний пароль» (при короткому замиканні було б ≈ 0). Використовуй `withEnv` і окремі IP/очищення, щоб не впертись у ліміт | 2 |
| 21 | Секрети не в логах | перенаправити `error_log` у тимчасовий файл (`ini_set('error_log', …)`, відновити); виконати неправильні входи, прострочений токен, некоректну конфігурацію; у файлі немає ні `TEST_ADMIN_PASSWORD`, ні токена, ні хеша, ні `TOKEN_SECRET`, ні заголовка `Authorization` | 16 |
| 22 | `no-store` скрізь | `Cache-Control: no-store` у відповідях логіну `200`/`401`/`422`/`429`/`400` та `me` | 18 |
| 23 | Публічне без токена | `GET /api/health`, `GET /api/message?lang=lv` → `200` без `Authorization`; з сміттєвим `Authorization` теж `200` | 5 |

**6.4. `tests/smoke.php`** — додай блок (лише безпечні для прода запити; **неправильні облікові дані не відправляти**: вони лічились би в ліміт і могли б заблокувати власний IP розробника на хостингу):
- `GET /api/admin/auth/me` без токена → `401`, JSON `unauthorized`;
- `GET /api/admin/phrases` без токена → `401`;
- `POST /api/admin/auth/login` з порожнім об'єктом `{}` → `422` (не `500`);
- `POST /api/admin/auth/login` з тілом `not json` → `400`.

Функція `call()` у smoke зараз підтримує лише метод без тіла — розшир її необов'язковим параметром тіла й заголовка `Content-Type`, не ламаючи наявних викликів.

### Крок 7 · Локальне налаштування (поза git)

1. Застосуй міграцію до робочої БД: `docker compose exec php php bin/migrate.php` → `Applied: 002_create_login_attempts.sql`.
2. У **локальному** `backend/.env` (він у `.gitignore`) встанови dev-облікові дані для ручної перевірки й для фронтенд-розробника:
   - згенеруй хеш: `printf '%s\n' 'local-dev-password' | docker compose exec -T php php bin/hash-password.php` і встав рядок у `.env` **інструментом Edit/Write, не через оболонку** (в `sed`/`echo` символ `$` буде розкрито);
   - `ADMIN_LOGIN=admin`;
   - `TOKEN_SECRET` — `docker compose exec php php -r "echo bin2hex(random_bytes(32));"` у одинарних лапках;
   - `TOKEN_TTL_HOURS=168` (вже стоїть).
   Це **лише локальний** пароль (`local-dev-password`); у звіті вкажи його користувачу, а `TOKEN_SECRET` і хеш у звіт **не виводь**. `.env.example` не змінюй (там лише заглушки).
3. Переконайся, що `docker compose -f docker-compose.yml config -q` не друкує попереджень про змінні (ознака, що хеш у `.env` у правильних лапках).
4. Розкажи в `development.md`, як створити свій локальний пароль, і що `local-dev-password` — просто приклад, який використано на машині розробника; для прод-середовища використовується процедура з BE-009.

### Крок 8 · Документація

Онови в одному проході — документи описують те, що **є** в коді:

| Файл | Що змінити |
|------|-----------|
| `backend/docs/tasks/BE-004-admin-auth/task.md` | Статус `Done (дата)`, відмітити AC `[x]`, розділ «Як перевірити» |
| `backend/docs/decisions.md` | **D-20** guard у `Kernel` до маршрутизації (fail-closed; публічні шляхи не залежать від адмін-конфігурації). **D-21** JWT HS256 без бібліотек, ключ із секрету + хеша пароля, строга перевірка (константний header, канонічний base64url, payload після підпису), термін 7 діб (рішення користувача). **D-22** ліміт за IP, `REMOTE_ADDR` лише, 5/15 хв, `Retry-After`, заблоковані не записуються, без блокування за логіном, перевірка IP на хостингу — BE-009. **D-23** `password_verify` без короткого замикання; коди `400`/`422`/`401`/`429`; некоректна конфігурація → `500` (fail-closed) |
| `backend/docs/security.md` | Розділ автентифікації: ⏳ → ✅ по пунктах, додати рядки про guard, ротацію токенів, відсутність логування секретів; залишити ⏳ для перевірки IP на хостингу (BE-009) |
| `backend/docs/configuration.md` | `ADMIN_*` і `TOKEN_*` більше не «з BE-004» — використовуються; процедура задання облікових даних (`bin/hash-password.php`, `TOKEN_SECRET`), **одинарні лапки**, процедура зміни пароля (анулює всі токени), діапазони `TOKEN_TTL_HOURS` |
| `backend/docs/architecture.md` | Структура (`src/Auth/`, `Http/AdminGuard.php`, `Controller/AuthController.php`, `bin/hash-password.php`), таблиця компонентів, правила залежностей (`Support` ← `Auth` ← `Http`; `Controller → Auth, Http, Support`), життєвий цикл запиту (guard перед router), прибрати BE-004 з «Що ще буде додано» |
| `backend/docs/api-conventions.md` | Розділ про адмінку: guard автоматичний, новий адмін-ендпоінт під `/api/admin/` захищається без жодних дій у контролері; усі адмін-відповіді `Cache-Control: no-store`; `Request::ip` |
| `backend/docs/error-handling.md` | `ApiException` з заголовками, `429` + `Retry-After`, `500` при некоректній конфігурації автентифікації |
| `backend/docs/database.md` | Таблиця `login_attempts` (✅), очищення прострочених записів, запит `Retry-After` |
| `backend/docs/testing.md` | Тестові облікові дані через `putenv` у `tests/api.php`, bcrypt cost 4, `withEnv`, `forgeToken`, TEST-NET IP у тестах, правило «smoke не відправляє неправильні облікові дані» |
| `backend/docs/development.md` | Команди `bin/hash-password.php`, локальний вхід (`admin` / свій пароль), як отримати токен через `curl` |
| `backend/docs/deployment.md` | Лише уточнити посилання на BE-009 (уже є розділ «Адмін і IP клієнта») |
| `docs/BACKEND.md` (спільний) | Для фронта: автентифікація (`login`, `me`), коди `401`/`422`/`429` і `Retry-After`, термін сесії 7 діб, локальний вхід |

Після правок перевір посилання між документами і що всі `D-xx` існують.

### Крок 9 · Фінальна перевірка

Виконай **усе** і наведи фактичний вивід:

```
cd backend
docker compose run --rm --no-deps php php tests/run.php            # усі ok
docker compose exec php php tests/api.php                          # усі ok; запусти двічі підряд
docker compose exec php php tests/smoke.php                        # усі ok
docker compose exec php php bin/build-schema.php --check           # schema.sql без змін
docker compose -f docker-compose.yml config -q                     # без попереджень
```

**Наскрізна перевірка по справжньому HTTP** (сервер на :8000, dev-БД `orakuls`, dev-облікові дані з кроку 7):

```
curl -s -i -X POST localhost:8000/api/admin/auth/login -H 'Content-Type: application/json' -d '{"login":"admin","password":"local-dev-password"}'
        # 200, token + expiresAt ≈ +7 діб, Cache-Control: no-store
curl -s -i localhost:8000/api/admin/auth/me -H "Authorization: Bearer <token>"      # 200 {"login":"admin"}
curl -s -i localhost:8000/api/admin/auth/me                                         # 401
curl -s -i localhost:8000/api/admin/anything                                        # 401
curl -s -i localhost:8000/api/admin/auth/me -H "Authorization: Bearer <token зі зміненим символом>"   # 401
# 5 разів неправильний пароль → 401, шостий (навіть правильний) → 429 з Retry-After
```

Заголовок `Authorization` має дійти до PHP через вбудований сервер — це перевірка того, що `Request` його читає. Після перевірки очисти спроби: `DELETE FROM login_attempts` у робочій БД (лише ця таблиця). Стан робочої БД на кінець: таблиці `migrations`, `phrases`, `login_attempts`; у `phrases` 0 рядків; у `migrations` 2 рядки; у `login_attempts` 0 рядків.

**Мутаційна перевірка** (тимчасово ламаєш код, тест має впасти, відкочуєш, перевіряєш контрольні суми файлів до/після — `sha256sum`):

| Мутація | Що має впасти |
|---------|---------------|
| (а) У `AdminGuard` повертатись достроково, якщо шлях не належить до жорсткого списку (`/api/admin/auth/me`) — тобто захищати лише «відомі» шляхи | тест 8 (невідомий адмін-шлях без токена → `401`) |
| (б) У `TokenService::verify` не перевіряти `exp` | тест 11, юніт-перевірки токена |
| (в) У `TokenService::verify` порівнювати header не з константою, а приймати будь-який | тест 10 (`alg:none`/`HS512`/зайве поле), юніт-перевірки |
| (г) Ключ підпису виводити лише з `TOKEN_SECRET` (без хеша пароля) | тест 13 |
| (д) Записувати спробу і при **успішному** вході | тест 17 |
| (е) Записувати спробу ще й для заблокованих запитів | тест 15 |
| (є) У логіні замінити обчислення двох умов на коротке замикання (`$loginOk && password_verify(...)`) | тест 20 |
| (ж) Брати IP з `X-Forwarded-For`, якщо він є | тест 18 |
| (з) Не відхиляти `TOKEN_SECRET` коротший за 32 символи | тест 14, юніт-перевірки `AuthConfig` |

Після кожної мутації поверни код і переконайся, що все знову зелене; наприкінці `git diff` / контрольні суми підтверджують відсутність тимчасових змін. Опиши результат у звіті.

Таблиця відповідності AC → перевірка (додай у звіт):

| AC (за порядком у task.md) | Перевірка |
|----------------------------|-----------|
| 1 успішний вхід, TTL 168 год | тести 1, 2; юніт-перевірки токена |
| 2 однакова невдача | тести 3, 20 |
| 3 `400`/`422`, не рахується | тести 4, 5, 6 |
| 4 `me` | тест 7 |
| 5 guard закритий | тести 8, 9, 23; smoke |
| 6 прострочений/підроблений/чужий токен | тести 10, 11; юніт-перевірки токена |
| 7 підміна header | тест 10; юніт-перевірки токена |
| 8 схема `Bearer` | тест 12 |
| 9 ротація анулює токени | тест 13 |
| 10 некоректна конфігурація | тест 14; юніт-перевірки `AuthConfig` |
| 11 ліміт, `Retry-After` | тести 15, 16 |
| 12 успіх не збільшує, `X-Forwarded-For` не діє | тести 17, 18 |
| 13 міграція `002` і `schema.sql` | тест схеми `login_attempts` у `database.php`, паритет, `build-schema.php --check` |
| 14 очищення прострочених записів | тест 19 |
| 15 `bin/hash-password.php` | юніт-перевірки через `proc_open`, ручний запуск |
| 16 секрети не в логах, пароль лише хешем | тест 21; огляд коду |
| 17 `.env.example` із заглушками | `git diff` файлу `.env.example` порожній |
| 18 `no-store` | тести 7, 22 |
| 19 наявні тести зелені | прогін усіх скриптів |
| 20 рішення й документація | крок 8, перевірка посилань |

## 5. Пастки

1. **Не розбирай payload до перевірки підпису** і не декодуй header — порівнюй його як константу. Це захист від `alg:none` і від атак на парсер.
2. **`hash_equals` вимагає двох рядків**; сирий підпис (32 байти) порівнюй із сирим, не з base64url-версією.
3. **Без короткого замикання в логіні.** `$loginOk = …; $passwordOk = …; if (!($loginOk && $passwordOk))`. Не пиши `hash_equals(...) && password_verify(...)`.
4. **Порядок у логіні:** конфігурація → тіло (`400`) → валідація (`422`) → ліміт (`429`) → перевірка пароля. Валідаційні відмови в ліміт не рахуються; заблоковані запити нічого не записують і bcrypt не запускають.
5. **`Retry-After`** передається через `ApiException`; `ErrorHandler` не повинен втрачати `Cache-Control: no-store`.
6. **Публічні шляхи не читають адмін-конфігурацію.** `AdminGuard` отримує провайдер конфігурації (замикання) і викликає його лише для адмін-шляхів; перевір тестом 14.
7. **`X-Forwarded-For` ігнорується.** IP тільки з `REMOTE_ADDR` через `Request::fromGlobals()`.
8. **Не логуй** пароль, токен, хеш, секрет, заголовок `Authorization`, тіло запиту логіну. Повідомлення про помилку конфігурації містить лише назву змінної.
9. **`putenv` у тестах — відновлювати** (`withEnv` з `finally`), інакше один тест зламає наступні. Хеш пароля містить `$`: `putenv` приймає його буквально, у `.env` — тільки одинарні лапки.
10. **`login_attempts` очищай між тестами** (`authClearAttempts`) і використовуй окремі IP з `203.0.113.0/24`.
11. **Не повертай різні тексти помилки** для неправильного логіна й пароля, і не додавай у відповідь `fields` до `401`.
12. **`tests/smoke.php` не відправляє неправильних облікових даних** — на хостингу вони б заблокували IP розробника.
13. **Heredoc у Bash з `\\`** псує регулярні вирази — пиши файли через Write/Edit.
14. **Не змінюй `docs/API.md`, `frontend/`, `.env.example`** (окрім уже зробленого коментаря про лапки). Нові змінні оточення не вводяться.
15. **Секрети не друкуй у звіт:** ні `TOKEN_SECRET`, ні хеш із локального `.env`.

## 6. Чого не робити

- Не додавати `logout`, refresh-токени, кілька адмінів, зміну пароля через API, CAPTCHA, блокування за логіном.
- Не довіряти `X-Forwarded-For`, не додавати налаштування «довірених проксі» (це рішення в BE-009 після перевірки хостингу).
- Не додавати залежності (Composer, бібліотеки JWT).
- Не зберігати токени чи сесії в БД.
- Не змінювати формат помилок і публічну поведінку `/api/message`, `/api/health`.
- Не коміти без прохання користувача.

## 7. Звіт після завершення

Короткий звіт користувачу: що створено і змінено; фактичний вивід кроку 9 (скорочено, з підсумковими рядками «N тестів, 0 провалено»); результат наскрізної перевірки по HTTP; результат кожної мутації; таблиця AC → перевірка; **усі відхилення від плану і чому**; що лишилось сумнівним (зокрема: гілка `bin/hash-password.php` із прихованим введенням у TTY не перевірена автоматично; `REMOTE_ADDR` на хостингу перевіряється в BE-009). Вкажи **локальний** dev-пароль (`local-dev-password`) для входу в локальну адмінку, але не секрети. Запропонуй розбиття на коміти (напр.: `ApiException`/`ErrorHandler`/`Request::ip`/`Iso8601`; міграція 002 + `schema.sql` + оновлення `database.php`; `Auth` (Config, TokenService, RateLimiter); `AdminGuard` + `Kernel` + `AuthController` + маршрути; `bin/hash-password.php`; тести; документація), але не виконуй без прохання.
