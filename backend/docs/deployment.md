# Розгортання

> **Статус (2026-11-04): розгорнуто на `https://orakulscoffee.com`, перевірки на хостингу зелені** — див. [«Результати перевірки хостингу»](#результати-перевірки-хостингу).
> Історично (2026-11-03): пакет для хостингу підготовлено й перевірено локально.
> Усе, що можна було зробити без доступу до cPanel, зроблено: правила `.htaccess`, точка входу, збірка пакета, скрипти перевірки, процедура
> облікових даних. Що **лишається на хостингу**, зібрано в розділі [«Що лишилось після отримання доступу»](#що-лишилось-після-отримання-доступу-до-хостингу).
> Задачі: [BE-009](tasks/BE-009-hosting-deploy/task.md) (деплой), [BE-014](tasks/BE-014-qr-preprint-check/task.md) (перевірка QR перед друком).

## Середовище

GoDaddy Web Hosting (cPanel, Apache, shared).

| Компонент | Версія | Примітка |
|-----------|--------|----------|
| PHP | **8.4** | Доступні 8.2 / 8.3 / 8.4, типова 8.3 — **8.4 треба вибрати вручну** в cPanel («Select PHP Version»). 8.1 і старіші — платний Extended Support |
| СУБД | **MariaDB 10.11.19** (фактично на акаунті, 2026-11-04) | Документація GoDaddy обіцяла MySQL 5.7; код сумісний з обома (без можливостей MySQL 8) |

Версії взято з довідки GoDaddy через пошукову видачу (сторінку напряму прочитати не вдалося). **Перед початком деплою звірити реальні версії в cPanel** (діагностичним скриптом, див. нижче) і виправити цей файл та `docs/BACKEND.md`.

Обмеження shared-хостингу, які впливають на код: немає root-доступу, довгоживучих процесів, CLI може бути недоступний (тому схема БД імпортується через phpMyAdmin), `.htaccess` доступний.

## Цільова топологія

```
https://<домен>/        ─ статика фронта   (frontend/dist)
https://<домен>/admin/  ─ статика адмінки  (frontend/dist/admin)
https://<домен>/api/*   ─ PHP-бекенд
```

Один домен, CORS не потрібен.

## Розкладка файлів на сервері

Код і секрети лежать **вище** веб-кореня, у `public_html` — лише те, що має бути публічним:

```
~/orakuls/                  вище public_html, недоступне по HTTP
    src/                    код застосунку
    database/               schema.sql + migrations/ (для ручного імпорту через phpMyAdmin)
    bin/                    migrate.php, hash-password.php, generate-admin-credentials.php (якщо є CLI/SSH)
    .env                    секрети (створюється на сервері вручну, один раз; у пакеті є лише .env.example)
~/public_html/
    index.html, assets/…    збірка фронта
    admin/                  збірка адмінки
    api/index.php           точка входу API (APP_ROOT = ~/orakuls; інший шлях — змінна оточення ORAKULS_ROOT)
    api/.user.ini           display_errors=Off, post_max_size=8M, memory_limit=128M (чи діє на хостингу — перевірити)
    .htaccess               правила (нижче)
```

Локальна розробка використовує `backend/public/index.php`; на хостингу — `backend/deploy/public_html/api/index.php`. Решта коду від розкладки не залежить: шляхи беруться від `APP_ROOT`. Якщо код застосунку не знайдено, точка входу віддає JSON `500 server_error` без шляху (причина — в лозі).

## Збірка пакета

```
# у frontend/:  npm run build                                   → frontend/dist (index.html, admin/, assets/, images/)
docker compose exec php php bin/build-release.php               # → backend/build/release/ (не в git)
cp -r ../frontend/dist/. build/release/public_html/             # у контейнері php каталог frontend/ недоступний — копіюємо на хості
```

Пакет:

```
build/release/orakuls/       → ~/orakuls          (src, database, bin, .env.example)
build/release/public_html/   → ~/public_html      (.htaccess, api/, + фронт)
build/release/diagnostics/   → тимчасово, див. нижче (diag.php)
```

У пакет **не потрапляють**: тести, сід (`database/seed/` — лише для `*_test`), Docker-файли, `.env`. `bin/build-release.php` відмовляється чистити непорожній каталог поза `build/release`. Без `frontend/dist` пакет містить лише бекенд (фронт можна залити окремо); пакет не дозволяє збірці фронта перезаписати `api/` чи `.htaccess`.

## `.htaccess` (`backend/deploy/public_html/.htaccess`)

| Правило | Навіщо |
|---------|--------|
| `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` | Заголовок `Authorization` доходить до PHP. **Перевірено на Apache + mod_php:** без цього правила Bearer-токен не потрапляє в PHP і всі `/api/admin/*` дають `401` (F-003, п. 4) |
| `www.<домен>` → `https://<домен>` і HTTP → HTTPS однією переадресацією `301`, умова `X-Forwarded-Proto` проти циклу за проксі | Єдина адреса сайту. Query string, зокрема `?qr`, зберігається автоматично (BE-014) |
| `^api(/.*)?$` → `api/index.php` (`QSA`) | `/api/*` обробляє PHP-роутер (шляхи в маршрутах — з префіксом `/api`); `/api/nope` → JSON `404`, а не HTML Apache. Реальні файли й папки (статика) правилами не чіпаються |
| `DirectoryIndex index.html`, `Options -Indexes` | `/admin/` віддає `admin/index.html`, лістинг каталогів вимкнено |
| `FilesMatch`: dotfiles (`.env`, `.git`, `.htaccess`) і `.env|sql|md|log|example|dist|bak|ini` → `Require all denied`; `RedirectMatch 404 /.git` | Службові файли недоступні по HTTP (навіть якщо випадково потрапили в `public_html`) |

`/api` без слеша Apache (`mod_dir`, бо `api/` — каталог) переадресує на `/api/`, де роутер віддає JSON `404`; жоден клієнт `/api` без суфікса не викликає.

## Локальна перевірка пакета (імітація хостингу)

Apache-контейнер з `.htaccess`, пакетом із `build/release` і тестовою БД `orakuls_test` (`docker-compose.hosting.yml`, `deploy/apache-test/`):

```
docker compose -f docker-compose.yml -f docker-compose.hosting.yml up -d --build apache
docker compose exec php php tests/hosting-check.php http://apache --local        # 38 перевірок
```

Після **кожної** перезбірки пакета (`build-release.php` перестворює каталог) контейнер треба перезапустити: `docker compose -f docker-compose.yml -f docker-compose.hosting.yml restart apache` (інакше bind-mount вказує на старий каталог і все віддає 404).

`tests/hosting-check.php` перевіряє: `/` і `/admin/` — статика; статичний файл не перехоплюється API; лістинг вимкнено; `/api/health`, `/api/message`, JSON-`404` для невідомого шляху; довгий `exclude` (100 і 200 ID, ≈ 1300 і ≈ 2600 символів) без `414`; `login` → `me` з Bearer-токеном; термін токена ≈ 7 діб; 14 службових шляхів (`.env`, `.git`, міграції, `src/`, `bin/` …) → `403/404` без вмісту; редиректи `http→https` і `www→без www` зі збереженням `?qr`. Той самий скрипт запускається й проти справжнього домену (без `--local`).

Що це **не** доводить: Apache на GoDaddy — інша збірка, ніж `php:8.4-apache` (PHP там, імовірно, FastCGI, а не `mod_php`; можливий проксі перед сайтом). Локальна перевірка відсікає помилки самих правил; поведінку хостингу підтверджує лише прогін на ньому.

## База даних на хостингу

Порядок розгортання схеми (без CLI):

1. cPanel → **MySQL Databases**: створити БД і користувача, додати користувача до БД з усіма правами (`ALL PRIVILEGES`). Значення — у `.env` на сервері (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
2. cPanel → **phpMyAdmin** → у лівій панелі обрати щойно створену **порожню** базу → вкладка **Import** → файл `database/schema.sql` → кодування файлу `utf-8` → **Go**. Файл створює таблиці `migrations`, `phrases`, `login_attempts`, `daily_stats` і записує в `migrations` усі міграції, які в нього вже входять (001–003).
3. Перевірити: `SHOW TABLES;` показує `daily_stats`, `login_attempts`, `migrations`, `phrases`; `SELECT * FROM migrations;` — три застосовані міграції.
4. **Сід не імпортується.** Він працює лише з БД, назва якої закінчується на `_test`. Реальні фрази завантажує адмін через імпорт (BE-007).

Наступні зміни схеми приходять як нові файли `database/migrations/NNN_*.sql` разом з оновленим `schema.sql`. На чинній БД виконується **лише новий** файл (phpMyAdmin → вкладка SQL → вставити вміст → Go) — повторний імпорт повного `schema.sql` не потрібен. Якщо на хостингу є CLI/SSH, те саме робить `php bin/migrate.php` (таблиця `migrations` показує, що вже застосовано). Після ручного виконання міграції треба вручну додати її ім'я в `migrations`: `INSERT INTO migrations (name, applied_at) VALUES ('NNN_name.sql', UTC_TIMESTAMP());`, інакше runner спробує застосувати її вдруге.

**Міграцію застосовують ДО розгортання коду, що її використовує**, а не після: без таблиці `daily_stats` `POST /api/visit` дає `500` (фронт це ігнорує, але відкриття не рахуються), а `GET /api/message` віддає послання, лишаючи запис у лозі на кожну видачу.

Тестова БД `orakuls_test` існує тільки локально в Docker.

## Облікові дані адміна (боєві)

1. Згенерувати: `docker compose exec php php bin/generate-admin-credentials.php` (локально або на сервері, де є CLI). У STDOUT — рядки для `.env` (`ADMIN_LOGIN`, `ADMIN_PASSWORD_HASH` в одинарних лапках, `TOKEN_SECRET` 64 символи, `TOKEN_TTL_HOURS=168`); пароль — **лише в STDERR**, один раз, у файли не пишеться.
2. Вписати рядки в серверний `.env` (не через `echo`/`sed`: оболонка розкриє `$` у хеші) — редактором файлів cPanel. У `.env` лишаються лише хеш пароля й `TOKEN_SECRET`.
3. Пароль передати клієнту безпечним каналом (менеджер паролів або одноразове посилання), не поштою й не месенджером відкритим текстом. Не лишати його в історії терміналу чи чатів.
4. Перевірити вхід (`tests/hosting-check.php` із `SMOKE_ADMIN_LOGIN`/`SMOKE_ADMIN_PASSWORD` робить `login` → `me`). `expiresAt` має бути ≈ +168 год.

Детально — [configuration.md](configuration.md). Зміна пароля = новий хеш у `.env` (анулює всі видані токени).

## Перший захід на хостинг: діагностика

Тимчасовий скрипт `backend/deploy/diagnostics/diag.php` (у пакеті — `build/release/diagnostics/`, **поза** `public_html`) показує: версії PHP/MySQL, потрібні розширення, `tzdata` PHP (`Europe/Riga` — для статистики), ліміти (`post_max_size`, `memory_limit`, `max_execution_time`, `max_allowed_packet`), `error_log`, `REMOTE_ADDR` і заголовки проксі, чи доходить `Authorization`, чи на місці таблиці й міграції. Секрети не друкує.

1. Вписати випадковий `DIAG_KEY` у файл (порожній ключ → скрипт відмовляється працювати), залити як `public_html/diag.php`.
2. Відкрити `https://<домен>/diag.php?key=<DIAG_KEY>` **з комп'ютера і з телефона через мобільну мережу** (різні публічні IP).
3. **Записати результати в цей файл** (розділ «Результати перевірки хостингу» нижче) і **одразу видалити `diag.php` із сервера.**

## Що пишеться в лог і де він

Бекенд пише лише через `error_log()`. У `diag.php` видно, куди він іде на цьому хостингу (`ini error_log`). Вимоги: файл **не публічний** (у `.htaccess` закритий `*.log`, а якщо `error_log` лежить у `public_html` — перевірити `403/404` або перенаправити поза веб-корінь через `.user.ini`/cPanel) і **не росте безмежно** (ротується хостингом або іншим способом без ручного обслуговування — клієнт хостинг не доглядає). Результат записати нижче.

## IP клієнта і ліміт спроб входу

Ліміт спроб входу (5 за 15 хв, [D-22](decisions.md)) рахується за `REMOTE_ADDR`; `X-Forwarded-For` код свідомо ігнорує (його підробляє будь-який клієнт). Якщо хостинг стоїть за проксі, цей IP буде спільним для всіх, і чужі невдалі спроби блокуватимуть адміна. Тому **обов'язкова** перевірка `diag.php` з двох мереж; якщо `REMOTE_ADDR` не справжній — **зупинитись і звернутись до користувача**, нічого не змінюючи в коді мовчки (рішення про те, якому заголовку довіряти, приймає користувач).

## Деплой і відкат

**Оновлення** (порядок має значення):

1. Зібрати пакет (розділ вище), запустити локальну перевірку `tests/hosting-check.php` — зелена.
2. **Резервна копія**: phpMyAdmin → Export БД; у cPanel File Manager — архів поточних `~/orakuls` і `~/public_html` (або копія в `~/backup-<дата>/`).
3. Якщо є нова міграція — виконати її (phpMyAdmin → SQL) і додати запис у `migrations`.
4. Залити `orakuls/` (без `.env`!) і `public_html/` (без власного `.htaccess`, якщо його змінювали вручну — звірити). `.env` не перезаписувати.
5. Прогнати `tests/hosting-check.php https://<домен>` (із `SMOKE_ADMIN_*`) і `tests/smoke.php https://<домен>`.

**Відкат:** повернути файли з резервної копії; БД відкочується лише якщо міграція була руйнівною (наші міграції лише додають таблиці/колонки, тож зазвичай БД відкочувати не треба — старий код нові таблиці просто не використовує).

Фронт і бекенд — два незалежні кроки: збірка й заливка статики (`public_html/index.html`, `assets/`, `admin/`), окремо — заливка API (`orakuls/`, `public_html/api/`). `frontend/dist/` не лежить у git, тож `git pull` на сервері готового фронта не дасть. Узгодження процесу з фронтом — через `docs/BACK-TO-FRONT.md`.

## Доступ до сервера для наступних змін

Між сесіями доступ на сервері **вимкнено**; у розробника лишається лише локальна папка з ключами. Секретів у репозиторії немає.

**Що де лежить (на компʼютері розробника, поза git):**

| Файл | Що це | Як відновити, якщо втрачено |
|------|-------|-----------------------------|
| `%USERPROFILE%.orakuls-secretsid_deploy` + `id_deploy.pub` | SSH-ключ `claude-deploy` (приватний + публічний) | `ssh-keygen -t ed25519 -C claude-deploy -f …id_deploy` і імпортувати новий `.pub` |
| `%USERPROFILE%.orakuls-secretsknown_hosts` | Відбиток сервера | Створиться сам при першому вході |
| `%USERPROFILE%.orakuls-secretsdb.env` | Дані БД (те саме, що в серверному `~/orakuls/.env`) | Прочитати з `~/orakuls/.env` на сервері |
| API-токен cPanel | Потрібен лише для створення БД/користувачів; для оновлення коду не потрібен | Створити новий (нижче) |

**Сервер:** cPanel-користувач `l6xf4w9lfi1i`, хост `sxb1plzcpnl503800.prod.sxb1.secureserver.net` (SSH — порт 22). Вхід у cPanel: GoDaddy → My Products → Web Hosting → Manage → cPanel Admin.

### Увімкнути доступ (на початку сесії змін)

1. **SSH у панелі GoDaddy:** host.godaddy.com → хостинг → Settings → Server → **SSH Access** → увімкнути.
2. **Ключ:** cPanel → Security → **SSH Access → Manage SSH Keys**. Якщо `claude-deploy` є в «Public Keys» — **Manage → Authorize**. Якщо немає — **Import Key**: Name `claude-deploy`, у «Public Key» вставити вміст `id_deploy.pub`, Import, потім Authorize.
3. **API-токен (лише якщо треба створювати БД/користувачів):** cPanel → Security → **Manage API Tokens** → Create, назва `claude-deploy`, термін +3 дні; зберегти в файл поза репозиторієм.
4. Перевірка: `ssh -i %USERPROFILE%.orakuls-secretsid_deploy -o IdentitiesOnly=yes l6xf4w9lfi1i@sxb1plzcpnl503800.prod.sxb1.secureserver.net "php -v"`.

### Оновити сайт

Порядок — розділ [«Деплой і відкат»](#деплой-і-відкат): збірка пакета → локальна перевірка → (нова міграція: `php bin/migrate.php` на сервері) → заливка `orakuls/` (**без** `.env`) і `public_html/` → `hosting-check.php` і `smoke.php` проти прода. По SSH заливка — `tar`:

```
cd backend/build/release
tar -cf - orakuls     | ssh <ключ і хост як вище> "tar -xf - -C ~"
tar -cf - -C public_html . | ssh <…> "tar -xf - -C ~/public_html"
```

`.env`, `~/.my.cnf`, `~/backups` і crontab при оновленні не чіпаються. Перед заливкою варто зробити свіжий дамп: `mysqldump --single-transaction --no-tablespaces l6xf4w9lfi1i_orakuls | gzip > ~/backups/orakuls-manual-$(date +%F).sql.gz`.

### Вимкнути доступ (наприкінці сесії)

- [ ] cPanel → Manage SSH Keys → `claude-deploy` → **Deauthorize** (ключ лишається в списку, наступного разу — лише Authorize).
- [ ] cPanel → Manage API Tokens → видалити токен `claude-deploy` (якщо створювався) і файл із ним.
- [ ] GoDaddy → Settings → Server → **SSH Access** → вимкнути.
- [ ] На сервері не лишилось тимчасових файлів (`diag.php` тощо) у `~/public_html`.

Cron (обрізання лога, дамп БД) працює без SSH і токена.

## Перевірка після деплою

```
SMOKE_ADMIN_LOGIN=admin SMOKE_ADMIN_PASSWORD='…' docker compose run --rm --no-deps php php tests/hosting-check.php https://<домен>
SMOKE_ADMIN_LOGIN=admin SMOKE_ADMIN_PASSWORD='…' docker compose run --rm --no-deps php php tests/smoke.php https://<домен>
```

Скрипти не відправляють неправильних облікових даних (вони рахуються в ліміт). `SMOKE_WRITE=1` **не вмикати** проти прода перед друком QR: статистику скинути нічим.

## Що лишилось після отримання доступу до хостингу

Зроблено локально (див. вище) і **ще не підтверджено на хостингу**:

- [x] Вибрати PHP 8.4, звірити версії PHP/MySQL з документацією (`diag.php`), розширення `pdo_mysql`, `mbstring`, `json`, `openssl`/`sodium`.
- [x] Створити БД і користувача, імпортувати `schema.sql`, перевірити таблиці й міграції 001–003.
- [x] Залити пакет; боєві облікові дані за процедурою вище; пароль передати клієнту.
- [x] `tests/hosting-check.php https://<домен>` — зелений (правила `.htaccess` на справжньому Apache/PHP хостингу: `Authorization`, роутинг `/api`, службові файли, редиректи, довгий `exclude`).
- [ ] `diag.php` з двох мереж: `REMOTE_ADDR` справжній? Ліміт спроб входу наскрізно (5 → `429`, інша мережа проходить).
- [x] Ліміти: `post_max_size` ≥ 8 МБ (чи діє `.user.ini`), `memory_limit`, `max_execution_time`, `max_allowed_packet`; найгірший запит імпорту (≈ 6 МБ) проходить.
- [x] `error_log`: куди йде, не публічний, не росте безмежно (cron, 2026-11-04).
- [x] Резервна копія: щотижневий `mysqldump` (cron), перша копія перевірена; відновлення описано в «Результатах».
- [ ] BE-014: контрольний прогін з телефоном і QR на фінальному домені.

## Результати перевірки хостингу

**2026-11-04, `https://orakulscoffee.com` — розгорнуто, перевірки зелені.**

| Що | Результат |
|----|-----------|
| Акаунт | cPanel `l6xf4w9lfi1i` на `sxb1plzcpnl503800.prod.sxb1.secureserver.net`; `orakulscoffee.com` — основний домен, docroot `~/public_html` |
| PHP | **8.4.27**, SAPI `litespeed` (не `mod_php`/FastCGI), CLI теж 8.4.27. Версію змінено з типової 8.3 у панелі GoDaddy (Settings → PHP version): у cPanel PHP Selector список версій на рівні акаунта неактивний, а налаштування на домен недоступне (ізоляцію сайтів GoDaddy заборонив) |
| Розширення | `pdo_mysql`, `mbstring`, `json`, `openssl`, `sodium` — є. На CloudLinux `pdo_mysql` конфліктує з `nd_pdo_mysql`: увімкнено `nd_pdo_mysql` + `nd_mysqli` (mysqlnd), у PHP вони видні як `pdo_mysql` |
| СУБД | **MariaDB 10.11.19** (`10.11.19-MariaDB-cll-lve`), а не MySQL 5.7. Код сумісний: без можливостей MySQL 8; кодування й суворий `sql_mode` задаються на кожному з'єднанні (`Db.php`), бо типові на сервері — `latin1` і `NO_ENGINE_SUBSTITUTION`; таблиці створено в `utf8mb4` |
| БД | `l6xf4w9lfi1i_orakuls`, користувач `l6xf4w9lfi1i_orakuls` (ALL PRIVILEGES лише на неї); префікса cPanel не вимагає. Міграції 001–003 застосовано `php bin/migrate.php` по SSH |
| `tzdata` | 2026.5; `Europe/Riga` 2026-10-25 00:30Z → 03:30 EEST ✔ |
| `REMOTE_ADDR` | Справжній IP клієнта: з компʼютера 159.224.216.56, з телефона через мобільну мережу 89.209.188.52; заголовків проксі (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `Forwarded`, `Via`) немає. Ліміт спроб входу за `REMOTE_ADDR` працює коректно |
| `Authorization` | Доходить до PHP (`HTTP_AUTHORIZATION`), `login` → `me` = 200 |
| Ліміти | `post_max_size` 32M, `upload_max_filesize` 30M, `memory_limit` 128M, `max_execution_time` 30, `max_allowed_packet` 256M — найгірший імпорт (≈ 6 МБ) влазить. `display_errors` вимкнено |
| `error_log` | `ini error_log = error_log` (відносний): файл `error_log` створюється в каталозі скрипта (для API — `~/public_html/api/error_log`). По HTTP `/api/error_log` і `/error_log` → **403** (закриває сам хостинг, наше правило `*.log` цей файл не ловить). Розмір обмежує cron (нижче) |
| `hosting-check.php` | **39 перевірок, 0 провалено** (з `SMOKE_ADMIN_*`), зокрема довгий `exclude` 100/200 ID, редиректи `http→https`, `www→без www` зі збереженням `?qr`, остаточна адреса `https://orakulscoffee.com/?qr` → 200 без редиректів |
| `smoke.php` | «все гаразд» (без `SMOKE_WRITE`) |
| Фрази | 600 фраз імпортовано адміном через адмінку (Excel), видача працює |
| Діагностика | `diag.php` залито з випадковим ключем, знято результати з двох мереж, **видалено** (тепер 404) |

**Як розгорнуто (фактично).** Через SSH (ключ, увімкнений у панелі GoDaddy → SSH access) замість ручного File Manager/phpMyAdmin: `tar` пакета `orakuls/` → `~/orakuls`, `public_html/` → `~/public_html` (заглушку GoDaddy `home.html`, `layout-styles.css` видалено; `cgi-bin/`, `404.shtml` лишились); `.env` (права `600`) зібрано на сервері: `DB_*` + вивід `bin/generate-admin-credentials.php`, запущеного на сервері. БД і користувача створено через cPanel UAPI (`Mysql/create_database`, `create_user`, `set_privileges_on_database`).

**Обслуговування без людини (cron, 2026-11-04).** Проєкт разовий, хостинг ніхто регулярно не доглядає, тож усе, що росте, обмежено автоматично (`crontab -l` на сервері; задачі позначені `# Orakuls (BE-009)`):

| Коли | Що | Навіщо |
|------|----|--------|
| Нд 04:00 (час сервера) | Якщо `~/public_html/api/error_log` > 5 МБ — лишити останній 1 МБ | Лог не росте безмежно (хостинг цей файл не ротує) |
| Нд 04:30 | `mysqldump` БД → `~/backups/orakuls-YYYY-MM-DD.sql.gz`, зберігаються 8 найновіших | Резервна копія фраз і статистики (≈ 43 КБ на копію) |

Облікові дані для `mysqldump` — у `~/.my.cnf` (права `600`, згенеровано з `~/orakuls/.env`); якщо змінюється пароль БД — оновити обидва файли. `~/backups` — поза `public_html`, права `700`. Обидві команди один раз прогнано вручну: дамп цілий (`gzip -t`), 4 таблиці з даними.

**Відновлення БД з копії** (по SSH; SSH вмикається в панелі GoDaddy, ключ — cPanel → SSH Access):

```
ls -1t ~/backups/                                              # вибрати копію
zcat ~/backups/orakuls-YYYY-MM-DD.sql.gz | mysql l6xf4w9lfi1i_orakuls   # перезаписує таблиці з дампу
```

Без SSH: cPanel → File Manager → завантажити `.sql.gz` з `~/backups` → phpMyAdmin → БД → Import. Файли сайту окремо не копіюються: їх відтворює збірка пакета з репозиторію (розділ «Збірка пакета»); `~/orakuls/.env` — єдиний файл, якого немає в git (пароль адміна можна перегенерувати, пароль БД — змінити в cPanel і вписати в `.env` і `~/.my.cnf`).

**Лишилось відкритим:**

- [ ] Ліміт спроб входу наскрізно (5 невдалих → `429` з `Retry-After`, інша мережа проходить) — виконує людина вручну; `REMOTE_ADDR` справжній, тож очікується коректна робота.
- [ ] BE-014: контрольний прогін з телефоном і QR.
- [ ] Після деплою: видалити API-токен `claude-deploy` і SSH-ключ `claude-deploy` у cPanel.
