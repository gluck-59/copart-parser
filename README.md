# Copart Parser — Telegram-бот

Telegram-бот, который парсит аукцион Copart по сохранённой ссылке поиска и присылает
подписчикам дайджест новых лотов. Парсер — Node.js + Playwright + реальный Chrome
(данные берутся из ответов браузера, обходя Incapsula).

дважды в сутки запускается плановый прогон: сбор → импорт в MySQL → рассылка подписчикам.
Дополнительно по команде `/parse` можно запустить неплановый поиск «прямо сейчас».

---

## Компоненты

| Компонент | Файл | Роль |
|---|---|---|
| Telegram-webhook | `public/bot.php` | Принимает апдейты Telegram (команды), ведёт подписки, режим ожидания ссылки, заявку на поиск. Отдаётся nginx → php84 |
| Схема БД | `schema.php` | Идемпотентное создание таблиц + миграции (`ensureSchema`) |
| Доступ к БД | `db.php` | `db(bool $fresh)` — PDO-соединение (singleton; `db(true)` пересоздаёт соединение) |
| Окружение | `env.php` | Загрузка `.env` (реальные env-переменные приоритетнее) |
| Telegram API | `telegram_api.php` | `apiRequest` / `apiRequestJson`, `tgLog` (пишет в `bot.log`) |
| Тексты | `messages.php` | Общие тексты ответов (`NO_FILTERS_TEXT`, `INVALID_URL`, `SETURL_PROMPT`, …) |
| Хранилище ссылки | `search_url.php` | `latestSearchUrl(PDO)` — последняя сохранённая ссылка поиска |
| Парсер | `scraper.js` | Node + Playwright + Chrome; сохраняет лоты в `output/copart_cars.json` |
| Импорт | `import.php` | `output/copart_cars.json` → таблица `lots` (upsert по `lot_number`) |
| Вотчер непланового поиска | `parse_watch.php` | Живёт в контейнере раннера, ловит триггер `output/parse.request`, гоняет сбор+импорт+дайджест |
| Рассылка дайджеста | `notify.php` | Дайджест новых лотов всем подписчикам, проставляет `send_at` |
| Рассылка «нет фильтров» | `notify_no_filters.php` | Плановый прогон без сохранённой ссылки |
| Формат дайджеста | `digest.php` | Сборка Rich Message (карточки лотов) |
| Оркестратор | `run.sh` | `cycle` (сбор+импорт) и `notify` (рассылка); создаёт/рестартит контейнер раннера |
| Локальный вход | `entrypoint.sh` | Dev-запуск сбора+импорта одной командой |

---

## Пользовательский флоу в Telegram

Бот работает только в приватных чатах. Команды обрабатывает `handleUpdate()`
(`public/bot.php`), апдейты статуса — `handleMyChatMember()`.

### `/start` — подписка
- Заводит/обновляет запись в `subscribers` (upsert по `user_id`).
- Шлёт приветствие + `HELP_TEXT` (`sendRich`), затем «Ваша подписка оформлена…».
- Если ссылка поиска ещё не задана — дополнительно `NO_FILTERS_TEXT . "\n" . SETURL_PROMPT`.

### `/seturl` — задать ссылку поиска
- Встаёт в режим ожидания ссылки: в `seturl_pending` пишется строка с `url = NULL` (это маркер).
- Отвечает `setUrlPromptText()`: если ссылка уже есть — «Текущий поиск: …», иначе `NO_FILTERS_TEXT`,
  затем `"\n\n"` + `SETURL_PROMPT`.

### Сообщение в режиме ожидания ссылки (не команда, когда маркер активен)
- Валидная ссылка (`looksLikeSearchUrl`: только `http/https`, есть хост с точкой, без переводов строк):
  сохраняется в последнюю строку-маркер (`url IS NULL` → `url`), ответ `SETURL_OK`.
- Невалидный ввод — **два отдельных сообщения**: сначала `NO_FILTERS_TEXT`,
  затем `INVALID_URL . "\n" . SETURL_PROMPT`. Маркер ожидания при этом сохраняется.
- Если ссылка валидна, но сохранить не удалось (нет активного маркера) — `SETURL_FAIL`.

### `/parse` — неплановый поиск «сейчас»
- Если ссылки нет — `NO_FILTERS_TEXT . "\n" . SETURL_PROMPT`, триггер не создаётся.
- Иначе пишется файл-триггер `output/parse.request` (содержимое — `chat_id`),
  ответ `PARSE_STARTED_TEXT` (при неудаче записи — `PARSE_FAIL_TEXT`).
- Дальше заявку обрабатывает `parse_watch.php` в контейнере раннера (см. ниже).

### `/stop` — отписка
- Удаляет `user_id` из `subscribers`, ответ «Подписка отменена.» / «Подписки не было.»

### Блокировка бота или выход из чата
- Telegram присылает апдейт `my_chat_member` с `new_chat_member.status` = `kicked` (блокировка)
  или `left`. Бот **тихо** (без сообщения — писать нельзя) удаляет `user_id` из `subscribers`.
- Удаление переписки в клиенте Telegram апдейтов не порождает — этот случай не отслеживается.

### Прочее
- Если маркер `/seturl` не активен — на любое сообщение отвечает справкой (`HELP_TEXT`).

---

## Жизненный цикл парсинга

### Плановый прогон (дважды в сутки)
`run.sh cycle` (prod):
1. Берёт ссылку из БД (`search_url.php`). Если пусто — `notify_no_filters.php` и выход без парсинга.
2. `ensure_runner` — создаёт контейнер `copart-parser-run` (или рестартит существующий).
   Cmd контейнера: прочитать ссылку из БД → `node scraper.js "$URL"` → `echo SCRAPER_DONE` → `php parse_watch.php`.
3. Ждёт появления `SCRAPER_DONE` в логах контейнера (до 10 минут).
4. Запускает `import.php` (запись лотов в `lots`).

`run.sh notify` — отдельно вызывает `notify.php` (рассылка дайджеста подписчикам).

### Неплановый поиск (`/parse`)
- Бот пишет триггер `output/parse.request`.
- `parse_watch.php` (постоянный процесс внутри раннера) в цикле раз в 5 секунд проверяет триггер:
  1. Читает `chat_id` из файла.
  2. Берёт свежее соединение к БД (`db(true)`) + `latestSearchUrl`.
  3. Если ссылки нет — шлёт `NO_FILTERS_TEXT + SETURL_PROMPT` и снимает триггер.
  4. Запускает `node scraper.js "<url>"`, затем `php import.php`.
  5. Выбирает неотправленные лоты (`send_at IS NULL`, лимит `DIGEST_LIMIT`) и шлёт `sendRichMessage` автору заявки, проставляет `send_at = NOW()`.
  6. Ошибки БД ловятся (`PDOException`) и логируются — процесс не падает.
- **Триггер снимается после любого ответа скрапера**: обрыв до ответа оставляет файл
  (заявка повторится после рестарта контейнера), ответ получен — файл удаляется (без ретраев).

### Отсутствие ссылки поиска
Во всех ветках (плановый прогон, `/start`, `/parse`, `parse_watch`) при отсутствии ссылки
отправляется `NO_FILTERS_TEXT + SETURL_PROMPT`, а парсинг не выполняется.

---

## Тонкости парсинга (`scraper.js`)

- **Реальный Chrome через CDP.** Playwright при запуске браузера ставит `navigator.webdriver = true`,
  и Incapsula режет API. Скрипт сам поднимает Chrome отдельным процессом
  (`--remote-debugging-port`, `--disable-blink-features=AutomationControlled`) и подключается через
  `chromium.connectOverCDP` → `webdriver=false`. Если Chrome не найден — фолбэк на запуск силами Playwright
  (Incapsula может резать).
- **Источник данных — ответы браузера.** Браузер уже прошёл Incapsula, поэтому `page.on("response")`
  перехватывает XHR/fetch JSON-ответы поиска: из них собираются номера лотов и кэшируются полные объекты лотов.
- **Первая навигация — сразу на `SEARCH_URL`.** Открытие `BASE_URL` первым заставляет SPA отрисовать
  пустую страницу (поисковый запрос не уходит). Дальше — ожидание `SESSION_WAIT_MS` и клик по кнопке поиска.
- **Детали лота тоже тянутся из контекста страницы** (`fetch` внутри `page.evaluate`): запрос уходит
  с cookies и заголовками браузера, поэтому Incapsula отвечает данными, а не челленджем. Перебираются
  кандидаты endpoint'ов `/public/data/lotdetails/...`; результат мержится с данными из результатов поиска.
- **Картинки.** Хранится только первое фото; превью `_thb.` заменяется на `_ful.`.
- **Пагинация — через интерфейс.** Клик по кнопке «next» в DOM (SPA сам делает запрос с валидной сессией),
  случайные задержки 1.2–2.6 с. Ограничители: `SEARCH_MAX_PAGES` (300) и `SEARCH_STALL_PAGES` (12) —
  остановка при отсутствии прироста.
- **Параллельная загрузка деталей** — `p-limit` (`CONCURRENCY`, по умолчанию 15).
- **CAPTCHA.** При `HEADLESS=false` можно решить проверку вручную в открытом окне — скрипт подождёт.
- **Прокси** отключен — `PROXY_URL` (например `socks5://127.0.0.1:9150` если у вас есть Tor browser).
- **Флаги Chrome для контейнера** — `CHROME_ARGS`: `--no-sandbox` (от root), `--disable-dev-shm-usage`
  (мал `/dev/shm`), ограничение памяти на слабом VPS.
- **URL поиска** — первый аргумент `node scraper.js "<url>"`; без аргумента используется `DEFAULT_SEARCH_URL`
  внутри скрипта. Домен определяется переданной ссылкой (бот хранит ссылку с `copart.es`).
- **Результат** — массив лотов пишется в `output/copart_cars.json`. Если лоты не собраны — скрипт падает с ненулевым кодом.

---

## Схема БД (`schema.php`)

Все таблицы создаются идемпотентно (`CREATE TABLE IF NOT EXISTS` + миграции по колонкам/индексам).

### `lots`
Ключ — `lot_number VARCHAR(16)`. Основные колонки: `vin`, `year`, `make`, `model`, `body_style`,
`engine`, `drive`, `fuel`, `damage`, `secondary_damage`, `title_type`, `has_keys`, `current_bid`,
`currency`, `location`, `odometer`, `odometer_unit`, `buy_it_now_price`, `item_url`; JSON-колонки:
`trim`, `color`, `transmission`, `build_sheet`, `full_model_name`, `estimated_retail_value`, `images`;
`raw LONGTEXT` — полный сырой объект лота; `added_at` (default `CURRENT_TIMESTAMP`); `send_at` (NULL = не отправлен).

### `subscribers`
`user_id BIGINT` (PK), `first_name`, `username`, `subscribed_at` (default `CURRENT_TIMESTAMP`).

### `seturl_pending`
`id BIGINT UNSIGNED` (PK, AUTO_INCREMENT), `user_id BIGINT`, `url VARCHAR(2048) NULL`
(**`NULL` = бот ждёт ссылку**), `requested_at` (default `CURRENT_TIMESTAMP`), индексы `idx_user`, `idx_requested`.
Последняя непустая ссылка (`latestSearchUrl`) — источник URL для парсера.

---

## Конфигурация

Секреты хранятся в `.env` (в git только `.env.example`).

| Переменная | Назначение |
|---|---|
| `TG_BOT_TOKEN` | токен Telegram-бота |
| `TG_WEBHOOK_SECRET` | секрет вебхука (заголовок `X-Telegram-Bot-Api-Secret-Token`) |
| `MYSQL_HOST` / `MYSQL_DB` / `MYSQL_USER` / `MYSQL_ROOT_PASSWORD` | подключение к БД |
| `ENVIROMENT` | `dev` (локальный Mac, docker compose) или `prod` (сервер) — для `run.sh` |
| `BASE_URL` | базовый домен Copart (по умолчанию `https://www.copart.com`) |
| `TARGET_LOTS` | сколько лотов собирать (по умолчанию 5000; в проде — 10) |
| `CONCURRENCY` | параллельных запросов деталей (по умолчанию 15) |
| `SEARCH_MAX_PAGES` | максимум страниц пагинации (300) |
| `SEARCH_STALL_PAGES` | страниц без прироста до остановки (12) |
| `HEADLESS` | `true` — браузер без UI |
| `SESSION_WAIT_MS` | ожидание после открытия страницы, мс (15000) |
| `PROXY_URL` | прокси браузерной сессии (`socks5://…`), пусто — напрямую |
| `BROWSER_CHANNEL` | канал Chrome (по умолчанию `chrome`) |
| `CHROME_ARGS` | доп. флаги запуска Chrome через пробел |
| `CDP_PORT` | порт отладочного протокола Chrome (9333) |
| `OUTPUT_FILE` | путь выходного JSON (по умолчанию `output/copart_cars.json`) |
| `DIGEST_LIMIT` | лимит лотов в одном дайджесте (`notify.php` — 3, `parse_watch.php` — 20) |
| `IMPORT_JSON` | путь входного JSON для `import.php` |

---

## Вебхук

- **Регистрация:** `GET https://copart.opengluck.ru/bot.php?setWebhook` — вызывает Telegram `setWebhook`
  с `allowed_updates = ['message', 'my_chat_member']` и `secret_token`, возвращает JSON.
- **Приём апдейтов:** `POST /bot.php`. При заданном `TG_WEBHOOK_SECRET` проверяется заголовок
  `X-Telegram-Bot-Api-Secret-Token`; при несовпадении — `403`.
- `allowed_updates` обязательно должен включать `my_chat_member`, иначе авто-отписка по блокировке не работает.
- nginx обслуживает `copart.opengluck.ru` → fastcgi `127.0.0.1:9084` → `public/bot.php`.

---

## Запуск и эксплуатация

### Локально (dev)
```
docker compose run --rm copart-parser   # сбор (entrypoint.sh: scraper → import)
docker compose up -d                    # MySQL + php84
docker compose down
```
Подключение к БД (Sequel Ace / любой MySQL-клиент):

| Параметр | Значение |
|---|---|
| Тип | Standard (TCP/IP) |
| Host | `localhost` |
| Port | `3306` |
| User | `root` (или `copart_parser`) |
| Password | `changeme` (если `MYSQL_ROOT_PASSWORD` не задан) |
| Database | `copart-parser` |

### Прод (сервер)
- Раннер `copart-parser-run` (образ `copart-parser:latest`, сеть `opengluck`, `TZ=Europe/Moscow`).
  В контейнер монтируются `output/`, `import.log`, `bot.log`, `.env`.
- `./run.sh cycle` — плановый сбор+импорт; `./run.sh notify` — рассылка.
- `ensure_runner` при существующем контейнере делает `docker restart` (тот же образ).
  **Чтобы подхватить новый образ после пересборки — контейнер нужно удалить (`docker rm -f copart-parser-run`)**
  и создать заново (`./run.sh cycle`).

### Обновление кода на сервере
1. `git pull` в `/var/www/copart-parser`.
2. Если изменились файлы вебхука (`public/bot.php`, `*.php` в корне, обслуживаемые php84) — достаточно pull;
   при смене `allowed_updates` заново вызвать `?setWebhook`.
3. Если изменились `parse_watch.php` / `db.php` (лежат **внутри образа** раннера) — пересобрать образ
   (`docker build -t copart-parser:latest .`) и пересоздать контейнер (см. выше).

---

## Формат вывода лота (`output/copart_cars.json`)

```json
{
  "lot_number": "12345678",
  "vin": "JTHBF5C2...",
  "year": 2015,
  "make": "Toyota",
  "model": "Camry",
  "trim": "LE",
  "full_model_name": "Toyota Camry LE",
  "color": "Silver",
  "transmission": "Automatic",
  "drive": "FWD",
  "fuel": "Gasoline",
  "odometer": 145000,
  "damage": "Front End",
  "location": "Los Angeles, CA, 90001",
  "buy_it_now_price": 8500,
  "estimated_retail_value": 12000,
  "item_url": "https://www.copart.com/lot/12345678",
  "images": ["https://cs.copart.com/...jpg"]
}
```

`import.php` раскладывает объект по колонкам `lots` (upsert по `lot_number`; `added_at` не меняется),
а исходный объект сохраняет в `raw` (в JSON-файле сырой объект лежит под ключом `raw_data`).

---

## Зависимости

- [playwright](https://playwright.dev) — управление браузером, подключение к Chrome по CDP
- [p-limit](https://github.com/sindresorhus/p-limit) — ограничение конкуренции при загрузке деталей

Серверная часть — PHP 8.4 (`php8.4-cli`, `php8.4-mysql`, `php8.4-curl`) и MySQL.

---

## Известные ограничения

- Incapsula может не отдать ни одного лота — тогда прогон падает; помогают повтор или запуск без `HEADLESS`.
- Удаление переписки в Telegram не отслеживается (апдейтов нет); отписка — по `/stop` или блокировке.
- Триггер `/parse` — одиночный файл `output/parse.request`: одновременные заявки разных пользователей
  перезаписывают друг друга. Планируется перенос заданий на парсинг в БД (см. `TODO.md`).
