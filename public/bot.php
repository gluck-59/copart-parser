<?php
declare(strict_types=1);

/**
 * Telegram webhook бота Copart Parser.
 *
 *   GET  bot.php?setWebhook  — регистрация вебхука
 *   POST bot.php             — апдейты от Telegram (отдаётся в web-root public/)
 *
 * Регистрация:
 *   curl "https://copart.opengluck.ru/bot.php?setWebhook"
 */

require_once __DIR__ . '/../telegram_api.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../schema.php';

define('WEBHOOK_URL', 'https://copart.opengluck.ru/bot.php');

const HELP_TEXT =
    'Я присылаю новые лоты Copart.\n' .
    '/start — подписаться на оповещения\n' .
    '/stop — отписаться';

function sendText(int $chatId, string $text): void
{
    apiRequestJson('sendMessage', [
        'chat_id' => $chatId,
        'text'    => $text,
    ]);
}

function subscribe(int $chatId, array $from): void
{
    $pdo = db();
    ensureSchema($pdo);

    $st = $pdo->prepare('SELECT 1 FROM subscribers WHERE user_id = ?');
    $st->execute([$chatId]);
    $already = (bool) $st->fetchColumn();

    $st = $pdo->prepare(
        'INSERT INTO subscribers (user_id, first_name, username)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), username = VALUES(username)'
    );
    $st->execute([
        $chatId,
        isset($from['first_name']) ? (string) $from['first_name'] : null,
        isset($from['username']) ? (string) $from['username'] : null,
    ]);

    sendText(
        $chatId,
        $already
            ? 'Вы уже подписаны.'
            : 'Подписка оформлена. Буду присылать новые лоты Copart.'
    );

    tgLog('подписка user_id=' . $chatId . ($already ? ' (повторно)' : ' (новая)'));
}

function unsubscribe(int $chatId): void
{
    $pdo = db();
    ensureSchema($pdo);

    $st = $pdo->prepare('DELETE FROM subscribers WHERE user_id = ?');
    $st->execute([$chatId]);
    $removed = $st->rowCount() > 0;

    sendText($chatId, $removed ? 'Подписка отменена.' : 'Подписки не было.');

    tgLog('отписка user_id=' . $chatId . ($removed ? '' : ' (нет подписки)'));
}

function handleUpdate(array $message): void
{
    $chat = $message['chat'] ?? [];
    $chatId = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';

    if ($chatId === null || $chatType !== 'private') {
        return;
    }

    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        return;
    }

    $parts = preg_split('/[\s@]+/u', $text, 2);
    $command = strtolower($parts[0] ?? '');

    switch ($command) {
        case '/start':
            subscribe((int) $chatId, $message['from'] ?? []);
            break;

        case '/stop':
            unsubscribe((int) $chatId);
            break;

        default:
            sendText((int) $chatId, HELP_TEXT);
            tgLog('прочее сообщение user_id=' . $chatId . ' text=' . mb_substr($text, 0, 50));
    }
}

// ---------------------------------------------------------------------------

// Регистрация вебхука
if (!empty($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] === 'setWebhook') {
    $params = [
        'url'             => WEBHOOK_URL,
        'allowed_updates' => ['message'],
    ];

    if (TG_WEBHOOK_SECRET !== '') {
        $params['secret_token'] = TG_WEBHOOK_SECRET;
    }

    $result = apiRequest('setWebhook', $params);

    tgLog('setWebhook url=' . WEBHOOK_URL . ' => ' . var_export($result, true));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'     => $result !== false,
        'result' => $result,
        'url'    => WEBHOOK_URL,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверка secret_token из заголовка X-Telegram-Bot-Api-Secret-Token
if (TG_WEBHOOK_SECRET !== '') {
    $received = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

    if (!hash_equals(TG_WEBHOOK_SECRET, $received)) {
        http_response_code(403);
        tgLog('webhook: отклонён запрос с неверным secret token');
        exit;
    }
} else {
    tgLog('webhook: webhook_secret не задан в token.php — проверка не выполняется');
}

$update = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($update) || !isset($update['message'])) {
    // прочие апдейты игнорируем
    exit;
}

handleUpdate($update['message']);
exit;
