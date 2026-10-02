<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kopiowanie animacji Animatora w PRAWDZIWYM builderze (1.275.0): piąty
 * testowy WordPress (bricks.test) z licencją, test
 * tests/bricks-builder-animator.test.js.
 *
 *   php tests/php/bricks-builder-animator.php wp         motyw, licencja
 *   php tests/php/bricks-builder-animator.php ustaw      Animator z „wjazd”, strona z trzema nagłówkami
 *   php tests/php/bricks-builder-animator.php tresc      ustawienia nagłówków zapisanej strony
 *   php tests/php/bricks-builder-animator.php filtr      filtr render_attributes na lustrze, wpisach ręcznych i liście
 *   php tests/php/bricks-builder-animator.php sprzataj
 *
 * Strona: A — lista Animatora („wjazd”, opóźnienie 0.2); B — sam atrybut
 * data-x (cel wklejenia); C — lista i RĘCZNY wpis data-evk-anim = „wjazd”
 * sprzed lustra (ma zostać nietknięty).
 */

$krok = $argv[1] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-bricks-builder-animator.json';
$opcje = ['evk_animator', 'bricks_global_settings'];
$out   = ['krok' => $krok];
const EVK_TBA_TYTUL = 'Builder Animator lustro';

function evk_tba_zapis(): array {
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
    $out += ['wp' => untrailingslashit(ABSPATH), 'motyw' => $t->get('Name'), 'licencja' => $licencja];
    break;

case 'ustaw':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed]));
    }
    $zapis = evk_tba_zapis();
    update_option('evk_animator', ['enabled' => 1, 'reduced_motion' => 1, 'builder_preview' => 0,
        'animations' => [['slug' => 'wjazd', 'label' => 'Wjazd', 'preset' => 'fade-up']]]);
    $s = get_option('bricks_global_settings', []);
    $s = is_array($s) ? $s : [];
    $s['postTypes'] = ['page'];
    update_option('bricks_global_settings', $s);
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TBA_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => EVK_TBA_TYTUL, 'post_type' => 'page', 'post_status' => 'publish']);
    $h = static function (string $id, string $tekst, array $dod = []) {
        return ['id' => $id, 'name' => 'heading', 'parent' => 'bas001', 'children' => [], 'settings' => ['text' => $tekst, 'tag' => 'h2'] + $dod];
    };
    update_post_meta($id, '_bricks_editor_mode', 'bricks');
    update_post_meta($id, '_bricks_page_content_2', wp_slash([
        ['id' => 'bas001', 'name' => 'section', 'parent' => 0, 'children' => ['baha01', 'bahb01', 'bahc01'], 'settings' => []],
        $h('baha01', 'Nagłówek A', ['evkAnimList' => [['id' => 'bar001', 'animation' => 'wjazd', 'delay' => '0.2']]]),
        $h('bahb01', 'Nagłówek B', ['_attributes' => [['id' => 'bat001', 'name' => 'data-x', 'value' => '1']]]),
        $h('bahc01', 'Nagłówek C', ['evkAnimList' => [['id' => 'bar002', 'animation' => 'wjazd', 'delay' => '0.5']],
            '_attributes' => [['id' => 'bat002', 'name' => 'data-evk-anim', 'value' => 'wjazd']]]),
    ]));
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strona' => $id, 'gotowe' => $id > 0];
    break;

case 'tresc':
    $id = (int) (evk_tba_zapis()['strona'] ?? 0);
    $t = (array) get_post_meta($id, '_bricks_page_content_2', true);
    $out['el'] = [];
    foreach ($t as $e) if (($e['name'] ?? '') === 'heading') $out['el'][$e['id']] = $e['settings'];
    break;

/* Filtr na sztucznym elemencie — te same ustawienia, jakie zapisuje builder. */
case 'filtr':
    $atrybuty = static function (array $s): array {
        $el = new stdClass();
        $el->settings = $s;
        $a = apply_filters('bricks/element/render_attributes', ['_root' => ['class' => ['x']]], '_root', $el);
        return array_map(static function ($v) { return implode(' ', (array) $v); }, array_diff_key($a['_root'], ['class' => 1]));
    };
    $lista = [['id' => 'r1', 'animation' => 'wjazd', 'delay' => '0.2']];
    $lustro = [['id' => 'a1', 'name' => 'data-evk-anim', 'value' => '[{"animation":"wjazd","delay":"0.2"}]']];
    $out['przypadki'] = [
        'lista'          => $atrybuty(['evkAnimList' => $lista]),
        'lista+lustro'   => $atrybuty(['evkAnimList' => $lista, '_attributes' => $lustro]),
        'samo-lustro'    => $atrybuty(['_attributes' => $lustro]),
        'reczna-nazwa'   => $atrybuty(['evkAnimList' => $lista, '_attributes' => [['id' => 'a2', 'name' => 'data-evk-anim', 'value' => 'wjazd']]]),
        'reczny-obiekt'  => $atrybuty(['_attributes' => [['id' => 'a3', 'name' => 'data-evk-anim', 'value' => '{"animation":"wjazd"}']]]),
        'tablica-bez-animation' => $atrybuty(['_attributes' => [['id' => 'a4', 'name' => 'data-evk-anim', 'value' => '[{"delay":1}]']]]),
    ];
    break;

case 'sprzataj':
    $zapis = evk_tba_zapis();
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
