<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * 2FA w formularzu logowania Bricksa (1.287.0) — piąty testowy WordPress
 * (prawdziwy motyw Bricks). Strona z formularzem logowania ma WŁASNE style
 * pól (tło, ramka, odstępy), żeby test widział, czy pole kodu je przejmuje.
 *
 *   php tests/php/logowanie-2fa-bricks.php wp
 *   php tests/php/logowanie-2fa-bricks.php przygotuj <sekret>   2FA włączone, konto dwa_bricks z sekretem, trzy strony z formularzem (trzecia z kontrolkami wyglądu)
 *   php tests/php/logowanie-2fa-bricks.php builder             podgląd kroku kodu na trzeciej stronie, builder dla stron; licencja
 *   php tests/php/logowanie-2fa-bricks.php kontrolki           grupa kontrolek i które pola dostają „… EN”
 *   php tests/php/logowanie-2fa-bricks.php konto               licznik, kody
 *   php tests/php/logowanie-2fa-bricks.php sprzataj
 */
$krok = $argv[1] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$plik = sys_get_temp_dir() . '/evk-t-2fa-bricks.json';
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out  = ['krok' => $krok];
if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

/** Formularz logowania Bricksa: login, hasło, „zapamiętaj mnie”, własne style pól. @param array<string,mixed> $dodatki */
function evk_t2b_formularz(array $dodatki = []): array {
    return [
        ['id' => 'b2s001', 'name' => 'section', 'parent' => 0, 'children' => ['b2f001'], 'settings' => []],
        ['id' => 'b2f001', 'name' => 'form', 'parent' => 'b2s001', 'children' => [], 'settings' => array_merge([
            'fields' => [
                ['id' => 'lgn001', 'type' => 'text', 'label' => 'Login', 'placeholder' => 'Twój login', 'required' => true],
                ['id' => 'pwd001', 'type' => 'password', 'label' => 'Hasło', 'required' => true],
                ['id' => 'rem001', 'type' => 'checkbox', 'label' => '', 'options' => 'Zapamiętaj mnie'],
            ],
            'actions' => ['login'], 'loginName' => 'lgn001', 'loginPassword' => 'pwd001', 'loginRemember' => 'rem001',
            'submitButtonText' => 'Zaloguj się', 'successMessage' => 'Zalogowano.',
            /* Własna typografia etykiet (1.289.0) — linki kroku kodu mają ją przejąć; domyślna byłaby nie do odróżnienia od dziedziczonej. */
            'labelTypography' => ['color' => ['hex' => '#5b2a86'], 'font-weight' => '700', 'font-size' => '18px'],
            'fieldBackgroundColor' => ['hex' => '#fff3d6'],
            'fieldPadding' => ['top' => '14px', 'right' => '18px', 'bottom' => '14px', 'left' => '18px'],
            'fieldBorder' => ['width' => ['top' => 2, 'right' => 2, 'bottom' => 2, 'left' => 2], 'style' => 'solid', 'color' => ['hex' => '#7a3cff'],
                              'radius' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10]],
        ], $dodatki)],
    ];
}

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    $out['bricks'] = defined('BRICKS_VERSION') ? BRICKS_VERSION : null;
    break;

case 'przygotuj':
    if (!isset($zap['opcje'])) {
        foreach (['evk_2fa', 'evk_2fa_dziennik'] as $o) $zap['opcje'][$o] = get_option($o, null);
    }
    update_option('evk_2fa', ['enabled' => 1, 'role' => [], 'pamietaj' => 1]);
    if ($u = get_user_by('login', 'dwa_bricks')) wp_delete_user($u->ID);
    $id = (int) wp_insert_user(['user_login' => 'dwa_bricks', 'user_pass' => 'test-haslo', 'role' => 'subscriber', 'user_email' => 'dwa_bricks@example.test']);
    $k = evk_2fa_konto($id);
    $k['sekret'] = evk_2fa_zaszyfruj((string) ($argv[2] ?? ''));
    $k['od'] = time();
    evk_2fa_zapisz_konto($id, $k);
    $out['kody'] = evk_2fa_nowe_kody($id);
    global $wpdb;
    /* Trzecia (1.289.0): wygląd i teksty kroku kodu z kontrolek „Logowanie dwuetapowe (Evoke)”. */
    $wlasne = ['tfaEtykieta' => 'Kod jednorazowy', 'tfaZapasowy' => 'Zapasowy', 'tfaWroc' => 'Cofnij', 'tfaPamietaj' => 'Pamiętaj mnie tutaj',
        'tfaBlad' => 'Zły kod, prób: {proby}', 'evk2faPolozenie' => 'przycisk', 'evk2faKierunek' => 'column', 'evk2faKlasy' => 'moja-klasa druga',
        'evk2faTypografia' => ['color' => ['hex' => '#0a7d3b'], 'font-size' => '13px'], 'evk2faBladTypografia' => ['color' => ['hex' => '#123456']]];
    foreach (['2FA Bricks', '2FA Bricks — własny błąd', '2FA Bricks — wygląd'] as $i => $tytul) {
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", $tytul)) as $stary) wp_delete_post((int) $stary, true);
        $p = (int) wp_insert_post(['post_title' => $tytul, 'post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($p, '_bricks_editor_mode', 'bricks');
        update_post_meta($p, '_bricks_page_content_2', wp_slash(evk_t2b_formularz($i === 1 ? ['loginErrorMessage' => 'Błędne dane logowania.'] : ($i === 2 ? $wlasne : []))));
        $zap['strony'][] = $p;
        $out['strony'][] = $p;
    }
    $zap['konto'] = $id;
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['id'] = $id;
    break;

case 'builder':
    /* Prawdziwy builder (1.289.0): podgląd kroku kodu na trzeciej stronie; builder tylko dla typów z ustawień Bricksa. */
    if (!array_key_exists('bricks_global_settings', $zap['opcje'] ?? [])) $zap['opcje']['bricks_global_settings'] = get_option('bricks_global_settings', null);
    $g = get_option('bricks_global_settings', []);
    $g = is_array($g) ? $g : [];
    $g['postTypes'] = ['page'];
    update_option('bricks_global_settings', $g);
    $s3 = (int) (($zap['strony'] ?? [])[2] ?? 0);
    $tresc = get_post_meta($s3, '_bricks_page_content_2', true);
    foreach ($tresc as &$el) if ($el['id'] === 'b2f001') $el['settings']['evk2faPodglad'] = true;
    unset($el);
    update_post_meta($s3, '_bricks_page_content_2', wp_slash($tresc));
    file_put_contents($plik, (string) wp_json_encode($zap));
    $licencja = null;
    if (class_exists('\Bricks\License')) {
        \Bricks\License::$license_key = \Bricks\License::get_license_key();
        $licencja = \Bricks\License::license_is_valid();
    }
    $out += ['strona' => $s3, 'licencja' => $licencja, 'podglad' => !empty(get_post_meta($s3, '_bricks_page_content_2', true)[1]['settings']['evk2faPodglad'])];
    break;

case 'kontrolki':
    /* Które kontrolki grupy dostają pola „… EN” (Tłumaczenia, 51) — te same definicje, które widzi builder. */
    /* Werdykt „treść czy wartość techniczna” — ta sama funkcja co w module Tłumaczeń (ładowany tylko przy włączonych). */
    if (!function_exists('evk_tl_el_tlumaczalna')) require_once dirname(__DIR__, 2) . '/includes/51-translation-element-fields.php';
    $k = apply_filters('bricks/elements/form/controls', ['actions' => ['options' => []]]);
    foreach ($k as $klucz => $def) {
        if (($def['group'] ?? '') === 'evk2fa' && function_exists('evk_tl_el_tlumaczalna')) $out['tlumaczone'][$klucz] = evk_tl_el_tlumaczalna((string) $klucz, $def, 'form');
    }
    $g = apply_filters('bricks/elements/form/control_groups', []);
    $out['grupa'] = $g['evk2fa'] ?? null;
    break;

case 'konto':
    $k = evk_2fa_konto((int) ($zap['konto'] ?? 0));
    $out['konto'] = ['licznik' => $k['licznik'], 'kody' => count($k['kody']), 'urzadzenia' => count($k['urzadzenia'])];
    break;

case 'sprzataj':
    if ($u = get_user_by('login', 'dwa_bricks')) wp_delete_user($u->ID);
    foreach ((array) ($zap['strony'] ?? []) as $p) wp_delete_post((int) $p, true);
    foreach ((array) ($zap['opcje'] ?? []) as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out);
