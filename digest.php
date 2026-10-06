<?php
declare(strict_types=1);

/**
 * Rich Message — дайджест новых лотов Copart.
 *
 * Формат согласован: одно сообщение на прогон, таблица со столбцами
 * лот / марка / модель / год / цена, номер лота — ссылка на Copart.
 *
 * Здесь только структура и разметка. Оформление (цвета, шапка, подпись)
 * под тебя: правь свободно.
 */

function buildDigestRichMessage(array $lots): string
{
    $escape = static fn (?string $v): string =>
        htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

    $rows = '';

    foreach ($lots as $lot) {
        $number = trim((string) ($lot['lot_number'] ?? ''));
        if ($number === '') {
            continue;
        }

        $url = trim((string) ($lot['item_url'] ?? ''));
        $cellNumber = $url !== ''
            ? '<a href="' . $escape($url) . '">' . $escape($number) . '</a>'
            : $escape($number);

        $year = $lot['year'] ?? null;
        $price = $lot['buy_it_now_price'] ?? null;

        $rows .= '<tr>'
            . '<td>' . $cellNumber . '</td>'
            . '<td>' . $escape($lot['make'] ?? null) . '</td>'
            . '<td>' . $escape($lot['model'] ?? null) . '</td>'
            . '<td>' . ($year !== null ? $escape((string) $year) : '—') . '</td>'
            . '<td>' . ($price !== null ? $escape((string) $price) : '—') . '</td>'
            . '</tr>';
    }

    $count = count($lots);

    return ''
        . '<h2>Новые лоты Copart</h2>'
        . '<p>Найдено лотов: ' . $count . '</p>'
        . '<table bordered striped>'
        . '<tr>'
        . '<th>Лот</th><th>Марка</th><th>Модель</th><th>Год</th><th>Цена</th>'
        . '</tr>'
        . $rows
        . '</table>';
}
