<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — menu „Evoke” w pasku admina na stronie (1.290.0). Decyzje
 * zgłaszającego z 02–03.10 (docs/plan-kolejka.md): jedno rozwijane menu
 * zamiast osobnych pozycji modułów — grupy Statystyki (licznik w tytule
 * menu), Hotspoty, Tłumaczenia i link do panelu; przełącznik konserwacji
 * zostaje osobno na wierzchu; widoczne także na telefonie.
 *
 * Moduły dokładają pozycje do SWOICH grup (`evk_pasek_grupa()`), zaczynając
 * od nagłówka (`evk_pasek_naglowek()`). Grupa bez pozycji nie powstaje,
 * a menu bez grup też nie — każdy widzi tylko to, do czego ma prawo
 * (czytelnik statystyk: Statystyki i Hotspoty, tłumacz: Tłumaczenia,
 * administrator: wszystko i panel).
 */

const EVK_PASEK = 'evk-menu';
/* Kolejność grup w menu. */
const EVK_PASEK_GRUPY = ['stat', 'hot', 'tl', 'panel'];

function evk_pasek_grupa(string $grupa): string {
    return EVK_PASEK . '-' . $grupa;
}

/** Nagłówek grupy — pozycja bez odnośnika; dokłada go moduł jako PIERWSZĄ pozycję swojej grupy. */
function evk_pasek_naglowek(WP_Admin_Bar $pasek, string $grupa, string $id, string $tytul): void {
    $pasek->add_node(['parent' => evk_pasek_grupa($grupa), 'id' => $id, 'title' => esc_html($tytul),
        'meta' => ['class' => 'evk-pasek-naglowek']]);
}

/**
 * Licznik w tytule menu (statystyki: „dziś / 30 dni” tej strony).
 * @return array{tekst:string, opis:string}
 */
function evk_pasek_licznik(?string $tekst = null, string $opis = ''): array {
    static $licznik = ['tekst' => '', 'opis' => ''];
    if ($tekst !== null) $licznik = ['tekst' => $tekst, 'opis' => $opis];
    return $licznik;
}

/** Na stronie, z paskiem — nie w kokpicie ani w builderze. */
function evk_pasek_tutaj(): bool {
    return !is_admin() && is_user_logged_in() && !(function_exists('evk_w_builderze') && evk_w_builderze());
}

/* Link do panelu — tylko administrator. */
add_action('admin_bar_menu', function (WP_Admin_Bar $pasek): void {
    if (!evk_pasek_tutaj() || !current_user_can('manage_options')) return;
    $pasek->add_node(['parent' => evk_pasek_grupa('panel'), 'id' => 'evk-panel', 'title' => 'Panel Evoke ONE',
        'href' => admin_url('options-general.php?page=evoke-one')]);
}, 100);

/*
 * Na końcu: grupy, które ktoś zapełnił, i samo menu. Pasek wiąże pozycje
 * z rodzicami dopiero przy wypisaniu, więc kolejność dodania nie gra roli —
 * poza kolejnością grup, którą ustala ta funkcja.
 */
add_action('admin_bar_menu', function (WP_Admin_Bar $pasek): void {
    if (!evk_pasek_tutaj()) return;
    $zajete = [];
    foreach ((array) $pasek->get_nodes() as $n) {
        if (is_object($n) && strpos((string) $n->parent, EVK_PASEK . '-') === 0) $zajete[(string) $n->parent] = true;
    }
    if (!$zajete) return;
    $l = evk_pasek_licznik();
    $tytul = '<span class="ab-icon dashicons dashicons-star-filled" aria-hidden="true"></span><span class="ab-label">Evoke</span>'
        . ($l['tekst'] !== '' ? '<span class="evk-pasek-licznik">' . esc_html($l['tekst']) . '</span>' : '');
    $pasek->add_node(['id' => EVK_PASEK, 'title' => $tytul,
        'meta' => ['title' => $l['opis'] !== '' ? $l['opis'] : 'Evoke ONE']]);
    foreach (EVK_PASEK_GRUPY as $g) {
        if (isset($zajete[evk_pasek_grupa($g)])) $pasek->add_group(['parent' => EVK_PASEK, 'id' => evk_pasek_grupa($g)]);
    }
}, PHP_INT_MAX);

/*
 * Wygląd: nagłówki grup, licznik, telefon. WordPress na wąskim ekranie
 * chowa w pasku wszystko poza kilkoma pozycjami rdzenia — „Evoke” zostaje
 * (ikona; licznik obok), a rozwinięte menu zajmuje całą szerokość ekranu.
 */
add_action('wp_head', function (): void {
    if (!evk_pasek_tutaj() || !is_admin_bar_showing()) return;
    echo '<style id="evk-pasek">'
        . '#wpadminbar #wp-admin-bar-evk-menu .ab-icon:before{content:"\f155";top:2px}'
        . '#wpadminbar #wp-admin-bar-evk-menu .evk-pasek-licznik{display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;background:rgba(240,246,252,.16);font-size:12px;line-height:20px}'
        . '#wpadminbar #wp-admin-bar-evk-menu .evk-pasek-naglowek>.ab-item{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#9ca2a7;height:auto;line-height:2.2;padding-top:4px}'
        . '#wpadminbar #wp-admin-bar-evk-menu .ab-sub-wrapper>.ab-submenu+.ab-submenu{border-top:1px solid rgba(240,246,252,.12)}'
        . '@media screen and (max-width:782px){'
        . '#wpadminbar li#wp-admin-bar-evk-menu{display:block;position:static}'
        . '#wpadminbar #wp-admin-bar-evk-menu>.ab-item{padding:0 8px;min-width:52px;text-align:center}'
        . '#wpadminbar #wp-admin-bar-evk-menu>.ab-item .ab-icon{margin:0;padding:0;width:36px;height:46px}'
        . '#wpadminbar #wp-admin-bar-evk-menu>.ab-item .ab-icon:before{font:normal 28px/1 dashicons;top:9px;display:block;text-align:center}'
        . '#wpadminbar #wp-admin-bar-evk-menu .evk-pasek-licznik{vertical-align:top;margin-top:13px}'
        . '#wpadminbar #wp-admin-bar-evk-menu>.ab-sub-wrapper{position:fixed;left:0;right:0;top:46px;width:auto;max-height:calc(100vh - 46px);overflow-y:auto}'
        . '#wpadminbar #wp-admin-bar-evk-menu .ab-submenu li{display:block}'
        . '#wpadminbar #wp-admin-bar-evk-menu .evk-pasek-naglowek>.ab-item{font-size:12px;padding:10px 16px 2px}'
        . '}'
        . '</style>';
}, 100);
