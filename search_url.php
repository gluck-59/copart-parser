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

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    echo latestSearchUrl(db()) ?? '';
}
