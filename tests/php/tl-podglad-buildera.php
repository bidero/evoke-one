<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Podgląd tłumaczeń w builderze (1.257.0, #82) — strona PHP na pierwszym testowym
 * WordPressie: co trafia do kanwy (dane + skrypt), a czego nie ma w powłoce i na
 * stronie. Zachowanie w przeglądarce sprawdza tests/tl-podglad-buildera.test.js
 * na fixturze z tymi danymi.
 *
 *   php tests/php/tl-podglad-buildera.php przygotuj        kopia opcji; Tłumaczenia, języki, słownik, mapa pól
 *   php tests/php/tl-podglad-buildera.php dane             dane skryptu kanwy (JSON)
 *   php tests/php/tl-podglad-buildera.php stopka <kontekst> kanwa | kanwa-gosc | powloka | front
 *   php tests/php/tl-podglad-buildera.php sprzataj         opcje jak przed testem
 */
$krok = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-podglad.json';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_dd_keys', 'evk_tl_el_pola'];
$out   = ['krok' => $krok];

function evk_t_pb_bez_pamieci(): void {
    foreach (['tl_compiled_slugs', 'tl_compiled_config', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) delete_transient($t);
}

switch ($krok) {

case 'przygotuj':
    if (!is_file($plik)) {
        $kopia = [];
        foreach ($opcje as $o) $kopia[$o] = get_option($o, '__brak__');
        file_put_contents($plik, (string) wp_json_encode($kopia));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    update_option('tl_dd_keys', ['zobacz' => 'Zobacz więcej']);
    update_option('tl_translations', ['groups' => ['g' => ['name' => 'Podgląd', 'rows' => [
        'r1' => ['pl' => 'Zobacz więcej', 'en' => 'See more', 'de' => 'Mehr sehen'],
        /* Klucz tylko w wierszu (bez tl_dd_keys) — ta sama droga co tl_get_dd_value(). */
        'r2' => ['pl' => 'Napisz do nas', 'en' => 'Write to us', 'dd_key' => 'napisz'],
        /* Fraza ze znacznikiem zamykającym skrypt — ma dojść jako tekst, nie kod. */
        'r3' => ['pl' => 'Uwaga </script><b>', 'en' => 'Mind </script><b>', 'dd_key' => 'uwaga'],
    ]]]]);
    update_option('evk_tl_el_pola', [
        'heading'    => ['pola' => ['text'], 'listy' => []],
        'text-basic' => ['pola' => ['text'], 'listy' => []],
        'button'     => ['pola' => ['text'], 'listy' => []],
        'icon-box'   => ['pola' => ['text'], 'listy' => []],
        'accordion'  => ['pola' => [], 'listy' => ['accordions' => ['title']]],
        'slider'     => ['pola' => ['title'], 'listy' => ['items' => ['title']]],
    ], false);
    evk_t_pb_bez_pamieci();
    $out['gotowe'] = true;
    break;

case 'dane':
    if (!function_exists('evk_tl_podglad_dane')) { $out['brak'] = 'moduł 58-translation-builder-preview.php nieaktywny'; break; }
    $out['dane'] = evk_tl_podglad_dane();
    break;

case 'stopka':
    $kontekst = $argv[2] ?? '';
    if ($kontekst === 'kanwa' || $kontekst === 'kanwa-gosc') { $_GET['bricks'] = 'run'; $_GET['brickspreview'] = 'true'; }
    if ($kontekst === 'powloka') { $_GET['bricks'] = 'run'; }
    wp_set_current_user($kontekst === 'kanwa-gosc' ? 0 : 1);
    ob_start();
    do_action('wp_enqueue_scripts');
    do_action('wp_footer');
    $html = (string) ob_get_clean();
    $out['kontekst'] = $kontekst;
    $out['dane']     = strpos($html, 'id="evk-tl-podglad-dane"') !== false;
    $out['skrypt']   = strpos($html, 'assets/admin/tl-builder-podglad.js') !== false;
    $out['stary']    = strpos($html, 'TL_DD_LABELS') !== false;
    /* Dane: `</script>` z frazy słownika nie może zamknąć bloku danych. */
    if (preg_match('#<script type="application/json" id="evk-tl-podglad-dane">(.*?)</script>#s', $html, $m)) {
        $json = json_decode($m[1], true);
        $out['json_poprawny'] = is_array($json);
        $out['uwaga_en']      = is_array($json) ? ($json['slownik']['uwaga']['en'] ?? null) : null;
    }
    break;

case 'sprzataj':
    $kopia = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ($kopia as $o => $v) {
        if ($v === '__brak__') delete_option($o); else update_option($o, $v);
    }
    evk_t_pb_bez_pamieci();
    @unlink($plik);
    $out['przywrocone'] = array_keys($kopia);
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
