<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — wygląd i teksty kroku kodu 2FA w formularzu logowania Bricksa
 * (1.289.0). Decyzje zgłaszającego z 03.10: kontrolki w elemencie Formularz,
 * domyślnie linki „jak etykiety pól”, konfigurowalne teksty, układ linków
 * i komunikat złego kodu.
 *
 * Grupa „Logowanie dwuetapowe (Evoke)” w formularzu z akcją „Login”:
 * - teksty (etykieta i podpowiedź pola, oba linki, „Zapamiętaj…”,
 *   komunikat złego kodu z `{proby}`) — klucze BEZ przedrostka `evk`, bo
 *   to treść: grupa „Tłumaczenia” (51) dokłada im pola „… EN”;
 * - wygląd przez CSS Bricksa (`css` w kontrolce, zasięg tego formularza):
 *   typografia linków, kolor po najechaniu, kierunek, wyrównanie, odstępy,
 *   typografia komunikatu; klasy CSS linków (np. klasy frameworka);
 * - położenie linków: pod polem albo pod przyciskiem;
 * - podgląd kroku kodu w builderze (krok pojawia się dopiero po haśle,
 *   więc bez podglądu nie byłoby czego stylować).
 *
 * Wartości domyślne stoją w zmiennych CSS (`--evk-2fa-…`) — da się je
 * ustawić globalnie, np. w stylach motywu; kontrolka elementu wygrywa.
 * Skrypt: assets/js/2fa-bricks.js; ustawienia dostaje w `data-evk2fa`.
 */

/** Teksty kroku kodu: klucz kontrolki => [etykieta w builderze, tekst domyślny]. */
function evk_2fa_bricks_teksty(): array {
    $dni = defined('EVK_2FA_URZADZENIE_DNI') ? (int) EVK_2FA_URZADZENIE_DNI : 30;
    return [
        'tfaEtykieta'         => ['Etykieta pola', 'Kod z aplikacji'],
        'tfaPodpowiedz'       => ['Podpowiedź w polu', '123 456'],
        'tfaZapasowy'         => ['Link: zapasowe wejście', 'Nie masz telefonu? Użyj kodu zapasowego'],
        'tfaZapasowyEtykieta' => ['Etykieta pola po wybraniu zapasowego', 'Kod zapasowy'],
        'tfaWroc'             => ['Link powrotu', '← Wróć'],
        'tfaPamietaj'         => ['Zapamiętaj urządzenie', 'Zapamiętaj to urządzenie na ' . $dni . ' dni'],
        'tfaBlad'             => ['Komunikat po złej próbie', 'Nieprawidłowy kod. Zostało prób: {proby}.'],
    ];
}

add_filter('bricks/elements/form/control_groups', function ($grupy) {
    if (!is_array($grupy)) return $grupy;
    $grupy['evk2fa'] = ['title' => 'Logowanie dwuetapowe (Evoke)', 'required' => ['actions', '=', 'login']];
    return $grupy;
});

add_filter('bricks/elements/form/controls', function ($k) {
    if (!is_array($k)) return $k;
    $g = ['group' => 'evk2fa'];
    $k['evk2faPodglad'] = $g + ['label' => 'Podgląd kroku kodu w builderze', 'type' => 'checkbox', 'rerender' => true,
        'description' => 'Krok kodu pojawia się na stronie dopiero po haśle. Podgląd pokazuje go tutaj, żeby dało się go ostylować; na stronie nic nie zmienia.'];
    foreach (evk_2fa_bricks_teksty() as $klucz => [$etykieta, $domyslny]) {
        /* `rerender` — teksty idą atrybutem z PHP, więc builder musi przerysować element (sprawdzone w prawdziwym builderze). */
        $k[$klucz] = $g + ['label' => $etykieta, 'type' => 'text', 'inline' => false, 'placeholder' => $domyslny, 'rerender' => true];
    }
    $k['tfaBlad']['description'] = '{proby} — ile prób zostało (z 5).';
    $k['evk2faPolozenie'] = $g + ['label' => 'Linki', 'type' => 'select', 'inline' => true, 'placeholder' => 'Pod polem', 'rerender' => true,
        'options' => ['pole' => 'Pod polem', 'przycisk' => 'Pod przyciskiem']];
    $k['evk2faKierunek'] = $g + ['label' => 'Linki: kierunek', 'type' => 'direction', 'inline' => true,
        'css' => [['property' => 'flex-direction', 'selector' => '.evk-2fa-nawig']]];
    $k['evk2faWyrownanie'] = $g + ['label' => 'Linki: wyrównanie', 'type' => 'justify-content', 'direction' => 'row',
        'css' => [['property' => 'justify-content', 'selector' => '.evk-2fa-nawig']]];
    $k['evk2faWyrownaniePion'] = $g + ['label' => 'Linki: wyrównanie w kolumnie', 'type' => 'align-items', 'direction' => 'column',
        'css' => [['property' => 'align-items', 'selector' => '.evk-2fa-nawig']]];
    $k['evk2faOdstepWiersze'] = $g + ['label' => 'Linki: odstęp w pionie', 'type' => 'number', 'units' => true, 'placeholder' => '4px',
        'css' => [['property' => 'row-gap', 'selector' => '.evk-2fa-nawig']]];
    $k['evk2faOdstepKolumny'] = $g + ['label' => 'Linki: odstęp w poziomie', 'type' => 'number', 'units' => true, 'placeholder' => '16px',
        'css' => [['property' => 'column-gap', 'selector' => '.evk-2fa-nawig']]];
    $k['evk2faTypografia'] = $g + ['label' => 'Linki: typografia', 'type' => 'typography',
        'css' => [['property' => 'font', 'selector' => '.evk-2fa-link']]];
    $k['evk2faNajechanie'] = $g + ['label' => 'Linki: kolor po najechaniu', 'type' => 'color',
        'css' => [['property' => 'color', 'selector' => '.evk-2fa-link:hover'], ['property' => 'color', 'selector' => '.evk-2fa-link:focus-visible']]];
    $k['evk2faKlasy'] = $g + ['label' => 'Linki: klasy CSS', 'type' => 'text', 'inline' => false, 'evkTlPomin' => true, 'placeholder' => 'np. btn--link', 'rerender' => true,
        'description' => 'Dokładane do obu linków, oddzielone spacją (np. klasy frameworka).'];
    $k['evk2faBladTypografia'] = $g + ['label' => 'Komunikat po złej próbie: typografia', 'type' => 'typography',
        'css' => [['property' => 'font', 'selector' => '.evk-2fa-blad']]];
    return $k;
});

/**
 * Ustawienia kroku kodu w atrybucie formularza — po podmianie ustawień przez
 * Tłumaczenia (`bricks/element/settings`), więc na /en/ idą teksty EN.
 * Puste pola nie jadą: skrypt bierze wtedy tekst domyślny.
 */
add_filter('bricks/element/render_attributes', function ($atrybuty, $klucz, $element) {
    if ($klucz !== '_root' || !is_object($element) || ($element->name ?? '') !== 'form') return $atrybuty;
    $u = is_array($element->settings ?? null) ? $element->settings : [];
    if (!in_array('login', (array) ($u['actions'] ?? []), true)) return $atrybuty;
    $dane = [];
    foreach (array_keys(evk_2fa_bricks_teksty()) as $t) {
        if (isset($u[$t]) && is_string($u[$t]) && trim($u[$t]) !== '') $dane[$t] = wp_strip_all_tags($u[$t]);
    }
    if (($u['evk2faPolozenie'] ?? '') === 'przycisk') $dane['polozenie'] = 'przycisk';
    if (!empty($u['evk2faKlasy']) && is_string($u['evk2faKlasy'])) {
        $dane['klasy'] = implode(' ', array_filter(array_map('sanitize_html_class', preg_split('/\s+/', $u['evk2faKlasy']) ?: [])));
    }
    /* Podgląd tylko w builderze: kanwa przy wczytaniu i przerysowanie elementu po zmianie kontrolki (zapytanie do serwera). */
    $builder = (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe()) || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
    if (!empty($u['evk2faPodglad']) && $builder) $dane['podglad'] = 1;
    if ($dane) $atrybuty['_root']['data-evk2fa'] = (string) wp_json_encode($dane);
    return $atrybuty;
}, 10, 3);
