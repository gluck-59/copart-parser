<?php
declare(strict_types=1);

/**
 * Рассылка дайджеста с новыми лотами Copart всем подписчикам.
 *
 * Запускается после импорта:
 *   docker exec -e MYSQL_HOST=mysql84 -e MYSQL_USER=root -e MYSQL_ROOT_PASSWORD=... \
 *     php84 php /var/www/copart-parser/notify.php
 *
 * Логика:
 *   - берём лоты, у которых send_at IS NULL;
 *   - если подписчиков нет — лоты не отправляем и не помечаем;
 *   - шлём один дайджест-Rich Message каждому подписчику;
 *   - при успешной доставке хотя бы одному подписчику помечаем лоты send_at = NOW().
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/digest.php';

$pdo = db();
ensureSchema($pdo);

$subscribers = $pdo
    ->query('SELECT user_id, first_name, username FROM subscribers ORDER BY subscribed_at, user_id')
    ->fetchAll();

$digestLimit = (int) (getenv('DIGEST_LIMIT') ?: 3);
if ($digestLimit < 1) {
    $digestLimit = 3;
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

printf(
    "notify: подписчиков %d, неотправленных лотов %d\n",
    count($subscribers),
    count($lots)
);

if ($lots === []) {
    echo "notify: новых лотов нет\n";
    exit(0);
}

if ($subscribers === []) {
    echo "notify: подписчиков нет — лоты оставлены неотправленными (send_at не менялся)\n";
    exit(0);
}

$html = buildDigestRichMessage($lots);

$delivered = 0;

foreach ($subscribers as $subscriber) {
    $chatId = (int) $subscriber['user_id'];

    $result = apiRequestJson('sendRichMessage', [
        'chat_id'      => $chatId,
        'rich_message' => ['html' => $html],
    ]);

    if ($result !== false) {
        $delivered++;
        tgLog("дайджест доставлен user_id={$chatId}");
    }
}

if ($delivered === 0) {
    fwrite(STDERR, "notify: сообщение не доставлено ни одному подписчику, лоты не отмечены\n");
    exit(1);
}

$numbers = array_map(static fn (array $lot): string => (string) $lot['lot_number'], $lots);
$placeholders = rtrim(str_repeat('?,', count($numbers)), ',');

$st = $pdo->prepare("UPDATE lots SET send_at = NOW() WHERE lot_number IN ($placeholders)");
$st->execute($numbers);

printf(
    "notify: дайджест отправлен %d из %d подписчиков, отмечено лотов %d\n",
    $delivered,
    count($subscribers),
    $st->rowCount()
);
