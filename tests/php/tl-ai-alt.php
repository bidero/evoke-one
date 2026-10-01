<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Alt obrazów z AI (1.271.0): tłumaczenie polskiego altu i brakujący polski
 * alt z obrazu (AI ogląda obraz) — hurt (wiersz „Obrazy”, części `evk_alt`
 * i `evk_alt_pl`), ✦ w oknie mediów i na ekranie obrazu, „Do sprawdzenia”.
 * Pierwszy testowy WordPress, dostawca AI — atrapa (_ai-atrapa.php: tłumaczy
 * „EN:…”, a na zapytanie z obrazem odpowiada „Opis obrazu {plik}”).
 *
 *   php tests/php/tl-ai-alt.php wp
 *   php tests/php/tl-ai-alt.php modul                  kopia opcji, moduł Tłumaczeń i języki (osobny proces)
 *   php tests/php/tl-ai-alt.php ustaw                  obrazy testu (GD, z miniaturami), klucz AI
 *   php tests/php/tl-ai-alt.php obraz O                plik, który pójdzie do AI (rozmiar, typ) — bez danych
 *   php tests/php/tl-ai-alt.php jednostki CZĘŚCI       lista hurtu wiersza „Obrazy” (alt, alt_pl) — porcje z obrazami testu
 *   php tests/php/tl-ai-alt.php krok META L            kroki porcji z obrazami testu do końca (META: evk_alt | evk_alt_pl)
 *   php tests/php/tl-ai-alt.php stan                   alty obrazów testu (PL, języki, źródła, znacznik AI)
 *   php tests/php/tl-ai-alt.php alt-pl O WARTOŚĆ       polski alt obrazu (bez znacznika opisu AI)
 *   php tests/php/tl-ai-alt.php lista                  „Do sprawdzenia”: wiersze obrazów testu i HTML sekcji
 *   php tests/php/tl-ai-alt.php ajax-sprawdzone O META KLUCZ  „Sprawdzone” z listy (prawdziwy AJAX)
 *   php tests/php/tl-ai-alt.php ajax PLIK              żądanie z przeglądarki (✦) przez prawdziwy AJAX, nonce „auto”
 *   php tests/php/tl-ai-alt.php sprzataj
 *
 * Obrazy (1600×1000 JPEG):
 *   O1  alt PL „Zespół przy pracy”, bez tłumaczeń;
 *   O2  alt PL „Biuro firmy”, EN wpisany ręcznie („Company office”);
 *   O3  bez polskiego altu.
 */

$krok = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-ai-alt.json';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'evk_tl_ai', 'evk_tl_ai_pamiec'];
$out   = ['krok' => $krok];

function evk_taa_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_taa_id(string $l): int {
    return (int) ((evk_taa_zapis()['obrazy'] ?? [])[$l] ?? 0);
}
/** @return list<int> */
function evk_taa_ids(): array {
    return array_map('intval', array_values((array) (evk_taa_zapis()['obrazy'] ?? [])));
}
/** Czy porcja hurtu (od–do) obejmuje któryś obraz testu. */
function evk_taa_nasza(array $j): bool {
    foreach (evk_taa_ids() as $id) if ($id >= (int) $j['post_id'] && $id <= (int) ($j['do'] ?? $j['post_id'])) return true;
    return false;
}
function evk_taa_ajax(array $post) {
    $_POST = $_REQUEST = wp_slash($post);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($komunikat = '') {
            if (is_scalar($komunikat)) echo $komunikat;
            throw new RuntimeException('koniec');
        };
    });
    ob_start();
    try {
        do_action('wp_ajax_' . $post['action']);
    } catch (RuntimeException $e) {
        // wp_send_json() kończy tu.
    }
    $w = (string) ob_get_clean();
    return json_decode($w, true) ?? $w;
}

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

/* Moduł Tłumaczeń wczytuje się przy starcie procesu — włączony tu, działa od następnego kroku. */
case 'modul':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'obrazy' => []]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    $out['gotowe'] = true;
    break;

case 'ustaw':
    if (!function_exists('evk_tl_ai_teksty_alt')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_taa_zapis();
    foreach (evk_taa_ids() as $id) wp_delete_attachment($id, true);
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-ai-123'], 'modele' => [], 'opis' => '',
        'wskazowki' => [], 'slowniczek' => ''], false);
    delete_option('evk_tl_ai_pamiec');
    $katalog = wp_upload_dir();
    $obrazy = [];
    foreach (['O1' => 'Zespół przy pracy', 'O2' => 'Biuro firmy', 'O3' => ''] as $l => $alt) {
        $nazwa = 'evk-t-alt-' . strtolower($l) . '.jpg';
        $sciezka = trailingslashit($katalog['path']) . $nazwa;
        $im = imagecreatetruecolor(1600, 1000);
        imagefill($im, 0, 0, imagecolorallocate($im, 40 + 60 * count($obrazy), 120, 200));
        imagejpeg($im, $sciezka, 80);
        imagedestroy($im);
        $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Obraz testu ' . $l, 'post_status' => 'inherit'], $sciezka);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $sciezka));
        if ($alt !== '') update_post_meta($id, '_wp_attachment_image_alt', $alt);
        $obrazy[$l] = $id;
    }
    update_post_meta($obrazy['O2'], '_evk_tl_en__alt', 'Company office');
    update_post_meta($obrazy['O2'], '_evk_tl_en__alt__zrodlo', evk_tlw_zrodlo('Biuro firmy'));
    $zapis['obrazy'] = $obrazy;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['obrazy' => $obrazy, 'rozmiary' => array_keys((array) (wp_get_attachment_metadata($obrazy['O1'])['sizes'] ?? [])), 'gotowe' => true];
    break;

case 'obraz':
    $o = evk_tl_ai_obraz_do_ai(evk_taa_id((string) ($argv[2] ?? 'O1')));
    $out['obraz'] = $o ? ['plik' => $o['plik'], 'mime' => $o['mime'], 'szer' => $o['szer'], 'wys' => $o['wys'], 'bajty' => strlen(base64_decode($o['dane']))] : null;
    break;

case 'jednostki':
    $cz = array_values(array_filter(explode(',', (string) ($argv[2] ?? ''))));
    $out['wiersz'] = evk_tl_ai_wiersze_zakresu()['obrazy'] ?? null;
    $out['jednostki'] = array_values(array_filter(evk_tl_ai_jednostki(false, evk_tl_ai_zakres_z(['obrazy' => $cz])), 'evk_taa_nasza'));
    break;

/* Kroki porcji obejmującej obrazy testu: od pierwszego do ostatniego z nich. */
case 'krok':
    $meta = (string) ($argv[2] ?? EVK_TL_AI_ALT);
    $lang = (string) ($argv[3] ?? 'en');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $ids = evk_taa_ids();
    $pomin = [];
    $out['kroki'] = [];
    for ($i = 0; $i < 10; $i++) {
        $r = evk_tl_ai_krok(min($ids), $meta, $lang, $pomin, ['do' => max($ids)]);
        $out['kroki'][] = $r;
        $pomin = array_merge($pomin, $r['odrzucone'], $r['pominiete'], $r['zapisane_klucze']);
        if (!$r['zostalo'] || !empty($r['stop'])) break;
    }
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $out['obrazy'] = $GLOBALS['evk_t_ai_obrazy'];
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $c = $z ? ($z['body']['contents'][0]['parts'] ?? []) : [];
    $out['wiadomosc'] = (string) ($c[0]['text'] ?? '');
    $out['system'] = $z ? (string) ($z['body']['systemInstruction']['parts'][0]['text'] ?? '') : '';
    break;

case 'stan':
    foreach ((array) (evk_taa_zapis()['obrazy'] ?? []) as $l => $id) {
        $m = ['pl' => (string) get_post_meta((int) $id, '_wp_attachment_image_alt', true), 'ai' => (string) get_post_meta((int) $id, '_evk_alt_ai', true)];
        foreach (get_post_meta((int) $id) as $k => $v) if (strpos($k, '_evk_tl_') === 0) $m[$k] = $v[0];
        $out['obrazy'][$l] = $m;
    }
    $out['skroty'] = ['O1' => evk_tlw_zrodlo('Zespół przy pracy'), 'O2' => evk_tlw_zrodlo('Biuro firmy')];
    break;

/* Polski alt ustawiony ręcznie — bez znacznika opisu AI (hurt musi go postawić sam). */
case 'alt-pl':
    update_post_meta(evk_taa_id((string) ($argv[2] ?? '')), '_wp_attachment_image_alt', (string) ($argv[3] ?? ''));
    delete_post_meta(evk_taa_id((string) ($argv[2] ?? '')), '_evk_alt_ai');
    $out['ok'] = true;
    break;

case 'lista':
    $ids = evk_taa_ids();
    $out['wiersze'] = array_values(array_filter(evk_tl_ai_alt_do_sprawdzenia(),
        static function ($m) use ($ids) { return in_array((int) $m['post_id'], $ids, true); }));
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    break;

case 'ajax-sprawdzone':
    $out['odp'] = evk_taa_ajax(['action' => 'evk_tl_el_sprawdzone', 'nonce' => wp_create_nonce('evk_tl_el_sprawdzone'),
        'post_id' => (string) evk_taa_id((string) ($argv[2] ?? '')), 'meta_key' => (string) ($argv[3] ?? ''), 'klucz' => (string) ($argv[4] ?? '')]);
    break;

/* ✦ z przeglądarki (tłumaczenie albo opis obrazu): ciało żądania z pliku, nonce świeży dla tego konta. */
case 'ajax':
    parse_str((string) @file_get_contents((string) ($argv[2] ?? '')), $post);
    if (($post['nonce'] ?? '') === 'auto') $post['nonce'] = wp_create_nonce('evk_tl_ai_pola');
    $GLOBALS['evk_t_ai_kod'] = is_string($post['lang'] ?? null) ? $post['lang'] : 'en';
    $out['odp'] = evk_taa_ajax($post);
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $out['obrazy'] = $GLOBALS['evk_t_ai_obrazy'];
    break;

case 'sprzataj':
    $zapis = evk_taa_zapis();
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach (evk_taa_ids() as $id) wp_delete_attachment($id, true);
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['brak'] = 'nieznane polecenie: ' . $krok;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
