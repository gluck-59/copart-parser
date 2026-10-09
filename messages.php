<?php
declare(strict_types=1);

/**
 * Тексты ответов бота, общие для вебхука (public/bot.php)
 * и воркеров (parse_watch.php, notify_no_filters.php).
 */

const NO_FILTERS_TEXT = 'Фильтры для поиска не заданы.';

const SETURL_PROMPT =
    'Измените фильтры на Копарте, запустите поиск и проверьте. Если все ок, скопируйте ссылку из браузера и вставьте ее сюда.';
const SETURL_OK = '✅ Фильтры сохранены, следующая партия лотов прилетит по расписанию.';
const SETURL_FAIL = '⚠️ Что-то пошло не так, пожалуйтесь <a href="https://t.me/motokofr">моему автору</a>.';
