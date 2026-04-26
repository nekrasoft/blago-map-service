# Карта бункеров — БлагоСервис

Веб-сервис для размещения и управления бункерами (контейнерами 8 м³) на карте Яндекс.Карт.

## Возможности

- Отображение бункеров на карте с цветовой индикацией заполненности
- Информация о бункере по клику (контрагент, адрес, объём, заполненность, дата вывоза, телефон)
- Перетаскивание маркеров для изменения координат (автосохранение + опциональное обратное геокодирование адреса)
- Опциональное геокодирование адреса из формы редактирования (Enter в поле «Адрес»)
- Фильтрация по району, типу мусора и контрагенту (с количеством)
- Добавление, редактирование и удаление бункеров
- Роль контрагента: пользователь видит только свои бункеры и может отметить бункер заполненным прямо из балуна (при `district_scope` — только по своим районам)
- Интеграция с MAX: при `mark-filled` можно отправлять уведомление в чат заявок
- Хранение данных в MySQL

## Стек

- **Бэкенд:** PHP (единственный файл `api.php`)
- **Фронтенд:** Vanilla JS + Яндекс.Карты API 2.1
- **БД:** MySQL (`bunkers`, `counterparty_users`, `counterparties`)
- **Важно:** в PHP должно быть включено расширение `pdo_mysql`
- **Логи API:** `logs/api-error.log` (или путь из `APP_LOG_FILE`)

## Установка на хостинг (Beget, поддомен map.blagokirov.ru)

1. Создайте поддомен `map.site.ru` в панели вашего хостера
2. Загрузите файлы в `public_html` поддомена
3. Скопируйте `.env.example` в `.env`:
   ```bash
   cp .env.example .env
   ```
4. Если `config.php` отсутствует, создайте его из примера:
   ```bash
   cp config.example.php config.php
   ```
5. Создайте базу и пользователя MySQL:
   ```sql
   CREATE DATABASE map_service CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'map_service'@'localhost' IDENTIFIED BY '...';
   GRANT ALL ON map_service.* TO 'map_service'@'localhost';
   ```
6. Заполните в `.env`:
   - `YANDEX_MAPS_API_KEY`
   - `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`
   - `ADMIN_PASSWORD_HASH` (и опционально demo-учётку)
   - `MAP_BOT_API_KEY` (опционально, бот-запись для `mark-filled`)
   - `MAP_BOT_READ_API_KEY` (опционально, read-only доступ бота к `GET /api/bunkers` и `GET /api/counterparties`)
   - `MAP_BOT_ALLOWED_IPS` (опционально, IP allowlist для bot-ключей)
   - `MAX_BOT_TOKEN`, `MAX_REQUEST_CHAT_ID` (опционально, уведомления в чат MAX)
7. При первом запуске API:
   - автоматически создаст таблицу `bunkers`;
   - автоматически создаст таблицу `counterparty_users`;
   - автоматически импортирует данные из `data/bunkers.json`, если таблица пуста.
8. Пользователей-контрагентов добавляйте в таблицу `counterparty_users` (через админку cp):
   - `login` — логин пользователя
   - `password_hash` — хеш пароля (`password_hash`)
   - `counterparty_id` — ID контрагента из `counterparties.id`
   - `district_scope` — опциональный фильтр районов (одно или несколько значений через запятую, например `Инноград, Знак`)
   - `is_active` — активность учётки
9. Если нужен legacy-источник для первого запуска, оставьте `data/bunkers.json` рядом с проектом.
   ```bash
   chmod 644 data/bunkers.json
   ```

## REST API

| Метод  | Путь               | Описание                                                      |
|--------|---------------------|---------------------------------------------------------------|
| GET    | /api/config         | Конфигурация (API-ключ карт)                                  |
| GET    | /api/counterparties | Справочник контрагентов (`id`, `shortName`, `name`, `schedule`) |
| GET    | /api/bunkers        | Список бункеров (?district=...&wasteType=...&contractor=...&counterpartyId=...) |
| POST   | /api/bunkers        | Создание бункера                                              |
| POST   | /api/bunkers/:id/mark-filled | Отметить бункер заполненным (fillLevel=100, сохраняет кто/когда, отправляет MAX-уведомление при настройке) |
| PUT    | /api/bunkers/:id    | Обновление бункера                                            |
| DELETE | /api/bunkers/:id    | Удаление бункера                                              |

- Для `bunkers` поддерживается `counterpartyId` (FK на `counterparties.id`), при этом поле `contractor` сохранено для обратной совместимости и отдаётся как `short_name` при наличии связи.
- Для пользователей-контрагентов `GET /api/bunkers` принудительно ограничивается их `counterpartyId`, а при заданном `district_scope` — ещё и районами; обычные CRUD-операции недоступны, кроме `mark-filled`.

### Авторизация ботов

- Read-only бот: задайте `MAP_BOT_READ_API_KEY`; ключ передаётся в `X-API-Key` или `Authorization: Bearer <token>`.
- Бот для записи `mark-filled`: задайте `MAP_BOT_API_KEY`; ключ также принимается через `X-API-Key` или `Authorization: Bearer <token>`.
- `MAP_BOT_API_KEY` автоматически даёт и read-доступ к `GET /api/bunkers` и `GET /api/counterparties`.
- Для дополнительной защиты можно задать `MAP_BOT_ALLOWED_IPS` (список IP через запятую): тогда bot-ключи работают только с этих адресов.

## Структура проекта

```
map-service/
  .htaccess              — URL-rewriting для /api/*
  index.html             — главная страница
  api.php                — REST API (PHP + MySQL)
  config.php             — настройки авторизации (читает env)
  config.example.php     — пример настроек авторизации
  data/bunkers.json      — legacy-данные для одноразового импорта
  css/styles.css         — стили
  js/api.js              — обёртка над REST API
  js/app.js              — логика карты и интерфейса
  _node_version/         — бэкап Node.js-версии
```
