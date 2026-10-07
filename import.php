<?php
declare(strict_types=1);

/**
 * Импорт output/copart_cars.json в таблицу lots.
 * Каждая строка — upsert по lot_number, added_at не меняется.
 */

require_once __DIR__ . '/env.php';

$host   = getenv('MYSQL_HOST') ?: 'mysql';
$dbname = getenv('MYSQL_DB') ?: 'copart-parser';
$user   = getenv('MYSQL_USER') ?: 'root';
$pass   = getenv('MYSQL_ROOT_PASSWORD') ?: '';
$json   = getenv('IMPORT_JSON') ?: __DIR__ . '/output/copart_cars.json';

$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
};

if (!is_readable($json)) {
    $fail("импорт: файл не читается: $json");
}

$raw = file_get_contents($json);
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($data)) {
    $fail('импорт: JSON не является массивом');
}

$pdo = new PDO(
    "mysql:host=$host;port=3306;dbname=$dbname;charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// Общая схема (lots + send_at + subscribers) — в schema.php
require_once __DIR__ . '/schema.php';

ensureSchema($pdo);

$scalar = [
    'vin', 'year', 'make', 'model', 'body_style', 'engine', 'drive',
    'fuel', 'damage', 'location', 'odometer', 'buy_it_now_price', 'item_url',
];
$jsonCols = [
    'trim', 'color', 'transmission', 'build_sheet', 'full_model_name',
    'estimated_retail_value', 'images', 'raw',
];
// В JSON-файле сырой объект лежит под ключом raw_data, в БД — колонка raw
$jsonAliases = ['raw' => 'raw_data'];

$cols = array_merge(['lot_number'], $scalar, $jsonCols);
$placeholders = rtrim(str_repeat('?,', count($cols)), ',');
$update = implode(
    ', ',
    array_map(static fn (string $c): string => "$c=VALUES($c)", array_merge($scalar, $jsonCols))
);

$sql = 'INSERT INTO lots (' . implode(', ', $cols) . ")
        VALUES ($placeholders)
        ON DUPLICATE KEY UPDATE $update";

$stmt = $pdo->prepare($sql);

$created = 0;
$updated = 0;

$pdo->beginTransaction();

foreach ($data as $i => $row) {
    if (!is_array($row) || !isset($row['lot_number'])) {
        $pdo->rollBack();
        $fail('импорт: строка ' . $i . ' без lot_number');
    }

    $values = [];

    foreach ($scalar as $c) {
        $v = $row[$c] ?? null;
        if (is_string($v) && $v === '') {
            $v = null;
        }
        $values[] = $v;
    }

    foreach ($jsonCols as $c) {
        $src = $jsonAliases[$c] ?? $c;
        $v = $row[$src] ?? null;
        if ($v === null || $v === '') {
            $values[] = null;
        } else {
            $values[] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    }

    $all = array_merge([$row['lot_number']], $values);
    $stmt->execute($all);

    // 1 = новая строка, 2 = обновлена через ON DUPLICATE KEY UPDATE
    $rc = $stmt->rowCount();
    if ($rc === 1) {
        $created++;
    } elseif ($rc === 2) {
        $updated++;
    }
}

$pdo->commit();

$total = (int) $pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn();

printf(
    "импорт: строк в файле %d, новых %d, обновлено %d, всего в таблице %d\n",
    count($data),
    $created,
    $updated,
    $total
);