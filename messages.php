<?php
declare(strict_types=1);

/**
 * Тексты ответов бота, общие для вебхука (public/bot.php)
 * и воркеров (parse_watch.php, notify_no_filters.php).
 */

const NO_FILTERS_TEXT = 'Фильтры для поиска не заданы.';

const INVALID_URL = 'Некорректная ссылка на поиск Копарт. Нажмите "изменить фильтр поиска" еще раз и пришлите ссылку.';

const SETURL_PROMPT =
    'Нажмите «Изменить фильтры поиска» в меню и следуйте инструкции.';
const SETURL_OK = '✅ Фильтры сохранены, следующая партия лотов прилетит по расписанию.';
const SETURL_FAIL = '⚠️ Что-то пошло не так, пожалуйтесь <a href="https://t.me/motokofr">моему автору</a>.';
