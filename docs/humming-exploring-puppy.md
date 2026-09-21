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

- `users`: id, telegram_id (unique), username, first_name, created_at
- `cards`: id, user_id (FK), lithic_card_token (id карты в Lithic), last_four, state (OPEN/PAUSED/CLOSED), spend_limit, created_at
- `transactions`: id, card_id (FK), lithic_transaction_token, amount, merchant, status, created_at

Миграции — простые `.sql`-файлы, применяются вручную/скриптом `php backend/Database/migrate.php` (без миграционного фреймворка, чтобы не тащить лишнее).

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
