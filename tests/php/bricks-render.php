<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Render z PRAWDZIWYM Bricksem (1.274.0): piąty testowy WordPress
 * (bricks.test, prefiks bricks_, motyw z prywatnego bidero/bricks-motyw —
 * tools/testowy-wp.sh). Strona z elementami i instancjami komponentu, render
 * przez `php -S` w teście.
 *
 *   php tests/php/bricks-render.php wp
 *   php tests/php/bricks-render.php modul        kopia opcji, moduł Tłumaczeń, język EN (osobny proces)
 *   php tests/php/bricks-render.php ustaw        komponenty i strona testu
 *   php tests/php/bricks-render.php stan         mapa pól, opcja komponentów, treść strony
 *   php tests/php/bricks-render.php tlumaczalne  pola elementów Evoke z polami „… EN” (prawdziwe kontrolki)
 *   php tests/php/bricks-render.php przejscie    przejście po komponentach (jak po aktualizacji)
 *   php tests/php/bricks-render.php sprzataj
 *
 * Komponenty (kształt z prób na testowej, docs/proby-komponenty.md):
 *   KS — „Stary”: nagłówek z właściwością, BEZ bliźniaka EN (zapis z pominięciem
 *        filtra uzupełniania) i z tłumaczeniem w komponencie — błąd z próby 1;
 *   KN — „Nowy”: nagłówek z właściwością i bliźniakiem EN, tekst stały
 *        z tłumaczeniem w komponencie.
 */

$krok = $argv[1] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-bricks-render.json';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'evk_tl_el_pola', 'bricks_components', 'evk_tl_kp_stan', 'evk_tl_kp_przejscie', 'evk_elements'];
$out   = ['krok' => $krok];
const EVK_TBR_TYTUL = 'Render Bricks KP';

function evk_tbr_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {

case 'wp':
    $t = wp_get_theme();
    $out += ['wp' => untrailingslashit(ABSPATH), 'motyw' => $t->get('Name'), 'wersja' => $t->get('Version'),
        'bricks' => defined('BRICKS_VERSION') ? BRICKS_VERSION : null, 'opcja' => defined('BRICKS_DB_COMPONENTS') ? BRICKS_DB_COMPONENTS : null];
    break;

/* Moduł Tłumaczeń wczytuje się przy starcie procesu — włączony tu, działa od następnego kroku. */
case 'modul':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US']]);
    /* Wszystkie elementy Evoke (domyślnie wyłączone) — krok „tlumaczalne”. */
    update_option('evk_elements', array_fill_keys(array_keys(evk_elements_registry()), 1));
    $out['gotowe'] = true;
    break;

/* Pola elementów Evoke z polami „… EN” — werdykt 51 na PRAWDZIWYCH definicjach
   kontrolek z rejestracji w Bricksie (1.275.0: wartości techniczne z `evkTlPomin`). */
case 'tlumaczalne':
    $out['pola'] = [];
    foreach ((array) \Bricks\Elements::$elements as $n => $el) {
        if (strpos((string) $n, 'evk-') !== 0) continue;
        \Bricks\Elements::get_element(['name' => $n]);   // kontrolki wczytują się leniwie
        $c = (array) (\Bricks\Elements::$elements[$n]['controls'] ?? []);
        $out['pola'][$n] = array_values(array_filter(array_keys($c), static function ($k) use ($c, $n) { return evk_tl_el_tlumaczalna((string) $k, $c[$k], (string) $n); }));
    }
    ksort($out['pola']);
    break;

case 'ustaw':
    if (!function_exists('evk_tl_kp_uzupelnij')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_tbr_zapis();
    $nagl = static function (string $id, string $rodzic, string $tekst, array $dod = []) {
        return ['id' => $id, 'name' => 'heading', 'parent' => $rodzic, 'children' => [], 'settings' => ['text' => $tekst, 'tag' => 'h3'] + $dod];
    };
    $ks = ['id' => 'brks01', 'category' => '', 'desc' => '', 'elements' => [
            ['id' => 'brks01', 'name' => 'block', 'parent' => 0, 'children' => ['brksnh'], 'settings' => [], 'label' => 'Stary'],
            $nagl('brksnh', 'brks01', 'Nagłówek starego', ['evk_tl_en__text' => 'OLD COMPONENT EN', '_cssId' => 'ks-n']),
        ], 'properties' => [['label' => 'Nagłówek', 'type' => 'text', 'id' => 'brkswn', 'connections' => ['brksnh' => ['text']]]]];
    $kn = ['id' => 'brkn01', 'category' => '', 'desc' => '', 'elements' => [
            ['id' => 'brkn01', 'name' => 'block', 'parent' => 0, 'children' => ['brknnh', 'brknst'], 'settings' => [], 'label' => 'Nowy'],
            $nagl('brknnh', 'brkn01', 'Nagłówek nowego', ['evk_tl_en__text' => 'NEW COMPONENT EN']),
            ['id' => 'brknst', 'name' => 'text-basic', 'parent' => 'brkn01', 'children' => [], 'settings' => ['text' => 'Tekst stały', 'evk_tl_en__text' => 'FIXED TEXT EN']],
        ], 'properties' => [
            ['label' => 'Nagłówek', 'type' => 'text', 'id' => 'brknwn', 'connections' => ['brknnh' => ['text']]],
            ['label' => 'Nagłówek EN', 'type' => 'text', 'id' => 'brknwe', 'connections' => ['brknnh' => ['evk_tl_en__text']]],
        ]];
    /* Bez filtra uzupełniania — „stary” ma zostać bez bliźniaka, jak przed 1.272.0. */
    $GLOBALS['evk_tl_kp_bez_uzupelnienia'] = true;
    update_option('bricks_components', [$ks, $kn]);
    unset($GLOBALS['evk_tl_kp_bez_uzupelnienia']);
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TBR_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => EVK_TBR_TYTUL, 'post_type' => 'page', 'post_status' => 'publish']);
    $inst = static function (string $id, string $cid, ?array $wl) {
        $e = ['id' => $id, 'name' => 'block', 'parent' => 'brs001', 'children' => [], 'settings' => [], 'cid' => $cid];
        if ($wl !== null) $e['properties'] = $wl;
        return $e;
    };
    update_post_meta($id, '_bricks_editor_mode', 'bricks');
    update_post_meta($id, '_bricks_page_content_2', wp_slash([
        ['id' => 'brs001', 'name' => 'section', 'parent' => 0, 'children' => ['brh001', 'brisa1', 'brina1', 'brinb1', 'brinc1'], 'settings' => []],
        $nagl('brh001', 'brs001', 'Zwykły nagłówek', ['evk_tl_en__text' => 'PLAIN HEADING EN']),
        /* SA: „stary”, własny polski tekst — próba 1 pokazała tu tłumaczenie komponentu. */
        $inst('brisa1', 'brks01', ['brkswn' => 'Instancja starego']),
        /* NA: tekst i tłumaczenie; NB: tylko tekst; NC: wstawiona bez wartości. */
        $inst('brina1', 'brkn01', ['brknwn' => 'Nagłówek A', 'brknwe' => 'HEADING A EN']),
        $inst('brinb1', 'brkn01', ['brknwn' => 'Nagłówek B']),
        $inst('brinc1', 'brkn01', null),
    ]));
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strona' => $id, 'gotowe' => $id > 0];
    break;

case 'stan':
    $id = (int) (evk_tbr_zapis()['strona'] ?? 0);
    $out += ['strona' => $id, 'mapa' => array_intersect_key(evk_tl_el_mapa(), array_flip(['heading', 'text-basic', 'block', 'section'])),
        'komponenty' => get_option('bricks_components'), 'tresc' => get_post_meta($id, '_bricks_page_content_2', true),
        'permalink' => get_option('permalink_structure'), 'tryb' => get_post_meta($id, '_bricks_editor_mode', true)];
    break;

/* Przejście po komponentach (jak po aktualizacji, 51): bliźniaki dla „starego” — z mapą pól z prawdziwego renderu. */
case 'przejscie':
    $out['zapisane'] = evk_tl_kp_przejscie();
    $out['wlasciwosci'] = array_map(static function ($k) { return [$k['id'], array_map(static function ($p) { return $p['label'] . ':' . $p['id']; }, $k['properties'])]; },
        (array) get_option('bricks_components'));
    break;

case 'sprzataj':
    $zapis = evk_tbr_zapis();
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
