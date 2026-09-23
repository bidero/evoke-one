<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — przywracanie kopii, także na innym serwerze.
 *
 * Zadanie typu `restore` w tej samej tabeli i z tym samym silnikiem kroków
 * co kopia (engine.php): lock, samokalibracja, żądania zwrotne, WP-Cron.
 * Fazy po kolei — każda wznawialna między krokami:
 *
 *   r_check    archiwum: katalog centralny, manifest, miejsce na dysku,
 *              lista wpisów do rozpakowania (plik JSONL w katalogu roboczym),
 *   r_extract  rozpakowanie DO KATALOGU ROBOCZEGO — strona jeszcze nietknięta,
 *   r_db       wczytanie zrzutu do tabel TYMCZASOWYCH `evkr<id>_<n>`,
 *              z podmianą adresów, ścieżek i prefiksu,
 *   ─── od tu nie ma odwrotu: anulowanie odmawia (evk_restore_cancellable) ───
 *   r_files    przeniesienie plików na miejsce (tryb konserwacji włączony),
 *   r_sweep    lustro: usunięcie plików, których nie ma w kopii (opcjonalne),
 *   r_swap     podmiana bazy JEDNYM `RENAME TABLE` (atomowo), poprawki po niej,
 *              sprzątanie.
 *
 * Kolejność — najpierw wszystko, co się może nie udać (rozpakowanie, CRC,
 * import), dopiero potem to, czego się nie cofa. Kopia uszkodzona, brak
 * miejsca albo błąd importu kończą zadanie ze stroną w stanie sprzed.
 *
 * CZEGO NIE PRZYWRACAMY:
 *   - katalogu tej wtyczki (pod bieżącą nazwą i nazwą z manifestu) — kod,
 *     który właśnie przywraca, nie może się podmienić w połowie,
 *   - katalogów kopii `evk-backups-*`,
 *   - plików z katalogu głównego (`_root/`, .htaccess itd.) — są w archiwum
 *     do podglądu; reguły serwera starej strony na nowej potrafią ją wyłożyć,
 *   - tabeli zadań — przywracanie w niej właśnie trwa.
 *
 * CO ZOSTAJE Z INSTALACJI DOCELOWEJ mimo podmiany tabeli opcji: sufiks
 * katalogu kopii i klucz żądań zwrotnych (należą do instalacji — patrz
 * settings.php), wtyczka aktywna, moduł włączony.
 *
 * Tabele docelowe z prefiksem strony, których nie ma w kopii, są USUWANE
 * (decyzja z 1.227.0) — po przywróceniu baza = baza z kopii. Tabele innych
 * prefiksów, także instalacji z prefiksem zaczynającym się od naszego
 * (evk_backup_db_foreign_prefixes), nietykalne.
 */

/** Porcja zapytania przy imporcie — i tak ograniczona przez max_allowed_packet. */
const EVK_RESTORE_SQL_BYTES = 1048576;
const EVK_RESTORE_SQL_ROWS  = 500;

/** Katalog roboczy zadania przywracania (jak przy kopii — chroniony). */
function evk_restore_staging(int $id): string {
    return evk_backup_work_dir($id) . '/pliki';
}

function evk_restore_tmp_table(int $id, int $n): string { return 'evkr' . $id . '_' . $n; }
function evk_restore_old_table(int $id, int $n): string { return 'evko' . $id . '_' . $n; }

/** Czy przywracanie można jeszcze przerwać bez szkody dla strony. */
function evk_restore_cancellable(array $job): bool {
    return in_array($job['phase'], ['r_check', 'r_extract', 'r_db'], true);
}

/**
 * Pliki „drop-in" w wp-content — WordPress ładuje je SAM przy każdym żądaniu,
 * zanim ruszy jakakolwiek wtyczka (pamięć podręczna obiektów, strony, własna
 * obsługa bazy). Zgłoszone z użycia (1.227.1): przywrócony `object-cache.php`
 * ze starego serwera łączył się z jego pamięcią podręczną i zatrzymywał
 * każde żądanie — także kroki samego przywracania; pomogło dopiero ręczne
 * usunięcie pliku. Należą więc do SERWERA, nie do strony: przywracanie ich
 * nie nadpisuje ani (lustro) nie usuwa. Wtyczka cache odtwarza swój plik
 * sama po włączeniu.
 */
function evk_restore_dropins(): array {
    $lista = function_exists('_get_dropins') ? array_keys(_get_dropins()) : [];
    return array_values(array_unique(array_merge($lista, ['advanced-cache.php', 'db.php', 'db-error.php', 'install.php',
        'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php',
        'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php'])));
}

/** Nazwy katalogu tej wtyczki, których przywracanie nie rusza. */
function evk_restore_own_dirs(array $manifest): array {
    return array_values(array_unique(array_filter([
        basename(rtrim(EVOKE_ONE_DIR, '/')),
        (string) ($manifest['plugin_dir'] ?? 'evoke-one'),
    ])));
}

// =========================================================================
// ARCHIWUM: manifest i podsumowanie dla okna potwierdzenia
// =========================================================================

/** Wpisy archiwum po nazwie — tylko te, o które pytamy (katalog centralny bywa ogromny). */
function evk_restore_find_entries(string $zip, array $nazwy): array {
    $znalezione = [];
    $licznik = ['files' => 0, 'size' => 0, 'root' => []];
    EVK_Zip_Reader::scan($zip, static function ($w) use ($nazwy, &$znalezione, &$licznik) {
        if (in_array($w['n'], $nazwy, true)) $znalezione[$w['n']] = $w;
        if (strpos($w['n'], 'wp-content/') === 0 && empty($w['d'])) { $licznik['files']++; $licznik['size'] += $w['z']; }
        if (strpos($w['n'], '_root/') === 0) $licznik['root'][] = substr($w['n'], 6);
    });
    return [$znalezione, $licznik];
}

/** Manifest z archiwum — albo wyjątek z czytelnym powodem. */
function evk_restore_read_manifest(string $zip, ?array $wpis = null): array {
    if ($wpis === null) {
        [$w] = evk_restore_find_entries($zip, ['manifest.json']);
        $wpis = $w['manifest.json'] ?? null;
    }
    if (!$wpis) throw new \RuntimeException('W archiwum nie ma manifestu — to nie jest kopia tej wtyczki.');
    $m = json_decode(EVK_Zip_Reader::read($zip, $wpis, 1048576), true);
    if (!is_array($m) || !isset($m['format'])) throw new \RuntimeException('Manifest kopii jest nieczytelny.');
    if ((int) $m['format'] > EVK_BACKUP_FORMAT) {
        throw new \RuntimeException('Kopia pochodzi z nowszej wersji wtyczki (format ' . (int) $m['format'] . ') — zaktualizuj wtyczkę.');
    }
    return $m;
}

/**
 * Pary podmian: stary adres/ścieżka z manifestu → ta instalacja. Pusta mapa,
 * gdy przywracamy na tę samą stronę.
 */
function evk_restore_pairs(array $m): array {
    $uploads = wp_upload_dir(null, false);
    $urls = [
        [(string) ($m['siteurl'] ?? ''), (string) get_option('siteurl')],
        [(string) ($m['home'] ?? ''), (string) get_option('home')],
        [(string) ($m['wp_content_url'] ?? ''), content_url()],
        [(string) ($m['uploads_url'] ?? ''), (string) $uploads['baseurl']],
    ];
    $paths = [
        [(string) ($m['uploads_dir'] ?? ''), (string) $uploads['basedir']],
        [(string) ($m['wp_content_dir'] ?? ''), WP_CONTENT_DIR],
        [rtrim((string) ($m['abspath'] ?? ''), '/'), rtrim(ABSPATH, '/')],
    ];
    $urls = array_values(array_filter($urls, static function ($p) { return $p[0] !== '' && rtrim($p[0], '/') !== rtrim($p[1], '/'); }));
    $paths = array_values(array_filter($paths, static function ($p) { return $p[0] !== '' && rtrim($p[0], '/') !== rtrim($p[1], '/'); }));
    return evk_sr_build_pairs($urls, $paths, true);
}

/** Podsumowanie do okna potwierdzenia. */
function evk_restore_info(string $archiwum): array {
    $zip = evk_backup_archive_path($archiwum);
    if (!$zip) throw new \RuntimeException('Nie ma takiej kopii.');
    [$w, $licznik] = evk_restore_find_entries($zip, ['manifest.json', 'database.jsonl']);
    $m = evk_restore_read_manifest($zip, $w['manifest.json'] ?? null);
    global $wpdb, $wp_version;
    $brakujace = array_values(array_diff((array) ($m['wp_config_constants'] ?? []), evk_backup_wp_config_constants()));
    return [
        'archive'      => $archiwum,
        'created_at'   => (string) ($m['created_at'] ?? ''),
        'source'       => (string) ($m['source'] ?? ''),
        'from_url'     => (string) ($m['home'] ?? ''),
        'to_url'       => (string) get_option('home'),
        'from_prefix'  => (string) ($m['table_prefix'] ?? ''),
        'to_prefix'    => $wpdb->prefix,
        'from_wp'      => (string) ($m['wp_version'] ?? ''),
        'to_wp'        => (string) $wp_version,
        'plugin'       => (string) ($m['plugin_version'] ?? ''),
        'has_db'       => isset($w['database.jsonl']),
        'db_rows'      => (int) ($m['db_rows'] ?? 0),
        'files'        => $licznik['files'],
        'files_size'   => evk_backup_bytes_label((float) $licznik['size']),
        'root_files'   => $licznik['root'],
        'missing_constants' => $brakujace,
        'pairs'        => count(evk_restore_pairs($m)),
    ];
}

// =========================================================================
// START
// =========================================================================

/**
 * Nowe przywracanie. $scope: all | db | files. $mirror: usuń pliki spoza
 * kopii. $snapshot: najpierw kopia obecnego stanu (przywracanie czeka na nią).
 * Zwraca id zadania przywracania albo WP_Error.
 */
function evk_restore_start(string $archiwum, string $scope = 'all', bool $mirror = false, bool $snapshot = false) {
    if (is_multisite()) return new WP_Error('evk_restore_multisite', 'Przywracanie na multisite nie jest obsługiwane.');
    if (!evk_backup_archive_path($archiwum)) return new WP_Error('evk_restore_archive', 'Nie ma takiej kopii.');
    if (!in_array($scope, ['all', 'db', 'files'], true)) return new WP_Error('evk_restore_scope', 'Nieznany zakres przywracania.');
    if (evk_backup_job_active()) return new WP_Error('evk_backup_busy', 'Inna kopia albo przywracanie już trwa.');

    $stan = ['archive' => $archiwum, 'scope' => $scope, 'mirror' => $mirror && $scope !== 'db'];
    $id = evk_backup_job_create('restore', 'manual', $snapshot ? 'waiting' : 'queued', 'r_check', $stan);
    if (is_wp_error($id)) return $id;
    evk_backup_job_update($id, ['archive' => $archiwum]);
    evk_backup_job_log($id, sprintf('Przywracanie z %s: %s%s%s.', $archiwum,
        ['all' => 'baza i pliki', 'db' => 'tylko baza', 'files' => 'tylko pliki'][$scope],
        $stan['mirror'] ? ', pliki spoza kopii do usunięcia' : '', $snapshot ? ', najpierw kopia obecnego stanu' : ''));

    if ($snapshot) {
        $kopia = evk_backup_start('snapshot', $id);
        if (is_wp_error($kopia)) {
            evk_backup_job_update($id, ['status' => 'failed', 'error' => $kopia->get_error_message(), 'finished_at' => time()]);
            return $kopia;
        }
        return $id;
    }
    evk_backup_schedule($id, 0);
    evk_backup_kick($id);
    return $id;
}

// =========================================================================
// FAZY
// =========================================================================

function evk_restore_phase(array $job, float $deadline): array {
    switch ($job['phase']) {
        case 'r_check':   return evk_restore_phase_check($job);
        case 'r_extract': return evk_restore_phase_extract($job, $deadline);
        case 'r_db':      return evk_restore_phase_db($job, $deadline);
        case 'r_files':   return evk_restore_phase_files($job, $deadline);
        case 'r_sweep':   return evk_restore_phase_sweep($job, $deadline);
        case 'r_swap':    return evk_restore_phase_swap($job);
    }
    throw new \RuntimeException('Nieznana faza przywracania: ' . $job['phase']);
}

/** Następna faza po $faza dla zakresu i trybu zadania. */
function evk_restore_next_phase(array $job, string $faza): string {
    $s = $job['state'];
    $kolej = ['r_check', 'r_extract'];
    if ($s['scope'] !== 'files') $kolej[] = 'r_db';
    if ($s['scope'] !== 'db') { $kolej[] = 'r_files'; if (!empty($s['mirror'])) $kolej[] = 'r_sweep'; }
    $kolej[] = 'r_swap';
    $i = array_search($faza, $kolej, true);
    return $kolej[$i + 1] ?? 'r_swap';
}

function evk_restore_phase_check(array $job): array {
    $s = $job['state'];
    $zip = evk_backup_archive_path((string) $s['archive']);
    if (!$zip) throw new \RuntimeException('Archiwum zniknęło z katalogu kopii: ' . $s['archive']);
    $praca = evk_backup_work_dir($job['id']);
    evk_backup_rmdir($praca);
    if (!wp_mkdir_p(evk_restore_staging($job['id']))) throw new \RuntimeException('Nie można utworzyć katalogu roboczego: ' . $praca);
    /* Tabele tymczasowe WSZYSTKICH zadań: zadanie trwa naraz jedno, więc
       każda inna `evkr<id>_<n>` to sierota po procesie ubitym bez sprzątania.
       Tabel `evko…` (baza sprzed nieudanej podmiany) nie ruszamy — to droga
       powrotu, o której mówi log tamtego zadania. */
    evk_restore_drop_tables('evkr%', '/^evkr\d+_\d+$/');

    /* Dwa przejścia po katalogu centralnym: najpierw manifest (z niego nazwy
       katalogu wtyczki do pominięcia), potem lista wpisów prosto do pliku —
       strona z mediami ma ich setki tysięcy, a w pamięci się nie zmieszczą. */
    [$w] = evk_restore_find_entries($zip, ['manifest.json', 'database.jsonl']);
    $m = evk_restore_read_manifest($zip, $w['manifest.json'] ?? null);
    $db = $w['database.jsonl'] ?? null;
    $wlasne = evk_restore_own_dirs($m);
    $licz = ['files' => 0, 'files_size' => 0, 'skipped' => [], 'root' => [], 'unsafe' => 0, 'dropins' => []];
    $bajty = 0;

    $lista = $praca . '/wpisy.jsonl';
    $fh = fopen($lista, 'wb');
    if (!$fh) throw new \RuntimeException('Nie można zapisać listy wpisów archiwum.');
    try {
        if ($s['scope'] !== 'files') {
            if (!$db) throw new \RuntimeException('W archiwum nie ma zrzutu bazy — wybierz przywracanie samych plików.');
            fwrite($fh, wp_json_encode($db + ['t' => 'db']) . "\n");
            $bajty += $db['z'];
        }
        if ($s['scope'] !== 'db') {
            $dropiny = evk_restore_dropins();
            EVK_Zip_Reader::scan($zip, static function ($w) use ($fh, $wlasne, $dropiny, &$licz, &$bajty) {
                $n = $w['n'];
                if ($n === 'manifest.json' || $n === 'database.jsonl') return;
                if (strpos($n, '_root/') === 0) { $licz['root'][] = substr($n, 6); return; }
                if (evk_zip_safe_name($n) === null || strpos($n, 'wp-content/') !== 0 || $n === 'wp-content/') { $licz['unsafe']++; return; }
                $rel = substr($n, 11);
                if (strpos($rel, 'evk-backups-') === 0) return;
                if (in_array($rel, $dropiny, true)) { $licz['dropins'][] = $rel; return; }
                foreach ($wlasne as $d) {
                    if (strpos($rel, 'plugins/' . $d . '/') === 0) { $licz['skipped'][$d] = true; return; }
                }
                fwrite($fh, wp_json_encode($w + ['t' => 'f'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                if (empty($w['d'])) { $licz['files']++; $licz['files_size'] += $w['z']; }
                $bajty += $w['z'];
            });
        }
    } finally {
        fclose($fh);
    }

    // Miejsce: rozpakowanie + (przy bazie) drugie tyle na tabele tymczasowe.
    $potrzeba = $bajty + ($db && $s['scope'] !== 'files' ? $db['z'] : 0);
    $wolne = function_exists('disk_free_space') ? @disk_free_space(WP_CONTENT_DIR) : false;
    if ($wolne !== false && $wolne < $potrzeba * 1.1) {
        throw new \RuntimeException(sprintf('Za mało miejsca na dysku: potrzeba ok. %s, wolne %s.',
            evk_backup_bytes_label($potrzeba * 1.1), evk_backup_bytes_label((float) $wolne)));
    }

    global $wpdb;
    $job['state']['manifest'] = [
        'home' => $m['home'] ?? '', 'siteurl' => $m['siteurl'] ?? '', 'table_prefix' => $m['table_prefix'] ?? $wpdb->prefix,
        'exclusions' => array_values((array) ($m['exclusions'] ?? [])), 'maintenance_before' => $m['maintenance_before'] ?? null,
        'created_at' => $m['created_at'] ?? '',
    ];
    $job['state']['own_dirs'] = $wlasne;
    $job['state']['pairs'] = evk_restore_pairs($m);
    $job['state']['list'] = $lista;
    $job['state']['ex'] = ['pos' => 0, 'cur' => null, 'bytes' => 0, 'files' => 0];
    $job['state']['db_size'] = $db && $s['scope'] !== 'files' ? (int) $db['z'] : 0;
    $job['state']['files_size'] = $licz['files_size'];
    $job['progress_total'] = max(1, $bajty + $job['state']['db_size'] + ($s['scope'] !== 'db' ? $licz['files_size'] : 0));
    $job['progress_done'] = 0;

    evk_backup_job_log($job['id'], sprintf('Kopia z %s (%s), prefiks tabel %s → %s. Do rozpakowania: %s%d plików (%s).',
        $m['created_at'] ?? '?', $m['home'] ?? '?', $m['table_prefix'] ?? '?', $wpdb->prefix,
        $db && $s['scope'] !== 'files' ? 'zrzut bazy i ' : '', $licz['files'], evk_backup_bytes_label((float) $licz['files_size'])));
    if ($job['state']['pairs']) {
        evk_backup_job_log($job['id'], 'Podmiana adresów: ' . ($m['home'] ?? '?') . ' → ' . get_option('home') . ' (' . count($job['state']['pairs']) . ' wariantów).');
    }
    if ($licz['root']) {
        evk_backup_job_log($job['id'], 'Pliki z katalogu głównego w kopii (nieprzywracane, są w archiwum pod _root/): ' . implode(', ', $licz['root']) . '.');
    }
    if ($licz['dropins']) {
        evk_backup_job_log($job['id'], 'Pominięte pliki drop-in (należą do serwera, zostają te z tego): ' . implode(', ', $licz['dropins'])
            . '. Wtyczka pamięci podręcznej odtworzy swój plik po włączeniu.');
    }
    if ($licz['skipped']) evk_backup_job_log($job['id'], 'Pominięty katalog tej wtyczki: plugins/' . implode(', plugins/', array_keys($licz['skipped'])) . '.');
    if ($licz['unsafe']) evk_backup_job_log($job['id'], 'Pominięte wpisy o niebezpiecznych albo obcych nazwach: ' . $licz['unsafe'] . '.');

    $job['phase'] = 'r_extract';
    return $job;
}

function evk_restore_phase_extract(array $job, float $deadline): array {
    $s = &$job['state'];
    $zip = evk_backup_archive_path((string) $s['archive']);
    if (!$zip) throw new \RuntimeException('Archiwum zniknęło z katalogu kopii: ' . $s['archive']);
    $praca = evk_backup_work_dir($job['id']);
    $staging = evk_restore_staging($job['id']);

    $fh = fopen($s['list'], 'rb');
    if (!$fh) throw new \RuntimeException('Nie można otworzyć listy wpisów archiwum.');
    fseek($fh, $s['ex']['pos']);
    try {
        while (true) {
            $linia = fgets($fh);
            if ($linia === false) {
                $job['phase'] = evk_restore_next_phase($job, 'r_extract');
                evk_backup_job_log($job['id'], sprintf('Rozpakowane: %d plików, %s.', $s['ex']['files'], evk_backup_bytes_label((float) $s['ex']['bytes'])));
                break;
            }
            $w = json_decode($linia, true);
            if (!is_array($w)) throw new \RuntimeException('Uszkodzona lista wpisów archiwum.');
            $cel = $w['t'] === 'db' ? $praca . '/database.jsonl' : $staging . '/' . $w['n'];

            if (!empty($w['d'])) {
                if (!is_dir($cel) && !wp_mkdir_p($cel)) throw new \RuntimeException('Nie można utworzyć katalogu: ' . $w['n']);
                $st = ['done' => true, 'pos' => 0];
            } else {
                if (!is_dir(dirname($cel)) && !wp_mkdir_p(dirname($cel))) throw new \RuntimeException('Nie można utworzyć katalogu dla: ' . $w['n']);
                $st = EVK_Zip_Reader::extract($zip, $w, $cel, $s['ex']['cur'] ?? ['pos' => 0, 'crc' => 0], $deadline);
            }
            if (empty($st['done'])) {
                $s['ex']['cur'] = $st;
                break;
            }
            $s['ex']['cur'] = null;
            $s['ex']['pos'] = ftell($fh);
            if (empty($w['d'])) { $s['ex']['files']++; $s['ex']['bytes'] += $w['z']; }
            if (microtime(true) >= $deadline) break;
        }
    } finally {
        fclose($fh);
    }
    unset($s);
    $job['progress_done'] = (int) $job['state']['ex']['bytes'] + (int) ($job['state']['ex']['cur']['pos'] ?? 0);
    return $job;
}

// =========================================================================
// BAZA: import do tabel tymczasowych
// =========================================================================

/**
 * Usuwa tabele pasujące do wzorca LIKE (tabele tymczasowe i stare po
 * podmianie), a przy $re — tylko te, które pasują też do wyrażenia.
 */
function evk_restore_drop_tables(string $like, string $re = ''): int {
    global $wpdb;
    $tabele = (array) $wpdb->get_col($wpdb->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $like));
    if ($re !== '') $tabele = preg_grep($re, $tabele) ?: [];
    foreach ($tabele as $t) $wpdb->query('DROP TABLE IF EXISTS ' . evk_backup_db_ident((string) $t));
    return count($tabele);
}

/** Kolacje, które zna ten serwer — dla CREATE TABLE ze starszego/nowszego MySQL-a. */
function evk_restore_collations(): array {
    static $znane = null;
    if ($znane === null) {
        global $wpdb;
        $znane = array_flip(array_map('strtolower', (array) $wpdb->get_col('SELECT COLLATION_NAME FROM information_schema.COLLATIONS')));
    }
    return $znane;
}

/**
 * CREATE TABLE ze zrzutu → CREATE tabeli tymczasowej na TYM serwerze:
 *   - nazwa tymczasowa,
 *   - kolacja nieznana temu serwerowi (utf8mb4_0900_ai_ci z MySQL 8 na
 *     MariaDB) zastąpiona najbliższą znaną,
 *   - klucze obce USUNIĘTE: nazwy ograniczeń są unikalne w całej bazie,
 *     a odwołują się do nazw sprzed podmiany. WordPress i popularne wtyczki
 *     ich nie używają; gdy się trafią, log mówi o tym wprost.
 */
function evk_restore_create_sql(string $create, string $zrodlo, string $tmp, int &$obce = 0): string {
    $sql = preg_replace('/^CREATE TABLE\s+`(?:[^`]|``)+`/i', 'CREATE TABLE ' . evk_backup_db_ident($tmp), $create, 1, $ile);
    if (!$ile || $sql === null) throw new \RuntimeException('Nieczytelna struktura tabeli ' . $zrodlo . '.');

    $sql = (string) preg_replace_callback('/,\s*CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN KEY[^,)]*\([^)]*\)\s*REFERENCES\s+`(?:[^`]|``)+`\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET NULL|NO ACTION|RESTRICT|SET DEFAULT))*/i',
        static function () use (&$obce) { $obce++; return ''; }, $sql);

    $znane = evk_restore_collations();
    return (string) preg_replace_callback('/\b(COLLATE[= ]\s*)([A-Za-z0-9_]+)/i', static function ($m) use ($znane) {
        $k = strtolower($m[2]);
        if (isset($znane[$k])) return $m[0];
        $zestaw = explode('_', $k)[0];
        foreach ([$zestaw . '_unicode_520_ci', $zestaw . '_unicode_ci', $zestaw . '_general_ci'] as $zast) {
            if (isset($znane[$zast])) return $m[1] . $zast;
        }
        return $m[0];
    }, $sql);
}

/**
 * Wartość → literał SQL. Bez osobnej drogi dla bajtów spoza UTF-8: zmierzone
 * (1.227.0, MariaDB 10.11, tryb zwykły i STRICT_ALL_TABLES) — do kolumny
 * BLOB literał z ucieczkami i X'…' zapisują te same bajty, a spoza UTF-8
 * przychodzą wyłącznie z kolumn binarnych (tekstowe oddają je już
 * przekodowane). Mutacja usuwająca gałąź X'…' przechodziła na zielono.
 */
function evk_restore_sql_value($v): string {
    if ($v === null) return 'NULL';
    return "'" . esc_sql((string) $v) . "'";
}

/**
 * Wiersz ze zrzutu → wartości po przeniesieniu: base64 zdekodowane, adresy
 * i ścieżki podmienione (parser bezpieczny dla serializacji), klucze
 * zależne od prefiksu przepisane.
 */
function evk_restore_row(array $linia, array $t, ?array $re, int $min, array &$stats): array {
    $wiersz = (array) $linia['row'];
    foreach ((array) ($linia['b64'] ?? []) as $k) {
        if (isset($wiersz[$k])) $wiersz[$k] = (string) base64_decode((string) $wiersz[$k]);
    }
    if ($re !== null) {
        foreach ($wiersz as $k => $v) {
            if ($v === null || strlen((string) $v) < $min || ctype_digit((string) $v)) continue;
            $wiersz[$k] = evk_sr_replace((string) $v, $re, $stats);
        }
    }
    if ($t['src_prefix'] !== $t['dst_prefix']) {
        if ($t['kind'] === 'options' && ($wiersz['option_name'] ?? '') === $t['src_prefix'] . 'user_roles') {
            $wiersz['option_name'] = $t['dst_prefix'] . 'user_roles';
        } elseif ($t['kind'] === 'usermeta' && strpos((string) ($wiersz['meta_key'] ?? ''), $t['src_prefix']) === 0) {
            // capabilities, user_level, user-settings… — WordPress szuka ich pod prefiksem tabel.
            $wiersz['meta_key'] = $t['dst_prefix'] . substr((string) $wiersz['meta_key'], strlen($t['src_prefix']));
        }
    }
    return $wiersz;
}

/**
 * Wysyła zebraną porcję wierszy bieżącej tabeli jednym zapytaniem. Tabela
 * z kluczem głównym: REPLACE (porcja powtórzona po ubitym kroku to nic),
 * bez klucza: INSERT (powtórzenie łapie liczenie wierszy w kroku).
 */
function evk_restore_flush(array &$s, array &$wartosci, int &$rozmiar): void {
    global $wpdb;
    if (!$wartosci) return;
    $c = $s['cur'];
    $sql = ($c['keyed'] ? 'REPLACE' : 'INSERT') . ' INTO ' . evk_backup_db_ident($c['tmp'])
        . ' (' . implode(', ', array_map('evk_backup_db_ident', $c['cols'])) . ') VALUES ' . implode(',', $wartosci);
    $wpdb->check_current_query = false;
    if ($wpdb->query($sql) === false) {
        throw new \RuntimeException('Import tabeli ' . $c['src'] . ' nie powiódł się: ' . $wpdb->last_error);
    }
    $s['cur']['rows'] += count($wartosci);
    $s['rows'] += count($wartosci);
    $wartosci = [];
    $rozmiar = 0;
}

/**
 * Wczytanie zrzutu do tabel tymczasowych, porcjami zapytań.
 *
 * WZNAWIANIE. Pozycja w pliku zatwierdzana po każdej porcji — ale krok może
 * zginąć między zapytaniem a zapisem stanu, i wtedy porcja pójdzie drugi raz:
 *   - tabela z kluczem głównym: REPLACE — drugi raz te same wiersze to nic,
 *   - bez klucza (rzadkość): na początku kroku liczba wierszy porównana ze
 *     stanem; niezgodna → tabela od nowa, od swojego nagłówka.
 */
function evk_restore_phase_db(array $job, float $deadline): array {
    global $wpdb;
    $id = $job['id'];
    $plik = evk_backup_work_dir($id) . '/database.jsonl';
    $s = $job['state']['db'] ?? ['pos' => 0, 'n' => 0, 'tables' => [], 'cur' => null, 'rows' => 0, 'stats' => [], 'fk' => 0];

    $fh = fopen($plik, 'rb');
    if (!$fh) throw new \RuntimeException('Brak rozpakowanego zrzutu bazy.');

    $tryb = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
    // Jak mysqldump: zero w AUTO_INCREMENT zostaje zerem, daty zerowe przechodzą.
    $wpdb->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', SESSION foreign_key_checks = 0");
    $paczka = (int) min(EVK_RESTORE_SQL_BYTES, max(65536, (int) $wpdb->get_var('SELECT @@max_allowed_packet') / 2));
    $re = $job['state']['pairs'] ? evk_sr_compile($job['state']['pairs']) : null;
    $min = $job['state']['pairs'] ? min(array_map('strlen', array_keys($job['state']['pairs']))) : 0;
    $zrodlo = (string) $job['state']['manifest']['table_prefix'];

    try {
        // Tabela bez klucza przerwana w połowie: sprawdzenie liczby wierszy.
        if ($s['cur'] !== null && !$s['cur']['keyed']) {
            $jest = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . evk_backup_db_ident($s['cur']['tmp']));
            if ($jest !== $s['cur']['rows']) {
                $wpdb->query('TRUNCATE TABLE ' . evk_backup_db_ident($s['cur']['tmp']));
                $s['rows'] -= $s['cur']['rows'];
                $s['cur']['rows'] = 0;
                $s['pos'] = $s['cur']['data_pos'];
            }
        }
        fseek($fh, $s['pos']);

        $wartosci = [];
        $rozmiar = 0;

        while (true) {
            $przed = ftell($fh);
            $linia = fgets($fh);
            if ($linia === false) {
                evk_restore_flush($s, $wartosci, $rozmiar);
                $s['pos'] = ftell($fh);
                $s['cur'] = null;
                $s['done'] = true;
                break;
            }
            $j = json_decode($linia, true);
            if (!is_array($j) || empty($j['table'])) throw new \RuntimeException('Uszkodzony zrzut bazy przy bajcie ' . $przed . '.');

            if (isset($j['create'])) {
                evk_restore_flush($s, $wartosci, $rozmiar);
                $src = (string) $j['table'];
                $s['n']++;
                $tmp = evk_restore_tmp_table($id, $s['n']);
                $final = strpos($src, $zrodlo) === 0 ? $wpdb->prefix . substr($src, strlen($zrodlo)) : $src;
                $wpdb->query('DROP TABLE IF EXISTS ' . evk_backup_db_ident($tmp));
                $wpdb->check_current_query = false;
                if ($wpdb->query(evk_restore_create_sql((string) $j['create'], $src, $tmp, $s['fk'])) === false) {
                    throw new \RuntimeException('Nie da się utworzyć tabeli ' . $src . ': ' . $wpdb->last_error);
                }
                $rodzaj = $final === $wpdb->prefix . 'options' ? 'options' : ($final === $wpdb->prefix . 'usermeta' ? 'usermeta' : '');
                $s['cur'] = ['src' => $src, 'tmp' => $tmp, 'final' => $final, 'cols' => (array) $j['cols'], 'kind' => $rodzaj,
                             'keyed' => !empty($j['pk']), 'rows' => 0, 'data_pos' => ftell($fh)];
                $s['tables'][$tmp] = $final;
                $s['pos'] = ftell($fh);
                continue;
            }
            if ($s['cur'] === null || $j['table'] !== $s['cur']['src']) throw new \RuntimeException('Wiersz tabeli ' . $j['table'] . ' bez nagłówka w zrzucie.');

            $wiersz = evk_restore_row($j, ['kind' => $s['cur']['kind'], 'src_prefix' => $zrodlo, 'dst_prefix' => $wpdb->prefix], $re, $min, $s['stats']);
            $krotka = '(' . implode(',', array_map(static function ($c) use ($wiersz) {
                return evk_restore_sql_value($wiersz[$c] ?? null);
            }, $s['cur']['cols'])) . ')';
            if ($wartosci && $rozmiar + strlen($krotka) > $paczka) {
                // Porcja pełna: wysyłka, zatwierdzenie pozycji PRZED tym wierszem.
                evk_restore_flush($s, $wartosci, $rozmiar);
                $s['pos'] = $przed;
                if (microtime(true) >= $deadline) break;
            }
            $wartosci[] = $krotka;
            $rozmiar += strlen($krotka);
            if (count($wartosci) >= EVK_RESTORE_SQL_ROWS) {
                evk_restore_flush($s, $wartosci, $rozmiar);
                $s['pos'] = ftell($fh);
                if (microtime(true) >= $deadline) break;
            }
        }
    } finally {
        fclose($fh);
        $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s, SESSION foreign_key_checks = 1', $tryb));
    }

    $job['state']['db'] = $s;
    $job['progress_done'] = (int) $job['state']['ex']['bytes'] + (int) $s['pos'];
    if (!empty($s['done'])) {
        evk_backup_job_log($id, sprintf('Baza wczytana do tabel tymczasowych: %d tabel, %d wierszy. Podmian adresów: %d%s%s.',
            count($s['tables']), $s['rows'], (int) ($s['stats']['replaced'] ?? 0),
            !empty($s['stats']['broken']) ? ', wartości z uszkodzoną serializacją (bez zmian): ' . (int) $s['stats']['broken'] : '',
            $s['fk'] ? ', usunięte klucze obce: ' . $s['fk'] : ''));
        $job['phase'] = evk_restore_next_phase($job, 'r_db');
    }
    return $job;
}

// =========================================================================
// PLIKI: podmiana i lustro
// =========================================================================

/** Włącza tryb konserwacji przed pierwszą nieodwracalną zmianą; pamięta stan sprzed. */
function evk_restore_maintenance_on(array &$job): void {
    if (array_key_exists('maint_before', $job['state'])) return;
    $job['state']['maint_before'] = (string) get_option('maintenance_mode', '');
    update_option('maintenance_mode', '1');
    evk_backup_job_log($job['id'], 'Tryb konserwacji włączony na czas podmiany.');
}

/** Przeniesienie pliku — rename, a między dyskami kopia do pliku obok i rename. */
function evk_restore_move(string $z, string $do): bool {
    if (@rename($z, $do)) return true;
    $tmp = $do . '.evk-tmp';
    if (!@copy($z, $tmp)) return false;
    if (!@rename($tmp, $do)) { @unlink($tmp); return false; }
    @unlink($z);
    return true;
}

/** Usuwa to, co stoi na drodze ścieżce docelowej (plik zamiast katalogu i odwrotnie). */
function evk_restore_clear_path(string $cel, bool $katalog): void {
    if ($katalog) {
        if (is_file($cel) || is_link($cel)) @unlink($cel);
        return;
    }
    if (is_dir($cel) && !is_link($cel)) evk_backup_rmdir($cel);
    // Przodek, który jest plikiem, blokuje utworzenie katalogów po drodze.
    $p = dirname($cel);
    while (strlen($p) > strlen(WP_CONTENT_DIR)) {
        if (is_file($p)) { @unlink($p); break; }
        if (is_dir($p)) break;
        $p = dirname($p);
    }
}

function evk_restore_phase_files(array $job, float $deadline): array {
    evk_restore_maintenance_on($job);
    $staging = evk_restore_staging($job['id']);
    $s = $job['state']['mv'] ?? ['pos' => 0, 'files' => 0, 'bytes' => 0];

    $fh = fopen($job['state']['list'], 'rb');
    if (!$fh) throw new \RuntimeException('Nie można otworzyć listy wpisów archiwum.');
    fseek($fh, $s['pos']);
    try {
        while (true) {
            $linia = fgets($fh);
            if ($linia === false) { $s['done'] = true; break; }
            $w = json_decode($linia, true);
            if (!is_array($w)) throw new \RuntimeException('Uszkodzona lista wpisów archiwum.');
            if ($w['t'] !== 'f') { $s['pos'] = ftell($fh); continue; }

            $cel = WP_CONTENT_DIR . '/' . substr(rtrim($w['n'], '/'), 11);
            if (!empty($w['d'])) {
                evk_restore_clear_path($cel, true);
                if (!is_dir($cel) && !wp_mkdir_p($cel)) throw new \RuntimeException('Nie można utworzyć katalogu: ' . $w['n']);
            } else {
                $zrodlo = $staging . '/' . $w['n'];
                if (!file_exists($zrodlo)) {
                    // Przeniesiony przez krok, który zginął przed zapisem stanu.
                    if (!file_exists($cel)) throw new \RuntimeException('Zniknął rozpakowany plik: ' . $w['n']);
                } else {
                    evk_restore_clear_path($cel, false);
                    if (!is_dir(dirname($cel)) && !wp_mkdir_p(dirname($cel))) throw new \RuntimeException('Nie można utworzyć katalogu dla: ' . $w['n']);
                    if (!evk_restore_move($zrodlo, $cel)) throw new \RuntimeException('Nie można zapisać pliku: ' . $w['n']);
                }
                $s['files']++;
                $s['bytes'] += (int) $w['z'];
            }
            $s['pos'] = ftell($fh);
            if (microtime(true) >= $deadline) break;
        }
    } finally {
        fclose($fh);
    }
    $job['state']['mv'] = $s;
    $job['progress_done'] = (int) $job['state']['ex']['bytes'] + (int) $job['state']['db_size'] + (int) $s['bytes'];
    if (!empty($s['done'])) {
        evk_backup_job_log($job['id'], sprintf('Pliki na miejscu: %d (%s).', $s['files'], evk_backup_bytes_label((float) $s['bytes'])));
        $job['phase'] = evk_restore_next_phase($job, 'r_files');
    }
    return $job;
}

/** Wzorce ścieżek (względem wp-content), których lustro nie usuwa. */
function evk_restore_protected(array $job): array {
    $wz = array_merge((array) $job['state']['manifest']['exclusions'], evk_backup_hard_exclusions());
    foreach ((array) $job['state']['own_dirs'] as $d) $wz[] = 'plugins/' . $d . '/';
    return array_values(array_unique($wz));
}

/** Zbiór ścieżek z kopii (względem wp-content): pliki i wszystkie katalogi po drodze. */
function evk_restore_archive_set(string $lista): array {
    $zbior = [];
    $fh = fopen($lista, 'rb');
    if (!$fh) throw new \RuntimeException('Nie można otworzyć listy wpisów archiwum.');
    while (($linia = fgets($fh)) !== false) {
        $w = json_decode($linia, true);
        if (!is_array($w) || $w['t'] !== 'f') continue;
        $rel = substr(rtrim($w['n'], '/'), 11);
        $zbior[$rel] = true;
        while (($p = strrpos($rel, '/')) !== false) {
            $rel = substr($rel, 0, $p);
            if (isset($zbior[$rel])) break;
            $zbior[$rel] = true;
        }
    }
    fclose($fh);
    return $zbior;
}

/**
 * Lustro: usuwa z wp-content pliki, których nie ma w kopii. Nie rusza
 * wykluczeń kopii (ich w kopii nie było z wyboru, nie dlatego, że zniknęły),
 * katalogów kopii ani tej wtyczki. Dowiązań symbolicznych nie śledzi i nie
 * usuwa — mogą prowadzić poza stronę. Katalogi spoza kopii znikają na końcu,
 * jeśli po usunięciu plików są puste.
 */
function evk_restore_phase_sweep(array $job, float $deadline): array {
    $s = $job['state']['sw'] ?? ['stack' => [''], 'dirs' => [], 'deleted' => 0, 'names' => [], 'links' => 0];
    $zbior = evk_restore_archive_set($job['state']['list']);
    $chron = evk_restore_protected($job);
    $dropiny = evk_restore_dropins();

    while ($s['stack']) {
        $rel = array_pop($s['stack']);
        $kat = WP_CONTENT_DIR . ($rel === '' ? '' : '/' . $rel);
        foreach ((array) @scandir($kat) as $e) {
            $e = (string) $e;
            if ($e === '' || $e === '.' || $e === '..') continue;
            $r = $rel === '' ? $e : $rel . '/' . $e;
            $p = $kat . '/' . $e;
            $katalog = is_dir($p);
            if (evk_backup_excluded($r, $katalog, $chron)) continue;
            if ($rel === '' && in_array($r, $dropiny, true)) continue;   // drop-in tego serwera
            if (is_link($p)) { if (!isset($zbior[$r])) $s['links']++; continue; }
            if ($katalog) {
                $s['stack'][] = $r;
                if (!isset($zbior[$r])) $s['dirs'][] = $r;
            } elseif (!isset($zbior[$r])) {
                if (@unlink($p)) {
                    $s['deleted']++;
                    if (count($s['names']) < 50) $s['names'][] = $r;
                }
            }
        }
        if (microtime(true) >= $deadline) break;
    }

    if (!$s['stack']) {
        // Najgłębsze najpierw — rodzic znika po dzieciach.
        usort($s['dirs'], static function ($a, $b) { return substr_count($b, '/') <=> substr_count($a, '/'); });
        foreach ($s['dirs'] as $d) @rmdir(WP_CONTENT_DIR . '/' . $d);
        evk_backup_job_log($job['id'], sprintf('Usunięte pliki spoza kopii: %d%s%s', $s['deleted'],
            $s['names'] ? ', np. ' . implode(', ', array_slice($s['names'], 0, 10)) : '',
            $s['links'] ? '. Dowiązań symbolicznych spoza kopii nie ruszano: ' . $s['links'] : '') . '.');
        $s['done'] = true;
        $job['phase'] = evk_restore_next_phase($job, 'r_sweep');
    }
    $job['state']['sw'] = $s;
    return $job;
}

// =========================================================================
// PODMIANA BAZY I KONIEC
// =========================================================================

/**
 * Jeden krok: RENAME TABLE (atomowo: wszystkie tabele naraz albo żadna),
 * poprawki po nim, sprzątanie. Stare tabele idą pod nazwy `evko<id>_<n>`
 * i znikają dopiero po udanych poprawkach — gdyby poprawki się wyłożyły,
 * log mówi, gdzie jest baza sprzed przywrócenia.
 */
function evk_restore_phase_swap(array $job): array {
    global $wpdb;
    $id = $job['id'];
    evk_restore_maintenance_on($job);

    if ($job['state']['scope'] !== 'files') {
        // Rzeczy należące do TEJ instalacji, odczytane przed podmianą tabeli opcji.
        $zostaje = [
            EVK_BACKUP_DIR_OPTION => evk_backup_dir_suffix(),
            'evk_backup_key'      => evk_backup_loopback_key(),
        ];
        $wtyczka = plugin_basename(EVOKE_ONE_FILE);

        [$obecne] = evk_backup_db_tables();
        $nowe = (array) $job['state']['db']['tables'];   // tmp => final
        $pary = [];
        $n = 0;
        $stare = [];
        foreach ($obecne as $t) {
            $stare[$t] = evk_restore_old_table($id, ++$n);
            $pary[] = evk_backup_db_ident($t) . ' TO ' . evk_backup_db_ident($stare[$t]);
        }
        foreach ($nowe as $tmp => $final) $pary[] = evk_backup_db_ident((string) $tmp) . ' TO ' . evk_backup_db_ident((string) $final);
        $wpdb->query('SET SESSION foreign_key_checks = 0');
        if ($pary && $wpdb->query('RENAME TABLE ' . implode(', ', $pary)) === false) {
            throw new \RuntimeException('Podmiana tabel nie powiodła się (strona bez zmian w bazie): ' . $wpdb->last_error);
        }
        $wpdb->query('SET SESSION foreign_key_checks = 1');
        $job['state']['swapped'] = true;
        evk_backup_job_update($id, ['state' => $job['state'], 'phase' => 'r_swap']);
        evk_backup_job_log($id, sprintf('Baza podmieniona: %d tabel z kopii, %d dotychczasowych odłożonych jako evko%d_*.', count($nowe), count($stare), $id));

        // Pamięć podręczna trzyma opcje sprzed podmiany — w tym alloptions.
        wp_cache_flush();

        foreach ($zostaje as $opcja => $wartosc) update_option($opcja, $wartosc, false);
        $ust = get_option(EVK_BACKUP_OPTION, []);
        $ust = is_array($ust) ? $ust : [];
        if (empty($ust['enabled'])) { $ust['enabled'] = 1; update_option(EVK_BACKUP_OPTION, $ust); }

        /* Wtyczka aktywna pod swoją ścieżką. Kopia mogła mieć ją w katalogu
           o innej nazwie — wpis wskazujący plik, którego tu nie ma, znika. */
        $aktywne = array_values(array_filter((array) get_option('active_plugins', []), static function ($p) {
            return !preg_match('#/evoke-one\.php$#', (string) $p) || is_file(WP_PLUGIN_DIR . '/' . $p);
        }));
        if (!in_array($wtyczka, $aktywne, true)) $aktywne[] = $wtyczka;
        update_option('active_plugins', $aktywne);

        // Tryb konserwacji włączony przez KOPIĘ na czas zrzutu siedzi w zrzucie.
        $przed = $job['state']['manifest']['maintenance_before'];
        if ($przed !== null) update_option('maintenance_mode', (string) $przed);

        $usuniete = evk_restore_drop_tables('evko' . $id . '\_%');
        evk_backup_job_log($id, 'Usunięte tabele sprzed przywrócenia: ' . $usuniete . '.');
        wp_cache_flush();
    } else {
        // Same pliki: tryb konserwacji wraca do stanu sprzed przywracania.
        update_option('maintenance_mode', (string) $job['state']['maint_before']);
    }

    evk_backup_rmdir(evk_backup_work_dir($id));
    $job['status'] = 'done';
    $job['phase'] = 'done';
    $job['progress_done'] = $job['progress_total'];
    evk_backup_job_update($id, [
        'status' => 'done', 'phase' => 'done', 'finished_at' => time(), 'state' => $job['state'],
        'progress_done' => $job['progress_done'], 'lock_until' => 0,
    ]);
    evk_backup_job_log($id, 'Przywracanie zakończone.' . ($job['state']['scope'] !== 'files' ? ' Zaloguj się kontem ze strony z kopii.' : ''));
    do_action('evk_backup_restored', $job);
    return $job;
}

/**
 * Sprzątanie po przerwanym przywracaniu: tabele tymczasowe, katalog roboczy,
 * tryb konserwacji. Po podmianie bazy (`swapped`) tabele sprzed niej
 * zostają — to jedyna droga powrotu, log mówi, gdzie są.
 */
function evk_restore_cleanup(array $job): void {
    evk_restore_drop_tables('evkr' . $job['id'] . '\_%');
    evk_backup_rmdir(evk_backup_work_dir($job['id']));
    if (!empty($job['state']['swapped'])) {
        evk_backup_job_log($job['id'], sprintf('Baza sprzed przywrócenia zostaje w tabelach evko%d_*.', $job['id']));
        return;
    }
    if (array_key_exists('maint_before', $job['state'])) {
        update_option('maintenance_mode', (string) $job['state']['maint_before']);
        if (!empty($job['state']['mv']['files'])) {
            evk_backup_job_log($job['id'], 'UWAGA: część plików była już podmieniona (' . (int) $job['state']['mv']['files'] . ').');
        }
    }
}

// =========================================================================
// KOPIE WGRANE PRZEZ FTP
// =========================================================================

/**
 * Przenosi archiwa z `wp-content/evk-backups-import/` do katalogu kopii.
 * Tam leżą pod przewidywalną nazwą — więc nie dłużej niż do otwarcia
 * zakładki. Plik zmieniony w ostatniej minucie może się jeszcze wgrywać
 * i zostaje do następnego razu. Oddaje nazwy przeniesionych.
 */
function evk_backup_import_scan(): array {
    $imp = evk_backup_import_dir();
    evk_backup_ensure_dir($imp);
    if (!evk_backup_ensure_dir(evk_backup_dir())) return [];
    $przeniesione = [];
    foreach (glob($imp . '/*.zip') ?: [] as $p) {
        if (!is_file($p) || time() - (int) filemtime($p) < 60) continue;
        $cel = evk_backup_register_upload($p, basename($p));
        if ($cel !== '') $przeniesione[] = $cel;
    }
    return $przeniesione;
}

/**
 * Archiwum z zewnątrz (FTP, wgrane z przeglądarki) → katalog kopii: nazwa
 * oczyszczona i unikalna, przeniesienie, opis z danymi z manifestu (albo
 * z powodem, dla którego manifestu nie ma). Oddaje nazwę w katalogu kopii,
 * pusty łańcuch, gdy przeniesienie się nie udało.
 */
function evk_backup_register_upload(string $sciezka, string $nazwa): string {
    $dir = evk_backup_dir();
    // Polskie litery na łacińskie („kopia źródło" → kopia-zrodlo), reszta spoza [A-Za-z0-9._-] na myślnik.
    $baza = preg_replace('/[^A-Za-z0-9._-]+/', '-', remove_accents((string) preg_replace('/\.zip$/i', '', basename($nazwa))));
    $baza = trim((string) $baza, '.-_') ?: 'wgrana';
    $cel = $baza . '.zip';
    for ($i = 2; file_exists($dir . '/' . $cel); $i++) $cel = $baza . '-' . $i . '.zip';
    if (!@rename($sciezka, $dir . '/' . $cel)) return '';
    $meta = ['archive' => $cel, 'created_at' => (int) filemtime($dir . '/' . $cel), 'source' => 'upload', 'pinned' => false,
             'imported_at' => time()];
    try {
        $m = evk_restore_read_manifest($dir . '/' . $cel);
        $meta['created_at'] = strtotime((string) ($m['created_at'] ?? '')) ?: $meta['created_at'];
        $meta += ['db_rows' => (int) ($m['db_rows'] ?? 0), 'files' => (int) ($m['files'] ?? 0), 'siteurl' => (string) ($m['siteurl'] ?? '')];
    } catch (\RuntimeException $e) {
        $meta['error'] = $e->getMessage();
    }
    evk_backup_meta_write($dir . '/' . $cel, $meta);
    return $cel;
}
