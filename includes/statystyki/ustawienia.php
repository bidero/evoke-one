<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: ustawienia, uprawnienie, menu raportów (1.283.0).
 *
 * Własne statystyki bez cookies (decyzje zgłaszającego z 02.10):
 *   - ogólny włącznik, domyślnie WYŁĄCZONY — bez niego nie ma skryptu na
 *     stronie, trasy REST, crona ani pozycji menu;
 *   - unikalni z dobowo zmienianej soli (skrót adresu IP i przeglądarki),
 *     bez cookies i bez baneru zgody; boty odfiltrowane;
 *   - surowe wpisy trzymane przez czas z panelu (domyślnie 90 dni),
 *     zbiorcze dzienne — na zawsze;
 *   - nie liczy zalogowanych redaktorów i administratorów oraz adresów IP
 *     z listy; sygnał „nie śledź” (DNT / GPC) domyślnie szanowany;
 *   - raporty w osobnym menu „Statystyki” (miejsce do wyboru, domyślnie
 *     osobna pozycja) dla administratora i ról z uprawnieniem „Statystyki”.
 */

const EVK_STAT_OPCJA      = 'evk_statystyki';
const EVK_STAT_MENU_SLUG  = 'evoke-statystyki';
const EVK_STAT_UPRAWNIENIE = 'evk_access_stats';
/** Miejsca menu raportów: wartość → etykieta w panelu. */
const EVK_STAT_MIEJSCA_MENU = ['osobna' => 'Osobna pozycja', 'index.php' => 'Kokpit', 'options-general.php' => 'Ustawienia', 'tools.php' => 'Narzędzia'];
/** Czas trzymania surowych wpisów (dni) — wybór w panelu. */
const EVK_STAT_RETENCJE = [30 => '30 dni', 90 => '90 dni', 180 => '180 dni', 365 => '1 rok', 730 => '2 lata'];

/** @return array<string,mixed> */
function evk_stat_domyslne(): array {
    return [
        'enabled'      => 0,
        'retencja'     => 90,
        'dnt'          => 1,     // szanuj Do Not Track / Global Privacy Control
        'wyklucz_role' => 1,     // nie licz zalogowanych z edit_posts (redaktorzy, administratorzy)
        'wyklucz_ip'   => '',    // adresy i sieci (CIDR), linia albo przecinek
        'menu'         => 'osobna',
        'licznik'      => 0,     // licznik odsłon tej strony w pasku admina (1.285.0)
        /* Zdarzenia automatyczne (1.285.0) — wszystkie od razu, każde do wyłączenia. */
        'zd_tel'        => 1,    // tel: i mailto:
        'zd_pobrania'   => 1,
        'zd_wychodzace' => 1,
        'zd_formularze' => 1,
    ];
}

/** @return array<string,mixed> */
function evk_stat_ustawienia(): array {
    $z = get_option(EVK_STAT_OPCJA, []);
    return array_merge(evk_stat_domyslne(), is_array($z) ? $z : []);
}

function evk_stat_wlaczone(): bool {
    return !empty(evk_stat_ustawienia()['enabled']);
}

/**
 * Walidacja zapisu z zakładki. `enabled` zostaje, jeśli formularz go nie
 * przysłał (przełącznik ma własny zapis — evk_preserve_toggle).
 *
 * @param mixed $wej
 * @return array<string,mixed>
 */
function evk_stat_sanitize($wej): array {
    $wej = is_array($wej) ? $wej : [];
    $d   = evk_stat_domyslne();
    $ret = (int) ($wej['retencja'] ?? $d['retencja']);
    $ip  = function_exists('evk_ip_lista_sieci') ? evk_ip_lista_sieci((string) ($wej['wyklucz_ip'] ?? '')) : [];
    $menu = (string) ($wej['menu'] ?? $d['menu']);
    return [
        'enabled'      => evk_preserve_toggle($wej, EVK_STAT_OPCJA),
        'retencja'     => array_key_exists($ret, EVK_STAT_RETENCJE) ? $ret : $d['retencja'],
        'dnt'          => !empty($wej['dnt']) ? 1 : 0,
        'wyklucz_role' => !empty($wej['wyklucz_role']) ? 1 : 0,
        'wyklucz_ip'   => implode("\n", $ip),
        'menu'         => array_key_exists($menu, EVK_STAT_MIEJSCA_MENU) ? $menu : $d['menu'],
        'licznik'      => !empty($wej['licznik']) ? 1 : 0,
        'zd_tel'        => !empty($wej['zd_tel']) ? 1 : 0,
        'zd_pobrania'   => !empty($wej['zd_pobrania']) ? 1 : 0,
        'zd_wychodzace' => !empty($wej['zd_wychodzace']) ? 1 : 0,
        'zd_formularze' => !empty($wej['zd_formularze']) ? 1 : 0,
    ];
}

/** Czy bieżący użytkownik widzi raporty. */
function evk_stat_moze_czytac(): bool {
    return current_user_can('manage_options') || current_user_can(EVK_STAT_UPRAWNIENIE);
}

/* Zapis z zakładki panelu (AJAX). Same ustawienia — tylko administrator;
   rola z uprawnieniem „Statystyki” czyta raporty, ale ich nie konfiguruje. */
add_action('wp_ajax_evk_stat_zapisz', function (): void {
    check_ajax_referer('evk_stat', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    $dane = json_decode(wp_unslash((string) ($_POST['dane'] ?? '')), true);
    $nowe = evk_stat_sanitize(is_array($dane) ? $dane : []);
    update_option(EVK_STAT_OPCJA, $nowe);
    wp_send_json_success($nowe);
});

/* Kasowanie statystyk za okres (1.285.0) — tylko administrator. Najpierw
   `licz` (liczba do potwierdzenia), potem właściwe usunięcie. */
add_action('wp_ajax_evk_stat_usun', function (): void {
    check_ajax_referer('evk_stat', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    if (!empty($_POST['wszystko'])) {
        [$od, $do] = EVK_STAT_WSZYSTKO;
    } else {
        $od = evk_stat_data((string) wp_unslash($_POST['od'] ?? ''));
        $do = evk_stat_data((string) wp_unslash($_POST['do'] ?? ''));
        if ($od === '' || $do === '') wp_send_json_error('Podaj obie daty.');
        if ($od > $do) wp_send_json_error('Data „od” jest późniejsza niż „do”.');
    }
    $ile = evk_stat_do_usuniecia($od, $do);
    if (!empty($_POST['licz'])) wp_send_json_success(['ile' => $ile]);
    $w = evk_stat_usun_okres($od, $do);
    wp_send_json_success(['ile' => $ile, 'usuniete' => $w]);
});

// =========================================================================
// MENU RAPORTÓW
// =========================================================================

add_action('admin_menu', function (): void {
    if (!evk_stat_wlaczone()) return;
    $miejsce = (string) evk_stat_ustawienia()['menu'];
    $cap     = current_user_can('manage_options') ? 'manage_options' : EVK_STAT_UPRAWNIENIE;
    if ($miejsce === 'osobna' || !array_key_exists($miejsce, EVK_STAT_MIEJSCA_MENU)) {
        add_menu_page('Statystyki', 'Statystyki', $cap, EVK_STAT_MENU_SLUG, 'evk_stat_render_raport', 'dashicons-chart-area', 3);
    } else {
        add_submenu_page($miejsce, 'Statystyki', 'Statystyki', $cap, EVK_STAT_MENU_SLUG, 'evk_stat_render_raport');
    }
}, 99);

function evk_stat_adres_raportu(): string {
    $miejsce = (string) evk_stat_ustawienia()['menu'];
    if ($miejsce === 'options-general.php') return admin_url('options-general.php?page=' . EVK_STAT_MENU_SLUG);
    if ($miejsce === 'index.php' || $miejsce === 'tools.php') return admin_url($miejsce . '?page=' . EVK_STAT_MENU_SLUG);
    return admin_url('admin.php?page=' . EVK_STAT_MENU_SLUG);
}
