<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kod QR bez usług zewnętrznych (1.235.0) — sonda testu og-layers-qr.
 *
 *   php tests/php/og-layers-qr.php koder     sam koder (bez WordPressa)
 *   php tests/php/og-layers-qr.php warstwa   warstwa obrazka OG na testowym WordPressie
 *
 * „koder" oddaje macierze do przeczytania niezależnym dekoderem (jsQR w teście):
 * wszystkie wersje poziomu L (tego z obrazków OG), trzynaście wersji każdego
 * z pozostałych poziomów, każdą z ośmiu masek i polskie znaki. Dane mają
 * dokładnie tyle bajtów, ile mieści wersja — koder nie ma wtedy wyboru.
 * Wiersze macierzy idą szesnastkowo: bufor execSync w teście to 1 MB.
 *
 * „warstwa" rysuje warstwę QR prawdziwym evk_og_render_layer() na obrazku GD
 * i oddaje piksele w skali szarości, a do tego liczbę żądań HTTP (do 1.234.1
 * każde generowanie pytało api.qrserver.com).
 */

$krok = $argv[1] ?? '';

/** Wiersz modułów jako szesnastkowy łańcuch (4 moduły na znak, dopełnienie zerami). */
function evk_t_hex(array $wiersz): string {
    $bity = implode('', $wiersz);
    $bity .= str_repeat('0', (4 - strlen($bity) % 4) % 4);
    $hex = '';
    foreach (str_split($bity, 4) as $cztery) $hex .= dechex((int) bindec($cztery));
    return $hex;
}

function evk_t_kod(string $id, string $dane, string $poziom, ?int $maska = null): array {
    $k = evk_qr_koduj($dane, $poziom, $maska);
    return ['id' => $id, 'poziom' => $poziom, 'dane' => base64_encode($dane),
            'wersja' => $k['wersja'] ?? null, 'maska' => $k['maska'] ?? null,
            'moduly' => $k ? array_map('evk_t_hex', $k['moduly']) : null, 'n' => $k ? count($k['moduly']) : 0];
}

/** Adres o długości dokładnie $ile bajtów. */
function evk_t_adres(int $ile): string {
    $s = 'https://evoke.pl/';
    while (strlen($s) < $ile) $s .= 'wpis-' . strlen($s) . '/';
    return substr($s, 0, $ile);
}

switch ($krok) {

case 'koder':
    define('ABSPATH', __DIR__ . '/');
    require dirname(__DIR__, 2) . '/includes/opengraph/qr.php';
    $pojemnosc = static function (int $v, string $p): int {
        return intdiv(evk_qr_slowa_danych($v, $p) * 8 - 4 - ($v < 10 ? 8 : 16), 8);
    };
    $kody = [];
    for ($v = 1; $v <= 40; $v++) $kody[] = evk_t_kod('L-v' . $v, evk_t_adres($pojemnosc($v, 'L')), 'L');
    foreach (['M', 'Q', 'H'] as $p) {
        foreach ([1, 2, 3, 6, 7, 9, 10, 14, 21, 23, 27, 32, 40] as $v) $kody[] = evk_t_kod($p . '-v' . $v, evk_t_adres($pojemnosc($v, $p)), $p);
    }
    for ($m = 0; $m < 8; $m++) $kody[] = evk_t_kod('maska-' . $m, 'https://evoke.pl/oferta/strona-z-maska-' . $m . '/', 'M', $m);
    $kody[] = evk_t_kod('utf8', 'https://evoke.pl/usługi/zażółć-gęślą-jaźń/?źródło=ŁÓDŹ', 'L');

    /* Wersja wybrana dla długości z tablicy pojemności normy (tryb bajtowy):
       ostatnia długość, która się mieści, i pierwsza, która już nie. */
    $wersje = [];
    foreach (['L' => [17, 32, 271, 2953], 'M' => [14, 26, 213, 2331], 'Q' => [11, 20, 151, 1663], 'H' => [7, 14, 119, 1273]] as $p => $dlugosci) {
        foreach ($dlugosci as $dl) {
            foreach ([$dl, $dl + 1] as $d) {
                $k = evk_qr_koduj(str_repeat('a', $d), $p);
                $wersje[$p][$d] = $k['wersja'] ?? null;
            }
        }
    }
    echo json_encode(['kody' => $kody, 'wersje' => $wersje]);
    break;

case 'warstwa':
    require __DIR__ . '/_testowy-wp.php';
    $http = [];
    add_filter('pre_http_request', static function ($pre, $args, $url) use (&$http) {
        $http[] = $url;
        return new WP_Error('evk_t_zablokowane', 'W teście żadnych żądań na zewnątrz.');
    }, 10, 3);
    $post = (int) wp_insert_post(['post_type' => 'post', 'post_status' => 'publish',
        'post_title' => 'Wpis z kodem QR', 'post_name' => 'wpis-z-kodem-qr-zazolc']);
    $s   = ['width' => 1200, 'height' => 630];
    $img = imagecreatetruecolor($s['width'], $s['height']);
    imagefilledrectangle($img, 0, 0, $s['width'] - 1, $s['height'] - 1, imagecolorallocate($img, 128, 128, 128));
    $warstwy = [
        'domyslne' => ['enabled' => 1, 'type' => 'qr', 'x' => 40, 'y' => 40, 'size' => 170, 'fg_color' => '#ffffff', 'bg_color' => '#000000'],
        'ciemne'   => ['enabled' => 1, 'type' => 'qr', 'x' => 300, 'y' => 300, 'size' => 170, 'fg_color' => '#111111', 'bg_color' => '#ffffff'],
    ];
    $out = ['adres' => get_permalink($post), 'warstwy' => []];
    foreach ($warstwy as $nazwa => $w) {
        evk_og_render_layer($img, $w, $post, $s);
        $x0 = $s['width'] - $w['size'] - $w['x'];
        $y0 = $w['y'];
        // Wycinek z marginesem 10 px wokół kwadratu, w skali szarości.
        $bok = $w['size'] + 20;
        $szare = '';
        for ($y = $y0 - 10; $y < $y0 - 10 + $bok; $y++) {
            for ($x = $x0 - 10; $x < $x0 - 10 + $bok; $x++) {
                $c = imagecolorat($img, $x, $y);
                $szare .= chr((int) round(((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3));
            }
        }
        $piksel = static function (int $x, int $y) use ($img): string { return sprintf('#%06x', imagecolorat($img, $x, $y) & 0xFFFFFF); };
        $out['warstwy'][$nazwa] = [
            'bok' => $bok, 'szare' => base64_encode($szare),
            // Rogi kwadratu w kolorze tła kodu, piksel obok — już tło obrazka.
            'rogi'  => [$piksel($x0, $y0), $piksel($x0 + $w['size'] - 1, $y0), $piksel($x0, $y0 + $w['size'] - 1), $piksel($x0 + $w['size'] - 1, $y0 + $w['size'] - 1)],
            'obok'  => [$piksel($x0 - 1, $y0), $piksel($x0 + $w['size'], $y0 + $w['size'] - 1)],
            'tlo'   => strtolower($w['bg_color']),
        ];
    }
    imagedestroy($img);
    wp_delete_post($post, true);
    $out['http'] = $http;
    echo wp_json_encode($out);
    break;
}
