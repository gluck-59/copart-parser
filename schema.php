<?php
declare(strict_types=1);

/**
 * Общая схема БД copart-parser (lots + subscribers).
 *
 * Идемпотентна, вызывается из:
 *   import.php — импорт JSON
 *   notify.php — рассылка новых лотов
 *   public/bot.php — /start и /stop
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
            location    VARCHAR(64) NULL,
            odometer    INT NULL,
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

    addSendAtColumn($pdo);
}

/** Миграция уже существующей таблицы lots: колонка send_at. */
function addSendAtColumn(PDO $pdo): void
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['lots', 'send_at']);

    if ((int) $st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE lots ADD COLUMN send_at DATETIME NULL');
    }
}
