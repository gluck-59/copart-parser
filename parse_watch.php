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
require_once __DIR__ . '/digest.php';

chdir(__DIR__);

$pdo = db();
ensureSchema($pdo);

$trigger = __DIR__ . '/output/parse.request';

$lock = fopen('/tmp/parse_watch.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "parse_watch: уже запущен\n");
    exit(1);
}

$digestLimit = (int) (getenv('DIGEST_LIMIT') ?: 20);
//if ($digestLimit < 1) {
//    $digestLimit = 3;
//}

function pwSendText(int $chatId, string $text): void
{
    apiRequestJson('sendMessage', [
        'chat_id'              => $chatId,
        'text'                 => $text,
        'parse_mode'           => 'HTML',
        'link_preview_options' => ['is_disabled' => true],
    ]);
}

function pwSendRich(int $chatId, string $html): void
{
    apiRequestJson('sendRichMessage', [
        'chat_id'      => $chatId,
        'rich_message' => ['html' => $html],
    ]);
}

while (true) {
    if (!is_file($trigger)) {
        sleep(5);
        continue;
    }

    $chatId = (int) trim((string) file_get_contents($trigger));
    @unlink($trigger);

    if ($chatId <= 0) {
        tgLog('parse_watch: пустой user_id в триггере');
        sleep(5);
        continue;
    }

    tgLog('parse_watch: запуск сбора user_id=' . $chatId);

    exec('node scraper.js >> import.log 2>&1', $out, $scrapeCode);

    if ($scrapeCode !== 0) {
        pwSendText($chatId, 'Не удалось выполнить поиск, попробуйте позже.');
        tgLog('parse_watch: сбор не удался, код ' . $scrapeCode . ' user_id=' . $chatId);
        sleep(5);
        continue;
    }

    exec('php import.php >> import.log 2>&1', $out2, $importCode);

    if ($importCode !== 0) {
        pwSendText($chatId, 'Не удалось выполнить поиск, попробуйте позже.');
        tgLog('parse_watch: импорт не удался, код ' . $importCode . ' user_id=' . $chatId);
        sleep(5);
        continue;
    }

    $lots = $pdo
        ->query(
            'SELECT lot_number, make, model, year, buy_it_now_price, item_url, images,
                    odometer, odometer_unit, damage, secondary_damage, title_type,
                    has_keys, current_bid, currency, location,
                    JSON_EXTRACT(raw, \'$.ad\') AS auction_ms
               FROM lots
              WHERE send_at IS NULL
              ORDER BY added_at, lot_number
              LIMIT ' . $digestLimit
        )
        ->fetchAll();

    if ($lots === []) {
        pwSendText($chatId, 'Новых лотов по вашему поиску нет.');
        tgLog('parse_watch: новых лотов нет user_id=' . $chatId);
        sleep(5);
        continue;
    }

    $html = buildDigestRichMessage($lots);
    $result = apiRequestJson('sendRichMessage', [
        'chat_id'      => $chatId,
        'rich_message' => ['html' => $html],
    ]);

    if ($result !== false) {
        $numbers = array_map(static fn (array $lot): string => (string) $lot['lot_number'], $lots);
        $placeholders = rtrim(str_repeat('?,', count($numbers)), ',');
        $pdo->prepare("UPDATE lots SET send_at = NOW() WHERE lot_number IN ($placeholders)")->execute($numbers);
        tgLog('parse_watch: дайджест доставлен user_id=' . $chatId . ' лотов=' . count($numbers));
    } else {
        tgLog('parse_watch: дайджест не доставлен user_id=' . $chatId);
    }

    sleep(5);
}