<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: kraj odwiedzającego z DB-IP Lite (1.285.0).
 *
 * Decyzja zgłaszającego (02.10): strona sama pobiera bazę co miesiąc.
 * Plik `dbip-country-lite-RRRR-MM.csv.gz` (~4,5 MB, ~710 tys. zakresów,
 * wiersz „od,do,KRAJ”, IPv4 i IPv6, „ZZ” = nieznany) na licencji CC BY 4.0 —
 * podpis „IP Geolocation by DB-IP” stoi w raporcie i w zakładce.
 *
 * Import idzie porcjami (WP-Cron albo przycisk w zakładce): pobranie,
 * rozpakowanie, wiersze do tabeli `…_kraje_nowe`, na końcu zamiana tabel
 * jednym RENAME — raport i zbieranie przez cały import korzystają ze starej.
 *
 * IPv4 i IPv6 leżą osobno (kolumna `v`): klucze mają 4 albo 16 bajtów, a przy
 * porównaniu bajtowym zakres IPv4 32.0.0.0/8 (0x20…) leży tuż przed „2001:…”
 * — bez rozdziału adres IPv6 trafiałby w kraj zakresu IPv4 (test KR1). Adresy idą do zapytań jako HEX —
 * surowe bajty w łańcuchu przy połączeniu utf8mb4 MySQL potrafi odrzucić.
 */

const EVK_STAT_DBIP_ADRES = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

function evk_stat_dbip_stan(): array {
    $s = get_option('evk_stat_dbip', []);
    return array_merge(['wersja' => '', 'zakresy' => 0, 'import' => null, 'blad' => '', 'kiedy' => 0], is_array($s) ? $s : []);
}

function evk_stat_dbip_katalog(): string {
    $u = wp_upload_dir(null, false);
    return trailingslashit((string) $u['basedir']) . 'evk-statystyki';
}

function evk_stat_kraje_gotowe(): bool {
    return evk_stat_dbip_stan()['wersja'] !== '';
}

/** Kraj adresu IP: dwuliterowy kod albo '' (brak bazy, adres spoza bazy, „ZZ”). */
function evk_stat_kraj(string $ip): string {
    global $wpdb;
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    if ($bin === false || !evk_stat_kraje_gotowe()) return '';
    $v = strlen($bin) === 4 ? 4 : 6;
    $hex = bin2hex($bin);
    $w = $wpdb->get_row($wpdb->prepare('SELECT kraj, HEX(do_ip) AS do_hex FROM ' . evk_stat_tabela('kraje') . ' WHERE v = %d AND od_ip <= UNHEX(%s) ORDER BY od_ip DESC LIMIT 1', $v, $hex), ARRAY_A);
    if (!$w || strcasecmp((string) $w['do_hex'], $hex) < 0 || $w['kraj'] === 'ZZ') return '';
    return (string) $w['kraj'];
}

/** Nazwa kraju po polsku (rozszerzenie intl) albo sam kod. */
function evk_stat_nazwa_kraju(string $kod): string {
    if ($kod === '') return 'nieznany';
    if (class_exists('Locale')) {
        /* Bez danych ICU dla regionu intl oddaje sam kod — wtedy zostaje kod. */
        $n = (string) \Locale::getDisplayRegion('-' . $kod, 'pl');
        if ($n !== $kod) return $n;
    }
    return $kod;
}

function evk_stat_kraje_tabela_sql(string $nazwa): string {
    global $wpdb;
    return "CREATE TABLE $nazwa (
        v tinyint(1) UNSIGNED NOT NULL,
        od_ip varbinary(16) NOT NULL,
        do_ip varbinary(16) NOT NULL,
        kraj char(2) NOT NULL,
        PRIMARY KEY  (v,od_ip)
    ) " . $wpdb->get_charset_collate();
}

/**
 * Jeden krok importu: start (pobranie i rozpakowanie) albo porcja wierszy.
 * Zwraca stan dla panelu.
 *
 * @return array{stan:string,procent:int,wersja:string,zakresy:int,blad:string}
 */
function evk_stat_dbip_krok(float $sekundy = 8.0): array {
    global $wpdb;
    $s = evk_stat_dbip_stan();
    $koniec = microtime(true) + $sekundy;
    $wynik = static function (string $stan, int $proc) use (&$s): array {
        return ['stan' => $stan, 'procent' => $proc, 'wersja' => (string) $s['wersja'], 'zakresy' => (int) $s['zakresy'], 'blad' => (string) $s['blad']];
    };
    $nowa = evk_stat_tabela('kraje_nowe');

    if (!is_array($s['import'])) {
        /* Start: plik bieżącego miesiąca, a gdy go jeszcze nie ma — poprzedniego. */
        $kat = evk_stat_dbip_katalog();
        if (!wp_mkdir_p($kat)) { $s['blad'] = 'Nie da się utworzyć katalogu ' . $kat . '.'; update_option('evk_stat_dbip', $s, false); return $wynik('blad', 0); }
        $gz = $kat . '/dbip.csv.gz';
        $blad = '';
        $miesiac = '';
        foreach ([wp_date('Y-m'), wp_date('Y-m', strtotime('first day of last month', time()))] as $m) {
            $adres = (string) apply_filters('evk_stat_dbip_adres', sprintf(EVK_STAT_DBIP_ADRES, $m), $m);
            $odp = wp_remote_get($adres, ['timeout' => 120, 'stream' => true, 'filename' => $gz]);
            $kod = is_wp_error($odp) ? 0 : (int) wp_remote_retrieve_response_code($odp);
            if ($kod === 200 && filesize($gz) > 0) { $miesiac = $m; break; }
            $blad = is_wp_error($odp) ? $odp->get_error_message() : 'HTTP ' . $kod;
            @unlink($gz);
        }
        if ($miesiac === '') { $s['blad'] = 'Nie udało się pobrać bazy DB-IP (' . $blad . ').'; $s['kiedy'] = time(); update_option('evk_stat_dbip', $s, false); return $wynik('blad', 0); }
        if ($miesiac === $s['wersja']) { @unlink($gz); $s['blad'] = ''; $s['kiedy'] = time(); update_option('evk_stat_dbip', $s, false); return $wynik('gotowe', 100); }
        /* Strona błędu z kodem 200 (CDN, przekierowanie na stronę główną) to nie baza — bez nagłówka gzip stop. */
        $naglowek = (string) @file_get_contents($gz, false, null, 0, 2);
        if ($naglowek !== "\x1f\x8b") { @unlink($gz); $s['blad'] = 'Pobrany plik nie jest bazą DB-IP (brak nagłówka gzip).'; $s['kiedy'] = time(); update_option('evk_stat_dbip', $s, false); return $wynik('blad', 0); }
        $csv = $kat . '/dbip.csv';
        $we = @gzopen($gz, 'rb');
        $wy = @fopen($csv, 'wb');
        if (!$we || !$wy) { @unlink($gz); $s['blad'] = 'Nie da się rozpakować pliku bazy.'; update_option('evk_stat_dbip', $s, false); return $wynik('blad', 0); }
        while (!gzeof($we)) fwrite($wy, (string) gzread($we, 1 << 20));
        gzclose($we); fclose($wy);
        @unlink($gz);
        $wpdb->query("DROP TABLE IF EXISTS $nowa");
        $wpdb->query(evk_stat_kraje_tabela_sql($nowa));
        $s['import'] = ['miesiac' => $miesiac, 'plik' => $csv, 'poz' => 0, 'rozmiar' => (int) filesize($csv), 'wiersze' => 0];
        $s['blad'] = '';
        update_option('evk_stat_dbip', $s, false);
        if (microtime(true) > $koniec) return $wynik('import', 0);
    }

    $im = $s['import'];
    $f = @fopen((string) $im['plik'], 'rb');
    if (!$f) { $s['import'] = null; $s['blad'] = 'Zniknął plik importu — zacznij od nowa.'; update_option('evk_stat_dbip', $s, false); return $wynik('blad', 0); }
    fseek($f, (int) $im['poz']);
    $porcja = (int) apply_filters('evk_stat_dbip_porcja', 2000);
    $wartosci = [];
    while (microtime(true) < $koniec && ($linia = fgets($f)) !== false) {
        $p = explode(',', trim($linia));
        if (count($p) !== 3) continue;
        $a = @inet_pton($p[0]); $b = @inet_pton($p[1]);
        if ($a === false || $b === false || strlen($a) !== strlen($b) || !preg_match('/^[A-Z]{2}$/', $p[2])) continue;
        $wartosci[] = '(' . (strlen($a) === 4 ? 4 : 6) . ",UNHEX('" . bin2hex($a) . "'),UNHEX('" . bin2hex($b) . "'),'" . $p[2] . "')";
        $im['wiersze']++;
        if (count($wartosci) >= $porcja) { evk_stat_dbip_wstaw($nowa, $wartosci); $wartosci = []; }
    }
    if ($wartosci) evk_stat_dbip_wstaw($nowa, $wartosci);
    $koniec_pliku = feof($f);
    $im['poz'] = (int) ftell($f);
    fclose($f);

    if (!$koniec_pliku) {
        $s['import'] = $im;
        update_option('evk_stat_dbip', $s, false);
        return $wynik('import', (int) min(99, floor($im['poz'] / max(1, $im['rozmiar']) * 100)));
    }
    /* Za mało zakresów (prawdziwa baza ma ~710 tys.) — plik uszkodzony albo obcy: stara baza zostaje. */
    $minimum = (int) apply_filters('evk_stat_dbip_minimum', 100000);
    if ((int) $im['wiersze'] < $minimum) {
        $wpdb->query("DROP TABLE IF EXISTS $nowa");
        @unlink((string) $im['plik']);
        $s['import'] = null;
        $s['blad'] = sprintf('Plik bazy ma tylko %d zakresów — wygląda na uszkodzony, zostaje poprzednia baza.', (int) $im['wiersze']);
        $s['kiedy'] = time();
        update_option('evk_stat_dbip', $s, false);
        return $wynik('blad', 0);
    }
    /* Koniec: zamiana tabel jednym RENAME — zbieranie nie widzi pustej bazy ani przez chwilę. */
    $stara = evk_stat_tabela('kraje');
    $wpdb->query("DROP TABLE IF EXISTS {$stara}_stara");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stara))) {
        $wpdb->query("RENAME TABLE $stara TO {$stara}_stara, $nowa TO $stara");
        $wpdb->query("DROP TABLE IF EXISTS {$stara}_stara");
    } else {
        $wpdb->query("RENAME TABLE $nowa TO $stara");
    }
    @unlink((string) $im['plik']);
    $s = ['wersja' => (string) $im['miesiac'], 'zakresy' => (int) $im['wiersze'], 'import' => null, 'blad' => '', 'kiedy' => time()];
    update_option('evk_stat_dbip', $s, false);
    return $wynik('gotowe', 100);
}

/** Jedno INSERT porcji wierszy importu (wartości już złożone z HEX i kodu kraju). @param list<string> $wartosci */
function evk_stat_dbip_wstaw(string $tabela, array $wartosci): void {
    global $wpdb;
    $wpdb->query("INSERT IGNORE INTO $tabela (v, od_ip, do_ip, kraj) VALUES " . implode(',', $wartosci));
}

/* Cron: porcje importu jedna po drugiej, co 10 s, aż do końca. */
add_action('evk_stat_dbip', function (): void {
    if (!evk_stat_wlaczone()) return;
    $w = evk_stat_dbip_krok(20.0);
    if ($w['stan'] === 'import' && !wp_next_scheduled('evk_stat_dbip')) wp_schedule_single_event(time() + 10, 'evk_stat_dbip');
});

/* Raz na dobę (zbiórka): baza z innego miesiąca niż bieżący — import w tle. */
add_action('evk_stat_dobowy', function (): void {
    $s = evk_stat_dbip_stan();
    if (!is_array($s['import']) && $s['wersja'] !== wp_date('Y-m') && !wp_next_scheduled('evk_stat_dbip')) wp_schedule_single_event(time() + 60, 'evk_stat_dbip');
}, 20);

/* Przycisk w zakładce: krok po kroku z paskiem postępu — tylko administrator. */
add_action('wp_ajax_evk_stat_dbip', function (): void {
    check_ajax_referer('evk_stat', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    wp_send_json_success(evk_stat_dbip_krok(8.0));
});
