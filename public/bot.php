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
require_once __DIR__ . '/../messages.php';
require_once __DIR__ . '/../search_url.php';

define('WEBHOOK_URL', 'https://copart.opengluck.ru/bot.php');

const HELP_TEXT =
    '<p>Я бот Копарс, умею парсить Копарт и присылать подходящие лоты в Телеграм. Подходящие ищу раз в сутки по ссылке, которую вы покажете мне позднее.</p>'
    . '<footer><a href="https://t.me/motokofr">Мой автор</a> будет благодарен за пару ящиков вкусного темного.</footer>';

const PARSE_STARTED_TEXT = 'Поиск начался, он займет от нескольких секунд до нескольких минут. Я пришлю вам лоты если они найдутся.';
const PARSE_FAIL_TEXT = '⚠️ Не удалось запустить поиск, попробуйте позже.';

function sendText(int $chatId, string $text): void
{
    apiRequestJson('sendMessage', [
        'chat_id'              => $chatId,
        'text'                 => $text,
        'parse_mode'           => 'HTML',
        'link_preview_options' => ['is_disabled' => true],
    ]);
}

function sendRich(int $chatId, string $html): void
{
    apiRequestJson('sendRichMessage', [
        'chat_id'      => $chatId,
        'rich_message' => ['html' => $html],
    ]);
}

function setUrlPromptText(): string
{
    $searchUrl = latestSearchUrl(db());

    $prefix = $searchUrl !== null
        ? 'Текущий поиск: ' . htmlspecialchars($searchUrl, ENT_QUOTES, 'UTF-8')
        : NO_FILTERS_TEXT;

    return $prefix . "\n\n" . SETURL_PROMPT;
}

/** Заявка на внеплановый поиск: пишем триггер-файл для раннера. */
function requestParse(int $chatId): void
{
    if (latestSearchUrl(db()) === null) {
        sendText($chatId, NO_FILTERS_TEXT . "\n" . SETURL_PROMPT);
        tgLog('parse: ссылка поиска не задана user_id=' . $chatId);
        return;
    }

    $trigger = dirname(__DIR__) . '/output/parse.request';

    $ok = @file_put_contents($trigger, (string) $chatId, LOCK_EX) !== false;

    sendText($chatId, $ok ? PARSE_STARTED_TEXT : PARSE_FAIL_TEXT);
    tgLog('parse: ' . ($ok ? 'заявка' : 'не удалось записать триггер') . ' user_id=' . $chatId);
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

    $first_name = isset($from['first_name']) ? (string) $from['first_name'] : null;
    $username = isset($from['username']) ? (string) $from['username'] : null;
    $st->execute([
        $chatId,
        $first_name,
        $username,
    ]);

    sendRich(
        $chatId,
        $already
            ? 'Вы уже подписаны.'
            : 'Привет '.$first_name.'! '.HELP_TEXT
    );
    sendText($chatId, 'Ваша подписка оформлена. Я буду присылать вам новые лоты по расписанию. Расписание можно обсудить <a href="https://t.me/motokofr">с моим автором</a>, а изменить фильтры поиска — через меню.');

    if (latestSearchUrl($pdo) === null) {
        sendText($chatId, NO_FILTERS_TEXT . "\n" . SETURL_PROMPT);
    }

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

/**
 * Апдейт my_chat_member: изменение статуса бота в приватном чате.
 * kicked — юзер заблокировал бота, left — покинул чат; в обоих случаях
 * тихо снимаем подписку (писать юзеру нельзя — он ушёл/заблокировал).
 */
function handleMyChatMember(array $member): void
{
    $chat = $member['chat'] ?? [];

    if (($chat['type'] ?? '') !== 'private') {
        return;
    }

    $userId = (int) ($chat['id'] ?? 0);
    $status = (string) ($member['new_chat_member']['status'] ?? '');

    if ($userId <= 0 || !in_array($status, ['kicked', 'left'], true)) {
        return;
    }

    $pdo = db();
    ensureSchema($pdo);

    $st = $pdo->prepare('DELETE FROM subscribers WHERE user_id = ?');
    $st->execute([$userId]);
    $removed = $st->rowCount() > 0;

    tgLog('my_chat_member: ' . $status . ' user_id=' . $userId . ($removed ? ' (отписан)' : ' (нет подписки)'));
}

/** Запоминаем, что от user_id ждём ссылку на поиск Copart (маркер: url IS NULL). */
function armSetUrl(int $chatId): void
{
    $pdo = db();
    ensureSchema($pdo);

    $pdo->prepare('INSERT INTO seturl_pending (user_id, url) VALUES (?, NULL)')
        ->execute([$chatId]);
}

function isSetUrlArmed(int $chatId): bool
{
    $pdo = db();
    ensureSchema($pdo);

    $st = $pdo->prepare('SELECT 1 FROM seturl_pending WHERE user_id = ? AND url IS NULL LIMIT 1');
    $st->execute([$chatId]);

    return (bool) $st->fetchColumn();
}

/**
 * Пригодна ли строка как ссылка поиска: только http/https с реальным хостом
 * и без переводов строк.
 */
function looksLikeSearchUrl(string $text): bool
{
    if ($text === '' || preg_match('/[\r\n]/', $text) === 1) {
        return false;
    }

    $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($text, PHP_URL_HOST));

    return in_array($scheme, ['http', 'https'], true)
        && $host !== ''
        && str_contains($host, '.');
}

/** Сохраняет валидную ссылку в маркер ожидания (url IS NULL → url). true — запись прошла. */
function saveSearchUrl(int $chatId, string $url): bool
{
    $pdo = db();
    ensureSchema($pdo);

    $st = $pdo->prepare(
        'UPDATE seturl_pending SET url = ?, requested_at = NOW()
          WHERE user_id = ? AND url IS NULL'
    );
    $st->execute([$url, $chatId]);

    return $st->rowCount() > 0;
}

/** Обработка вставленной после /seturl ссылки. true — ответ уже отправлен. */
function handleSetUrlInput(int $chatId, string $text): bool
{
    if (!looksLikeSearchUrl($text)) {
        sendText($chatId, INVALID_URL);
        tgLog('seturl: невалидная ссылка user_id=' . $chatId . ' text=' . mb_substr($text, 0, 50));

        return true;
    }

    if (!saveSearchUrl($chatId, $text)) {
        sendText($chatId, SETURL_FAIL);
        tgLog('seturl: неудача сохранения user_id=' . $chatId . ' text=' . mb_substr($text, 0, 50));

        return true;
    }

    sendText($chatId, SETURL_OK);
    tgLog('seturl: сохранено user_id=' . $chatId . ' url=' . mb_substr($text, 0, 120));

    return true;
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

        case '/seturl':
            armSetUrl((int) $chatId);
            sendText((int) $chatId, setUrlPromptText());
            tgLog('seturl: жду ссылку user_id=' . $chatId);
            break;

        case '/parse':
            requestParse((int) $chatId);
            break;

        default:
            if (isSetUrlArmed((int) $chatId)) {
                handleSetUrlInput((int) $chatId, $text);
                break;
            }

            $firstName = (string) ($message['from']['first_name'] ?? '');
            $greeting = $firstName !== ''
                ? '<p>Привет, ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '!</p>'
                : '';
            sendRich((int) $chatId, $greeting . HELP_TEXT);
            tgLog('прочее сообщение user_id=' . $chatId . ' text=' . mb_substr($text, 0, 50));
    }
}

// ---------------------------------------------------------------------------

// Регистрация вебхука
if (!empty($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] === 'setWebhook') {
    $params = [
        'url'             => WEBHOOK_URL,
        'allowed_updates' => ['message', 'my_chat_member'],
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
    tgLog('webhook: webhook_secret не задан в .env — проверка не выполняется');
}

$update = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($update)) {
    exit;
}

if (isset($update['message'])) {
    handleUpdate($update['message']);
} elseif (isset($update['my_chat_member'])) {
    handleMyChatMember($update['my_chat_member']);
}

exit;
