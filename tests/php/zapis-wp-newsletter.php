<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Newsletter i obrazek OG na PRAWDZIWYM WordPressie (tools/testowy-wp.sh).
 *
 * Trzy błędy z audytu 1.229.6, których atrapy nie widziały, bo nie mają
 * prawdziwego `sanitize_email()`, `esc_url_raw()`, `add_query_arg()` ani
 * katalogu uploads:
 *   — CSV z polskiego Excela dawał adresy typu „jan@firma.plJanKowalski",
 *   — import przywracał osoby wypisane,
 *   — śledzenie kliknięć psuło linki z `&amp;` (UTM-y),
 *   — og:image wskazywał nieistniejący `og-fallback.jpg`.
 *
 *   php tests/php/zapis-wp-newsletter.php <scenariusz>
 *
 * Scenariusze: import, klik, og. Każdy sprząta po sobie.
 */

require __DIR__ . '/_testowy-wp.php';

$scenariusz = $argv[1] ?? '';
$out = ['scenariusz' => $scenariusz];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});
wp_set_current_user(1);

switch ($scenariusz) {

// ── Import subskrybentów ────────────────────────────────────────────────────
case 'import':
    evk_nl_create_tables();
    $lista = (int) evk_nl_create_list('Test importu ' . wp_rand());
    $sprzatanie[] = static function () use ($lista) { evk_nl_delete_list($lista); };

    $out['excel'] = evk_nl_parse_csv("\xEF\xBB\xBFemail;imie;nazwisko\r\njan.kowalski@firma.pl;Jan;Kowalski\r\nanna@example.com;Anna;Nowak\r\n");
    $out['przecinek_imie_pierwsze'] = evk_nl_parse_csv("Imię,E-mail\nJan,jan@example.com\nEwa,ewa@example.com\n");
    $out['tabulator'] = evk_nl_parse_csv("jan@example.com\tJan\newa@example.com\tEwa\n");
    $out['cudzyslow'] = evk_nl_parse_csv("\"Kowalski; Jan\";jan@example.com\n\"Nowak; Ewa\";ewa@example.com\n");
    $out['jedna_kolumna'] = evk_nl_parse_csv("jan@example.com\newa@example.com\n");
    $out['wiersz_bez_adresu'] = evk_nl_parse_csv("email;imie\njan@example.com;Jan\nKowalski;Jan\n");
    $out['textarea'] = evk_nl_parse_textarea("Jan Kowalski <jan@example.com>\nzly-adres\newa@example.com; Ewa\n\n");
    $out['smieci_z_przecinka'] = evk_nl_import_emails($lista, ['jan.kowalski@firma.pl;Jan;Kowalski']);

    // Wypisana osoba: zapis z formularza, potwierdzenie, wypis linkiem z maila.
    $zgoda = ['_consent_at' => '2026-01-10 10:00:00', '_consent_ip' => '1.2.3.4', '_consent_text' => 'Zgadzam się'];
    $r = evk_nl_add_pending_subscriber($lista, 'wypisana@example.com', $zgoda);
    evk_nl_confirm_subscriber($r['token']);
    evk_nl_unsubscribe_by_token($r['token']);
    $przed = evk_nl_get_subscriber_by_token($r['token']);
    // Oczekująca (bez potwierdzenia) — import jej nie ruszy ani nie aktywuje.
    $o = evk_nl_add_pending_subscriber($lista, 'oczekuje@example.com', $zgoda);

    $out['wynik'] = evk_nl_import_emails($lista, ['wypisana@example.com', 'oczekuje@example.com', 'nowa@example.com', 'nowa@example.com', 'jan@firma.pl;Jan']);
    $po = evk_nl_get_subscriber_by_token($r['token']);
    $out['wypisana'] = ['status_przed' => (int) $przed['status'], 'status_po' => (int) $po['status'],
                        'wypis_zostal' => $po['unsubscribed_at'] !== null, 'zgoda_zostala' => $po['fields_json'] === $przed['fields_json']];
    $out['oczekujaca_status'] = (int) evk_nl_get_subscriber_by_token($o['token'])['status'];

    // Formularz zapisu (bez double opt-in) — decyzja samej osoby: przywraca.
    evk_nl_add_subscriber($lista, 'wypisana@example.com', ['_consent_at' => '2026-02-01 12:00:00']);
    $out['formularz_przywraca'] = (int) evk_nl_get_subscriber_by_token($r['token'])['status'];
    break;

// ── Śledzenie kliknięć ──────────────────────────────────────────────────────
case 'klik':
    global $wp_rewrite;
    $struktura = (string) get_option('permalink_structure');
    $sprzatanie[] = static function () use ($struktura) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure($struktura);
        flush_rewrite_rules();
    };

    $wewn = home_url('/oferta/?utm_source=nl&utm_medium=email&utm_campaign=wrzesien');
    $zewn = 'https://example.com/sklep/?produkt=12&wariant=niebieski&ilosc=2';
    // Tak zapisuje TinyMCE: `&` w atrybucie jako `&amp;`.
    $html = '<p><a href="' . str_replace('&', '&amp;', $wewn) . '">Oferta</a> '
          . '<a href="' . str_replace('&', '&amp;', $zewn) . '">Sklep</a></p>';

    add_filter('wp_redirect', static function ($l) { throw new RuntimeException($l); });
    // Tak, jak przeglądarka: atrybut → adres → parametry żądania.
    $parametry = static function (string $href): array {
        parse_str((string) parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY), $q);
        return $q;
    };
    $klik = static function (string $href) use ($parametry): string {
        $adres = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $q = $parametry($href);
        $_GET = $q;
        preg_match('#/nl/click/(\d+)/([A-Za-z0-9]+)/#', $adres, $sciezka);
        $kampania = (int) ($q['evk_nl_campaign'] ?? ($sciezka[1] ?? 0));
        $token    = (string) ($q['evk_nl_token'] ?? ($sciezka[2] ?? ''));
        try { evk_nl_handle_click($token, $kampania); } catch (RuntimeException $e) { return $e->getMessage(); }
        return '(brak przekierowania)';
    };

    foreach (['zwykle' => '', 'ladne' => '/%postname%/'] as $tryb => $str) {
        $wp_rewrite->set_permalink_structure($str);
        flush_rewrite_rules();
        $wynik = evk_nl_inject_tracking($html, 'TOKENTEST', 7);
        preg_match_all('/<a href="([^"]+)"/', $wynik, $m);
        $out[$tryb] = [
            'wewn_cel'  => $klik($m[1][0] ?? ''),
            'zewn_cel'  => $klik($m[1][1] ?? ''),
            'wewn_href' => $m[1][0] ?? '',
            'zewn_href' => $m[1][1] ?? '',
            // Cel zapisany w samym linku (podpisany) — przed naprawą przy kliknięciu.
            'wewn_url'  => (string) ($parametry($m[1][0] ?? '')['url'] ?? ''),
            'zewn_url'  => (string) ($parametry($m[1][1] ?? '')['url'] ?? ''),
        ];
    }
    $out['wzor_wewn'] = $wewn;
    $out['wzor_zewn'] = $zewn;

    /* Mail wysłany PRZED poprawką (ładne odnośniki): cel z `&amp;` podpisany
       w tej postaci. Po poprawce ma prowadzić do prawdziwego adresu. */
    $stary_cel = esc_url_raw(str_replace('&', '&amp;', $zewn));
    $_GET = ['url' => $stary_cel, 'sig' => hash_hmac('sha256', '7|' . $stary_cel, wp_salt('auth'))];
    try { evk_nl_handle_click('TOKENTEST', 7); $out['stary_link'] = '(brak)'; }
    catch (RuntimeException $e) { $out['stary_link'] = $e->getMessage(); }

    /* Encja w części z hostem: po zamianie `&#038;` → `&` host zmieniłby się
       na example.com. Taki cel ma odpaść przy walidacji, nawet podpisany. */
    $w_hoscie = esc_url_raw('https://stara.test&#038;@example.com/');
    $_GET = ['url' => $w_hoscie, 'sig' => hash_hmac('sha256', '7|' . $w_hoscie, wp_salt('auth'))];
    try { evk_nl_handle_click('TOKENTEST', 7); $out['encja_w_hoscie'] = '(brak)'; }
    catch (RuntimeException $e) { $out['encja_w_hoscie'] = $e->getMessage(); }
    $out['strona_glowna'] = home_url();
    break;

// ── Obrazek OG bez wygenerowanego pliku ─────────────────────────────────────
case 'og':
    $stare = get_option('evk_og', []);
    $sprzatanie[] = static function () use ($stare) { update_option('evk_og', $stare); };
    update_option('evk_og', array_merge(is_array($stare) ? $stare : [], ['enabled' => 1, 'fallback_url' => '', 'post_types' => ['post']]));

    $uploads  = wp_upload_dir();
    $zapasowy = $uploads['basedir'] . '/og-fallback.jpg';
    $byl      = is_file($zapasowy);
    if ($byl) { rename($zapasowy, $zapasowy . '.evk-test'); }
    $sprzatanie[] = static function () use ($zapasowy, $byl) {
        if (is_file($zapasowy) && !$byl) unlink($zapasowy);
        if ($byl && is_file($zapasowy . '.evk-test')) rename($zapasowy . '.evk-test', $zapasowy);
    };

    $strona = (int) wp_insert_post(['post_title' => 'Stara strona', 'post_type' => 'page', 'post_status' => 'publish']);
    $sprzatanie[] = static function () use ($strona) { wp_delete_post($strona, true); };
    delete_post_meta($strona, '_evk_og_url');

    $out['bez_niczego']      = evk_og_get_url($strona);
    $out['meta_bez_niczego'] = evk_seo_get_meta($strona)['og_image'];

    // Nieaktualny adres wygenerowanego pliku (plik usunięty z dysku).
    update_post_meta($strona, '_evk_og_url', $uploads['baseurl'] . '/og-images/og-nie-ma-' . $strona . '.jpg');
    $out['plik_zniknal'] = evk_og_get_url($strona);

    // Miniatura wpisu.
    $plik = $uploads['basedir'] . '/evk-t-miniatura-' . $strona . '.png';
    file_put_contents($plik, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $zal = (int) wp_insert_attachment(['post_title' => 'Miniatura', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $plik, $strona);
    wp_update_attachment_metadata($zal, ['width' => 1, 'height' => 1, 'file' => basename($plik)]);
    $sprzatanie[] = static function () use ($zal, $plik) { wp_delete_attachment($zal, true); if (is_file($plik)) unlink($plik); };
    set_post_thumbnail($strona, $zal);
    $out['miniatura']     = evk_og_get_url($strona);
    $out['wzor_miniatura'] = (string) wp_get_attachment_url($zal);
    delete_post_thumbnail($strona);

    // Obrazek domyślny z ustawień.
    update_option('evk_og', array_merge(get_option('evk_og'), ['fallback_url' => 'https://example.com/domyslny.jpg']));
    $out['z_ustawien'] = evk_og_get_url($strona);
    update_option('evk_og', array_merge(get_option('evk_og'), ['fallback_url' => '']));

    // Plik og-fallback.jpg położony w uploads (zgodność ze starą podpowiedzią).
    file_put_contents($zapasowy, 'x');
    $out['plik_w_uploads'] = evk_og_get_url($strona);
    $out['wzor_uploads']   = $uploads['baseurl'] . '/og-fallback.jpg';
    break;

default:
    $out['blad'] = 'nieznany scenariusz';
}

echo wp_json_encode($out);
