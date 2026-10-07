<?php
declare(strict_types=1);

/**
 * Загрузка файла .env в окружение PHP.
 *
 * Семантика та же, что у process.loadEnvFile() в scraper.js:
 *   - уже заданные переменные окружения НЕ переопределяются;
 *   - строки, начинающиеся с #, и пустые строки пропускаются;
 *   - значения в одинарных/двойных кавычках освобождаются от кавычек;
 *   - файл читается один раз за запрос (повторный include безопасен).
 *
 * Путь к файлу: корень репозитория. Конфиг и секреты хранятся в .env,
 * в git кладётся только .env.example (см. .gitignore).
 */

function loadDotEnv(string $path): void
{
    static $loaded = [];

    if (isset($loaded[$path])) {
        return;
    }
    $loaded[$path] = true;

    if (!is_readable($path)) {
        return;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return;
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $pos));
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            continue;
        }

        if (getenv($name) !== false) {
            continue;
        }

        $value = trim(substr($line, $pos + 1));
        $len = strlen($value);

        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];

            if (($first === '"' || $first === "'") && $last === $first) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadDotEnv(__DIR__ . '/.env');
