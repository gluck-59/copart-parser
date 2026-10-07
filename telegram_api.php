<?php
declare(strict_types=1);

/**
 * Запросы к Telegram Bot API.
 * Паттерн повторяет synonim_bot: apiRequest (GET) + apiRequestJson (POST JSON).
 */

require_once __DIR__ . '/env.php';

/**
 * Секреты (TG_BOT_TOKEN, TG_WEBHOOK_SECRET) живут в .env.
 * .env.example без секретов лежит в git, сам .env — в .gitignore.
 */
function tgMissingEnv(string $name): never
{
    $msg = 'telegram_api: переменная ' . $name . " не задана в .env\n";

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
        exit(1);
    }

    http_response_code(500);
    exit('bot config is missing');
}

$botToken = (string) (getenv('TG_BOT_TOKEN') ?: '');
if ($botToken === '') {
    tgMissingEnv('TG_BOT_TOKEN');
}

define('TG_TOKEN', $botToken);
define('TG_WEBHOOK_SECRET', (string) (getenv('TG_WEBHOOK_SECRET') ?: ''));
define('API_URL', 'https://api.telegram.org/bot' . TG_TOKEN . '/');

/**
 * Лог работы бота в bot.log (в .gitignore, вне web-root).
 */
function tgLog(string $msg): void
{
    @error_log(date('d-m-y H:i') . ' ' . $msg . "\n", 3, __DIR__ . '/bot.log');
}

function tgMethodError(string $method): bool
{
    if (!is_string($method) || $method === '') {
        tgLog('method name must be a non-empty string');
        return true;
    }
    return false;
}

/**
 * Разбор ответа Telegram.
 * @return mixed result при ok=true, false при ошибке
 */
function tgExecResponse(string $method, string $response, int $httpCode)
{
    if ($response === false) {
        tgLog("{$method}: curl error: " . json_last_error_msg());
        return false;
    }

    if ($httpCode >= 500) {
        tgLog("{$method}: HTTP {$httpCode} (server error)");
        return false;
    }

    $body = json_decode($response, true);

    if ($httpCode !== 200 || !is_array($body)) {
        tgLog("{$method}: HTTP {$httpCode} body=" . substr($response, 0, 300));
        return false;
    }

    if (($body['ok'] ?? false) !== true) {
        $code = $body['error_code'] ?? '?';
        $desc = $body['description'] ?? '';
        tgLog("{$method}: error {$code}: {$desc}");

        if ($code === 401) {
            throw new RuntimeException('Invalid bot token provided');
        }
        return false;
    }

    return $body['result'] ?? true;
}

/**
 * @param array $parameters
 * @return mixed
 */
function apiRequest(string $method, array $parameters = [])
{
    if (tgMethodError($method)) {
        return false;
    }

    foreach ($parameters as $key => $val) {
        if (!is_numeric($val) && !is_string($val)) {
            $parameters[$key] = json_encode($val, JSON_UNESCAPED_UNICODE);
        }
    }

    $url = API_URL . $method . '?' . http_build_query($parameters);

    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 30);

    $response   = curl_exec($handle);
    $httpCode   = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return tgExecResponse($method, $response, $httpCode);
}

/**
 * @param array $parameters
 * @return mixed
 */
function apiRequestJson(string $method, array $parameters = [])
{
    if (tgMethodError($method)) {
        return false;
    }

    $parameters['method'] = $method;

    $handle = curl_init(API_URL);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 30);
    curl_setopt($handle, CURLOPT_POST, true);
    curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters, JSON_UNESCAPED_UNICODE));
    curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($handle);
    $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return tgExecResponse($method, $response, $httpCode);
}
