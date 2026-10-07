<?php
declare(strict_types=1);

/**
 * Общая схема БД copart-parser (lots + subscribers + seturl_pending).
 *
 * Идемпотентна, вызывается из:
 *   import.php — импорт JSON
 *   notify.php — рассылка новых лотов
 *   public/bot.php — /start, /stop, /seturl
 */

function ensureSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lots (
            lot_number  VARCHAR(16) NOT NULL PRIMARY KEY,
            vin         VARCHAR(32) NULL,
            year        SMALLINT NULL,
            make        VARCHAR(64) NULL,
            model       VARCHAR(128) NULL,
            body_style  VARCHAR(64) NULL,
            engine      VARCHAR(64) NULL,
            drive       VARCHAR(64) NULL,
            fuel        VARCHAR(32) NULL,
            damage      VARCHAR(128) NULL,
            secondary_damage VARCHAR(128) NULL,
            title_type  VARCHAR(64) NULL,
            has_keys    VARCHAR(16) NULL,
            current_bid INT NULL,
            currency    VARCHAR(8) NULL,
            location    VARCHAR(64) NULL,
            odometer    INT NULL,
            odometer_unit VARCHAR(8) NULL,
            buy_it_now_price INT NULL,
            item_url    VARCHAR(255) NULL,
            trim        JSON NULL,
            color       JSON NULL,
            transmission JSON NULL,
            build_sheet JSON NULL,
            full_model_name JSON NULL,
            estimated_retail_value JSON NULL,
            images      JSON NULL,
            added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            send_at     DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subscribers (
            user_id     BIGINT NOT NULL,
            first_name  VARCHAR(255) NULL,
            username    VARCHAR(255) NULL,
            subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS seturl_pending (
            user_id      BIGINT NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    addColumnIfMissing($pdo, 'lots', 'send_at', 'DATETIME NULL');
    addColumnIfMissing($pdo, 'lots', 'raw', 'LONGTEXT NULL');
    addColumnIfMissing($pdo, 'lots', 'secondary_damage', 'VARCHAR(128) NULL');
    addColumnIfMissing($pdo, 'lots', 'title_type', 'VARCHAR(64) NULL');
    addColumnIfMissing($pdo, 'lots', 'has_keys', 'VARCHAR(16) NULL');
    addColumnIfMissing($pdo, 'lots', 'current_bid', 'INT NULL');
    addColumnIfMissing($pdo, 'lots', 'currency', 'VARCHAR(8) NULL');
    addColumnIfMissing($pdo, 'lots', 'odometer_unit', 'VARCHAR(8) NULL');
}

/** Идемпотентное добавление колонки в существующую таблицу. */
function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);

    if ((int) $st->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}
