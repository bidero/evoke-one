<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Śledzenie newslettera (1.233.2) na PRAWDZIWYM WordPressie.
 *
 *   php tests/php/newsletter-sledzenie.php sciezka
 *   php tests/php/newsletter-sledzenie.php przygotuj <adres serwera>
 *   php tests/php/newsletter-sledzenie.php stan <kampania>        (osobno dla każdego odbiorcy)
 *   php tests/php/newsletter-sledzenie.php cofnij <kampania>
 *   php tests/php/newsletter-sledzenie.php sprzataj <kampania> <lista> <szablon>
 *
 * „przygotuj" stawia listę z jednym odbiorcą, szablon z linkami do strony
 * zapisanymi tak, jak zapisuje je edytor (zmierzone w TinyMCE z WordPressa:
 * okno linku dokleja „http://" i znacznik data-wplink-url-error, zakładka
 * „Tekst" zostawia to, co wpisano, przycisk tagu wstawia sam tekst),
 * wysłaną kampanię z wierszem kolejki i buduje mail PRAWDZIWYM
 * evk_nl_send_mail() — wp_mail() zatrzymuje `pre_wp_mail`, więc sonda widzi
 * dokładnie to, co poszłoby do odbiorcy.
 *
 * Przeglądarka i żądania HTTP (piksel, kliknięcie, podgląd) idą MIĘDZY
 * wywołaniami sondy, przez `php -S` — stąd osobne kroki. Mail powstaje
 * z adresem TEGO serwera (WP_HOME, jak w routerze): cel kliknięcia musi
 * przejść `wp_http_validate_url()` po stronie serwera, a „stara.test" nie
 * rozwiązuje się na maszynie testowej — obcy host bez adresu IP kończy na
 * stronie głównej, więc test mierzyłby środowisko, nie kod. „cofnij" przesuwa
 * wpisy otwarć w przeszłość o okno otwarcia i sekundę: tak wygląda kolejne
 * otwarcie bez czekania minuty w teście.
 */

$krok = $argv[1] ?? '';
if ($krok === 'przygotuj' && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

global $wpdb;
$kampania = (int) ($argv[2] ?? 0);
$plik     = sys_get_temp_dir() . '/evk-t-newsletter-sledzenie.json';
$out      = ['krok' => $krok];

switch ($krok) {

case 'sciezka':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!is_file($plik)) file_put_contents($plik, wp_json_encode(['evk_newsletter' => get_option('evk_newsletter', null)]));
    update_option('evk_newsletter', array_merge((array) get_option('evk_newsletter', []), ['enabled' => 1]));
    evk_nl_create_tables();

    $lista = (int) evk_nl_create_list('Śledzenie ' . wp_rand());
    $sid   = (int) evk_nl_add_subscriber($lista, 'sledzenie' . wp_rand() . '@example.com');
    $wpdb->update(evk_nl_table('subscribers'), ['status' => 1], ['id' => $sid]);
    $sub = evk_nl_get_subscriber($sid);
    /* Drugi odbiorca nie pobiera piksela (obrazki zablokowane) — tylko klika.
       Od 1.233.4 takie kliknięcie jest jego otwarciem. */
    $sid2 = (int) evk_nl_add_subscriber($lista, 'bez-obrazkow' . wp_rand() . '@example.com');
    $wpdb->update(evk_nl_table('subscribers'), ['status' => 1], ['id' => $sid2]);
    $sub2 = evk_nl_get_subscriber($sid2);

    /* Każdy wariant w osobnym akapicie z identyfikatorem: sprawdzenia patrzą
       na akapit, nie na pozycję linku w mailu — brak jednego linku nie
       przesuwa pozostałych i nie zapala sprawdzeń innych wariantów. */
    $szablon = (int) evk_nl_create_template(['name' => 'Śledzenie', 'subject' => 'Temat', 'body_html' =>
          '<p id="t-tekst">Tekst: {site_url}. Koniec</p>'
        . '<p id="t-sciezka">Pełny: {site_url_full}/sklep/?a=1&amp;b=2, dalej</p>'
        . '<p id="t-nbsp">Dalej {site_url}&nbsp;koniec.</p>'
        . '<p id="t-strong"><strong>{site_url_full}</strong></p>'
        . '<p id="o-krotki"><a href="http://{site_url}" data-wplink-url-error="true">okno-krotki</a></p>'
        . '<p id="o-pelny"><a href="http://{site_url_full}" data-wplink-url-error="true">okno-pelny</a></p>'
        . '<p id="h-krotki"><a href="{site_url}">tekst-krotki</a></p>'
        . '<p id="h-pelny"><a href="{site_url_full}/kontakt/">tekst-pelny</a></p>'
        . '<p id="w-linku"><a href="https://example.com/">{site_url}</a> <span title="{site_url}">t</span></p>']);
    $wpdb->insert(evk_nl_table('campaigns'), ['name' => 'Śledzenie', 'template_id' => $szablon,
        'lists_json' => '[' . $lista . ']', 'status' => 'sent', 'tracking_enabled' => 1]);
    $kampania = (int) $wpdb->insert_id;
    foreach ([$sid, $sid2] as $s) {
        $wpdb->insert(evk_nl_table('queue'), ['campaign_id' => $kampania, 'subscriber_id' => $s,
            'status' => 'sent', 'sent_at' => current_time('mysql')]);
    }

    $mail = null;
    $lap = static function ($r, $atts) use (&$mail) { $mail = (string) $atts['message']; return true; };
    add_filter('pre_wp_mail', $lap, 10, 2);
    evk_nl_send_mail($sub, evk_nl_get_campaign($kampania), evk_nl_get_template($szablon), []);
    remove_filter('pre_wp_mail', $lap, 10);

    /* Linki akapitami: id akapitu → [tekst, cel kliknięcia (albo surowy href,
       gdy link nie jest śledzony), znak zaraz za linkiem]. */
    $linki = [];
    preg_match_all('#<p id="([a-z-]+)">(.*?)</p>#s', (string) $mail, $akapity, PREG_SET_ORDER);
    foreach ($akapity as $ak) {
        $linki[$ak[1]] = [];
        preg_match_all('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>(.?)#s', $ak[2], $m, PREG_SET_ORDER);
        foreach ($m as $a) {
            $href = html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cel  = '';
            if (preg_match('#[?&]evk_nl=click\b#', $href) || strpos($href, '/nl/click/') !== false) {
                parse_str((string) parse_url($href, PHP_URL_QUERY), $zap);
                $cel = (string) ($zap['url'] ?? '');
            }
            $linki[$ak[1]][] = ['tekst' => html_entity_decode(wp_strip_all_tags($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                                'cel' => $cel !== '' ? $cel : '(bez śledzenia: ' . $href . ')', 'po' => $a[3], 'href' => $href];
        }
    }
    $out += [
        'wp'        => untrailingslashit(ABSPATH),
        'home'      => home_url(),
        'lista'     => $lista,
        'szablon'   => $szablon,
        'kampania'  => $kampania,
        'token'     => $sub['token'],
        'sid1'      => $sid,
        'sid2'      => $sid2,
        'klik2'     => evk_nl_click_url($sub2['token'], home_url(), $kampania),
        'piksel'    => html_entity_decode((string) (preg_match('#<img\s[^>]*src="([^"]*(?:evk_nl=open|/nl/open/)[^"]*)"#', (string) $mail, $p) ? $p[1] : ''), ENT_QUOTES),
        'pikseli'   => preg_match_all('#evk_nl=open|/nl/open/#', (string) $mail),
        'linki'     => $linki,
        'wszystkich_w_mailu' => preg_match_all('#<a\\s#', (string) $mail),
        'zagniezdzony' => (bool) preg_match('#<a\b[^>]*>(?:(?!</a>).)*<a\b#s', (string) $mail),
        'title'     => preg_match('#<span title="([^"]*)"#', (string) $mail, $t) ? $t[1] : null,
        'podglad'   => evk_nl_view_url($kampania, $sub['token']),
    ];
    break;

case 'stan':
    /* Każdy odbiorca osobno: zdarzenia w kolejności zapisu, dane otwarć,
       cele kliknięć i wiersz kolejki. */
    $out += ['zdarzenia' => [], 'otwarcia' => [], 'klikniecia' => [], 'kolejka' => []];
    foreach ($wpdb->get_results($wpdb->prepare('SELECT subscriber_id FROM ' . evk_nl_table('queue')
        . ' WHERE campaign_id=%d ORDER BY id', $kampania), ARRAY_A) ?: [] as $r) {
        $s = (int) $r['subscriber_id'];
        $out['zdarzenia'][$s] = $out['otwarcia'][$s] = $out['klikniecia'][$s] = [];
        $out['kolejka'][$s] = $wpdb->get_row($wpdb->prepare('SELECT status, opened_at FROM ' . evk_nl_table('queue')
            . ' WHERE campaign_id=%d AND subscriber_id=%d', $kampania, $s), ARRAY_A);
    }
    foreach ($wpdb->get_results($wpdb->prepare('SELECT subscriber_id, event, data_json FROM ' . evk_nl_table('logs')
        . " WHERE campaign_id=%d AND event IN ('open','click') ORDER BY id", $kampania), ARRAY_A) ?: [] as $r) {
        $s    = (int) $r['subscriber_id'];
        $dane = json_decode((string) $r['data_json'], true) ?: [];
        $out['zdarzenia'][$s][] = $r['event'];
        if ($r['event'] === 'open') $out['otwarcia'][$s][] = $dane;
        else $out['klikniecia'][$s][] = $dane['url'] ?? '';
    }
    $st = evk_nl_campaign_stats($kampania);   // kafelki Raportów
    $out['statystyki'] = ['otwarte' => (int) $st['opened'], 'klikniete' => (int) $st['clicked']];
    break;

case 'cofnij':
    $out['przesuniete'] = (int) $wpdb->query($wpdb->prepare('UPDATE ' . evk_nl_table('logs')
        . " SET created_at = created_at - INTERVAL %d SECOND WHERE campaign_id=%d AND event='open'",
        EVK_NL_OKNO_OTWARCIA + 1, $kampania));
    break;

case 'sprzataj':
    if ($kampania) evk_nl_delete_campaign($kampania);
    if (!empty($argv[3])) evk_nl_delete_list((int) $argv[3]);
    if (!empty($argv[4])) evk_nl_delete_template((int) $argv[4]);
    $przed = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    if (array_key_exists('evk_newsletter', $przed)) {
        $przed['evk_newsletter'] === null ? delete_option('evk_newsletter') : update_option('evk_newsletter', $przed['evk_newsletter']);
    }
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
