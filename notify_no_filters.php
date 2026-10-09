<?php
declare(strict_types=1);

/**
 * Рассылка «фильтры не заданы» всем подписчикам.
 *
 * Запускается, когда наступил плановый прогон, но ссылка поиска ещё не сохранена:
 *   php /var/www/copart-parser/notify_no_filters.php
 * Парсинг в этом случае не выполняется.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/messages.php';

$pdo = db();
ensureSchema($pdo);

$subscribers = $pdo
    ->query('SELECT user_id FROM subscribers ORDER BY subscribed_at, user_id')
    ->fetchAll();

$text = NO_FILTERS_TEXT . "\n" . SETURL_PROMPT;

$delivered = 0;

foreach ($subscribers as $subscriber) {
    $chatId = (int) $subscriber['user_id'];

    $result = apiRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => $text,
    ]);

    if ($result !== false) {
        $delivered++;
        tgLog("нет фильтров: уведомление доставлено user_id={$chatId}");
    }
}

printf("notify_no_filters: доставлено %d из %d подписчиков\n", $delivered, count($subscribers));

if ($subscribers !== [] && $delivered === 0) {
    exit(1);
}
