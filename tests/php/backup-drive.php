<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — Dysk Google (includes/backup/gdrive.php) na prawdziwym
 * WordPressie (tools/testowy-wp.sh), z atrapą Google
 * (tests/php/_google-atrapa.php) pod adresem z EVK_GOOGLE.
 *
 *   EVK_GOOGLE=http://127.0.0.1:PORT php tests/php/backup-drive.php
 *
 * Kroki zadań wołane wprost (evk_backup_tick) z budżetem 1 ms — jedna porcja
 * na krok, kawałki po 256 KB. Jeden przebieg, jeden JSON z faktami —
 * oceniają je sprawdzenia w tests/backup-drive.test.js.
 */

$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'zip-reader' => 'evk_zip_safe_name',
    'serialize-replace' => 'evk_sr_build_pairs', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick', 'restore' => 'evk_restore_start', 'schedule' => 'evk_backup_next_run',
    'gdrive' => 'evk_gdrive_config', 'ajax' => 'evk_backup_job_public',
];
require __DIR__ . '/_testowy-wp.php';

$atrapa = rtrim((string) getenv('EVK_GOOGLE'), '/');
$posrednik = (string) getenv('EVK_GOOGLE_BROKER');
if ($atrapa === '' || $posrednik === '') { echo json_encode(['brak' => 'Brak EVK_GOOGLE / EVK_GOOGLE_BROKER — sondę uruchamia tests/backup-drive.test.js razem z atrapą Google.']); exit; }

global $wpdb;
add_filter('evk_backup_loopback', '__return_false');
/* Jak na stronie klienta: bez sekretu, tokeny przez pośrednika
   (tools/oauth-relay/token.php), który zna sekret i mówi do atrapy. */
add_filter('evk_backup_gdrive_endpoints', static function (array $c) use ($atrapa, $posrednik): array {
    return array_merge($c, ['auth' => $atrapa . '/o/oauth2/v2/auth', 'token' => $posrednik, 'revoke' => $atrapa . '/revoke',
        'api' => $atrapa . '/drive/v3', 'upload' => $atrapa . '/upload/drive/v3', 'redirect' => $atrapa . '/evk-oauth/',
        'client_id' => 'test-klient']);
});
// Co strona wysyła do pośrednika — sekretu nie ma mieć w ogóle.
$do_posrednika = [];
add_filter('http_request_args', static function ($args, $url) use ($posrednik, &$do_posrednika) {
    if ($url === $posrednik) $do_posrednika[] = array_keys((array) ($args['body'] ?? []));
    return $args;
}, 10, 2);
// Sufit kawałka: 256 KB (wiele kawałków na małym archiwum); sekcja „dopasowanie" podnosi go do 8 MB.
$sufit = 262144;
add_filter('evk_backup_gdrive_chunk', static function () use (&$sufit) { return $sufit; });
$maile = [];
add_filter('pre_wp_mail', static function ($nic, $atts) use (&$maile) { $maile[] = $atts; return true; }, 10, 2);

evk_backup_create_tables();
$wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
update_option(EVK_BACKUP_OPTION, ['enabled' => 1, 'notify_notice' => 1, 'notify_address' => '', 'gdrive_auto_schedule' => 1,
    'gdrive_retention_days' => 14, 'retention_count' => 50]);
evk_gdrive_forget();
delete_option(EVK_BACKUP_ALERT_OPTION);
wp_set_current_user(1);

$tmp = sys_get_temp_dir() . '/evk-dysk-' . getmypid();
@mkdir($tmp, 0700, true);
$przed = array_column(evk_backup_list_archives(), 'archive');
register_shutdown_function(static function () use ($tmp, $przed, $wpdb) {
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
    foreach (evk_backup_list_archives() as $k) if (!in_array($k['archive'], $przed, true)) evk_backup_delete_archive($k['archive']);
    foreach (glob(evk_backup_dir() . '/.pobieranie-*') ?: [] as $p) @unlink($p);
    $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
    evk_gdrive_forget();
    delete_option(EVK_BACKUP_ALERT_OPTION);
});

/** Żądanie do sterowania atrapą. */
function atrapa(string $p, ?array $dane = null): array {
    global $atrapa;
    $r = $dane === null ? wp_remote_get($atrapa . $p, ['timeout' => 30])
        : wp_remote_post($atrapa . $p, ['timeout' => 30, 'body' => (string) wp_json_encode($dane), 'headers' => ['Content-Type' => 'application/json']]);
    return is_wp_error($r) ? ['blad' => $r->get_error_message()] : (json_decode((string) wp_remote_retrieve_body($r), true) ?: []);
}

function losowe(int $n, int $ziarno): string {
    mt_srand($ziarno);
    $s = '';
    while (strlen($s) < $n) $s .= pack('N', mt_rand());
    return substr($s, 0, $n);
}

/** Kopia w katalogu kopii: manifest i plik nieściśliwy $n bajtów. */
function kopia(string $nazwa, int $n, int $ziarno, int $utworzona): string {
    global $tmp;
    $cel = evk_backup_dir() . '/' . $nazwa;
    evk_backup_ensure_dir(evk_backup_dir());
    $w = EVK_Zip_Writer::create($cel);
    $w->add_string('manifest.json', (string) json_encode(['format' => 1, 'created_at' => gmdate('c', $utworzona),
        'home' => 'http://stara.test', 'siteurl' => 'http://stara.test', 'db_rows' => 0, 'files' => 1]));
    file_put_contents($tmp . '/src.bin', losowe($n, $ziarno));
    $w->add_file($tmp . '/src.bin', 'wp-content/uploads/evk-dysk.bin', microtime(true) + 60);
    $w->finish();
    evk_backup_meta_write($cel, ['archive' => $nazwa, 'created_at' => $utworzona, 'source' => 'manual', 'pinned' => false,
        'db_rows' => 0, 'files' => 1]);
    return $cel;
}

/**
 * Kroki do końca zadania; $co_krok przed każdym. Budżet 1 ms = jedna porcja
 * na krok; $budzet (ms) może go zmienić dla kroku — wtedy w jednym kroku idzie
 * kilka porcji, jak na prawdziwym serwerze.
 */
function do_konca(int $id, ?callable $co_krok = null, int $limit = 400, ?callable $budzet = null): array {
    for ($i = 0; $i < $limit; $i++) {
        $j = evk_backup_job_get($id);
        if (!in_array($j['status'], ['queued', 'running'], true)) return $j;
        if ($co_krok) $co_krok($j, $i);
        evk_backup_tick($id, $budzet ? $budzet($j) : 1);
    }
    return evk_backup_job_get($id);
}

function blad(callable $f): string {
    try { $f(); return 'przeszło'; } catch (RuntimeException $e) { return $e->getMessage(); }
}

/** Zgoda w atrapie: adres zgody → przekierowanie z kodem (bez strony pośredniej — ta ma własny test). */
function zgoda(string $url): array {
    $r = wp_remote_get($url, ['redirection' => 0, 'timeout' => 30]);
    parse_str((string) wp_parse_url((string) wp_remote_retrieve_header($r, 'location'), PHP_URL_QUERY), $q);
    return $q;
}

$w = [];
atrapa('/_atrapa/wyczysc');

// ── Łączenie ─────────────────────────────────────────────────────────────
$url = evk_gdrive_auth_url(1);
parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
$cz = explode('.', (string) $q['state']);
$dane = json_decode((string) base64_decode(strtr($cz[0], '-_', '+/')), true);
$w['adres_zgody'] = [
    'klient' => $q['client_id'], 'powrot' => $q['redirect_uri'] === $atrapa . '/evk-oauth/', 'pkce' => $q['code_challenge_method'],
    'wyzwanie' => strlen((string) $q['code_challenge']), 'offline' => $q['access_type'], 'zgoda' => $q['prompt'],
    'zakres' => $q['scope'], 'u' => $dane['u'] ?? null, 'u_ok' => ($dane['u'] ?? '') === evk_gdrive_callback_url(),
];
// Ten sam state: zmieniony podpis, zmieniona treść (inny adres powrotu) — odmowa.
$w['zly_podpis'] = blad(static function () use ($q) { evk_gdrive_verify_state(substr($q['state'], 0, -1) . 'x', 1); });
$obcy = evk_gdrive_b64url((string) wp_json_encode(['u' => 'https://zly.test/wp-admin/admin-post.php?action=evk_backup_gdrive_callback', 'n' => $dane['n']]));
$w['podmieniona_tresc'] = blad(static function () use ($obcy, $cz) { evk_gdrive_verify_state($obcy . '.' . $cz[1], 1); });
$w['inny_uzytkownik'] = blad(static function () use ($q) { evk_gdrive_verify_state($q['state'], 2); });

// Jednorazowość: ten sam powrót drugi raz.
$z = zgoda(evk_gdrive_auth_url(1));
$wer = evk_gdrive_verify_state($z['state'], 1);
$w['drugi_raz'] = blad(static function () use ($z) { evk_gdrive_verify_state($z['state'], 1); });
// PKCE: kod z weryfikatorem nie z tego łączenia — Google (atrapa) odmawia.
$w['zly_weryfikator'] = blad(static function () use ($z) { evk_gdrive_connect_finish($z['code'], evk_gdrive_b64url(random_bytes(48))); });
$w['po_zlym'] = evk_gdrive_connected();

// Zgoda bez dostępu do Dysku (odznaczony w oknie Google).
atrapa('/_atrapa/ster', ['bez_drive_file' => 1]);
$z = zgoda(evk_gdrive_auth_url(1));
$w['bez_dysku'] = blad(static function () use ($z) { evk_gdrive_connect_finish($z['code'], evk_gdrive_verify_state($z['state'], 1)); });
$w['bez_dysku_polaczone'] = evk_gdrive_connected();
atrapa('/_atrapa/ster', ['bez_drive_file' => 0]);

$z = zgoda(evk_gdrive_auth_url(1));
$s = evk_gdrive_connect_finish($z['code'], evk_gdrive_verify_state($z['state'], 1));
$stan = atrapa('/_atrapa/stan');
$foldery = array_values(array_filter($stan['pliki'], static function ($f) { return $f['mimeType'] === EVK_GDRIVE_FOLDER_MIME; }));
$w['polaczone'] = ['refresh' => !empty($s['refresh']), 'email' => $s['email'] ?? '', 'folder' => $s['folder'] ?? '',
    'foldery' => count($foldery), 'folder_nazwa' => $foldery[0]['name'] ?? '', 'folder_strona' => $foldery[0]['appProperties']['evk_site'] ?? '',
    'klucz' => evk_gdrive_site_key()];
// Ponowne połączenie tego samego konta: ten sam folder, bez drugiego.
evk_gdrive_forget();
$z = zgoda(evk_gdrive_auth_url(1));
$s2 = evk_gdrive_connect_finish($z['code'], evk_gdrive_verify_state($z['state'], 1));
$stan = atrapa('/_atrapa/stan');
$w['ponownie'] = ['ten_sam' => ($s2['folder'] ?? '') === ($s['folder'] ?? '-'),
    'foldery' => count(array_filter($stan['pliki'], static function ($f) { return $f['mimeType'] === EVK_GDRIVE_FOLDER_MIME; }))];
$folder = (string) $s2['folder'];

// ── Wysyłka z ubitym krokiem i chwilowymi błędami ──────────────────────────
$zip1 = kopia('dysk-test-1.zip', 1300000, 11, time() - 60);
$md5_1 = md5_file($zip1);
// Token dostępu, którego Google już nie zna: 401 → odświeżenie → powtórka.
$st = evk_gdrive_state();
$st['access'] = 'ya29.przeterminowany';
$st['access_exp'] = time() + 3000;
evk_gdrive_save($st);
$odsw_przed = (int) ($stan['odswiezen'] ?? 0);
$id = evk_gdrive_upload_start('dysk-test-1.zip');
/* Dwa osobne zdarzenia, mierzone osobno:
   1) krok „zginął" po dwóch kawałkach, przed zapisem pozycji — w bazie
      stara; następny krok (jedna porcja) ma zapytać Google, gdzie jest,
   2) potem, w krokach po 5 s (kilka kawałków w jednym): kawałek przyjęty ze
      zgubioną odpowiedzią i kawałek odrzucony błędem 503 — w ŚRODKU kroku,
      bez pytania o stan na początku kolejnego. */
$etap = 0;
$cofniete = null;
$migawka = [];
$puty = static function (array $stan): int {
    return count(array_filter($stan['log'], static function ($l) { return $l['m'] === 'PUT' && $l['n'] > 0; }));
};
$job = do_konca($id, static function ($j) use (&$etap, &$cofniete, &$migawka, $puty) {
    if ($etap === 0 && $j['phase'] === 'u_send' && (int) ($j['state']['u']['pos'] ?? 0) >= 524288) {
        $cofniete = (int) $j['state']['u']['pos'];
        $j['state']['u']['pos'] = 0;
        evk_backup_job_update($j['id'], ['state' => $j['state']]);
        $etap = 1;
    } elseif ($etap === 1) {
        $st = atrapa('/_atrapa/stan');
        $migawka = ['niezgodne' => (int) ($st['niezgodne'] ?? 0), 'puty' => $puty($st), 'pos' => (int) $j['state']['u']['pos']];
        atrapa('/_atrapa/ster', ['put_zgub' => 1, 'put_503' => 1]);
        $etap = 2;
    }
}, 400, static function () use (&$etap) { return $etap < 2 ? 1 : 5000; });
$stan = atrapa('/_atrapa/stan');
$meta1 = json_decode((string) file_get_contents($zip1 . '.json'), true);
$plik1 = $stan['pliki'][$meta1['drive_id'] ?? ''] ?? [];
$w['wysylka'] = [
    'status' => $job['status'], 'blad' => $job['error'], 'cofniete' => $cofniete, 'drive_id' => $meta1['drive_id'] ?? '',
    'md5' => ($plik1['md5Checksum'] ?? '') === $md5_1, 'rozmiar' => (int) ($plik1['size'] ?? 0) === filesize($zip1),
    'folder' => ($plik1['parents'][0] ?? '') === $folder, 'wlasciwosci' => $plik1['appProperties'] ?? [],
    'niezgodne_po_ubiciu' => $migawka['niezgodne'] ?? -1,
    'niezgodne_po_bledach' => (int) ($stan['niezgodne'] ?? 0) - ($migawka['niezgodne'] ?? 0),
    'kawalki_po_bledach' => $puty($stan) - ($migawka['puty'] ?? 0), 'pos_przed_bledami' => $migawka['pos'] ?? -1,
    'rozmiar_zip' => (int) filesize($zip1), 'odswiezenia' => (int) ($stan['odswiezen'] ?? 0) - $odsw_przed,
    'wznowienie' => (bool) preg_grep('/Wznowienie wysyłki: Google ma 512/', explode("\n", $job['log'])),
    'ponowienia' => count(preg_grep('/ponawiam/', explode("\n", $job['log'])) ?: []),
];

// ── Retencja: 14 dni, przypięte i cudze zostają ────────────────────────────
$dzien = DAY_IN_SECONDS;
$posiej = static function (string $nazwa, array $rodzice, array $wl) {
    return atrapa('/_atrapa/plik', ['name' => $nazwa, 'parents' => $rodzice, 'body' => 'x',
        'appProperties' => $wl + ['evk' => 'backup']])['id'];
};
$stara     = $posiej('stara.zip', [$folder], ['evk_site' => evk_gdrive_site_key(), 'created' => (string) (time() - 20 * $dzien), 'pinned' => '0']);
$przypieta = $posiej('stara-przypieta.zip', [$folder], ['evk_site' => evk_gdrive_site_key(), 'created' => (string) (time() - 20 * $dzien), 'pinned' => '1']);
$swieza    = $posiej('swieza.zip', [$folder], ['evk_site' => evk_gdrive_site_key(), 'created' => (string) (time() - 3 * $dzien)]);
$cudza     = $posiej('cudza.zip', ['INNYFOLDER'], ['evk_site' => 'inna.test', 'created' => (string) (time() - 20 * $dzien), 'source' => 'schedule']);
kopia('dysk-test-2.zip', 300000, 12, time());
$job2 = do_konca(evk_gdrive_upload_start('dysk-test-2.zip'));
$stan = atrapa('/_atrapa/stan');
$w['retencja'] = ['status' => $job2['status'], 'stara' => isset($stan['pliki'][$stara]), 'przypieta' => isset($stan['pliki'][$przypieta]),
    'swieza' => isset($stan['pliki'][$swieza]), 'cudza' => isset($stan['pliki'][$cudza]),
    'pierwsza' => isset($stan['pliki'][$meta1['drive_id'] ?? '']),
    'log' => (bool) preg_grep('/Retencja na Dysku: usunięte kopie starsze niż 14 dni: stara\.zip\./', explode("\n", $job2['log']))];

// ── Przypięcie lokalne jedzie na Dysk ─────────────────────────────────────
evk_backup_set_pinned('dysk-test-1.zip', true);
$stan = atrapa('/_atrapa/stan');
$w['przypiecie'] = $stan['pliki'][$meta1['drive_id']]['appProperties']['pinned'] ?? null;

// ── Lista z Dysku: także kopie innych stron (przenosiny) ─────────────────────
$lista = evk_gdrive_list_backups();
$html = evk_gdrive_render_list($lista);
$w['lista'] = ['nazwy' => array_column($lista, 'name'), 'cudza_strona' => strpos($html, 'inna.test') !== false,
    'ta_strona' => substr_count($html, 'ta strona'), 'na_serwerze' => substr_count($html, 'data-evk-gdrive-local'),
    'miejsce' => evk_gdrive_quota_label((array) evk_gdrive_quota()),
    'usun' => substr_count($html, 'data-evk-gdrive-delete'), 'usun_strona' => substr_count($html, 'data-site="inna.test"'),
    'usun_przypieta' => substr_count($html, ' data-pinned'), 'usun_lokalna' => substr_count($html, ' data-local'),
    'przypietych' => count(array_filter($lista, static function ($f) { return $f['pinned']; })), 'wierszy' => count($lista)];

// ── Pobranie z Dysku zakresami, z chwilowym błędem ───────────────────────────
evk_backup_delete_archive('dysk-test-1.zip');
$w['lokalna_po_usunieciu'] = evk_gdrive_local_for((string) ($meta1['drive_id'] ?? '-'));
atrapa('/_atrapa/ster', ['get_503' => 1]);
$idp = evk_gdrive_download_start((string) ($meta1['drive_id'] ?? '-'));
$jobp = is_wp_error($idp) ? ['status' => 'nie ruszyło', 'error' => $idp->get_error_message(), 'archive' => ''] : do_konca($idp);
$stan = atrapa('/_atrapa/stan');
$zakresy = array_values(array_filter($stan['log'], static function ($l) use ($meta1) {
    return strpos($l['q'], 'alt=media') !== false && strpos($l['p'], (string) (string) ($meta1['drive_id'] ?? '-')) !== false;
}));
$pobrana = evk_backup_archive_path((string) $jobp['archive']);
$metap = $pobrana ? json_decode((string) file_get_contents($pobrana . '.json'), true) : [];
$w['pobranie'] = [
    'status' => $jobp['status'], 'blad' => $jobp['error'], 'archiwum' => $jobp['archive'], 'md5' => $pobrana ? md5_file($pobrana) === $md5_1 : false,
    'zrodlo' => $metap['source'] ?? '', 'drive_id' => ($metap['drive_id'] ?? '') === $meta1['drive_id'], 'przypieta' => !empty($metap['pinned']),
    'rozmiar' => $pobrana ? (int) filesize($pobrana) : 0, 'zakresow' => count($zakresy), 'pierwszy' => $zakresy[0]['range'] ?? '', 'ostatni' => end($zakresy)['range'] ?? '',
    'czesc_zostala' => (bool) glob(evk_backup_dir() . '/.pobieranie-*'),
    'info' => $pobrana ? (evk_restore_info((string) $jobp['archive'])['from_url'] ?? '') : '',
    'lokalna' => evk_gdrive_local_for((string) ($meta1['drive_id'] ?? '-')),
];
$inny = atrapa('/_atrapa/plik', ['name' => 'nie-kopia.zip', 'parents' => [$folder], 'body' => 'x', 'appProperties' => ['evk' => 'inne']])['id'];
$e = evk_gdrive_download_start($inny);
$w['nie_kopia'] = is_wp_error($e) ? $e->get_error_message() : 'ruszyło';

// ── Automatycznie po kopii nocnej ─────────────────────────────────────────
$auto = static function (string $zrodlo) use ($wpdb): array {
    $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
    do_action('evk_backup_done', ['id' => 0, 'source' => $zrodlo, 'next_job_id' => 0], ['archive' => 'dysk-test-2.zip']);
    return $wpdb->get_results('SELECT type, source, status FROM ' . evk_backup_jobs_table(), ARRAY_A);
};
$w['auto'] = ['nocna' => $auto('schedule'), 'reczna' => $auto('manual')];
$ust = evk_backup_get_settings();
update_option(EVK_BACKUP_OPTION, array_merge($ust, ['gdrive_auto_schedule' => 0]));
$w['auto']['wylaczona'] = $auto('schedule');
update_option(EVK_BACKUP_OPTION, $ust);

// Wysyłka kopii nocnej, która się nie udaje: powiadomienie, kopia na serwerze zostaje.
$wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
delete_option(EVK_BACKUP_ALERT_OPTION);
atrapa('/_atrapa/ster', ['sesja_503' => 20]);
$jobf = do_konca(evk_gdrive_upload_start('dysk-test-2.zip', 'schedule'));
atrapa('/_atrapa/ster', ['sesja_503' => 0]);
$a = get_option(EVK_BACKUP_ALERT_OPTION);
$w['nieudana'] = ['status' => $jobf['status'], 'blad' => $jobf['error'], 'alert' => is_array($a) ? $a['title'] : null,
    'kopia_zostala' => (bool) evk_backup_archive_path('dysk-test-2.zip')];

// ── Czasy połączeń; pobieranie strumieniem (1.229.3) ────────────────────────
$MB = 1048576;
$lista = evk_gdrive_list_core();
$w['lista_czasy'] = ['n' => count($lista['czasy'] ?? []), 'razem' => $lista['razem'] ?? 0,
    'opisy' => array_column($lista['czasy'] ?? [], 'opis'), 'co' => array_column($lista['czasy'] ?? [], 'co')];

// Wysyłka: stałe kawałki (sufit 4 MB przy archiwum 8 MB) — dobór z 1.229.2 wycofany.
$sufit = 4 * $MB;
kopia('dysk-tempo.zip', 8 * $MB, 21, time());
$md5_tempo = md5_file(evk_backup_dir() . '/dysk-tempo.zip');
$jobT = do_konca(evk_gdrive_upload_start('dysk-tempo.zip'), null, 400, static function () { return 20000; });
$stan = atrapa('/_atrapa/stan');
$metaT = json_decode((string) file_get_contents(evk_backup_dir() . '/dysk-tempo.zip.json'), true);
$sesjaT = '';
foreach ($stan['log'] as $l) if ($l['m'] === 'PUT' && $l['n'] > 0) $sesjaT = $l['q'];
$puty = array_values(array_filter($stan['log'], static function ($l) use ($sesjaT) { return $l['m'] === 'PUT' && $l['n'] > 0 && $l['q'] === $sesjaT; }));
evk_backup_delete_archive('dysk-tempo.zip');

/* Pobieranie jak na evoke.pl: 1,5 s czekania na pierwszy bajt (stałe na
   żądanie), potem 1 MB/s; kroki po 1 s — krótsze niż samo czekanie. Po
   starcie token dostępu, którego Google już nie zna (401 w strumieniu). */
atrapa('/_atrapa/ster', ['czekaj' => 1.5, 'wolno' => 1 * $MB]);
$postepy = 0;
$widok = [];   // co panel widział przy każdym zapisie postępu: sekundy czekania albo null, szczegół
delete_option('evk_gdrive_czekanie');
add_action('evk_backup_gdrive_postep', static function ($id) use (&$postepy, &$widok) {
    $postepy++;
    $pub = evk_backup_job_public(evk_backup_job_get((int) $id));
    $widok[] = [$pub['czeka'] ?? 'brak', $pub['detail'] ?? ''];
});
$idP = (int) evk_gdrive_download_start((string) ($metaT['drive_id'] ?? '-'));
$st = evk_gdrive_state();
$st['access'] = 'ya29.przeterminowany';
$st['access_exp'] = time() + 3000;
evk_gdrive_save($st);
$odsw_przed = (int) (atrapa('/_atrapa/stan')['odswiezen'] ?? 0);
$t0 = microtime(true);
$kroki = 0;
$jobP = do_konca($idP, static function () use (&$kroki) { $kroki++; }, 400, static function () { return 1000; });
$czasP = microtime(true) - $t0;
atrapa('/_atrapa/ster', ['czekaj' => 0, 'wolno' => 0]);
$stan = atrapa('/_atrapa/stan');
$media = array_values(array_filter($stan['log'], static function ($l) use ($metaT) {
    return strpos($l['q'], 'alt=media') !== false && strpos($l['p'], (string) ($metaT['drive_id'] ?? '-')) !== false;
}));
$pobranaT = evk_backup_archive_path((string) $jobP['archive']);
$w['strumien'] = [
    'wysylka' => $jobT['status'], 'puty' => array_column($puty, 'n'),
    'pobranie' => $jobP['status'], 'blad' => $jobP['error'], 'rozmiar' => $pobranaT ? (int) filesize($pobranaT) : 0,
    'md5' => $pobranaT ? md5_file($pobranaT) === $md5_tempo : false,
    'zakresy' => array_column($media, 'range'), 'ae' => array_values(array_unique(array_column($media, 'ae'))),
    'postepy' => $postepy, 'kroki' => $kroki, 'czas' => round($czasP, 1),
    'widok' => $widok, 'czekanie' => get_option('evk_gdrive_czekanie'), 'po_koncu' => evk_backup_job_public($jobP)['czeka'],
    'odswiezenia' => (int) ($stan['odswiezen'] ?? 0) - $odsw_przed,
    'log_zadanie' => array_values(preg_grep('/^\S+ Żądanie \d+:/', explode("\n", (string) $jobP['log']))),
    'log_pomiar' => array_values(preg_grep('/Pomiar:/', explode("\n", (string) $jobP['log']))),
    'log_wysylka' => array_values(preg_grep('/Pomiar:/', explode("\n", (string) $jobT['log']))),
];

/* Klucze pomiaru na PRAWDZIWYM curl_getinfo() (1.243.0). Do 1.242.0 TLS szedł
   z `appconnect_time`, którego ta tablica nie ma — dziennik z evoke.pl mówił
   „TLS 0,00 s" przy każdym żądaniu. Atrapa idzie po HTTP, więc samego czasu
   TLS tu nie zobaczymy — dlatego sprawdzamy NAZWY na prawdziwej tablicy,
   a rachunek na tablicy w jej kształcie (liczby z dziennika z evoke.pl). */
$ch = curl_init($atrapa . '/_atrapa/stan');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($ch);
$w['pomiar_klucze'] = ['brak' => array_values(array_diff(evk_gdrive_pomiar_klucze(), array_keys(curl_getinfo($ch)))),
    'kod' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE)];
unset($ch);
$GLOBALS['evk_gdrive_curl'] = ['namelookup_time' => 0.0, 'connect_time' => 0.21, 'appconnect_time_us' => 450000,
    'pretransfer_time' => 0.47, 'starttransfer_time' => 29.1, 'total_time' => 30.1, 'size_download' => 84305920,
    'size_upload' => 0, 'redirect_count' => 0, 'primary_ip' => '172.217.118.4'];
$w['pomiar_opis'] = evk_gdrive_pomiar_opis(evk_gdrive_pomiar(microtime(true) - 30.1));

// Błąd od Google w strumieniu (403 z treścią JSON) — do części nie trafia ani bajt.
$pobranaT && evk_backup_delete_archive((string) $jobP['archive']);
atrapa('/_atrapa/ster', ['media_403' => 1]);
$jobE = do_konca((int) evk_gdrive_download_start((string) ($metaT['drive_id'] ?? '-')));
$w['strumien_blad'] = ['status' => $jobE['status'], 'blad' => $jobE['error'],
    'czesc' => (bool) glob(evk_backup_dir() . '/.pobieranie-*')];
$sufit = 262144;

// ── Usuwanie jednej kopii z Dysku z panelu (1.229.6) ─────────────────────
$wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
kopia('dysk-usun.zip', 100000, 31, time());
$jobU = do_konca(evk_gdrive_upload_start('dysk-usun.zip'));
$idU = (string) (json_decode((string) file_get_contents(evk_backup_dir() . '/dysk-usun.zip.json'), true)['drive_id'] ?? '');
$usun = static function (string $id) {
    try { return evk_gdrive_delete_backup($id); } catch (\RuntimeException $e) { return 'odmowa: ' . $e->getMessage(); }
};
$uPrzypieta = $posiej('usun-przypieta.zip', [$folder], ['evk_site' => evk_gdrive_site_key(), 'created' => (string) time(), 'pinned' => '1']);
$uCudza     = $posiej('usun-cudza.zip', ['INNYFOLDER'], ['evk_site' => 'inna.test', 'created' => (string) time()]);
$uPobierana = $posiej('usun-pobierana.zip', [$folder], ['evk_site' => evk_gdrive_site_key(), 'created' => (string) time()]);
$w['usuwanie'] = ['wysylka' => $jobU['status'], 'id' => $idU !== ''];
$w['usuwanie']['wyslana'] = $usun($idU);
$stan = atrapa('/_atrapa/stan');
$metaU = json_decode((string) file_get_contents(evk_backup_dir() . '/dysk-usun.zip.json'), true);
$w['usuwanie'] += ['wyslana_na_dysku' => isset($stan['pliki'][$idU]), 'lokalna' => is_file(evk_backup_dir() . '/dysk-usun.zip'),
    'lokalna_drive_id' => $metaU['drive_id'] ?? null, 'lokalna_zrodlo' => $metaU['source'] ?? null];
$w['usuwanie']['przypieta'] = $usun($uPrzypieta);
$w['usuwanie']['cudza'] = $usun($uCudza);
$w['usuwanie']['folder'] = $usun($folder);
$w['usuwanie']['nie_kopia'] = $usun($inny);
$doDysku = static function (): int {
    return count(array_filter(atrapa('/_atrapa/stan')['log'], static function ($l) { return strpos($l['p'], '/drive/') === 0; }));
};
$przed = $doDysku();
$w['usuwanie']['zly_id'] = $usun('../../x');
$w['usuwanie']['zly_id_zadan'] = $doDysku() - $przed;
$w['usuwanie']['juz_nie_ma'] = $usun($idU);
$idPob = evk_gdrive_download_start($uPobierana);
$w['usuwanie']['w_trakcie'] = $usun($uPobierana);
if (!is_wp_error($idPob)) evk_backup_cancel((int) $idPob);
$wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
$stan = atrapa('/_atrapa/stan');
$w['usuwanie'] += ['przypieta_na_dysku' => isset($stan['pliki'][$uPrzypieta]), 'cudza_na_dysku' => isset($stan['pliki'][$uCudza]),
    'folder_na_dysku' => isset($stan['pliki'][$folder]), 'nie_kopia_na_dysku' => isset($stan['pliki'][$inny]),
    'pobierana_na_dysku' => isset($stan['pliki'][$uPobierana]),
    'w_folderze' => count(array_filter($stan['pliki'], static function ($f) use ($folder) { return in_array($folder, (array) ($f['parents'] ?? []), true); }))];
evk_backup_delete_archive('dysk-usun.zip');

// ── Dostęp cofnięty w Google (invalid_grant) ─────────────────────────────
delete_option(EVK_BACKUP_ALERT_OPTION);
atrapa('/_atrapa/ster', ['invalid_grant' => 1]);
$st = evk_gdrive_state();
$st['access_exp'] = 0;
evk_gdrive_save($st);
$w['cofniety'] = ['blad' => blad(static function () { evk_gdrive_list_backups(); }), 'polaczone' => evk_gdrive_connected(),
    'alert' => (get_option(EVK_BACKUP_ALERT_OPTION)['title'] ?? null)];
atrapa('/_atrapa/ster', ['invalid_grant' => 0]);


$w['do_posrednika'] = ['zadan' => count($do_posrednika), 'z_sekretem' => count(array_filter($do_posrednika, static function ($k) {
    return in_array('client_secret', $k, true);
})), 'klucze' => array_values(array_unique(array_merge(...($do_posrednika ?: [[]]))))];
echo json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
