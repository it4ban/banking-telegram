# План разработки Telegram Mini App для выпуска виртуальных карт (Lithic Sandbox)

## Context

Проект `telegram-banking/app` — учебный, с прицелом на возможный будущий стартап. Сейчас есть:

- `backend/` — чистый PHP 8.5 без фреймворка: `index.php` (entrypoint), `Http/Request.php`, `Http/Response.php`, `Router/Router.php` (простой роутер с `{param}`-паттернами, без middleware/DI/контроллеров).
- `frontend/` — Vue 3 + Vite + TS + Tailwind 4, уже подключён `@tma.js/sdk-vue` (Telegram Mini Apps SDK), но не используется в коде.
- `docker/` — nginx + php-fpm + nodejs (vite dev), без БД.
- README описывает разработку через cloudflared tunnel для регистрации Mini App URL в @BotFather.

Задача — построить путь от «пользователь открыл бота» до «пользователь выпустил виртуальную карту через Lithic Sandbox и может ей пользоваться (мок-транзакции)», с архитектурой, которую не стыдно расширять дальше (auth, отдельные слои, миграции), но без избыточного комплаенс/security-груза на старте.

Решения по стеку: webhook (не long polling), PostgreSQL, слоистая архитектура Router → Controller → Service → Repository с самого начала.

## Общая архитектура

```
Telegram Client
   │
   ├── Bot API (webhook) ──► backend: POST /webhook/telegram ──► BotController ──► обрабатывает команды (/start), шлёт кнопку "Открыть приложение" (WebApp button)
   │
   └── Mini App (Vue, внутри Telegram WebView)
          │  Telegram.WebApp.initData (подпись, user info)
          ▼
       frontend (Vite/Vue) ──► REST API backend (те же php-fpm/nginx)
          │
          ▼
       backend: /api/* маршруты
          Router ──► Controller ──► Service (бизнес-логика) ──► Repository (PDO/Postgres)
                                          │
                                          └──► LithicClient (HTTP-клиент к Lithic Sandbox API)
```

Ключевая идея: **backend — единая точка входа** и для Telegram webhook, и для Mini App REST API. Авторизация Mini App делается через валидацию `initData` (HMAC по бот-токену), а не через отдельный логин/пароль.

## Слои backend (новая структура `backend/`)

```
backend/
  index.php                 # entrypoint, регистрация роутов, диспетчеризация
  Http/
    Request.php             # уже есть
    Response.php            # уже есть
    Middleware/
      TelegramAuthMiddleware.php   # валидация initData, кладёт user в Request
  Router/
    Router.php               # уже есть, добавить поддержку middleware на группу/роут
  Controllers/
    BotWebhookController.php # обработка апдейтов от Telegram (webhook)
    AuthController.php       # обмен initData -> внутренняя сессия/JWT (если нужно)
    CardController.php       # выпуск карты, список карт, детали карты
    TransactionController.php# список мок-транзакций по карте
  Services/
    TelegramBotService.php   # отправка сообщений, кнопок через Bot API
    CardIssuingService.php   # бизнес-логика выпуска карты (оркестрирует Lithic + Repository)
    UserService.php          # создание/поиск пользователя по telegram_id
  Integrations/
    Lithic/
      LithicClient.php       # тонкая обёртка над Lithic Sandbox REST API (cURL/Guzzle)
      DTO/CardDTO.php, DTO/TransactionDTO.php
  Repositories/
    UserRepository.php
    CardRepository.php
    TransactionRepository.php
  Database/
    Connection.php           # PDO singleton/factory, читает DSN из .env
    migrations/
      001_create_users.sql
      002_create_cards.sql
      003_create_transactions.sql
  Support/
    Env.php                  # загрузка .env (или подключить vlucas/phpdotenv через composer)
  composer.json               # добавить зависимости: guzzlehttp/guzzle, vlucas/phpdotenv, ramsey/uuid (опц.)
```

Repository работает через PDO напрямую (без ORM) — этого достаточно для учебного проекта и не добавляет магии.

## Схема БД (PostgreSQL)

Миграции — простые `.sql`-файлы (`backend/Database/migrations/`), применяются вручную/скриптом `php backend/Database/migrate.php` (без миграционного фреймворка, чтобы не тащить лишнее).

### `users` ([001_create_users.sql](../backend/Database/migrations/001_create_users.sql))

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | `uuid`, PK, `DEFAULT uuidv4()` | Внутренний идентификатор пользователя в нашей БД. |
| `telegram_id` | `bigint`, `UNIQUE NOT NULL` | ID пользователя в Telegram (из `initData`/апдейтов бота). По нему находим/создаём пользователя при первом обращении — это естественный внешний идентификатор личности, `bigint`, т.к. Telegram ID превышает диапазон `int`. |
| `username` | `varchar(255)`, nullable | Telegram-юзернейм (`@username`), может отсутствовать — не у всех пользователей он задан. |
| `first_name` | `varchar(255)`, nullable | Имя из профиля Telegram, для приветствий/UI. |
| `last_name` | `varchar(255)`, nullable | Фамилия из профиля Telegram, часто отсутствует. |
| `status` | `user_status` enum (`active`/`blocked`), `NOT NULL DEFAULT 'active'` | Может ли пользователь пользоваться сервисом. При переводе в `blocked` необходимо на уровне `UserService` также закрыть/приостановить его карты (см. ниже) — это бизнес-логика, а не каскад на уровне БД, так как требует ещё и вызова Lithic API. |
| `avatar_url` | `text`, nullable | Ссылка на аватар из Telegram, для UI. |
| `created_at` | `timestamptz`, `DEFAULT now()` | Когда пользователь впервые зарегистрировался. |
| `updated_at` | `timestamptz`, `DEFAULT now()` | Когда запись последний раз менялась (смена статуса, данных профиля). |

### `cards` ([002_create_cards.sql](../backend/Database/migrations/002_create_cards.sql))

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | `uuid`, PK, `DEFAULT uuidv4()` | Внутренний идентификатор карты. |
| `user_id` | `uuid`, `NOT NULL`, FK → `users.id` `ON DELETE RESTRICT` | Владелец карты. `NOT NULL` + `RESTRICT` намеренно: карта не может существовать без владельца, а удаление пользователя с активными картами блокируется на уровне БД, пока карты не будут явно закрыты через сервисный слой (см. "Владение картой" ниже). |
| `lithic_card_token` | `text NOT NULL` | Идентификатор этой же карты в Lithic. Источник истины по чувствительным данным карты (полный PAN, CVV) — Lithic, не наша БД; этот токен — мостик к нему для запросов к Lithic API. |
| `last_four` | `varchar(4) NOT NULL` | Последние 4 цифры номера карты — единственный фрагмент номера, который храним у себя, для отображения в UI ("•••• 4242"), без риска хранить PCI-чувствительные данные. |
| `state` | `card_state` enum (`open`/`paused`/`closed`), `NOT NULL DEFAULT 'open'` | Статус карты: `open` — активна, можно платить; `paused` — временно приостановлена; `closed` — закрыта навсегда. Отражает (и должно синхронизироваться с) статус карты в Lithic. |
| `daily_limit` | `numeric(10,2) NOT NULL DEFAULT 0`, `CHECK (>= 0)` | Дневной лимит трат по карте, задаётся при выпуске через Lithic API и кэшируется здесь для UI. `numeric`, а не `float`, чтобы избежать ошибок округления денежных сумм. |
| `monthly_limit` | `numeric(10,2) NOT NULL DEFAULT 0`, `CHECK (>= 0)` | Месячный лимит трат, аналогично `daily_limit`. |
| `created_at` | `timestamptz`, `DEFAULT now()` | Когда карта была выпущена. |
| `updated_at` | `timestamptz`, `DEFAULT now()` | Когда карта последний раз менялась (смена статуса, лимитов). |

Индекс `idx_cards_user_id` — ускоряет выборку "все карты пользователя" (`GET /api/cards`).

**Владение картой:** карта закрепляется за пользователем навсегда и не передаётся другому — иначе новый владелец увидел бы в истории карты чужие транзакции (`transactions.card_id` не разделяет периоды владения). При блокировке пользователя его карты переводятся в `paused`/`closed` силами `UserService`/`CardIssuingService` (со звонком в Lithic API на закрытие), а не автоматическим каскадом в БД. Жёсткое удаление пользователя (`DELETE FROM users`) — редкий сценарий (основной механизм — блокировка навсегда); `ON DELETE RESTRICT` заставляет explicitly закрыть все карты пользователя до того, как его можно будет удалить, не оставляя "бесхозных" карт с обнулённым `user_id`.

### `transactions` ([003_create_transactions.sql](../backend/Database/migrations/003_create_transactions.sql))

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | `uuid`, PK, `DEFAULT uuidv4()` | Внутренний идентификатор транзакции. |
| `card_id` | `uuid NOT NULL`, FK → `cards.id` `ON DELETE CASCADE` | К какой карте относится транзакция. `CASCADE` здесь уместен (в отличие от `cards.user_id`): если карта удаляется физически, её транзакции — не самостоятельная сущность, а история именно этой карты, смысла в них без карты нет. |
| `lithic_transaction_token` | `text NOT NULL` | Идентификатор транзакции в Lithic — по нему сверяем/дозапрашиваем детали и сопоставляем вебхуки с записью у себя. |
| `amount` | `numeric(10,2) NOT NULL DEFAULT 0`, `CHECK (>= 0)` | Сумма транзакции. `numeric` вместо `float` — обязательное правило для денег. |
| `merchant` | `varchar(255) DEFAULT 'unknown'` | Название/идентификатор продавца, приходит от Lithic при авторизации операции. |
| `status` | `transaction_state` enum (`approved`/`declined`/`pending`), `NOT NULL DEFAULT 'pending'` | Статус операции, зеркалирует статус на стороне Lithic. |
| `created_at` | `timestamptz`, `DEFAULT now()` | Когда транзакция была зафиксирована. |
| `updated_at` | `timestamptz`, `DEFAULT now()` | Когда статус транзакции последний раз менялся (например, `pending` → `approved`). |

Индекс `idx_transactions_card_id` — ускоряет выборку "история транзакций по карте" (`GET /api/cards/{id}/transactions`).

## Docker / инфраструктура

- Добавить сервис `postgres` в `compose.yml` (образ `postgres:16-alpine`, volume, порт из `.env`).
- Добавить переменные в `.env` / `.env.example`: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `LITHIC_API_KEY`, `LITHIC_API_BASE_URL` (sandbox).
- `composer.json`: добавить `guzzlehttp/guzzle` (HTTP-клиент к Lithic и Bot API), `vlucas/phpdotenv`.

## Этапы разработки (каждый даёт видимый результат)

### Этап 0 — Подготовка инфраструктуры

- Добавить Postgres в docker-compose, поднять и проверить подключение.
- Настроить `.env`/`.env.example`, загрузчик Env.php.
- Создать таблицы через миграции.
- **Результат:** `docker compose up` поднимает nginx+php+node+postgres, есть тестовый скрипт, подтверждающий коннект к БД.

### Этап 1 — Регистрация бота и webhook

- Получить токен у @BotFather (пользователь делает сам).
- Реализовать `BotWebhookController` + `TelegramBotService::sendMessage()`.
- Роут `POST /webhook/telegram`, скрипт для установки webhook (`setWebhook` через cloudflared URL).
- Обработать `/start`: сохранить/найти пользователя в `users`, ответить приветствием с кнопкой Mini App (`web_app` inline-кнопка).
- **Результат:** пишешь боту `/start` — получаешь приветственное сообщение с кнопкой "Открыть приложение".

### Этап 2 — Открытие Mini App и авторизация

- Настроить Mini App URL в @BotFather на frontend (через tunnel).
- Frontend: интеграция `@tma.js/sdk-vue`, получение `initData`, отправка на backend.
- Backend: `AuthController` + `TelegramAuthMiddleware` — валидация HMAC-подписи `initData` по бот-токену, создание/поиск пользователя, выдача простого токена сессии (JWT либо подписанный cookie).
- **Результат:** открываешь Mini App из Telegram — видишь своё имя/данные, полученные и провалидированные backend'ом.

### Этап 3 — Интеграция с Lithic Sandbox

- Зарегистрироваться в Lithic Sandbox, получить API key.
- Реализовать `LithicClient`: создание Account Holder (или использовать sandbox test account), выпуск виртуальной карты (`POST /cards`), получение деталей карты.
- **Результат:** тестовый скрипт/эндпоинт создаёт карту в Lithic Sandbox и возвращает её JSON-ответ.

### Этап 4 — Выпуск карты через Mini App

- `CardController` + `CardIssuingService`: эндпоинт `POST /api/cards` — вызывает Lithic, сохраняет карту в `cards` с привязкой к `user_id`.
- `GET /api/cards` — список карт пользователя.
- Frontend: экран "Выпустить карту" (кнопка) и экран списка карт (номер маскирован, last_four, статус).
- **Результат:** в Mini App нажимаешь "Получить карту" — карта создаётся в Lithic и отображается в интерфейсе.

### Этап 5 — Просмотр карты и мок-транзакций

- `GET /api/cards/{id}` — детали карты (через Lithic API, PAN/CVV по требованию с осторожной выдачей — sandbox допускает).
- Использовать Lithic Sandbox симуляцию транзакций (`POST /simulate/authorize` и т.п.) — либо ручной эндпоинт "смоделировать оплату" в Mini App для теста, либо просто dev-скрипт.
- `TransactionController` + сохранение/синхронизация транзакций в `transactions`.
- Frontend: экран деталей карты + список транзакций.
- **Результат:** можно смоделировать оплату сервисом и увидеть транзакцию в истории карты в Mini App.

### Этап 6 — Полировка MVP

- Обработка ошибок Lithic (недостаточно средств, отклонённая транзакция и т.п.) на уровне Service/Controller с понятными сообщениями.
- Базовое логирование (что происходит с картой/транзакцией) — простой файловый/БД лог, без полноценного audit-comliance слоя.
- UI-полировка на Tailwind: состояния загрузки, ошибки, пустые состояния.
- **Результат:** законченный сквозной сценарий от `/start` до выпуска карты и просмотра истории, презентуемый как демо.

## Что сознательно оставляем за скобками MVP (задел на будущее, не делаем сейчас)

- KYC/верификация личности.
- Шифрование PII в БД, полноценный audit trail.
- Множественные роли/права, админка.
- Реальные деньги / выход из Lithic Sandbox.
- Retry/idempotency-ключи для вызовов Lithic (в sandbox не критично, но для прод-версии понадобится).

Эти пункты стоит держать в голове при проектировании слоёв (Service/Repository разделены так, чтобы потом было легко это добавить), но не реализовывать сразу.

## Верификация на каждом этапе

- Этап 0: `docker compose up -d --build`, зайти в контейнер postgres, проверить таблицы.
- Этап 1: реальная переписка с ботом в Telegram.
- Этап 2: открыть Mini App через Telegram (мобильный клиент или Telegram Desktop), проверить в Network-запросах, что initData валидируется.
- Этап 3: curl/Postman к тестовому backend-эндпоинту, проверка ответа Lithic Sandbox dashboard.
- Этап 4–5: сквозной ручной прогон в самом Mini App внутри Telegram.
- На каждом этапе — простые smoke-тесты (PHPUnit опционально, не обязателен для MVP, но полезен для Service-слоя, т.к. он не зависит от HTTP).
