<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * PRAWDZIWY builder Bricksa (1.274.0): piąty testowy WordPress (bricks.test)
 * z licencją ze zmiennej EVK_BRICKS_KLUCZ (tools/testowy-wp.sh). Test
 * tests/bricks-builder.test.js otwiera builder w Chromium przez `php -S`.
 *
 *   php tests/php/bricks-builder.php wp           motyw, wersja, licencja
 *   php tests/php/bricks-builder.php modul        kopia opcji, moduł Tłumaczeń, język EN (osobny proces)
 *   php tests/php/bricks-builder.php mu           atrapa dostawców AI jako mu-plugin, ustawienia AI (Gemini)
 *   php tests/php/bricks-builder.php ustaw        komponent, strona, builder dla stron; mapa pól pusta
 *   php tests/php/bricks-builder.php przejscie-raz  jednorazowe przejście (51) przy pustej mapie
 *   php tests/php/bricks-builder.php stan         właściwości komponentu w bazie, mapa, przejście
 *   php tests/php/bricks-builder.php sprzataj
 *
 * Komponent „Nowy” (kształt z prób na testowej, docs/proby-komponenty.md):
 * nagłówek z właściwością „Nagłówek”, ręczny bliźniak „Nagłówek EN” (brknwe)
 * i bliźniak „Nagłówek DE” języka, którego nie ma w ustawieniach (51 go
 * zostawia — wpisane tłumaczenia wracają z językiem). Instancje: A (tekst
 * i EN), B (sam tekst).
 *
 * Krok `ustaw` zostawia stan jak po aktualizacji na świeżej stronie: mapa pól
 * (evk_tl_el_pola) pusta, jednorazowe przejście po komponentach (51) przed
 * nami. Do 1.273.0 przejście z pustą mapą kasowało oba bliźniaki: bez mapy
 * wyglądały na osierocone. Krok `przejscie-raz` woła je wprost.
 */

$krok = $argv[1] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-bricks-builder.json';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'evk_tl_el_pola', 'bricks_components', 'evk_tl_kp_stan',
          'evk_tl_kp_przejscie', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'bricks_global_settings'];
$mu     = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-bricks-builder.php';
$out    = ['krok' => $krok];
const EVK_TBB_TYTUL = 'Builder Bricks KP';

function evk_tbb_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {

case 'wp':
    $t = wp_get_theme();
    $licencja = null;
    if (class_exists('\Bricks\License')) {
        \Bricks\License::$license_key = \Bricks\License::get_license_key();
        $licencja = \Bricks\License::license_is_valid();
    }
    $out += ['wp' => untrailingslashit(ABSPATH), 'motyw' => $t->get('Name'), 'bricks' => defined('BRICKS_VERSION') ? BRICKS_VERSION : null,
        'licencja' => $licencja, 'klucz' => getenv('EVK_BRICKS_KLUCZ') !== false && getenv('EVK_BRICKS_KLUCZ') !== ''];
    break;

case 'modul':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'mu_bylo' => is_dir($mu)]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US']]);
    $out['gotowe'] = true;
    break;

/* Atrapa dostawców jak w tl-ai.php: podkatalog wczytywany tylko pod `php -S`. */
case 'mu':
    $bramka = "if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }";
    $atrapa = (string) file_get_contents(__DIR__ . '/_ai-atrapa.php');
    if (strpos($atrapa, $bramka) === false || strpos($atrapa, '<?php') !== 0) { $out['brak'] = 'atrapa bez bramki CLI w pierwszych liniach'; break; }
    if (!is_dir($mu . '/evk-t-bricks-builder')) mkdir($mu . '/evk-t-bricks-builder', 0755, true);
    file_put_contents($mu . '/evk-t-bricks-builder/atrapa.php', str_replace($bramka, "if (!defined('ABSPATH')) exit;", $atrapa));
    file_put_contents($muPlik, "<?php\n// Wyłącznie test bricks-builder (tests/php/bricks-builder.php) — usuwany po teście.\n"
        . "if (PHP_SAPI !== 'cli-server') return;\n"
        . "\$GLOBALS['evk_t_ai_scenariusz'] = 'ok';\n"
        . "\$GLOBALS['evk_t_ai_kod'] = isset(\$_POST['lang']) && is_string(\$_POST['lang']) ? \$_POST['lang'] : 'en';\n"
        . "require __DIR__ . '/evk-t-bricks-builder/atrapa.php';\n");
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-ai-123'], 'modele' => [], 'opis' => '',
        'wskazowki' => [], 'slowniczek' => ''], false);
    $out['mu'] = is_file($muPlik);
    break;

case 'ustaw':
    if (!function_exists('evk_tl_kp_uzupelnij')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_tbb_zapis();
    $kn = ['id' => 'brkn01', 'category' => '', 'desc' => '', 'elements' => [
            ['id' => 'brkn01', 'name' => 'block', 'parent' => 0, 'children' => ['brknnh'], 'settings' => [], 'label' => 'Nowy'],
            ['id' => 'brknnh', 'name' => 'heading', 'parent' => 'brkn01', 'children' => [], 'settings' => ['text' => 'Nagłówek nowego', 'tag' => 'h3']],
        ], 'properties' => [
            ['label' => 'Nagłówek', 'type' => 'text', 'id' => 'brknwn', 'connections' => ['brknnh' => ['text']]],
            ['label' => 'Nagłówek EN', 'type' => 'text', 'id' => 'brknwe', 'connections' => ['brknnh' => ['evk_tl_en__text']]],
            ['label' => 'Nagłówek DE', 'type' => 'text', 'id' => 'brknwd', 'connections' => ['brknnh' => ['evk_tl_de__text']]],
        ]];
    $GLOBALS['evk_tl_kp_bez_uzupelnienia'] = true;
    update_option('bricks_components', [$kn]);
    unset($GLOBALS['evk_tl_kp_bez_uzupelnienia']);
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TBB_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => EVK_TBB_TYTUL, 'post_type' => 'page', 'post_status' => 'publish']);
    $inst = static function (string $id, array $wl) {
        return ['id' => $id, 'name' => 'block', 'parent' => 'bbs001', 'children' => [], 'settings' => [], 'cid' => 'brkn01', 'properties' => $wl];
    };
    update_post_meta($id, '_bricks_editor_mode', 'bricks');
    update_post_meta($id, '_bricks_page_content_2', wp_slash([
        ['id' => 'bbs001', 'name' => 'section', 'parent' => 0, 'children' => ['bbina1', 'bbinb1'], 'settings' => []],
        $inst('bbina1', ['brknwn' => 'Nagłówek A', 'brknwe' => 'HEADING A EN', 'brknwd' => 'ÜBERSCHRIFT A']),
        $inst('bbinb1', ['brknwn' => 'Nagłówek B']),
    ]));
    /* Builder Bricksa tylko dla typów z ustawień — bez tego odsyła do listy stron. */
    $s = get_option('bricks_global_settings', []);
    $s = is_array($s) ? $s : [];
    $s['postTypes'] = ['page'];
    update_option('bricks_global_settings', $s);
    /* Jak po aktualizacji na świeżej stronie: mapa pusta, przejście przed nami. */
    delete_option('evk_tl_el_pola');
    delete_option('evk_tl_kp_przejscie');
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strona' => $id, 'gotowe' => $id > 0];
    break;

/* Jednorazowe przejście po aktualizacji (51) wprost, przy pustej mapie — w przeglądarce
   mapę wypełnia już pierwsze żądanie frontu (np. /favicon.ico przez router), zanim
   ruszy panel, więc „pusta mapa przy logowaniu” nie jest warunkiem, który da się ustawić. */
case 'przejscie-raz':
    if (evk_tl_el_mapa()) { $out['brak'] = 'mapa pól nie jest pusta — najpierw krok „ustaw”'; break; }
    evk_tl_kp_przejscie_raz();
    $k = (array) get_option('bricks_components', []);
    $out += ['wlasciwosci' => array_map(static function ($p) { return $p['label'] . ':' . $p['id']; }, (array) ($k[0]['properties'] ?? [])),
        'przejscie' => get_option('evk_tl_kp_przejscie', null)];
    break;

case 'stan':
    $k = (array) get_option('bricks_components', []);
    $out += ['wlasciwosci' => array_map(static function ($p) { return $p['label'] . ':' . $p['id']; }, (array) ($k[0]['properties'] ?? [])),
        'mapa' => count(evk_tl_el_mapa()), 'przejscie' => get_option('evk_tl_kp_przejscie', null)];
    break;

case 'sprzataj':
    $zapis = evk_tbb_zapis();
    @unlink($muPlik);
    @unlink($mu . '/evk-t-bricks-builder/atrapa.php');
    @rmdir($mu . '/evk-t-bricks-builder');
    if (array_key_exists('mu_bylo', $zapis) && !$zapis['mu_bylo'] && is_dir($mu) && count((array) scandir($mu)) === 2) @rmdir($mu);
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
