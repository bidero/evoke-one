<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — wgrywanie kopii z przeglądarki (includes/backup/upload.php) na
 * prawdziwym WordPressie (tools/testowy-wp.sh). Rdzeń wołany wprost,
 * kawałki po 64 KB wycinane z archiwum zbudowanego pisarzem wtyczki.
 *
 *   php tests/php/backup-wgrywanie.php
 *
 * Jeden przebieg, jeden JSON z faktami — oceniają je sprawdzenia
 * w tests/backup-wgrywanie.test.js.
 */

$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'zip-reader' => 'evk_zip_safe_name',
    'serialize-replace' => 'evk_sr_build_pairs', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick', 'restore' => 'evk_restore_start', 'upload' => 'evk_backup_upload_start_core',
];
require __DIR__ . '/_testowy-wp.php';

$tmp = sys_get_temp_dir() . '/evk-wgrywanie-' . getmypid();
@mkdir($tmp, 0700, true);
$przed = array_column(evk_backup_list_archives(), 'archive');
register_shutdown_function(static function () use ($tmp, $przed) {
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
    foreach (evk_backup_list_archives() as $k) if (!in_array($k['archive'], $przed, true)) evk_backup_delete_archive($k['archive']);
    foreach (glob(evk_backup_dir() . '/.wgrywanie-*') ?: [] as $p) @unlink($p);
});
const KAWALEK = 65536;

function losowe(int $n, int $ziarno): string {
    mt_srand($ziarno);
    $s = '';
    while (strlen($s) < $n) $s .= pack('N', mt_rand());
    return substr($s, 0, $n);
}

/** Archiwum kopii: manifest, mały zrzut, plik nieściśliwy 300 KB. */
function archiwum(string $plik, bool $zManifestem = true): void {
    global $tmp;
    $w = EVK_Zip_Writer::create($plik);
    if ($zManifestem) {
        $w->add_string('manifest.json', (string) json_encode(['format' => 1, 'created_at' => '2026-09-20T10:00:00Z',
            'siteurl' => 'http://zrodlo.test', 'db_rows' => 3, 'files' => 1]));
    }
    $w->add_string('database.jsonl', "{\"table\":\"wp_x\",\"create\":\"CREATE TABLE x (a INT)\",\"cols\":[\"a\"],\"pk\":[]}\n");
    file_put_contents($tmp . '/src.bin', losowe(300000, 5));
    $w->add_file($tmp . '/src.bin', 'wp-content/uploads/a.mp4', microtime(true) + 60);
    $w->finish();
}

/** Kawałek pliku od $od do osobnego pliku (jak $_FILES['chunk']['tmp_name']). */
function kawalek(string $plik, int $od, int $dl = KAWALEK): string {
    global $tmp;
    $k = $tmp . '/kawalek-' . $od;
    file_put_contents($k, (string) file_get_contents($plik, false, null, $od, $dl));
    return $k;
}

/** Wysyła kawałki od $od; $ile = null → do końca. Oddaje ostatni wynik i liczbę wysłanych. */
function wyslij(string $plik, string $id, int $od, ?int $ile = null): array {
    $n = 0;
    $wynik = ['offset' => $od, 'done' => false];
    while (!$wynik['done'] && ($ile === null || $n < $ile)) {
        $wynik = evk_backup_upload_chunk_core($id, $wynik['offset'], kawalek($plik, $wynik['offset']));
        $n++;
    }
    return [$wynik, $n];
}

function blad(callable $f): string {
    try { $f(); return 'przeszło'; } catch (RuntimeException $e) { return $e->getMessage(); }
}

update_option(EVK_BACKUP_OPTION, ['enabled' => 1]);
$w = [];

// ── Wgranie z przerwą i wznowieniem ─────────────────────────────────────────
$zip = $tmp . '/moja kopia (1).zip';
archiwum($zip);
$rozmiar = (int) filesize($zip);
$mtime = 1790000000;
$s1 = evk_backup_upload_start_core('moja kopia (1).zip', $rozmiar, $mtime, 1);
[$po3, $n3] = wyslij($zip, $s1['id'], 0, 3);   // karta zamknięta po trzech kawałkach
$s2 = evk_backup_upload_start_core('moja kopia (1).zip', $rozmiar, $mtime, 1);
$w['rozmiar'] = $rozmiar;
$w['start'] = ['offset' => $s1['offset'], 'chunk' => $s1['chunk'], 'id_ok' => (bool) preg_match('/^[a-f0-9]{40}$/', $s1['id'])];
$w['wznowienie'] = ['ten_sam_id' => $s2['id'] === $s1['id'], 'offset' => $s2['offset'], 'po_trzech' => $po3['offset']];
$w['inny_uzytkownik_inny_id'] = evk_backup_upload_start_core('moja kopia (1).zip', $rozmiar, $mtime, 2)['id'] !== $s1['id'];
evk_backup_upload_cancel_core(evk_backup_upload_start_core('moja kopia (1).zip', $rozmiar, $mtime, 2)['id']);

// Kawałek powtórzony (odpowiedź zginęła, klient wysyła jeszcze raz poprzedni).
$czesc = evk_backup_upload_part($s1['id']);
$przedPowtorka = md5_file($czesc);
$powt = evk_backup_upload_chunk_core($s1['id'], 2 * KAWALEK, kawalek($zip, 2 * KAWALEK));
clearstatcache();
$w['powtorka'] = ['resync' => !empty($powt['resync']), 'offset' => $powt['offset'], 'plik_nietkniety' => md5_file($czesc) === $przedPowtorka];

[$koniec, $nReszta] = wyslij($zip, $s1['id'], $s2['offset']);
$wpis = null;
foreach (evk_backup_list_archives() as $k) if ($k['archive'] === ($koniec['archive'] ?? '')) $wpis = $k;
$w['koniec'] = [
    'done' => $koniec['done'], 'archive' => $koniec['archive'] ?? null,
    'wyslanych_po_wznowieniu' => $nReszta, 'kawalkow_razem' => (int) ceil($rozmiar / KAWALEK),
    'md5_zgodne' => $wpis && md5_file(evk_backup_dir() . '/' . $wpis['archive']) === md5_file($zip),
    'zrodlo' => $wpis['source'] ?? null, 'db_rows' => $wpis['db_rows'] ?? null, 'siteurl' => $wpis['siteurl'] ?? null,
    'czesc_zostala' => file_exists($czesc) || file_exists($czesc . '.json'),
];

// ── Odmowy ───────────────────────────────────────────────────────────────────
$nieZip = $tmp . '/nie-zip.zip';
file_put_contents($nieZip, losowe(100000, 9));
$sN = evk_backup_upload_start_core('nie-zip.zip', 100000, 1, 1);
$w['nie_zip'] = blad(static function () use ($nieZip, $sN) { wyslij($nieZip, $sN['id'], 0); });
$w['nie_zip_czesc'] = file_exists(evk_backup_upload_part($sN['id']));

$bezM = $tmp . '/bez-manifestu.zip';
archiwum($bezM, false);
$sB = evk_backup_upload_start_core('bez-manifestu.zip', (int) filesize($bezM), 1, 1);
$w['bez_manifestu'] = blad(static function () use ($bezM, $sB) { wyslij($bezM, $sB['id'], 0); });

$w['zle_id'] = blad(static function () use ($zip) { evk_backup_upload_chunk_core('../../../etc/passwd', 0, kawalek($zip, 0)); });
$w['zle_rozszerzenie'] = blad(static function () { evk_backup_upload_start_core('kopia.php', 1000, 1, 1); });
$sZ = evk_backup_upload_start_core('za-duzy.zip', 1000, 1, 1);
$w['poza_rozmiar'] = blad(static function () use ($zip, $sZ) { evk_backup_upload_chunk_core($sZ['id'], 0, kawalek($zip, 0, 5000)); });
evk_backup_upload_cancel_core($sZ['id']);

add_filter('evk_backup_upload_free_space', static function () { return 1000000; });
$w['brak_miejsca'] = blad(static function () { evk_backup_upload_start_core('duza.zip', 500000000, 1, 1); });
remove_all_filters('evk_backup_upload_free_space');

// Nazwa z „../": oczyszczona, w katalogu kopii.
$sP = evk_backup_upload_start_core('../../złośliwa nazwa.zip', $rozmiar, 7, 1);
[$kP] = wyslij($zip, $sP['id'], 0);
$w['nazwa'] = $kP['archive'] ?? null;

// ── Anulowanie i sprzątanie ──────────────────────────────────────────────────
$sA = evk_backup_upload_start_core('anuluj.zip', $rozmiar, 3, 1);
wyslij($zip, $sA['id'], 0, 2);
$w['anulowane'] = [evk_backup_upload_cancel_core($sA['id']), file_exists(evk_backup_upload_part($sA['id']))];

$stara = evk_backup_upload_start_core('stara.zip', $rozmiar, 4, 1);
wyslij($zip, $stara['id'], 0, 1);
touch(evk_backup_upload_part($stara['id']), time() - 2 * DAY_IN_SECONDS);
$swieza = evk_backup_upload_start_core('swieza.zip', $rozmiar, 5, 1);
wyslij($zip, $swieza['id'], 0, 1);
$w['sprzatanie'] = ['usunietych' => evk_backup_upload_cleanup(), 'stara' => file_exists(evk_backup_upload_part($stara['id'])),
                    'swieza' => file_exists(evk_backup_upload_part($swieza['id']))];

echo json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
