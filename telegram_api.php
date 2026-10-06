<?php
declare(strict_types=1);

/**
 * Запросы к Telegram Bot API.
 * Паттерн повторяет synonim_bot: apiRequest (GET) + apiRequestJson (POST JSON).
 */

function tgConfigPath(): string
{
    return __DIR__ . '/token.php';
}

function tgLoadConfig(): array
{
    $path = tgConfigPath();

    if (!is_readable($path)) {
        $msg = 'telegram_api: не найден конфиг ' . basename($path) . "\n";
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg);
            exit(1);
        }
        http_response_code(500);
        exit('bot config is missing');
    }

    $cfg = require $path;

    if (!is_array($cfg) || empty($cfg['bot_token'])) {
        $msg = 'telegram_api: в ' . basename($path) . " не задан bot_token\n";
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg);
            exit(1);
        }
        http_response_code(500);
        exit('bot token is missing');
    }

    return $cfg;
}

$tgCfg = tgLoadConfig();

define('TG_TOKEN', (string) $tgCfg['bot_token']);
define('TG_WEBHOOK_SECRET', (string) ($tgCfg['webhook_secret'] ?? ''));
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
