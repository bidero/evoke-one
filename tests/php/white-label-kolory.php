<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * White Label: kolory treści a pasek górny i ekrany Evoke ONE (1.239.0).
 *
 *   php tests/php/white-label-kolory.php ustaw '<JSON ustawień White Label>' '<JSON pozycji paska>'
 *   php tests/php/white-label-kolory.php przywroc
 *
 * Ustawia opcje w testowym WordPressie (tools/testowy-wp.sh), który test
 * podaje przez php -S i ogląda w Chromium. Pierwsze „ustaw" odkłada stan
 * sprzed testu do osobnej opcji, a „przywroc" go odtwarza. Kopia powstaje
 * tylko raz, więc przerwany przebieg nie nadpisze jej wartościami testu.
 */
require __DIR__ . '/_testowy-wp.php';

const EVK_TEST_WL_KOPIA = 'evk_test_wl_kopia';

switch ($argv[1] ?? '') {
    case 'ustaw':
        if (get_option(EVK_TEST_WL_KOPIA, null) === null) {
            update_option(EVK_TEST_WL_KOPIA, [
                'wl'    => get_option('evk_white_label', null),
                'items' => get_option('evk_wl_bar_items', null),
            ], false);
        }
        $wl    = json_decode($argv[2] ?? '{}', true);
        $items = json_decode($argv[3] ?? '[]', true);
        update_option('evk_white_label', is_array($wl) ? $wl : []);
        update_option('evk_wl_bar_items', wp_json_encode(is_array($items) ? $items : []));
        echo json_encode(['ok' => true, 'wp' => rtrim(ABSPATH, '/')]);
        break;

    case 'przywroc':
        $kopia = get_option(EVK_TEST_WL_KOPIA, null);
        if (is_array($kopia)) {
            foreach (['wl' => 'evk_white_label', 'items' => 'evk_wl_bar_items'] as $k => $opcja) {
                if ($kopia[$k] === null) delete_option($opcja);
                else update_option($opcja, $kopia[$k]);
            }
            delete_option(EVK_TEST_WL_KOPIA);
        }
        echo json_encode(['ok' => true]);
        break;

    case 'ekrany':
        /* Strony wtyczki wprost ze źródła: każde wywołanie add_*_page() w includes/,
           rozebrane tokenizerem PHP (nie wyrażeniem regularnym — argumenty bywają
           wyrażeniami z nawiasami). Nowa strona, której evk_wl_ekran_wtyczki() nie
           rozpozna, zapali test, zamiast po cichu dostać kolory White Label. */
        if (!function_exists('evk_wl_ekran_wtyczki')) {
            echo json_encode(['brak' => 'brak funkcji evk_wl_ekran_wtyczki()']);
            break;
        }
        $pozycja_sluga = ['add_menu_page' => 3, 'add_options_page' => 3, 'add_management_page' => 3,
                          'add_dashboard_page' => 3, 'add_submenu_page' => 4];
        $slugi = [];
        $pliki = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($evk_root . '/includes'));
        foreach ($pliki as $plik) {
            if ($plik->getExtension() !== 'php') continue;
            $tokeny = token_get_all((string) file_get_contents($plik->getPathname()));
            foreach ($tokeny as $i => $tok) {
                if (!is_array($tok) || $tok[0] !== T_STRING || !isset($pozycja_sluga[strtolower($tok[1])])) continue;
                $j = $i + 1;
                while (isset($tokeny[$j]) && is_array($tokeny[$j]) && $tokeny[$j][0] === T_WHITESPACE) $j++;
                if (($tokeny[$j] ?? null) !== '(') continue;
                // Argumenty najwyższego poziomu: przecinki przy głębokości 1.
                $arg = [[]]; $gleb = 0;
                for ($k = $j; isset($tokeny[$k]); $k++) {
                    $t = $tokeny[$k];
                    // „{$x}" w łańcuchu otwiera się tokenem T_CURLY_OPEN, a zamyka zwykłym „}".
                    $otw = in_array($t, ['(', '[', '{'], true)
                        || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
                    if ($otw) { if (++$gleb === 1) continue; }
                    elseif (in_array($t, [')', ']', '}'], true)) { if (--$gleb === 0) break; }
                    elseif ($t === ',' && $gleb === 1) { $arg[] = []; continue; }
                    if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                    $arg[count($arg) - 1][] = $t;
                }
                $a = $arg[$pozycja_sluga[strtolower($tok[1])]] ?? [];
                $zrodlo = implode('', array_map(fn($x) => is_array($x) ? $x[1] : $x, $a));
                if (count($a) === 1 && is_array($a[0]) && $a[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $slugi[] = trim($a[0][1], '\'"');
                } elseif (count($a) === 1 && is_array($a[0]) && $a[0][0] === T_STRING && defined($a[0][1])) {
                    $slugi[] = (string) constant($a[0][1]);
                } else {
                    $slugi[] = 'nieodczytany: ' . $zrodlo . ' (' . basename($plik->getPathname()) . ')';
                }
            }
        }
        $slugi = array_values(array_unique($slugi));
        $obce  = ['plugins', 'index', 'woocommerce', 'bricks', 'evoke', 'evkx', 'my-evoke-one', ''];
        $zle   = [];
        foreach ($slugi as $s) if (!evk_wl_ekran_wtyczki($s)) $zle[] = 'nierozpoznana strona wtyczki: ' . $s;
        foreach ($obce as $s) if (evk_wl_ekran_wtyczki($s)) $zle[] = 'obca strona wzięta za wtyczki: ' . ($s === '' ? '(pusta)' : $s);
        echo json_encode(['slugi' => $slugi, 'zle' => $zle], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['brak' => 'nieznane polecenie: ' . ($argv[1] ?? '')]);
}
