<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Import subskrybentów z podglądem, mapowaniem kolumn i listą wykluczeń
 * (includes/newsletter/import.php) na PRAWDZIWYM WordPressie.
 *
 *   php tests/php/newsletter-import.php <scenariusz>
 *
 * Scenariusze:
 *   tabela   — czytanie pliku: nagłówek, separator, kolumna adresu, klucze,
 *   przejdz  — podgląd i import na liście z każdym rodzajem wykluczenia,
 *   ajax     — to samo przez punkty AJAX panelu (podgląd, import z mapą,
 *              lista wykluczeń),
 *   rodo     — wpis na liście wykluczeń w eksporcie i usuwaniu danych osoby.
 *
 * Listy sondy znikają; lista wykluczeń i blokady RODO wracają do stanu sprzed.
 */

require __DIR__ . '/_testowy-wp.php';

$scenariusz = $argv[1] ?? '';
$out = ['scenariusz' => $scenariusz];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});
wp_set_current_user(1);
evk_nl_create_tables();
foreach (['evk_nl_wykluczenia', 'evk_nl_zablokowane'] as $opcja) {
    $przed = get_option($opcja, null);
    $sprzatanie[] = static function () use ($opcja, $przed) {
        $przed === null ? delete_option($opcja) : update_option($opcja, $przed);
    };
    delete_option($opcja);
}

function evk_t_lista(string $nazwa): int {
    global $sprzatanie;
    $id = (int) evk_nl_create_list($nazwa . ' ' . wp_rand());
    $sprzatanie[] = static function () use ($id) { evk_nl_delete_list($id); };
    return $id;
}

/** Subskrybent po adresie na liście: [status, pola] albo null. */
function evk_t_sub(int $lista, string $email): ?array {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT status, fields_json FROM ' . evk_nl_table('subscribers') . ' WHERE list_id=%d AND email=%s', $lista, $email), ARRAY_A);
    return $r ? ['status' => (int) $r['status'], 'pola' => json_decode((string) $r['fields_json'], true) ?: []] : null;
}

/** Punkt AJAX panelu; wp_die (także z wp_send_json) kończy się wyjątkiem. */
function evk_t_ajax(string $akcja, array $post): array {
    static $raz = false;
    if (!$raz) {
        add_filter('wp_die_ajax_handler', static function () { return static function () { throw new RuntimeException('wp_die'); }; });
        if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
        $raz = true;
    }
    $_POST = $_REQUEST = wp_slash($post + ['nonce' => wp_create_nonce('evk_nl_nonce')]);
    $_FILES = [];
    ob_start();
    try { do_action('wp_ajax_' . $akcja); } catch (RuntimeException $e) {}
    return json_decode((string) ob_get_clean(), true) ?: [];
}

/** Lista z każdym rodzajem wykluczenia — wspólna dla `przejdz` i `ajax`. */
function evk_t_scena(): array {
    $l1 = evk_t_lista('Sonda importu');
    $l2 = evk_t_lista('Sonda importu druga');
    evk_nl_add_subscriber($l1, 'jest@example.com');
    evk_nl_add_subscriber($l1, 'wypisany@example.com');
    evk_nl_add_subscriber($l2, 'wypisany2@example.com');
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . evk_nl_table('subscribers') . " SET status=0 WHERE email IN (%s, %s)", 'wypisany@example.com', 'wypisany2@example.com'));
    evk_nl_wyklucz('reczny@example.com', 'reczny');
    evk_nl_wyklucz('odbity@example.com', 'odbity');
    evk_nl_zablokuj('rodo@example.com');
    return [$l1, $l2];
}

$PLIK = "\xEF\xBB\xBFE-mail;Imię\r\njest@example.com;Ala\r\nwypisany@example.com;Ola\r\nwypisany2@example.com;Ela\r\n"
      . "reczny@example.com;Iza\r\nODBITY@example.com;Ula\r\nrodo@example.com;Ewa\r\nnowy1@example.com;Jan\r\n"
      . "nowy1@example.com;Jan drugi\r\nzly-adres;Zly\r\nnowy2@example.com;\r\n";

switch ($scenariusz) {

case 'tabela':
    $t = static function (string $tresc): array {
        $tab = evk_nl_import_tabela($tresc);
        return ['naglowek' => $tab['naglowek'], 'wierszy' => count($tab['wiersze']), 'mapa' => evk_nl_import_mapa_domyslna($tab)];
    };
    $out['excel']        = $t("\xEF\xBB\xBFE-mail;Imię;Nazwisko;Nazwa firmy\r\njan@x.pl;Jan;Kowalski;ACME\r\nanna@y.pl;Anna;Nowak;\r\n");
    $out['adres_drugi']  = $t("Imię,E-mail\nJan,jan@x.pl\nEwa,ewa@x.pl\n");
    $out['bez_naglowka'] = $t("jan@x.pl\tJan\nanna@y.pl\tAnna\n");
    $out['te_same']      = $t("Imię,imie,E-mail,_consent_ip\nJan,J,jan@x.pl,1.1.1.1\n");
    $out['adresy'] = array_map('evk_nl_import_adres', ['Jan Kowalski <jan@x.pl>', 'jan@x.pl;Jan', ' "anna@y.pl" ', 'zly-adres', 'x <nie-adres>']);
    // Mapa z panelu: tylko istniejące kolumny, klucze oczyszczone, bez kolizji z adresem.
    $tab = evk_nl_import_tabela("E-mail,Imię,Firma\njan@x.pl,Jan,ACME\n");
    $out['mapa_z_zadania'] = evk_nl_import_mapa_z_zadania('{"email":0,"pola":{"0":"email","1":"Imię Główne","2":"_ip","7":"poza"}}', $tab);
    $out['mapa_zepsuta']   = evk_nl_import_mapa_z_zadania('nie-json', $tab);
    break;

case 'przejdz':
    [$l1] = evk_t_scena();
    $tab  = evk_nl_import_tabela($PLIK);
    $mapa = evk_nl_import_mapa_domyslna($tab);
    $out['mapa'] = $mapa;
    $out['podglad'] = evk_nl_import_przejdz($l1, $tab, $mapa, false);
    $out['po_podgladzie_nowy1'] = evk_t_sub($l1, 'nowy1@example.com');
    $out['import'] = evk_nl_import_przejdz($l1, $tab, $mapa, true);
    unset($out['import']['probka']);
    $out['nowy1'] = evk_t_sub($l1, 'nowy1@example.com');
    $out['nowy2'] = evk_t_sub($l1, 'nowy2@example.com');
    $out['wypisany_dalej'] = evk_t_sub($l1, 'wypisany@example.com');
    // Stara droga (tablica adresów) też szanuje wykluczenia.
    $out['stara_droga'] = evk_nl_import_emails($l1, ['reczny@example.com', 'wypisany2@example.com', 'nowy3@example.com']);
    break;

case 'ajax':
    [$l1] = evk_t_scena();
    $p = evk_t_ajax('evk_nl_import_podglad', ['list_id' => $l1, 'content' => $PLIK]);
    $out['podglad'] = ['sukces' => $p['success'] ?? null, 'kolumny' => $p['data']['kolumny'] ?? null, 'naglowek' => $p['data']['naglowek'] ?? null,
                       'mapa' => $p['data']['mapa'] ?? null, 'added' => $p['data']['added'] ?? null, 'unsubscribed' => $p['data']['unsubscribed'] ?? null,
                       'probka_stany' => array_map(static fn($w) => $w['stan'] . ($w['powod'] ? ':' . $w['powod'] : ''), $p['data']['probka'] ?? [])];
    // Import z mapą z panelu: kolumna „Imię" pominięta.
    $i = evk_t_ajax('evk_nl_import_subscribers', ['list_id' => $l1, 'content' => $PLIK, 'mapa' => '{"email":0,"pola":{}}']);
    $out['import'] = ['sukces' => $i['success'] ?? null, 'added' => $i['data']['added'] ?? null, 'probka' => array_key_exists('probka', $i['data'] ?? [])];
    $out['nowy1_bez_imienia'] = evk_t_sub($l1, 'nowy1@example.com');
    $out['zla_lista'] = evk_t_ajax('evk_nl_import_podglad', ['list_id' => 999999, 'content' => $PLIK]);
    $out['pusto'] = evk_t_ajax('evk_nl_import_podglad', ['list_id' => $l1, 'content' => "  \n "]);

    // Lista wykluczeń: dodanie wypisuje aktywnego; usunięcie go NIE przywraca.
    $w1 = evk_t_ajax('evk_nl_wykluczenia', ['akcja' => 'dodaj', 'adresy' => "jest@example.com, Zly-Adres\nkto@example.com"]);
    $out['wyk_dodaj'] = ['dodane' => $w1['data']['dodane'] ?? null, 'bledne' => $w1['data']['bledne'] ?? null,
                         'adresy' => array_column($w1['data']['lista'] ?? [], 'email')];
    $out['jest_po_wykluczeniu'] = evk_t_sub($l1, 'jest@example.com');
    // Tabela subskrybentów w panelu mówi, dlaczego adres jest wypisany.
    $s = evk_t_ajax('evk_nl_get_subscribers', ['list_id' => $l1, 'page' => 1, 'search' => 'jest@']);
    $out['lista_subskrybentow'] = array_map(static fn($x) => $x['email'] . ':' . $x['status'] . ':' . $x['wykluczenie'], $s['data']['items'] ?? []);
    $w2 = evk_t_ajax('evk_nl_wykluczenia', ['akcja' => 'usun', 'email' => 'jest@example.com']);
    $out['wyk_po_usunieciu'] = array_column($w2['data']['lista'] ?? [], 'email');
    $out['jest_po_usunieciu'] = evk_t_sub($l1, 'jest@example.com');
    // Bez prawa do newslettera — nic.
    $uid = (int) wp_insert_user(['user_login' => 'evk_t_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber',
                                 'user_email' => 'evk_t_sub_' . wp_rand() . '@example.com']);
    $sprzatanie[] = static function () use ($uid) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); };
    wp_set_current_user($uid);
    $out['bez_prawa'] = evk_t_ajax('evk_nl_wykluczenia', ['akcja' => 'dodaj', 'adresy' => 'obcy@example.com']);
    wp_set_current_user(1);
    $out['obcy_dodany'] = isset(evk_nl_wykluczenia()['obcy@example.com']);
    break;

case 'rodo':
    evk_nl_wyklucz('rodo.wyk@example.com', 'odbity', 'test');
    $out['eksport'] = array_values(array_filter(evk_rodo_eksport_newsletter('rodo.wyk@example.com')['data'],
        static fn($g) => $g['group_id'] === 'evk-newsletter-wykluczenia'));
    $out['usuniecie'] = evk_rodo_usun_newsletter('rodo.wyk@example.com');
    $out['po'] = ['na_liscie' => isset(evk_nl_wykluczenia()['rodo.wyk@example.com']), 'zablokowany' => evk_nl_zablokowany('rodo.wyk@example.com'),
                  'adres_w_opcjach' => strpos((string) wp_json_encode([get_option('evk_nl_wykluczenia'), get_option('evk_nl_zablokowane')]), 'rodo.wyk') !== false];
    break;

default:
    $out['blad'] = 'nieznany scenariusz';
}

echo wp_json_encode($out);
