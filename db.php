<?php
declare(strict_types=1);

/**
 * Подключение к БД copart-parser.
 *
 * Порядок приоритета: переменная окружения -> .env -> дефолт.
 * Пароль в репозиторий не кладётся: см. .env (в .gitignore), .env.example — в git.
 */

require_once __DIR__ . '/env.php';

function dbConfig(): array
{
    return [
        'host' => getenv('MYSQL_HOST') ?: 'mysql',
        'name' => getenv('MYSQL_DB') ?: 'copart-parser',
        'user' => getenv('MYSQL_USER') ?: 'root',
        'pass' => getenv('MYSQL_ROOT_PASSWORD') ?: '',
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
