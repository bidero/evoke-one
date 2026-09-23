<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — przywracanie na PRAWDZIWYCH WordPressach (tools/testowy-wp.sh):
 * kopia na pierwszym (stara.test, wp_), przywrócenie na drugim (nowa.test,
 * nowy_) w TEJ SAMEJ bazie. Jeden proces widzi jedną instalację, więc
 * scenariusze są rozdzielone na strony:
 *
 *   php tests/php/backup-przywracanie.php kopia_a              dane testowe na A + kopia → ścieżka archiwum
 *   php tests/php/backup-przywracanie.php sumy_a               sumy kontrolne tabel A (bez WordPressa)
 *   php tests/php/backup-przywracanie.php przywroc <zip> <zakres> <lustro> [powtorka]
 *                                                              przywrócenie na B krok po kroku
 *   php tests/php/backup-przywracanie.php fakty_b              stan B po przywróceniu (świeży proces)
 *   php tests/php/backup-przywracanie.php anuluj <zip>         anulowanie w trakcie importu na B
 *   php tests/php/backup-przywracanie.php sprzataj_a           dane testowe i archiwum z A
 *
 * Kroki wołane wprost (evk_backup_tick) z budżetem 1 ms, bez żądań zwrotnych.
 */

$scen = $argv[1] ?? '';

// Sumy tabel A — bez ładowania WordPressa, który sam potrafi coś zapisać.
if ($scen === 'sumy_a') {
    $db = new mysqli('localhost', getenv('EVK_WP_USER') ?: 'evk', getenv('EVK_WP_PASS') ?: 'evk', getenv('EVK_WP_DB') ?: 'evk_test');
    $sumy = [];
    $wynik = $db->query("SHOW TABLES LIKE 'wp\\_%'");
    while ($w = $wynik->fetch_row()) {
        if ($w[0] === 'wp_evk_backup_jobs') continue;   // zadania A pracują w innych testach
        $sumy[$w[0]] = $db->query('CHECKSUM TABLE `' . $w[0] . '`')->fetch_row()[1];
    }
    echo json_encode($sumy);
    exit;
}

$evk_drugi = in_array($scen, ['przywroc', 'fakty_b', 'anuluj', 'zlosliwe'], true);
$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'zip-reader' => 'evk_zip_safe_name',
    'serialize-replace' => 'evk_sr_build_pairs', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick', 'restore' => 'evk_restore_start',
];
require __DIR__ . '/_testowy-wp.php';

add_filter('evk_backup_loopback', '__return_false');
global $wpdb;
evk_backup_create_tables();

const EVK_TEST_TYTUL = 'Evoke test przywracania';

/** Kroki do końca zadania z budżetem 1 ms. $co_krok($job) przed każdym krokiem. */
function do_konca(int $id, ?callable $co_krok = null, int $limit = 50000): array {
    $n = 0;
    while (true) {
        $job = evk_backup_job_get($id);
        if (!in_array($job['status'], ['queued', 'running'], true)) return $job;
        if ($co_krok) $co_krok($job);
        evk_backup_job_update($id, ['budget_ms' => 1]);
        evk_backup_tick($id);
        if (++$n > $limit) throw new RuntimeException('zadanie się nie kończy: ' . $job['phase']);
    }
}

function losowe(int $n, int $ziarno): string {
    mt_srand($ziarno);
    $s = '';
    while (strlen($s) < $n) $s .= pack('N', mt_rand());
    return substr($s, 0, $n);
}

/** Bajty z każdą wartością 0–255 i ucięty znak UTF-8 — nie przejdzie przez json_encode. */
function bajty_blob(): string {
    $s = '';
    for ($i = 0; $i < 256; $i++) $s .= chr($i);
    return $s . "\xC5";
}

$wynik = [];
switch ($scen) {

    case 'kopia_a':
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1, 'retention_count' => 50, 'maintenance_db' => 1,
            'exclusions' => implode("\n", array_merge(evk_backup_default_exclusions(), ['plugins/evoke-one/']))]);
        update_option('maintenance_mode', '');
        // Adres z instalacji (tools/testowy-wp.sh) — inne eksperymenty potrafiły go zmienić.
        update_option('home', 'http://stara.test');
        update_option('siteurl', 'http://stara.test');
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());

        // Treść z adresami w każdym zapisie, w jakim trzymają je wtyczki.
        foreach (get_posts(['title' => EVK_TEST_TYTUL, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1]) as $p) {
            wp_delete_post($p->ID, true);
        }
        wp_insert_post(['post_title' => EVK_TEST_TYTUL, 'post_status' => 'publish', 'post_content' =>
            '<a href="http://stara.test/o-nas/">O nas</a> <img src="http://stara.test/wp-content/uploads/evk-test/obraz.jpg">'
            . ' {"url":"http:\/\/stara.test\/x"} https%3A%2F%2Fstara.test%2Fy stara.test.eu']);
        update_option('evk_test_ser', ['url' => 'http://stara.test/sklep', 'plik' => WP_CONTENT_DIR . '/uploads/evk-test/obraz.jpg',
            'polski' => 'zażółć http://stara.test', 'obce' => 'https://stara.test.eu/']);

        $wpdb->query('DROP TABLE IF EXISTS wp_evk_test_bin, wp_evk_test_bezpk, wp_evk_test_klucz');
        $wpdb->query('CREATE TABLE wp_evk_test_bin (id INT PRIMARY KEY, dane BLOB, nul VARCHAR(20) NULL, pusty VARCHAR(20) NOT NULL DEFAULT \'x\')');
        $wpdb->query($wpdb->prepare("INSERT INTO wp_evk_test_bin VALUES (1, UNHEX(%s), NULL, '')", bin2hex(bajty_blob())));
        $wpdb->query('CREATE TABLE wp_evk_test_bezpk (a INT, b VARCHAR(40))');
        $wpdb->query('CREATE TABLE wp_evk_test_klucz (id INT PRIMARY KEY, b VARCHAR(40))');
        foreach (['wp_evk_test_bezpk', 'wp_evk_test_klucz'] as $t) {
            for ($i = 0; $i < 1200; $i += 200) {
                $w = [];
                // Bez klucza: co dziesiąty wiersz powtórzony — duplikaty SĄ danymi tej tabeli.
                for ($k = $i; $k < $i + 200; $k++) $w[] = '(' . ($t === 'wp_evk_test_bezpk' ? intdiv($k, 10) * 10 : $k) . ", 'wiersz http://stara.test/$k')";
                $wpdb->query("INSERT INTO $t VALUES " . implode(',', $w));
            }
        }

        $up = WP_CONTENT_DIR . '/uploads/evk-test';
        wp_mkdir_p($up);
        file_put_contents($up . '/plik.txt', "treść zażółć\n");
        file_put_contents($up . '/obraz.jpg', losowe(300000, 7));

        // Połączenie z Dyskiem strony A jedzie w zrzucie — przywracanie na B nie może go przejąć.
        update_option('evk_backup_gdrive', ['refresh' => 'dysk-strony-a'], false);
        $id = evk_backup_start('manual');
        $job = do_konca($id);
        // Bez tego panel strony A (testy panelu) próbowałby rozmawiać z prawdziwym Google.
        delete_option('evk_backup_gdrive');
        $zip = evk_backup_dir() . '/' . $job['archive'];
        $wynik = ['status' => $job['status'], 'blad' => $job['error'], 'zip' => $zip,
                  'blob_md5' => md5(bajty_blob()), 'obraz_md5' => md5_file($up . '/obraz.jpg')];
        break;

    case 'sprzataj_a':
        $wpdb->query('DROP TABLE IF EXISTS wp_evk_test_bin, wp_evk_test_bezpk, wp_evk_test_klucz');
        delete_option('evk_test_ser');
        foreach (get_posts(['title' => EVK_TEST_TYTUL, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1]) as $p) {
            wp_delete_post($p->ID, true);
        }
        evk_backup_rmdir(WP_CONTENT_DIR . '/uploads/evk-test');
        foreach (evk_backup_list_archives() as $k) evk_backup_delete_archive($k['archive']);
        $wynik = ['ok' => true];
        break;

    case 'przywroc':
    case 'anuluj':
        /* B przed przywróceniem: tabela i pliki, których w kopii nie ma,
           i rzeczy, które lustro ma zostawić (wykluczenia kopii). */
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1]);
        // Adres B od nowa — poprzednie przywrócenie (albo mutacja) mogło zostawić inny.
        update_option('home', 'http://nowa.test');
        update_option('siteurl', 'http://nowa.test');
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
        $wpdb->query('CREATE TABLE IF NOT EXISTS nowy_obca (id INT PRIMARY KEY)');
        wp_mkdir_p(WP_CONTENT_DIR . '/uploads/obcy-katalog');
        file_put_contents(WP_CONTENT_DIR . '/uploads/obcy.txt', 'obcy');
        file_put_contents(WP_CONTENT_DIR . '/uploads/obcy-katalog/a.txt', 'obcy');
        file_put_contents(WP_CONTENT_DIR . '/debug.log', 'log');
        /* Drop-in TEGO serwera. db-error.php, bo WordPress ładuje go wyłącznie
           przy błędzie bazy — object-cache.php zmieniłby działanie sond B. */
        file_put_contents(WP_CONTENT_DIR . '/db-error.php', '<?php // serwer B');
        wp_mkdir_p(WP_CONTENT_DIR . '/cache');
        file_put_contents(WP_CONTENT_DIR . '/cache/x.txt', 'cache');
        // Znaczniki zakresu: opcja B (znika tylko z podmianą bazy), pliki z kopii (wracają tylko z plikami).
        update_option('evk_test_znacznik', 'b');
        evk_backup_rmdir(WP_CONTENT_DIR . '/uploads/evk-test');
        /* Druga instalacja z prefiksem ZACZYNAJĄCYM SIĘ od prefiksu B (nowy_sklep_):
           jej tabele pasują do LIKE 'nowy_%', a nie są nasze. */
        foreach (['options', 'posts', 'users'] as $t) $wpdb->query("CREATE TABLE IF NOT EXISTS nowy_sklep_$t (id INT PRIMARY KEY)");

        /* Stałe wartości B, RÓŻNE od A — bez tego mutacja, która podmienia je
           na wartości z kopii, zostawia je w B i kolejne przebiegi porównują
           wartość A z wartością A (tak przeżyła mutacja R5). */
        update_option(EVK_BACKUP_DIR_OPTION, 'b0b0b0b0b0b0b0b0b0b0', false);
        update_option('evk_backup_key', str_repeat('klucz-b-', 6), false);
        // Dysk strony B: po przywróceniu zostaje ten, nie ten z kopii A.
        update_option('evk_backup_gdrive', ['refresh' => 'dysk-strony-b'], false);
        $zrodlo = $argv[2];
        $dir = evk_backup_dir();
        evk_backup_ensure_dir($dir);
        foreach (evk_backup_list_archives() as $k) evk_backup_delete_archive($k['archive']);
        copy($zrodlo, $dir . '/' . basename($zrodlo));
        $przed = ['dir' => evk_backup_dir_suffix(), 'key' => evk_backup_loopback_key()];
        file_put_contents(sys_get_temp_dir() . '/evk-przywracanie-przed.json', json_encode($przed));

        $id = evk_restore_start(basename($zrodlo), $argv[3] ?? 'all', ($argv[4] ?? '0') === '1');
        if (is_wp_error($id)) { $wynik = ['blad_startu' => $id->get_error_message()]; break; }

        if ($scen === 'anuluj') {
            // Do połowy importu bazy, potem anulowanie.
            $job = do_konca($id, static function ($j) use ($id) {
                if ($j['phase'] === 'r_db' && count((array) ($j['state']['db']['tables'] ?? [])) >= 3) evk_backup_cancel($id);
            });
            $tmp = (array) $wpdb->get_col("SHOW TABLES LIKE 'evkr%'");
            $wynik = ['status' => $job['status'], 'tabele_tymczasowe' => $tmp, 'praca' => is_dir(evk_backup_work_dir($id)),
                      'home' => get_option('home'), 'obca' => (bool) $wpdb->get_var("SHOW TABLES LIKE 'nowy_obca'"),
                      'maint' => get_option('maintenance_mode')];
            break;
        }

        /* `powtorka`: krok ginie PO wysłaniu porcji, a PRZED zapisem stanu —
           stan zadania cofany do sprzed kroku, dane w tabeli zostają. Raz
           w tabeli z kluczem i raz w tabeli bez klucza. */
        $cofniete = [];
        $fazy = [];
        $powtorka = ($argv[5] ?? '') === 'powtorka';
        $job = do_konca($id, static function ($j) use ($id, $powtorka, &$cofniete, &$fazy) {
            $fazy[$j['phase']] = ($fazy[$j['phase']] ?? 0) + 1;
            if (!$powtorka || $j['phase'] !== 'r_db') return;
            $cur = $j['state']['db']['cur'] ?? null;
            if (!$cur || $cur['rows'] < 500 || isset($cofniete[$cur['src']])) return;
            if (!in_array($cur['src'], ['wp_evk_test_bezpk', 'wp_evk_test_klucz'], true)) return;
            $cofniete[$cur['src']] = $cur['rows'];
            evk_backup_job_update($id, ['budget_ms' => 1]);
            evk_backup_tick($id);                                   // porcja wysłana…
            evk_backup_job_update($id, ['state' => $j['state']]);   // …stan jej nie pamięta
        });
        $wynik = ['status' => $job['status'], 'blad' => $job['error'], 'krokow' => $job['ticks'], 'fazy' => $fazy,
                  'cofniete' => $cofniete, 'log' => array_slice(explode("\n", (string) $job['log']), -14)];
        break;

    case 'zlosliwe':
        /* Archiwum z wpisami, których przywracanie NIE może zapisać: katalog
           tej wtyczki (w teście to dowiązanie do repozytorium — zapis poszedłby
           prosto do kodu), `../` poza stronę, pliki z katalogu głównego,
           katalog kopii. Manifest i zrzut z prawdziwej kopii A. */
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1]);
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
        $zrodlo = new ZipArchive();
        $zrodlo->open($argv[2]);
        $dir = evk_backup_dir();
        evk_backup_ensure_dir($dir);
        $zip = $dir . '/zlosliwe-test.zip';
        $w = EVK_Zip_Writer::create($zip);
        $w->add_string('manifest.json', (string) $zrodlo->getFromName('manifest.json'));
        $w->add_string('wp-content/plugins/evoke-one/evk-zly.php', '<?php // podmieniony kod');
        $w->add_string('wp-content/../evk-zly-wyzej.txt', 'poza stroną');
        $w->add_string('_root/.htaccess', 'Deny from all # z kopii');
        $w->add_string('wp-content/evk-backups-obcy/x.txt', 'kopia w kopii');
        $w->add_string('wp-content/uploads/evk-ok.txt', 'ok');
        // Drop-iny starego serwera: pierwszy zatrzymałby KAŻDE żądanie (zgłoszone z użycia, 1.227.1).
        $w->add_string('wp-content/object-cache.php', "<?php die('pamięć podręczna starego serwera');");
        $w->add_string('wp-content/db-error.php', '<?php // z kopii');
        file_put_contents(WP_CONTENT_DIR . '/db-error.php', '<?php // serwer B');
        $w->finish();
        $zrodlo->close();
        $htaccess = @file_get_contents(ABSPATH . '.htaccess');
        $id = evk_restore_start('zlosliwe-test.zip', 'files', false);
        $job = do_konca($id);
        $wynik = [
            'status'     => $job['status'], 'blad' => $job['error'],
            'ok'         => @file_get_contents(WP_CONTENT_DIR . '/uploads/evk-ok.txt'),
            'wtyczka'    => file_exists(EVOKE_ONE_DIR . 'evk-zly.php'),
            'wyzej'      => file_exists(ABSPATH . 'evk-zly-wyzej.txt') || file_exists(dirname(WP_CONTENT_DIR) . '/evk-zly-wyzej.txt'),
            'htaccess'   => @file_get_contents(ABSPATH . '.htaccess') === $htaccess,
            'kopia'      => is_dir(WP_CONTENT_DIR . '/evk-backups-obcy'),
            'object_cache' => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
            'db_error'   => @file_get_contents(WP_CONTENT_DIR . '/db-error.php'),
            'log'        => array_values(preg_grep('/Pominię|katalogu głównego|drop-in/u', explode("\n", (string) $job['log']))),
        ];
        // Sprzątanie także po mutacji, która zapisała do repozytorium albo drop-in.
        @unlink(EVOKE_ONE_DIR . 'evk-zly.php');
        @unlink(WP_CONTENT_DIR . '/object-cache.php');
        @unlink(ABSPATH . 'evk-zly-wyzej.txt');
        @unlink(WP_CONTENT_DIR . '/uploads/evk-ok.txt');
        evk_backup_rmdir(WP_CONTENT_DIR . '/evk-backups-obcy');
        evk_backup_delete_archive('zlosliwe-test.zip');
        break;

    case 'fakty_b':
        $przed = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/evk-przywracanie-przed.json'), true) ?: [];
        $post = get_posts(['title' => EVK_TEST_TYTUL, 'post_type' => 'post', 'numberposts' => 1]);
        $tresc = $post ? $post[0]->post_content : '';
        $ser = get_option('evk_test_ser');
        $admin = get_user_by('login', 'admin');
        $job = evk_backup_job_get((int) $wpdb->get_var('SELECT MAX(id) FROM ' . evk_backup_jobs_table()));
        $wynik = [
            'home'        => get_option('home'),
            'siteurl'     => get_option('siteurl'),
            'tresc'       => $tresc,
            'ser'         => is_array($ser) ? $ser : ['nie_tablica' => $wpdb->get_var("SELECT option_value FROM nowy_options WHERE option_name = 'evk_test_ser'")],
            'content_dir' => WP_CONTENT_DIR,
            'role'        => array_keys((array) get_option('nowy_user_roles', [])),
            'admin_z_a'   => $admin ? $admin->has_cap('manage_options') : null,
            'nowy_z_b'    => (bool) get_user_by('login', 'nowy'),
            'stare_klucze' => (int) $wpdb->get_var("SELECT COUNT(*) FROM nowy_usermeta WHERE meta_key LIKE 'wp\\_%'"),
            'obca'        => (bool) $wpdb->get_var("SHOW TABLES LIKE 'nowy_obca'"),
            'znacznik'    => get_option('evk_test_znacznik'),
            'sklep'       => count((array) $wpdb->get_col("SHOW TABLES LIKE 'nowy\\_sklep\\_%'")),
            'sklep_w_zrzucie' => in_array('nowy_sklep_options', evk_backup_db_tables()[0], true),
            'robocze'     => (array) $wpdb->get_col("SHOW TABLES WHERE Tables_in_" . DB_NAME . " LIKE 'evkr%' OR Tables_in_" . DB_NAME . " LIKE 'evko%'"),
            'blob_md5'    => md5((string) $wpdb->get_var('SELECT dane FROM nowy_evk_test_bin WHERE id = 1')),
            'nul'         => $wpdb->get_row('SELECT nul, pusty FROM nowy_evk_test_bin WHERE id = 1', ARRAY_A),
            'bezpk'       => (int) $wpdb->get_var('SELECT COUNT(*) FROM nowy_evk_test_bezpk'),
            'bezpk_rozne' => (int) $wpdb->get_var('SELECT COUNT(DISTINCT a) FROM nowy_evk_test_bezpk'),
            'klucz'       => (int) $wpdb->get_var('SELECT COUNT(*) FROM nowy_evk_test_klucz'),
            'klucz_url'   => (string) $wpdb->get_var('SELECT b FROM nowy_evk_test_klucz WHERE id = 7'),
            'dir_zostal'  => get_option('evk_backup_dir') === 'b0b0b0b0b0b0b0b0b0b0' && ($przed['dir'] ?? '') === get_option('evk_backup_dir'),
            'key_zostal'  => get_option('evk_backup_key') === str_repeat('klucz-b-', 6),
            'dysk'        => (get_option('evk_backup_gdrive')['refresh'] ?? null),
            'wtyczka'     => in_array('evoke-one/evoke-one.php', (array) get_option('active_plugins'), true),
            'modul'       => !empty(get_option(EVK_BACKUP_OPTION)['enabled']),
            'maint'       => get_option('maintenance_mode'),
            'zadanie'     => $job ? [$job['type'], $job['status']] : null,
            'pliki'       => [
                'plik'      => @file_get_contents(WP_CONTENT_DIR . '/uploads/evk-test/plik.txt'),
                'obraz_md5' => @md5_file(WP_CONTENT_DIR . '/uploads/evk-test/obraz.jpg'),
                'obcy'      => file_exists(WP_CONTENT_DIR . '/uploads/obcy.txt'),
                'obcy_kat'  => is_dir(WP_CONTENT_DIR . '/uploads/obcy-katalog'),
                'debug_log' => file_exists(WP_CONTENT_DIR . '/debug.log'),
                'db_error'  => @file_get_contents(WP_CONTENT_DIR . '/db-error.php'),
                'cache'     => file_exists(WP_CONTENT_DIR . '/cache/x.txt'),
                'wtyczka_link' => is_link(WP_CONTENT_DIR . '/plugins/evoke-one'),
                'katalog_kopii' => is_dir(evk_backup_dir()),
            ],
        ];
        break;
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
