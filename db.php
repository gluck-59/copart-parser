<?php
declare(strict_types=1);

/**
 * Подключение к БД copart-parser.
 *
 * Порядок приоритета: переменная окружения (docker) -> token.php -> дефолт.
 * Пароль в репозиторий не кладётся: см. token.php (в .gitignore).
 */

function dbConfig(): array
{
    $local = [];

    $file = __DIR__ . '/token.php';
    if (is_readable($file)) {
        $cfg = require $file;
        if (is_array($cfg) && isset($cfg['db']) && is_array($cfg['db'])) {
            $local = $cfg['db'];
        }
    }

    return [
        'host' => getenv('MYSQL_HOST') ?: ($local['host'] ?? 'mysql'),
        'name' => getenv('MYSQL_DB') ?: ($local['name'] ?? 'copart-parser'),
        'user' => getenv('MYSQL_USER') ?: ($local['user'] ?? 'root'),
        'pass' => getenv('MYSQL_ROOT_PASSWORD') ?: ($local['pass'] ?? ''),
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = dbConfig();

    $pdo = new PDO(
        "mysql:host={$c['host']};port=3306;dbname={$c['name']};charset=utf8mb4",
        $c['user'],
        $c['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    return $pdo;
}
