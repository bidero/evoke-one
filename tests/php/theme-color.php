<?php
// Tylko z wiersza poleceń — patrz _wp-stubs.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kolor pasków przeglądarki — zrzut PRAWDZIWEGO wyjścia do <head>.
 *
 * Ten moduł produkuje jedną jedyną rzecz: znacznik `theme-color`. Wszystko,
 * co można o nim sprawdzić bez Safari, mieści się w tym, CO trafia do
 * dokumentu — i to jest tutaj mierzone na prawdziwym `render_head()`, a nie
 * na jego opisie.
 *
 * Czego ta droga NIE sprawdzi i trzeba o tym wiedzieć: czy Safari faktycznie
 * pomaluje paski tym kolorem. To jest zachowanie przeglądarki, zestaw jedzie
 * na Chromium i takiego pomiaru nie da się tu zrobić. Sprawdzamy więc naszą
 * połowę umowy — poprawny znacznik we właściwej kolejności — a nie cudzą.
 *
 * Kolejność znaczników jest częścią umowy, nie kosmetyką: przeglądarka bierze
 * PIERWSZY, którego zapytanie `media` pasuje, więc jasny musi stać przed
 * ciemnym. Odwrócenie tej pary nie rzuca błędu — po prostu w trybie jasnym
 * wychodzi kolor ciemny.
 */
require __DIR__ . '/_wp-stubs.php';

// Moduł woła to w sanitize; w produkcji przychodzi z 30-admin-settings-ajax.php.
if (!function_exists('evk_preserve_toggle')) {
    function evk_preserve_toggle($input, string $option, string $field = 'enabled', int $default = 0): int {
        return isset($input[$field]) ? (int) (bool) $input[$field] : $default;
    }
}

/**
 * Ustawia opcję, buduje moduł OD NOWA i zwraca jego wyjście do <head>.
 *
 * Od nowa, bo moduł czyta `enabled` w konstruktorze i tylko wtedy podpina się
 * pod `wp_head` — stan wyłączony trzeba więc sprawdzać na świeżej instancji,
 * inaczej mierzyłoby się skutek pierwszego wywołania.
 */
function evk_tc_head(array $opcje): string {
    update_option('evk_theme_color', $opcje);
    $GLOBALS['hooks'] = [];
    $r = new ReflectionClass('EVK_Theme_Color');
    $p = $r->getProperty('instance');
    $p->setValue(null, null);
    EVK_Theme_Color::get_instance();
    return trim(evk_test_fire('wp_head'));
}

require EVK_TEST_ROOT . '/includes/91-theme-color.php';

$domyslne = (new ReflectionClass('EVK_Theme_Color'))->getProperty('defaults');

echo wp_json_encode([
    // Wyłączony moduł nie może wypisać ani jednego znaku — inaczej włączenie
    // wtyczki zmieniałoby wygląd pasków bez pytania.
    'wylaczony'   => evk_tc_head(['enabled' => 0, 'light' => '#ffffff', 'dark' => '#111111']),
    'jedenKolor'  => evk_tc_head(['enabled' => 1, 'light' => '#ff0000', 'dark' => '']),
    'dwaKolory'   => evk_tc_head(['enabled' => 1, 'light' => '#ffffff', 'dark' => '#111111']),
    // Ten sam kolor w obu polach to nadal jeden znacznik — para z identyczną
    // treścią byłaby tylko szumem w kodzie strony.
    'takiSam'     => evk_tc_head(['enabled' => 1, 'light' => '#abcdef', 'dark' => '#abcdef']),
    // Bez koloru jasnego NIE zgadujemy. Pomyłka przemalowuje paski na całej
    // stronie, a milczenie zostawia zachowanie sprzed włączenia.
    'bezJasnego'  => evk_tc_head(['enabled' => 1, 'light' => '', 'dark' => '#111111']),
    'smiec'       => evk_tc_head(['enabled' => 1, 'light' => 'javascript:alert(1)', 'dark' => '']),
    /* Ciemny NIEPUSTY, ale nieprawidłowy — jedyna droga do `null`, bo pusty
       daje `''` i tam żaden `null` nie powstaje. Bez rzutowania na łańcuch
       `null` przechodzi przez porównanie z `''` i wychodzi DRUGI znacznik
       z pustą treścią, który w trybie ciemnym wygrywa z jasnym. */
    'ciemnySmiec' => evk_tc_head(['enabled' => 1, 'light' => '#ffffff', 'dark' => 'rgb(0,0,0)']),
    // Sanityzacja odsiewa wszystko, co nie jest kolorem szesnastkowym.
    'sanityzacja' => EVK_Theme_Color::get_instance()->sanitize_settings(
        ['enabled' => 1, 'light' => '#FFF', 'dark' => 'rgb(0,0,0)']),
    'domyslne'    => $domyslne->getValue(EVK_Theme_Color::get_instance()),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
