# Документація бекенда

Технічна документація для тих, хто розробляє й підтримує `backend/`. Якщо ви фронтенд-розробник і вам потрібне лише те, що стосується інтеграції — читайте спільні файли в [../../docs/](../../docs/README.md): [API.md](../../docs/API.md) і [BACKEND.md](../../docs/BACKEND.md).

## З чого почати

- Новий у проєкті → [architecture.md](architecture.md), потім [development.md](development.md).
- Додаєте ендпоінт → [api-conventions.md](api-conventions.md) і [error-handling.md](error-handling.md).
- Деплой → [deployment.md](deployment.md).

## Документи

| Файл | Про що |
|------|--------|
| [architecture.md](architecture.md) | Загальна картина, структура коду, компоненти, правила залежностей, життєвий цикл запиту |
| [development.md](development.md) | Локальний запуск у Docker, команди, порти, правила коду, типові проблеми |
| [configuration.md](configuration.md) | `.env` і змінні оточення, секрети, облікові дані адміна |
| [api-conventions.md](api-conventions.md) | Як код реалізує контракт: типи в JSON, запит/відповідь, як додати ендпоінт |
| [error-handling.md](error-handling.md) | Формат помилок, `ApiException`, що буде з несподіваними помилками, логування |
| [database.md](database.md) | Підключення, налаштування сесії, правила запитів, обмеження MySQL 5.7 |
| [security.md](security.md) | Що вже захищено і що заплановано (автентифікація адміна, секрети, файли на хостингу) |
| [testing.md](testing.md) | Юніт-перевірки та smoke-тести, як додати нову перевірку |
| [deployment.md](deployment.md) | Хостинг GoDaddy: версії, топологія, розкладка файлів, `.htaccess` |
| [decisions.md](decisions.md) | Журнал архітектурних рішень: що вибрали, чому, що відкинули |

## Задачі

План робіт — у папці [tasks/](tasks/): по одній папці на задачу, в кожній `task.md` з описом і acceptance criteria. Загальні вимоги до всіх задач — [tasks/README.md](tasks/README.md).

## Спільні документи проєкту

| Файл | Про що |
|------|--------|
| [../../docs/API.md](../../docs/API.md) | Контракт API — єдине джерело правди про взаємодію з фронтом |
| [../../docs/BACKEND.md](../../docs/BACKEND.md) | Короткий опис бекенда для обох сторін |
| [../../docs/FRONT-TO-BACK.md](../../docs/FRONT-TO-BACK.md), [../../docs/BACK-TO-FRONT.md](../../docs/BACK-TO-FRONT.md) | Обмін запитами між фронтом і бекендом |

## Як підтримувати документацію

- Змінили код → у тому ж коміті оновіть відповідний документ. Документи описують те, що є в коді; заплановане позначено явно (⏳ або «планується», з номером задачі).
- Нове рішення, що має альтернативи → запис у [decisions.md](decisions.md).
- Нова змінна оточення → [configuration.md](configuration.md) і `.env.example`.
- Зміна контракту з фронтом — лише через `docs/` за правилами [docs/README.md](../../docs/README.md), не тут.
