<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — kolizje z innymi wtyczkami Evoke i z drugą kopią samej siebie.
 *
 * Do 1.233.0 funkcja kolizji stała w evoke-one.php jako
 * `evoke_one_check_conflicts()`. PHP wiąże funkcje z najwyższego poziomu pliku
 * JUŻ PRZY KOMPILACJI: gdy na stronie działały dwie kopie wtyczki (dwa
 * katalogi), druga wywracała stronę błędem „Cannot redeclare function" zanim
 * wykonało się cokolwiek — także strażnik drugiej kopii na górze
 * evoke-one.php. Stąd dwie zmiany (1.233.1):
 *
 *   — w pliku głównym nie ma żadnej deklaracji funkcji ani klasy (pilnuje
 *     tego tests/zapis-wp-dwie-kopie.test.js),
 *   — funkcja ma NOWĄ NAZWĘ. Kopia sprzed 1.233.1 wczytana jako druga
 *     deklaruje u siebie starą nazwę, więc nie zderza się z tą; dalej kończy
 *     na własnym sprawdzeniu kolizji albo nie robi nic, bo jej moduły już są
 *     wczytane (stałe katalogu ma z pierwszej kopii).
 */

function evoke_one_kolizje(): bool {
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    }
    /* Tylko znane kolizje: stare Tłumaczenia, Parallax i WP Maintenance Mode.
       Do 1.231.x stał tu też `/^system.*\.php$/i` — bez śladu, skąd się wziął
       (był już w pierwszym wgraniu evoke-one-old). Każda aktywna wtyczka
       o pliku zaczynającym się od „system" wyłączała CAŁE Evoke ONE,
       zostawiając sam komunikat o konflikcie. */
    $conflict_patterns = [
        '/^evoke-tlumaczenia.*\.php$/i',
        '/^evk-parallax.*\.php$/i',
        '/^wp-maintenance-mode.*\.php$/i',
    ];
    foreach ($active as $plugin_file) {
        if ($plugin_file === plugin_basename(EVOKE_ONE_FILE)) continue;
        $base = basename((string) $plugin_file);
        foreach ($conflict_patterns as $pattern) {
            if (preg_match($pattern, $base)) return true;
        }
    }
    return false;
}

/**
 * Inne AKTYWNE kopie Evoke ONE — wpisy „…/evoke-one.php" inne niż ta, która
 * działa. Tak wygląda strona z ręcznie wgraną kopią z gałęzi obok
 * „evoke-one-main".
 */
function evoke_one_inne_kopie(): array {
    $moja    = plugin_basename(EVOKE_ONE_FILE);
    $aktywne = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $aktywne = array_merge($aktywne, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    }
    return array_values(array_filter(array_unique(array_map('strval', $aktywne)), static function ($p) use ($moja) {
        return $p !== $moja && preg_match('#(^|/)evoke-one\.php$#', $p);
    }));
}

/**
 * Komunikat o drugiej kopii — z kopii, która działa. Kopia 1.233.1+ wczytana
 * jako druga ma własny (strażnik w evoke-one.php); flaga pilnuje, żeby
 * administrator nie dostał dwóch takich samych ramek. Przy włączonym „Usuń
 * dane" ostrzega przed usuwaniem drugiej kopii: kopia sprzed 1.233.1 ma
 * uninstall.php bez sprawdzenia, czy na stronie zostaje inna.
 */
add_action('admin_notices', static function () {
    if (!current_user_can('activate_plugins') || !empty($GLOBALS['evoke_one_kopie_zgloszone'])) return;
    $inne = evoke_one_inne_kopie();
    if (!$inne) return;
    $GLOBALS['evoke_one_kopie_zgloszone'] = true;
    echo '<div class="notice notice-error" data-evk-druga-kopia><p><strong>Evoke ONE jest włączony więcej niż raz.</strong> Działa kopia <code>'
        . esc_html(plugin_basename(EVOKE_ONE_FILE)) . '</code>; wyłącz w Wtyczkach: <code>'
        . implode('</code>, <code>', array_map('esc_html', $inne)) . '</code>.'
        . (get_option('evk_usun_dane') ? ' <strong>Włączone jest „Usuń wszystkie dane przy odinstalowaniu"</strong> — wyłącz je przed usunięciem drugiej kopii.' : '')
        . '</p></div>';
});
