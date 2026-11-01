# Як код реалізує контракт API

Контракт — [../../docs/API.md](../../docs/API.md), він єдине джерело правди. Цей документ — про правила в коді, які гарантують, що відповіді відповідають контракту. Помилки описані окремо: [error-handling.md](error-handling.md).

## Типи в JSON

Фронт чутливий до типів (`"42"` ≠ `42`, `"0"` у JS істинне), тому:

| Правило | Як забезпечено |
|---------|----------------|
| Числа — числами (`id`, `total`, `page`, `perPage`…) | PDO: нативні prepared statements (`EMULATE_PREPARES=false`) і `STRINGIFY_FETCHES=false`; у сумнівних місцях — явне `(int)` |
| Булеві — булевими (`isActive`) | TINYINT з MySQL приводити через `(bool)` |
| Об'єкти — об'єктами, навіть порожніми (`text`, `byCategory`, `missing`, `fields`) | `Json::object($array)` перетворює `[]` на `{}`; `json_encode([])` дає `[]` |
| Масиви — масивами (`items: []`, не `null`) | Порожній список лишається `[]` |
| Дати — ISO 8601 UTC, `2026-10-26T18:30:00Z` | Сесія MySQL у `+00:00`, форматування в одному місці — `Support\Iso8601::fromDb()` |
| Ідентифікатори — цілі числа | Нечисловий `{id}` у шляху → `404 not_found` |
| Юнікод не екранується | `Json::encode()` використовує `JSON_UNESCAPED_UNICODE` |

Усі відповіді кодуються лише через `Response::json()` → `Json::encode()`, напряму `json_encode` у контролерах не викликаємо.

## Запит

- `Request::query('name')` — рядок або `null`; масив у параметрі → `400`.
- `Request::json()` — тіло як асоціативний масив; порожнє / не-JSON / не-об'єкт → `400`. **Валідність полів** (типи, довжини, довідники) перевіряє окремий код і кидає `ApiException::validation()` → `422`.
- `Request::header('Authorization')` — імена заголовків нечутливі до регістру. На Apache заголовок читається з `HTTP_AUTHORIZATION` або `REDIRECT_HTTP_AUTHORIZATION`.
- Кінцевий `/` у шляху ігнорується.

## Відповідь

- `Response::json($data, $status = 200, $headers = [])`.
- `Response::noContent()` → `204` без тіла (для `DELETE`).
- Публічні та адмінські відповіді мають `Cache-Control: no-store` (за контрактом для `GET /api/message`; для адмінки — щоб браузер не показував застарілі дані).
- Заголовок `X-Powered-By` прибирається.

## Додати ендпоінт

1. Переконатися, що він є в `API.md`. Нового там немає → спершу запит у `docs/BACK-TO-FRONT.md` (правила — `docs/README.md`).
2. Створити контролер `src/Controller/…Controller.php`:

   ```php
   final class ExampleController
   {
       public function show(Request $request, array $params): Response
       {
           $id = ctype_digit($params['id']) ? (int) $params['id'] : throw ApiException::notFound();

           return Response::json(['id' => $id]);
       }
   }
   ```

3. Зареєструвати маршрут у `src/routes.php` — шлях **з `/api`**:

   ```php
   $router->get('/api/example/{id}', [new ExampleController(), 'show']);
   ```

4. Додати перевірки: логіку — в `tests/run.php`, наскрізну поведінку — в `tests/smoke.php` ([testing.md](testing.md)).
5. Позначити зміни в `docs/BACKEND.md`, якщо вони впливають на фронт або розгортання.

Захист `/api/admin/*` (перевірка токена) з'явиться в BE-004; до того адмінські маршрути додавати не слід.

## Службові ендпоінти поза контрактом

`GET /api/health` — для моніторингу та перевірки розгортання. Фронт його не використовує і в `API.md` його немає. Відповіді: `200 {"status":"ok"}`, `503 {"status":"error"}` (БД недоступна); версій і деталей у відповіді немає.
