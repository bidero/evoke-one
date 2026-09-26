<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Pliki `.min` frontu (1.248.0): wybór pliku w PHP i prawdziwa strona.
 *
 *   php tests/php/minifikacja.php pomocnik          evk_zasob_url() na przypadkach
 *   php tests/php/minifikacja.php debug             to samo przy SCRIPT_DEBUG
 *   php tests/php/minifikacja.php strona-ustaw      testowy WordPress: moduły frontu włączone
 *   php tests/php/minifikacja.php strona-przywroc   opcje z powrotem
 */
$tryb = $argv[1] ?? '';

if ($tryb === 'pomocnik' || $tryb === 'debug') {
    define('ABSPATH', '/');
    define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');
    if ($tryb === 'debug') define('SCRIPT_DEBUG', true);
    require __DIR__ . '/../../includes/02-zasoby-frontu.php';
    $u = EVOKE_ONE_URL;
    $przypadki = [
        'js z .min'          => $u . 'assets/js/parallax.js',
        'css elementu z .min' => $u . 'includes/bricks-elements/evoke-offcanvas-menu/assets/offcanvas-menu.css',
        'bez pliku .min'     => $u . 'assets/js/nie-ma-takiego.js',
        'już skrócony'       => $u . 'assets/vendor/gsap/gsap.min.js',
        'obcy adres'         => 'https://cdn.example.test/assets/js/parallax.js',
        'nie js ani css'     => $u . 'assets/img/logo.png',
    ];
    $wynik = [];
    foreach ($przypadki as $nazwa => $url) $wynik[$nazwa] = str_replace($u, '', evk_zasob_url($url));
    echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/_testowy-wp.php';

const EVK_TEST_MIN_KOPIA = 'evk_test_minifikacja_kopia';
/* Moduły, które kolejkują własne pliki JS/CSS na froncie (i kilka obok,
   żeby strona wyglądała jak prawdziwa). Animator potrzebowałby animacji
   w treści, elementy — Bricksa; te są w fixturach. */
const EVK_TEST_MIN_MODULY = ['evk_parallax', 'evk_darkmode', 'evk_cursor', 'evk_lenis', 'evk_bgshift', 'evk_a11y'];

switch ($tryb) {
    case 'strona-ustaw':
        if (get_option(EVK_TEST_MIN_KOPIA, null) === null) {
            $k = [];
            foreach (EVK_TEST_MIN_MODULY as $o) $k[$o] = get_option($o, null);
            update_option(EVK_TEST_MIN_KOPIA, $k, false);
        }
        foreach (EVK_TEST_MIN_MODULY as $o) {
            $v = get_option($o, []);
            update_option($o, array_merge(is_array($v) ? $v : [], ['enabled' => 1]));
        }
        echo json_encode(['wp' => rtrim(ABSPATH, '/')]);
        break;

    case 'strona-przywroc':
        $k = get_option(EVK_TEST_MIN_KOPIA, null);
        if (is_array($k)) {
            foreach ($k as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
            delete_option(EVK_TEST_MIN_KOPIA);
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['brak' => 'nieznane polecenie: ' . $tryb]);
}
