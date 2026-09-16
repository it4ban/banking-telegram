# Telegram Banking Mini App

Проект Telegram Mini App с фронтендом на Vue + Vite и бэкендом на PHP, запускается через Docker.

## Запуск проекта

```bash
docker compose up -d --build
```

Фронтенд (Vite) будет доступен на `http://localhost:5173`.

---

## Публичная HTTPS-ссылка для Telegram (Cloudflare Tunnel)

Telegram Mini App требует публичный HTTPS-адрес. Для разработки используем бесплатный
Cloudflare Tunnel, который пробрасывает локальный Vite (`localhost:5173`) в интернет.

### Установка cloudflared

**Windows** (через winget):

```powershell
winget install -e --id Cloudflare.cloudflared
```

**macOS** (через Homebrew):

```bash
brew install cloudflared
```

### Генерация ссылки

Запусти туннель (контейнеры должны быть подняты):

```bash
cloudflared tunnel --url http://localhost:5173
```

В выводе появится строка вида:

```
https://random-words-1234.trycloudflare.com
```

Эту ссылку вставь в [@BotFather](https://t.me/BotFather):
**Bot Settings → Configure Mini App → Edit Web App URL**.

> При каждом новом запуске туннеля адрес меняется — просто обнови URL в BotFather.

---

## Полезные команды

| Действие               | Команда                                          |
| ---------------------- | ------------------------------------------------ |
| Поднять контейнеры     | `docker compose up -d --build`                   |
| Остановить контейнеры  | `docker compose down`                            |
| Перезапустить фронтенд | `docker compose restart nodejs`                  |
| Установить npm-пакет   | `docker compose exec nodejs npm install <пакет>` |
| Логи фронтенда         | `docker compose logs -f nodejs`                  |
