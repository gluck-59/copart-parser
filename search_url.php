<?php
declare(strict_types=1);

/**
 * Хранилище ссылки поиска (seturl_pending) и выбор последней.
 *
 * Используется ботом (/seturl) и воркерами, которые запускают scraper.js.
 * При прямом запуске из CLI печатает последнюю сохранённую ссылку (или пусто).
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

/** Последняя сохранённая ссылка поиска или null. */
function latestSearchUrl(PDO $pdo): ?string
{
    ensureSchema($pdo);

    $url = $pdo
        ->query(
            'SELECT url FROM seturl_pending
              WHERE url IS NOT NULL AND url <> \'\'
              ORDER BY requested_at DESC, id DESC
              LIMIT 1'
        )
        ->fetchColumn();

    return is_string($url) && $url !== '' ? $url : null;
}

/**
 * Автоматический запуск парсинга (cron): проверяет ссылку и подписчиков,
 * при их отсутствии выходит без парсинга, иначе создаёт триггер для parse_watch.
 */
function autoRequestParse(): void
{
    $pdo = db();
    ensureSchema($pdo);

    if (latestSearchUrl($pdo) === null) {
        echo "auto: ссылка поиска не задана — пропуск\n";
        return;
    }

    $subs = (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();

    if ($subs === 0) {
        echo "auto: подписчиков нет — пропуск\n";
        return;
    }

    $ok = @file_put_contents(__DIR__ . '/output/parse.request', 'cron', LOCK_EX) !== false;

    echo $ok
        ? "auto: заявка создана (подписчиков {$subs})\n"
        : "auto: не удалось записать триггер\n";
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argv[1] ?? '') === 'auto') {
        autoRequestParse();
    } else {
        echo latestSearchUrl(db()) ?? '';
    }
}
