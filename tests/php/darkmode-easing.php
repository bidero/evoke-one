<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dark mode: co przechodzi przez sanityzację easingów i co się dzieje z resztą.
 *
 * Silnik przyjmował dowolne `cubic-bezier(...)` na długo przed 1.114.0 —
 * blokował wyłącznie interfejs, w którym trzy z sześciu pól były listami
 * wyboru, a jedno (`logo_easing`) nie miało w panelu żadnego pola.
 *
 * Czego NIE DA SIĘ zmierzyć z markupu zakładki, a widać stąd:
 *
 *   · że wartość spoza listy nazw naprawdę przechodzi, a nie tylko wygląda
 *     na przyjętą w polu formularza;
 *   · że śmieci są odrzucane — z `javascript:` włącznie;
 *   · że odrzucenie MÓWI O SOBIE, zamiast po cichu cofnąć wpis do domyślnego.
 *     Przy liście wyboru pomylić się nie było jak; przy polu tekstowym
 *     literówka kasowała ustawienie bez słowa.
 */
require __DIR__ . '/_wp-stubs.php';

/* Komunikaty ustawień — atrapa zbiera je zamiast wyświetlać. */
$GLOBALS['settings_errors'] = [];
if (!function_exists('add_settings_error')) {
    function add_settings_error($setting, $code, $message, $type = 'error') {
        $GLOBALS['settings_errors'][] = compact('setting', 'code', 'message', 'type');
    }
}

/* Sanityzacja sięga po wspólny pomocnik przełączników (30-admin-settings-ajax.php).
   Podstawiamy sam ten pomocnik, a nie cały moduł AJAX-a: tu chodzi o easingi,
   a przełącznik ma tylko nie wywrócić wywołania. */
if (!function_exists('evk_preserve_toggle')) {
    function evk_preserve_toggle($input, string $option, string $field = 'enabled', int $default = 0): int {
        return !empty($input[$field]) ? 1 : $default;
    }
}

require EVK_TEST_ROOT . '/includes/93-darkmode.php';

/* Moduł jest singletonem i sam się rejestruje na dole pliku — bierzemy tę samą
   instancję, której używa panel, zamiast robić drugą obok. */
$el = EVK_DarkMode::get_instance();

/** Jeden przebieg sanityzacji — oddaje wynik i zebrane komunikaty. */
function przepusc($el, array $wejscie) {
    $GLOBALS['settings_errors'] = [];
    $out = $el->sanitize_settings($wejscie);
    return [
        'easingi'    => array_intersect_key($out, array_flip(
            ['global_easing', 'bricks_easing', 'logo_easing',
             'ripple_easing', 'wipe_easing', 'post_trans_easing'])),
        'zmienne'    => $out['color_vars'],
        'komunikaty' => array_map(function ($k) { return $k['message']; }, $GLOBALS['settings_errors']),
    ];
}

$wyniki = [];

// ── Dowolna krzywa przechodzi ────────────────────────────────────────────────
$wyniki['wlasna'] = przepusc($el, [
    'global_easing'     => 'cubic-bezier(0.87, 0, 0.13, 1)',
    'wipe_easing'       => 'cubic-bezier(.25,.1,.25,1)',
    'post_trans_easing' => 'cubic-bezier(0.16, 1, 0.3, 1)',
    'logo_easing'       => 'cubic-bezier(0.65, 0, 0.35, 1)',
]);

// ── Nazwy z listy dalej działają ─────────────────────────────────────────────
$wyniki['nazwa'] = przepusc($el, ['global_easing' => 'ease-in-out']);

// ── Śmieci odlatują I MÓWIĄ O SOBIE ──────────────────────────────────────────
$wyniki['smieci'] = przepusc($el, [
    'global_easing' => 'javascript:alert(1)',
    'wipe_easing'   => '1s ease',
]);

// Ujemne wartości sterujące są w krzywych legalne — regex musi je puścić.
$wyniki['ujemne'] = przepusc($el, ['global_easing' => 'cubic-bezier(0.68, -0.55, 0.27, 1.55)']);

// ── Puste pole to świadome „wróć do domyślnego", nie pomyłka ─────────────────
$wyniki['puste'] = przepusc($el, ['global_easing' => '']);

// ── Zmienne kolorów: normalizacja nazw i odrzucanie śmieci ───────────────────
/*
 * Nazwy trafiają do `@property` i do listy przejść, więc byle co wpuszczone tu
 * kończy się nieważną regułą albo — gorzej — kolorem podmienionym na
 * `transparent` w całym motywie.
 *
 * Brak wiodących myślników to najczęstsza pomyłka przy przepisywaniu nazwy
 * z arkusza stylów, więc dopisujemy je zamiast odrzucać wpis.
 */
$wyniki['zmienne_ok'] = przepusc($el, [
    'color_vars' => "--kolor-glowny-d-2\nkolor-b\n  --kolor-c  ",
]);

$wyniki['zmienne_powtorka'] = przepusc($el, ['color_vars' => "--a\n--a\na"]);

$wyniki['zmienne_smieci'] = przepusc($el, [
    'color_vars' => "--dobra\nzła nazwa!\n--url(evil)\n--a;color:red",
]);

$wyniki['zmienne_puste'] = przepusc($el, ['color_vars' => '']);

echo json_encode($wyniki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
