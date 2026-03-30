# Карта бункеров — БлагоСервис

Веб-сервис для размещения и управления бункерами (контейнерами 8 м³) на карте Яндекс.Карт.

## Возможности

- Отображение бункеров на карте с цветовой индикацией заполненности
- Информация о бункере по клику (контрагент, адрес, объём, заполненность, дата вывоза, телефон)
- Перетаскивание маркеров для изменения координат (автосохранение + обратное геокодирование адреса)
- Геокодирование адреса из формы редактирования (Enter в поле «Адрес»)
- Фильтрация по району, типу мусора и контрагенту (с количеством)
- Добавление, редактирование и удаление бункеров
- Хранение данных в MySQL

## Стек

- **Бэкенд:** PHP (единственный файл `api.php`)
- **Фронтенд:** Vanilla JS + Яндекс.Карты API 2.1
- **БД:** MySQL (`bunkers`)
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
7. При первом запуске API:
   - автоматически создаст таблицу `bunkers`;
   - автоматически импортирует данные из `data/bunkers.json`, если таблица пуста.
8. Если нужен legacy-источник для первого запуска, оставьте `data/bunkers.json` рядом с проектом.
   ```bash
   chmod 644 data/bunkers.json
   ```

## REST API

| Метод  | Путь               | Описание                                                      |
|--------|---------------------|---------------------------------------------------------------|
| GET    | /api/config         | Конфигурация (API-ключ карт)                                  |
| GET    | /api/counterparties | Справочник контрагентов (`id`, `shortName`, `name`)           |
| GET    | /api/bunkers        | Список бункеров (?district=...&wasteType=...&contractor=...&counterpartyId=...) |
| POST   | /api/bunkers        | Создание бункера                                              |
| PUT    | /api/bunkers/:id    | Обновление бункера                                            |
| DELETE | /api/bunkers/:id    | Удаление бункера                                              |

- Для `bunkers` поддерживается `counterpartyId` (FK на `counterparties.id`), при этом поле `contractor` сохранено для обратной совместимости и отдаётся как `short_name` при наличии связи.

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
