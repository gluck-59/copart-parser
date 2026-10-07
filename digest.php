<?php
declare(strict_types=1);

/**
 * Rich Message — дайджест новых лотов Copart.
 *
 * Формат согласован: одно сообщение на прогон, карточка на лот:
 * реальное фото (первое из сохранённых) + подпись-ссылка.
 * BIN показывается только если цена больше нуля.
 */

function buildDigestRichMessage(array $lots): string
{
    $escape = static fn (?string $v): string =>
        htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

    $cards = '';
    $count = 0;

    foreach ($lots as $lot) {
        $number = trim((string) ($lot['lot_number'] ?? ''));
        if ($number === '') {
            continue;
        }
        $count++;

        $url = trim((string) ($lot['item_url'] ?? ''));
        $year = $lot['year'] ?? null;
        $price = $lot['buy_it_now_price'] ?? null;

        $images = is_string($lot['images'] ?? null)
            ? (json_decode($lot['images'], true) ?: [])
            : ($lot['images'] ?? []);
        $photo = trim((string) ($images[0] ?? ''));

        $title = trim(sprintf(
            '%s %s%s',
            $escape($lot['make'] ?? null),
            $escape($lot['model'] ?? null),
            $year !== null ? ' ' . $escape((string) $year) : ''
        ));

        $link = $url !== ''
            ? '<a href="' . $escape($url) . '">' . $title . '</a>'
            : $title;

        $caption = $link;
        if ($price !== null && (int) $price > 0) {
            $caption .= ' · BIN ' . $escape((string) $price);
        }

        if ($photo !== '' && filter_var($photo, FILTER_VALIDATE_URL)) {
            $cards .= '<figure>'
                . '<img src="' . $escape($photo) . '"/>'
                . '<figcaption>' . $caption . '</figcaption>'
                . '</figure>';
        } else {
            $cards .= '<p>' . $caption . '</p>';
        }
    }

    return ''
        . '<h3>Copart · новые лоты</h3>'
        . '<p>Подобрано: ' . $count . '</p>'
        . '<hr/>'
        . $cards
        . '<hr/>'
        . '<footer>Копарс</footer>';
}
