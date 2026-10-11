<?php
declare(strict_types=1);

/**
 * Watcher внепланового поиска (/parse).
 *
 * Живёт в контейнере copart-parser-run (там есть node и php8.4-cli).
 * Ждёт триггер-файл output/parse.request (пишет бот из php84), затем:
 *   node scraper.js -> php import.php -> дайджест автору заявки -> send_at = NOW().
 *
 * Запуск (Cmd контейнера):
 *   sh -c "node scraper.js && echo SCRAPER_DONE && php /app/parse_watch.php"
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/search_url.php';

chdir(__DIR__);

$pdo = db();
ensureSchema($pdo);

$trigger = __DIR__ . '/output/parse.request';

$lock = fopen('/tmp/parse_watch.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "parse_watch: уже запущен\n");
    exit(1);
}

/**
 * Перечитывает .env заново (в отличие от env.php без статического guard)
 * и перезаписывает переменные окружения. Вызывается перед каждым запуском
 * парсера, чтобы правки .env применялись без перезапуска контейнера.
 */
function refreshEnv(): void
{
    $content = @file_get_contents(__DIR__ . '/.env');
    if ($content === false) {
        return;
    }

    foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false || $pos === 0) {
            continue;
        }

        $name = trim(substr($line, 0, $pos));
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            continue;
        }

        $value = trim(substr($line, $pos + 1));
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' || $first === "'") && $last === $first) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

while (true) {
    if (!is_file($trigger)) {
        sleep(5);
        continue;
    }

    // Содержимое триггера больше не адресат: рассылка идёт всем подписчикам
    // через notify.php. Важен факт заявки; пустой файл считаем мусором.
    if (trim((string) file_get_contents($trigger)) === '') {
        @unlink($trigger);
        tgLog('parse_watch: пустой триггер, пропущен');
        sleep(5);
        continue;
    }

    // Правки .env применяются перед каждым запуском парсера, без рестарта контейнера.
    refreshEnv();

    // Долгоживущий процесс: берём свежее соединение перед каждой заявкой, иначе
    // MySQL закрывает простаивавшее соединение (wait_timeout) и запрос падает с
    // 2006 "MySQL server has gone away".
    try {
        $pdo = db(true);
        ensureSchema($pdo);
        $searchUrl = latestSearchUrl($pdo);
    } catch (PDOException $e) {
        tgLog('parse_watch: ошибка БД: ' . $e->getMessage());
        sleep(5);
        continue;
    }

    if ($searchUrl === null) {
        @unlink($trigger);
        tgLog('parse_watch: ссылка поиска не задана');
        sleep(5);
        continue;
    }

    tgLog('parse_watch: запуск сбора');

    exec('node scraper.js ' . escapeshellarg($searchUrl) . ' >> import.log 2>&1', $out, $scrapeCode);

    // Заявку снимаем после любого ответа скрапера: обрыв до ответа оставляет
    // триггер (повторится после рестарта), ответ получен — снимаем без ретраев.
    @unlink($trigger);

    if ($scrapeCode !== 0) {
        tgLog('parse_watch: сбор не удался, код ' . $scrapeCode);
        sleep(5);
        continue;
    }

    exec('php import.php >> import.log 2>&1', $out2, $importCode);

    if ($importCode !== 0) {
        tgLog('parse_watch: импорт не удался, код ' . $importCode);
        sleep(5);
        continue;
    }

    // Рассылка всем подписчикам — общий notify.php.
    exec('php notify.php >> import.log 2>&1', $out3, $notifyCode);

    tgLog('parse_watch: парсинг завершён, рассылка код ' . $notifyCode);

    sleep(5);
}