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
        $year = $lot['year'] ?? null;
        $price = $lot['buy_it_now_price'] ?? null;

        $carInfo = $url !== ''
            ? '<a href="' . $escape($url) . '">'
            . $escape($lot['make'] ?? null) . '&nbsp' . $escape($lot['model'] ?? null) . '&nbsp'.($year !== null ? $escape((string) $year) : '')
            . '</a>'
            : $escape($number);

        $rows .= '<tr>'
            . '<td>' . 'ФОТО №1'.'</td>'
            . '<td>' . $price .'</td>';
        
        $rows .= $carInfo;
        
        $rows .= '</tr>';

//        $rows .= '<tr>'
//            . '<td>' . $carInfo . '</td>'
//            . '<td>' . $escape($lot['make'] ?? null) . '&nbsp'
//            . $escape($lot['model'] ?? null) . '&nbsp'
//            . ($year !== null ? $escape((string) $year) : '') . '</td>'
//            . '<td>' . ($price !== null ? $escape((string) $price) : '—') . '</td>'
//            . '</tr>';
    }

    $count = count($lots);

    return ''
        . '<h3>Новых лотов: '. $count . ' </h3>'
        . '<table bordered striped>'
        . '<tr>'
        . '<th>Ссылка</th><th>Машина</th><th>BIN</th>'
        . '</tr>'
        . $rows
        . '</table>';
}
