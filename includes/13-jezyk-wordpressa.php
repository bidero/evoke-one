<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — język WordPressa na wersjach językowych (1.258.0).
 *
 * ZGŁOSZONE: na /en/ daty i teksty WordPressa i Bricksa były po polsku
 * („29 września”, komunikaty formularzy, stronicowanie). Wtyczka rozpoznawała
 * język z adresu (10-language-system.php), ale WordPress pracował dalej
 * w języku witryny: `wp_date()`, `date_i18n()` i każde `__()` brały polskie
 * tłumaczenia. Słownikiem się tego nie naprawi, bo data nie jest frazą.
 *
 * JAK. Filtr `locale` podaje język WordPressa dla wersji językowej. Działa od
 * pierwszego wywołania `get_locale()`, czyli ZANIM WordPress wczyta
 * tłumaczenia rdzenia (`load_default_textdomain()` po `setup_theme`)
 * i zbuduje `$wp_locale` z nazwami miesięcy. Dlatego nic nie trzeba
 * przeładowywać, ale też język musi przyjść z samego adresu
 * (`tl_jezyk_z_adresu()`) — wykrywanie na `init` jest później.
 *
 * GDZIE NIE:
 *   - panel (tam obowiązuje język użytkownika, `get_user_locale()`);
 *   - builder i jego kanwa: kanwa pokazuje polski oryginał, a podgląd EN robi
 *     skrypt z 1.257.0; podgląd szablonu Bricksa tak samo;
 *   - język bazowy (polski) — zostaje językiem witryny.
 * `switch_to_locale()` (np. mail w języku odbiorcy) wygrywa: przełącznik
 * WordPressa dokłada swój filtr `locale` później niż wtyczki, więc działa po nas.
 *
 * JĘZYK WITRYNY. Po `get_locale()` w wp-settings.php globalne `$locale` ma
 * już wartość z filtra, więc na /en/ samo `get_locale()` odpowiada „en_US”.
 * Kto potrzebuje języka witryny (og:locale:alternate polskiej wersji w
 * 85-seo.php), czyta `tl_locale_witryny()`.
 *
 * KOD LOCALE z kodu HTML języka (Tłumaczenia → Języki): `en-US` → `en_US`,
 * `de-DE` → `de_DE`. Sam kod bez regionu (`de`) → zainstalowana paczka tego
 * języka, a bez niej `de_DE`. Brak paczki nie psuje strony: WordPress pokazuje
 * wtedy teksty angielskie, daty też — i zakładka Języki o tym mówi.
 */

/**
 * Język witryny z ustawień, BEZ filtra `locale` — kolejność źródeł jak
 * w `get_locale()`: paczka instalacyjna, stała WPLANG, opcja WPLANG.
 */
function tl_locale_witryny(): string {
    global $wp_local_package;
    $locale = isset($wp_local_package) ? (string) $wp_local_package : '';
    if (defined('WPLANG')) $locale = (string) WPLANG;
    if (is_multisite()) {
        $ms = wp_installing() ? get_site_option('WPLANG') : get_option('WPLANG');
        if ($ms === false && !wp_installing()) $ms = get_site_option('WPLANG');
        if ($ms !== false) $locale = (string) $ms;
    } else {
        $db = get_option('WPLANG');
        if ($db !== false) $locale = (string) $db;
    }
    return $locale !== '' ? $locale : 'en_US';
}

/** Paczki językowe WordPressa na serwerze (pliki w wp-content/languages). */
function tl_locale_zainstalowane(): array {
    static $pamiec = null;
    if ($pamiec === null) $pamiec = array_values(array_map('strval', (array) get_available_languages()));
    return $pamiec;
}

/**
 * Locale WordPressa z kodu HTML języka — bez ustawień, do sprawdzenia wprost.
 *   `en-US` → `en_US`; region wielkimi literami, reszta bez zmian
 *   (`de-DE-formal` → `de_DE_formal`);
 *   sam język (`de`) → zainstalowana paczka (`de`, `de_DE`, potem dowolna
 *   `de_*`), a bez niej `de_DE`; `en` → `en_US`.
 * Kod HTML nie do odczytania → kod języka strony ($lang); i ten zły → ''.
 *
 * @param list<string>|null $zainstalowane null = paczki z serwera (tylko gdy potrzebne)
 */
function tl_locale_z_kodu(string $html, string $lang, ?array $zainstalowane = null): string {
    $czlony = explode('_', str_replace('-', '_', trim($html)));
    $jezyk  = strtolower((string) $czlony[0]);
    if (!preg_match('/^[a-z]{2,3}$/', $jezyk)) {
        $czlony = [$lang];
        $jezyk  = strtolower($lang);
        if (!preg_match('/^[a-z]{2,3}$/', $jezyk)) return '';
    }
    if (isset($czlony[1]) && preg_match('/^[A-Za-z]{2}$/', $czlony[1])) {
        $czlony[1] = strtoupper($czlony[1]);
        return $jezyk . '_' . implode('_', array_slice($czlony, 1));
    }
    $zainstalowane = $zainstalowane ?? tl_locale_zainstalowane();
    foreach ([$jezyk, $jezyk . '_' . strtoupper($jezyk)] as $dokladny) {
        if (in_array($dokladny, $zainstalowane, true)) return $dokladny;
    }
    foreach ($zainstalowane as $l) {
        if (strpos((string) $l, $jezyk . '_') === 0) return (string) $l;
    }
    return $jezyk === 'en' ? 'en_US' : $jezyk . '_' . strtoupper($jezyk);
}

/**
 * Locale WordPressa dla języka strony; '' dla języka spoza ustawień.
 * Filtr `evk_tl_locale_wp` pozwala wskazać inne (np. `de_DE_formal`).
 */
function tl_locale_wp(string $lang): string {
    $jezyki = tl_get_languages();
    if (!isset($jezyki[$lang])) return '';
    $wynik = tl_locale_z_kodu((string) ($jezyki[$lang]['html'] ?? ''), $lang);
    return $wynik === '' ? '' : (string) apply_filters('evk_tl_locale_wp', $wynik, $lang);
}

/**
 * Filtr `locale`: na wersji językowej język WordPressa tej wersji.
 *
 * @param mixed $locale
 * @return mixed
 */
function tl_filtr_locale($locale) {
    static $pamiec = [];
    if (!is_string($locale) || $locale === '') return $locale;
    if (is_admin() || evk_w_builderze() || tl_is_bricks_preview()) return $locale;
    $klucz = (string) ($_SERVER['REQUEST_URI'] ?? '') . '|' . (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if (!array_key_exists($klucz, $pamiec)) {
        $lang = tl_jezyk_z_adresu();
        $pamiec[$klucz] = $lang !== '' ? tl_locale_wp($lang) : '';
    }
    return $pamiec[$klucz] !== '' ? $pamiec[$klucz] : $locale;
}
add_filter('locale', 'tl_filtr_locale');

/**
 * Stan języków WordPressa dla zakładki Języki: locale i to, czy jest paczka.
 * `en_US` jest wbudowany (rdzeń pisze po angielsku).
 *
 * @return list<array{kod:string,nazwa:string,locale:string,stan:string}>
 */
function tl_paczki_jezykow(): array {
    $zainstalowane = tl_locale_zainstalowane();
    $out = [];
    foreach (tl_get_languages() as $kod => $jezyk) {
        $locale = tl_locale_wp((string) $kod);
        if ($locale === '') continue;
        $stan = $locale === 'en_US' ? 'wbudowany' : (in_array($locale, $zainstalowane, true) ? 'jest' : 'brak');
        $out[] = ['kod' => (string) $kod, 'nazwa' => (string) ($jezyk['name'] ?? $kod), 'locale' => $locale, 'stan' => $stan];
    }
    return $out;
}

/**
 * Pobranie paczki rdzenia z WordPress.org (to samo, co przy zmianie języka
 * witryny w Ustawieniach, ale bez zmiany języka witryny). Tylko dla locale
 * języków z ustawień i tylko dla kogoś, kto może instalować języki.
 */
add_action('wp_ajax_tl_pobierz_paczke', function () {
    evk_tl_ajax_check();
    if (!current_user_can('install_languages')) wp_send_json_error('Brak uprawnień do instalowania języków.', 403);
    $locale = sanitize_text_field(wp_unslash((string) ($_POST['locale'] ?? '')));
    $znane  = array_column(tl_paczki_jezykow(), 'locale');
    if ($locale === '' || !in_array($locale, $znane, true)) wp_send_json_error('Nieznany język.');
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/translation-install.php';
    if (!wp_can_install_language_pack()) {
        wp_send_json_error('Serwer nie pozwala instalować paczek językowych (zapis w wp-content/languages).');
    }
    $wynik = wp_download_language_pack($locale);
    if (!$wynik) wp_send_json_error('Nie udało się pobrać paczki ' . $locale . ' z WordPress.org.');
    wp_send_json_success('Zainstalowano paczkę ' . $wynik . '.');
});
