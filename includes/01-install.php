<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — inicjalizacja instalacji
 *
 * Zasada: po pierwszej instalacji WSZYSTKIE moduły są wyłączone. Domyślne
 * wartości 'enabled' w modułach wynoszą 0, więc świeża instalacja nie wymaga
 * żadnego zapisu — wystarczy oznaczyć ją znacznikiem instalacji.
 *
 * Migracja: strony działające na starych domyślnych (Tryb ciemny, Dostępność,
 * Schema, OpenGraph, Tłumaczenia, Mapa strony startowały jako WŁĄCZONE)
 * dostają te moduły zapisane jawnie jako włączone. Zmiana domyślnych nie może
 * wyłączyć czegoś, co działało na żywej stronie.
 */

const EVK_ONE_INSTALL_OPTION = 'evk_one_install_state';

/**
 * Moduły, które przed 1.20.0 miały domyślne 'enabled' = 1.
 * Wartość: 'array' — opcja tablicowa z polem 'enabled'; 'scalar' — flaga 0/1.
 */
function evk_one_legacy_enabled_modules(): array {
    return [
        'evk_darkmode'          => 'array',
        'evk_a11y'              => 'array',
        'evk_schema'            => 'array',
        'evk_og'                => 'array',
        'tl_sitemap_settings'   => 'array',
        'evk_tl_module_enabled' => 'scalar',
        'evk_tl_fab_enabled'    => 'scalar',
    ];
}

/**
 * Czy w bazie są ślady wcześniejszej konfiguracji Evoke ONE?
 * Jeśli tak — to aktualizacja, a nie pierwsza instalacja.
 */
function evk_one_is_existing_install(): bool {
    $fingerprints = [
        'evk_darkmode', 'evk_cursor', 'evk_lenis', 'evk_parallax', 'evk_a11y',
        'evk_fonts', 'evk_schema', 'evk_og', 'evk_security', 'evk_smtp',
        'evk_white_label', 'evk_newsletter', 'evk_forminbox', 'evk_elements',
        'evk_cleanup', 'tl_translations', 'tl_languages', 'tl_sitemap_settings',
        'evk_tl_module_enabled', 'maintenance_mode', 'evoke_dashboard_active',
    ];
    foreach ($fingerprints as $option) {
        if (get_option($option, null) !== null) return true;
    }
    return false;
}

/**
 * Uruchamiane raz — przy pierwszym załadowaniu wtyczki po instalacji
 * lub po aktualizacji z wersji sprzed znacznika instalacji.
 */
function evk_one_maybe_install(): void {
    if (get_option(EVK_ONE_INSTALL_OPTION, '')) return;

    // Świeża instalacja → nic nie zapisujemy, domyślne 'enabled' = 0 wystarczą.
    if (evk_one_is_existing_install()) {
        foreach (evk_one_legacy_enabled_modules() as $option => $type) {
            if ($type === 'scalar') {
                if (get_option($option, null) === null) {
                    update_option($option, 1);
                }
                continue;
            }

            $current = get_option($option, null);
            if (!is_array($current)) $current = [];
            if (array_key_exists('enabled', $current)) continue;

            $current['enabled'] = 1;
            update_option($option, $current);
        }
    }

    update_option(EVK_ONE_INSTALL_OPTION, EVOKE_ONE_VERSION);
}

evk_one_maybe_install();
