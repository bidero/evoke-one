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
 *   php tests/php/logowanie-2fa-bricks.php przygotuj <sekret>   2FA włączone, konto dwa_bricks z sekretem, dwie strony z formularzem
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
    foreach (['2FA Bricks', '2FA Bricks — własny błąd'] as $i => $tytul) {
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", $tytul)) as $stary) wp_delete_post((int) $stary, true);
        $p = (int) wp_insert_post(['post_title' => $tytul, 'post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($p, '_bricks_editor_mode', 'bricks');
        update_post_meta($p, '_bricks_page_content_2', wp_slash(evk_t2b_formularz($i ? ['loginErrorMessage' => 'Błędne dane logowania.'] : [])));
        $zap['strony'][] = $p;
        $out['strony'][] = $p;
    }
    $zap['konto'] = $id;
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['id'] = $id;
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
