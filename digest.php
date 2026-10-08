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

    $currencySymbol = static function (?string $code): string {
        $c = strtoupper((string) $code);
        if ($c === 'EUR') {
            return '€';
        }
        if ($c === 'USD') {
            return '$';
        }
        return $code !== null && $code !== '' ? $code : '';
    };

    $fmt = static fn ($n): string => number_format((float) $n, 0, '.', ' ');

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
        $bid = $lot['current_bid'] ?? null;
        $odo = $lot['odometer'] ?? null;
        $unit = trim((string) ($lot['odometer_unit'] ?? ''));
        $damage = trim((string) ($lot['damage'] ?? ''));
        $secondary = trim((string) ($lot['secondary_damage'] ?? ''));
        $titleType = trim((string) ($lot['title_type'] ?? ''));
        $hasKeys = trim((string) ($lot['has_keys'] ?? ''));
        $location = trim((string) ($lot['location'] ?? ''));
        $cur = $currencySymbol($lot['currency'] ?? null);

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

        $lines = [$link];

        if ($price !== null && (int) $price > 0) {
            $lines[] = 'BIN ' . $fmt($price) . ($cur !== '' ? ' ' . $cur : '');
        }
        if ($bid !== null && (int) $bid > 0) {
            $lines[] = 'Puja actual: ' . $fmt($bid) . ($cur !== '' ? ' ' . $cur : '');
        }
        if ($odo !== null && (int) $odo > 0) {
            $lines[] = 'Kilometraje: ' . $fmt($odo) . ($unit !== '' ? ' ' . $escape($unit) : '');
        }

        $damageLine = $damage;
        if ($secondary !== '') {
            $damageLine .= $damage !== '' ? ' · ' . $secondary : $secondary;
        }
        if ($damageLine !== '') {
            $lines[] = 'Daños: ' . $escape($damageLine);
        }

        $typeKeys = [];
        if ($titleType !== '') {
            $typeKeys[] = $escape($titleType);
        }
        if ($hasKeys !== '') {
            $typeKeys[] = 'Llaves: ' . (strtolower($hasKeys) === 'yes' ? 'Sí' : $escape($hasKeys));
        }
        if ($typeKeys !== []) {
            $lines[] = implode(' · ', $typeKeys);
        }

        if ($location !== '') {
            $lines[] = $escape($location);
        }

        $caption = implode('<br/>', $lines);

        if ($photo !== '' && filter_var($photo, FILTER_VALIDATE_URL)) {
            $cards .= '<figure>'
                . '<img src="' . $escape($photo) . '"/>'
                . '<figcaption>' . $caption . '</figcaption>'
                . '</figure>'
            ;
        } else {
            $cards .= '<p>' . $caption . '</p>';
        }
    }

    return ''
        . '<h3>Новых: ' . $count . '</h3>'
        . $cards;
}
