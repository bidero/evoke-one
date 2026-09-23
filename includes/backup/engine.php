<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — silnik zadań: kroki, lock, samokalibracja, fazy kopii,
 * retencja.
 *
 * ZADANIE to wiersz w evk_backup_jobs (patrz tables.php). Pracę robią KROKI
 * (ticki) — krótkie żądania, z których każde robi, ile zdąży w swoim
 * budżecie czasu, zapisuje punkt, w którym stanęło, i oddaje pałeczkę.
 *
 * KTO URUCHAMIA KROK — trzy drogi, każda woła evk_backup_tick():
 *   - żądanie zwrotne (loopback) wysłane przez poprzedni krok — główny napęd,
 *     działa także w nocy na stronie bez ruchu,
 *   - WP-Cron — zapasowy krok planowany Z GÓRY, zanim zacznie się praca:
 *     krok ubity w połowie nie zostawia zadania bez następcy,
 *   - panel — dopóki zakładka jest otwarta, jej odpytywanie popycha zadanie,
 *     które od kilku sekund nikt nie ruszył (loopback zablokowany).
 *
 * LOCK — warunkowy UPDATE na wierszu zadania, sprawdzany liczbą zmienionych
 * wierszy. Atomowy, w przeciwieństwie do transientu. Krok odnawia go przy
 * każdym zapisie stanu, więc długi, ale żywy krok go nie traci.
 *
 * SAMOKALIBRACJA. Limitu czasu serwera nie widać z PHP (nginx, LiteSpeed,
 * Cloudflare mają własne), a max_execution_time bywa fikcją. Więc zamiast go
 * zgadywać — MIERZYMY, co przeżywa: krok, który nie zdjął swojego locka
 * (termin minął, a lock_until wciąż niezerowy), został ubity. Budżet
 * następnych spada wtedy o połowę; udany krok podnosi go o ćwierć, do sufitu.
 * Start 20 s, sufit 60 s, podłoga 3 s (decyzja z 1.226.0: krótkie kroki
 * prawie nic nie kosztują — wznowienie zapisu ZIP i zrzutu to milisekundy).
 */

const EVK_BACKUP_BUDGET_START = 20000;   // ms
const EVK_BACKUP_BUDGET_MAX   = 60000;
const EVK_BACKUP_BUDGET_MIN   = 3000;
/** Zapas locka ponad budżet: jeden krok potrafi przeciągnąć (duży katalog,
    paczka bazy 4 MB) — lock nie może wygasnąć żywemu. */
const EVK_BACKUP_LOCK_MARGIN  = 60;      // s
/** Tyle ubitych kroków z rzędu bez postępu i zadanie się poddaje. */
const EVK_BACKUP_MAX_KILLS    = 8;
/** Linii logu trzymanych w zadaniu. */
const EVK_BACKUP_LOG_LINES    = 300;
/** Najdłuższa porcja pracy między zapisami postępu (s) — patrz run_phases. */
const EVK_BACKUP_SLICE        = 1.0;

add_action('evk_backup_tick', 'evk_backup_tick');

// =========================================================================
// ZADANIA
// =========================================================================

function evk_backup_job_get(int $id): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . evk_backup_jobs_table() . ' WHERE id = %d', $id), ARRAY_A);
    if (!$row) return null;
    $row['state'] = $row['state'] ? (json_decode($row['state'], true) ?: []) : [];
    foreach (['id', 'next_job_id', 'lock_until', 'heartbeat', 'budget_ms', 'ticks', 'kills', 'progress_done',
              'progress_total', 'created_at', 'started_at', 'finished_at'] as $k) {
        $row[$k] = (int) $row[$k];
    }
    return $row;
}

function evk_backup_job_update(int $id, array $pola): void {
    global $wpdb;
    if (isset($pola['state']) && is_array($pola['state'])) {
        $pola['state'] = wp_json_encode($pola['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $wpdb->update(evk_backup_jobs_table(), $pola, ['id' => $id]);
}

/** Dopisuje linię do logu zadania (czas strony + treść), trzyma ostatnie N. */
function evk_backup_job_log(int $id, string $tresc): void {
    global $wpdb;
    $t = evk_backup_jobs_table();
    $log = (string) $wpdb->get_var($wpdb->prepare("SELECT log FROM $t WHERE id = %d", $id));
    $linie = $log === '' ? [] : explode("\n", $log);
    $linie[] = wp_date('H:i:s') . ' ' . $tresc;
    $linie = array_slice($linie, -EVK_BACKUP_LOG_LINES);
    $wpdb->update($t, ['log' => implode("\n", $linie)], ['id' => $id]);
}

/** Zadanie, które jeszcze pracuje (albo czeka), jeśli jest — jedno naraz. */
function evk_backup_job_active(): ?array {
    global $wpdb;
    $id = (int) $wpdb->get_var('SELECT id FROM ' . evk_backup_jobs_table() . " WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1");
    return $id ? evk_backup_job_get($id) : null;
}

/**
 * Wiersz nowego zadania. $status 'waiting' — czeka na inne zadanie
 * (przywracanie na kopię sprzed przywrócenia, patrz evk_backup_release_next).
 * Zwraca id albo WP_Error.
 */
function evk_backup_job_create(string $type, string $source, string $status, string $phase, array $state = [], int $next = 0) {
    global $wpdb;
    $wpdb->insert(evk_backup_jobs_table(), [
        'type' => $type, 'source' => $source, 'status' => $status, 'phase' => $phase, 'next_job_id' => $next,
        'budget_ms' => EVK_BACKUP_BUDGET_START, 'created_at' => time(),
        'state' => wp_json_encode((object) $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $id = (int) $wpdb->insert_id;
    if (!$id) return new WP_Error('evk_backup_db', 'Nie udało się zapisać zadania: ' . $wpdb->last_error);
    return $id;
}

/**
 * Nowe zadanie kopii. $source: manual | schedule | snapshot. $next — zadanie
 * czekające na tę kopię (przywracanie). Zwraca id albo WP_Error, gdy inne
 * zadanie już pracuje.
 */
function evk_backup_start(string $source = 'manual', int $next = 0) {
    if (evk_backup_job_active()) {
        return new WP_Error('evk_backup_busy', 'Inna kopia, wysyłka albo przywracanie już trwa.');
    }
    $id = evk_backup_job_create('backup', $source, 'queued', 'init', [], $next);
    if (is_wp_error($id)) return $id;
    evk_backup_job_log($id, 'Zadanie utworzone (' . $source . ').');
    evk_backup_schedule($id, 0);
    evk_backup_kick($id);
    return $id;
}

/** Zadanie, na które czeka $id (kopia sprzed przywrócenia), jeśli jeszcze trwa. */
function evk_backup_job_before(int $id): ?array {
    global $wpdb;
    $przed = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . evk_backup_jobs_table()
        . " WHERE next_job_id = %d AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", $id));
    return $przed ? evk_backup_job_get($przed) : null;
}

/** Zadanie czekające na to (przywracanie po kopii sprzed niego) rusza. */
function evk_backup_release_next(array $job): void {
    if (!$job['next_job_id']) return;
    $nast = evk_backup_job_get($job['next_job_id']);
    if (!$nast || $nast['status'] !== 'waiting') return;
    evk_backup_job_update($nast['id'], ['status' => 'queued']);
    evk_backup_job_log($nast['id'], 'Kopia sprzed przywrócenia gotowa: ' . $job['archive'] . '.');
    evk_backup_schedule($nast['id'], 0);
    evk_backup_kick($nast['id']);
}

// =========================================================================
// NAPĘD: WP-Cron i żądanie zwrotne
// =========================================================================

/**
 * Zapasowy krok w WP-Cron za $za sekund. WordPress odrzuca drugi taki sam
 * event (ten sam hook i argumenty) w oknie 10 minut — stąd czyszczenie przed
 * zaplanowaniem (ten sam problem ma newsletter/queue.php).
 */
function evk_backup_schedule(int $id, int $za): void {
    wp_clear_scheduled_hook('evk_backup_tick', [$id]);
    wp_schedule_single_event(time() + max(0, $za), 'evk_backup_tick', [$id]);
}

/** Klucz żądań zwrotnych — osobna opcja, należy do instalacji, nie do strony. */
function evk_backup_loopback_key(): string {
    $k = (string) get_option('evk_backup_key', '');
    if (strlen($k) < 32) {
        $k = bin2hex(random_bytes(24));
        update_option('evk_backup_key', $k, false);
    }
    return $k;
}

function evk_backup_loopback_sig(int $id): string {
    return hash_hmac('sha256', 'evk-backup-tick-' . $id, evk_backup_loopback_key());
}

/**
 * Żądanie zwrotne: następny krok w osobnym żądaniu, bez czekania na odpowiedź.
 * Filtr `evk_backup_loopback` = false wyłącza (np. gdy serwer ubija takie
 * żądania — napęd przejmuje wtedy WP-Cron i panel).
 */
function evk_backup_kick(int $id): void {
    if (!apply_filters('evk_backup_loopback', true, $id)) return;
    wp_remote_post(admin_url('admin-ajax.php'), [
        'blocking'  => false,
        'timeout'   => 0.01,
        'sslverify' => false,
        'body'      => ['action' => 'evk_backup_loopback', 'id' => $id, 'sig' => evk_backup_loopback_sig($id)],
    ]);
}

// =========================================================================
// KROK
// =========================================================================

/**
 * Jeden krok zadania. $budzet_ms nadpisuje budżet z zadania (panel popycha
 * krótszymi krokami, żeby pasek postępu żył). Zwraca stan po kroku albo null,
 * gdy krok się nie odbył (brak zadania, zakończone, lock zajęty).
 */
function evk_backup_tick(int $id, ?int $budzet_ms = null): ?array {
    global $wpdb;
    $job = evk_backup_job_get($id);
    if (!$job || !in_array($job['status'], ['queued', 'running'], true)) return null;
    $t = evk_backup_jobs_table();
    $teraz = time();

    /* UBITY POPRZEDNIK: lock niezerowy, a jego termin minął — krok nie dożył
       końca, bo koniec zawsze zeruje lock. To jest POMIAR limitu serwera. */
    if ($job['lock_until'] > 0 && $job['lock_until'] < $teraz) {
        $nowy = max(EVK_BACKUP_BUDGET_MIN, intdiv($job['budget_ms'], 2));
        $zywy = $job['heartbeat'] > 0 ? ($job['heartbeat'] - ($job['state']['tick_start'] ?? $job['heartbeat'])) : 0;
        $wpdb->query($wpdb->prepare("UPDATE $t SET kills = kills + 1, budget_ms = %d, lock_until = 0 WHERE id = %d AND lock_until = %d",
            $nowy, $id, $job['lock_until']));
        evk_backup_job_log($id, sprintf('Poprzedni krok przerwany przez serwer (ostatni znak życia po ~%d s). Krótsze kroki: %d s.',
            max(0, $zywy), intdiv($nowy, 1000)));
        $job = evk_backup_job_get($id);
        if (!$job) return null;
        if ($job['kills'] >= EVK_BACKUP_MAX_KILLS) {
            evk_backup_fail($job, 'Serwer przerywa kroki kopii ' . $job['kills'] . ' razy z rzędu — nawet najkrótsze. Sprawdź log błędów PHP.');
            return null;
        }
    }

    $budzet = $budzet_ms ?? $job['budget_ms'];
    /* Limit czasu PHP. Jeśli set_time_limit() działa, ustawiamy własny i po
       sprawie. Jeśli hosting ją zablokował, obowiązuje max_execution_time
       LICZONY OD POCZĄTKU ŻĄDANIA — a część już zjadło ładowanie WordPressa.
       Budżet to wtedy 80% limitu minus to, co upłynęło. */
    $zapas = (int) ceil($budzet / 1000) + EVK_BACKUP_LOCK_MARGIN;
    /* function_exists, bo w PHP 8 funkcja z disable_functions NIE ISTNIEJE —
       wywołanie rzuca Error, którego @ nie tłumi (zmierzone: krok nie ruszał
       wcale). Hostingi wyłączają set_time_limit dość często. */
    if (!function_exists('set_time_limit') || !@set_time_limit($zapas)) {
        $ini = (int) ini_get('max_execution_time');
        if ($ini > 0) {
            $juz = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0;
            $budzet = min($budzet, (int) ($ini * 800 - $juz));
        }
    }
    $budzet = max(1, $budzet);

    // LOCK: atomowo, sprawdzany liczbą zmienionych wierszy.
    $termin = $teraz + (int) ceil($budzet / 1000) + EVK_BACKUP_LOCK_MARGIN;
    $wzial = $wpdb->query($wpdb->prepare(
        "UPDATE $t SET lock_until = %d, heartbeat = %d, status = 'running', ticks = ticks + 1,
             started_at = IF(started_at = 0, %d, started_at) WHERE id = %d AND lock_until = 0 AND status IN ('queued','running')",
        $termin, $teraz, $teraz, $id));
    if ($wzial !== 1) return null;

    // Zapas z góry: jeśli ten krok zginie, WP-Cron podejmie zadanie po terminie locka.
    evk_backup_schedule($id, $termin - $teraz + 5);
    ignore_user_abort(true);

    $job = evk_backup_job_get($id);
    $start = microtime(true);
    $deadline = $start + $budzet / 1000;
    $job['state']['tick_start'] = $teraz;

    /* Błąd krytyczny (brak pamięci, limit czasu PHP) kończy proces bez
       wyjątku — shutdown zapisuje go do logu. Lock ZOSTAJE: następny krok
       rozpozna ubitego poprzednika i skróci budżet. */
    evk_backup_tick_finished($id, false);
    register_shutdown_function(static function () use ($id) {
        if (evk_backup_tick_finished($id)) return;
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            evk_backup_job_log($id, 'Krok przerwany błędem PHP: ' . $e['message']);
        }
    });

    try {
        $job = evk_backup_run_phases($job, $deadline);
    } catch (\Throwable $e) {
        evk_backup_tick_finished($id, true);
        evk_backup_fail($job, $e->getMessage());
        return evk_backup_job_get($id);
    }
    evk_backup_tick_finished($id, true);

    $czas = (int) round((microtime(true) - $start) * 1000);
    if (in_array($job['status'], ['done', 'failed', 'cancelled'], true)) {
        wp_clear_scheduled_hook('evk_backup_tick', [$id]);
        evk_backup_job_update($id, ['lock_until' => 0]);
        return evk_backup_job_get($id);
    }

    // Krok udany: budżet w górę o ćwierć (do sufitu), licznik ubitych od zera.
    $nowy = min(EVK_BACKUP_BUDGET_MAX, max($job['budget_ms'], (int) ($job['budget_ms'] * 1.25)));
    evk_backup_job_update($id, [
        'state' => $job['state'], 'phase' => $job['phase'], 'progress_done' => $job['progress_done'],
        'progress_total' => $job['progress_total'], 'heartbeat' => time(), 'lock_until' => 0,
        'kills' => 0, 'budget_ms' => $budzet_ms === null ? $nowy : $job['budget_ms'],
    ]);
    evk_backup_job_log($id, sprintf('Krok %d: %s, %.1f s.', $job['ticks'], evk_backup_phase_label($job['phase']), $czas / 1000));
    evk_backup_kick($id);
    return evk_backup_job_get($id);
}

/**
 * Czy krok zadania $id doszedł do końca w tym procesie — dla funkcji
 * zamykającej, która ma rozpoznać śmierć kroku od fatala. Z $ustaw: zapis.
 */
function evk_backup_tick_finished(int $id, ?bool $ustaw = null): bool {
    static $koniec = [];
    if ($ustaw !== null) $koniec[$id] = $ustaw;
    return !empty($koniec[$id]);
}

/** Zapis stanu w trakcie kroku: postęp + odnowienie locka i znaku życia. */
function evk_backup_checkpoint(array $job): void {
    $teraz = time();
    evk_backup_job_update($job['id'], [
        'state' => $job['state'], 'phase' => $job['phase'], 'progress_done' => $job['progress_done'],
        'progress_total' => $job['progress_total'], 'heartbeat' => $teraz,
        'lock_until' => $teraz + (int) ceil($job['budget_ms'] / 1000) + EVK_BACKUP_LOCK_MARGIN,
    ]);
}

function evk_backup_phase_label(string $faza): string {
    return [
        'init' => 'przygotowanie', 'db' => 'zrzut bazy', 'list' => 'lista plików',
        'pack' => 'pakowanie', 'finalize' => 'zamykanie archiwum', 'done' => 'gotowe',
        'r_check' => 'sprawdzanie archiwum', 'r_extract' => 'rozpakowywanie', 'r_db' => 'wczytywanie bazy',
        'r_files' => 'podmiana plików', 'r_sweep' => 'usuwanie plików spoza kopii', 'r_swap' => 'podmiana bazy',
        'u_session' => 'łączenie z Dyskiem Google', 'u_send' => 'wysyłka na Dysk Google', 'u_finish' => 'porządki na Dysku Google',
        'd_fetch' => 'pobieranie z Dysku Google',
    ][$faza] ?? $faza;
}

// =========================================================================
// FAZY KOPII
// =========================================================================

/**
 * Fazy po kolei, dopóki starcza czasu. Każda faza robi porcję i zapisuje
 * stan (checkpoint) — ubicie w połowie kosztuje najwyżej tę porcję.
 */
function evk_backup_run_phases(array $job, float $deadline): array {
    do {
        /* PORCJA najwyżej ~1 s, nawet w kroku 20-sekundowym: po każdej idzie
           checkpoint, więc pasek w panelu (odpytywanie co 1 s) widzi postęp
           na bieżąco. Zmierzone przed zmianą: kopia z plikiem 150 MB trwała
           10,7 s, a pasek zmienił się 3 razy — postęp zapisywał się na
           końcu kroku. Porcja kończy też anulowanie szybciej. */
        $porcja = min($deadline, microtime(true) + EVK_BACKUP_SLICE);
        // Anulowanie z panelu sprawdzane między porcjami.
        $status = evk_backup_job_status($job['id']);
        if ($status === 'cancelled') {
            evk_backup_cleanup($job);
            $job['status'] = 'cancelled';
            return $job;
        }

        switch (in_array($job['type'], ['restore', 'upload', 'download'], true) ? $job['type'] : $job['phase']) {
            case 'restore':  $job = evk_restore_phase($job, $porcja); break;
            case 'upload':
            case 'download': $job = evk_gdrive_phase($job, $porcja); break;
            case 'init':     $job = evk_backup_phase_init($job); break;
            case 'db':       $job = evk_backup_phase_db($job, $porcja); break;
            case 'list':     $job = evk_backup_phase_list($job, $porcja); break;
            case 'pack':     $job = evk_backup_phase_pack($job, $porcja); break;
            case 'finalize': $job = evk_backup_phase_finalize($job); break;
            default: throw new \RuntimeException('Nieznana faza zadania: ' . $job['phase']);
        }
        if ($job['status'] === 'done') return $job;
        evk_backup_checkpoint($job);
    } while (microtime(true) < $deadline);
    return $job;
}

function evk_backup_job_status(int $id): string {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . evk_backup_jobs_table() . ' WHERE id = %d', $id));
}

/** Katalog roboczy zadania — w katalogu kopii, więc chroniony tak jak one. */
function evk_backup_work_dir(int $id): string {
    return evk_backup_dir() . '/.praca-' . $id;
}

/** Nazwa archiwum: host-data-źródło-losowe. Losowa końcówka: nazwa nieprzewidywalna. */
function evk_backup_archive_name(string $source, ?int $czas = null): string {
    $host = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) ?: 'strona';
    $zrodlo = ['manual' => 'reczna', 'schedule' => 'nocna', 'snapshot' => 'przed-przywroceniem'][$source] ?? 'kopia';
    return trim($host, '-') . '-' . wp_date('Y-m-d-Hi', $czas ?? time()) . '-' . $zrodlo . '-' . bin2hex(random_bytes(4)) . '.zip';
}

function evk_backup_phase_init(array $job): array {
    $dir = evk_backup_dir();
    if (!evk_backup_ensure_dir($dir)) throw new \RuntimeException('Nie można utworzyć katalogu kopii: ' . $dir);
    $praca = evk_backup_work_dir($job['id']);
    if (!is_dir($praca) && !wp_mkdir_p($praca)) throw new \RuntimeException('Nie można utworzyć katalogu roboczego: ' . $praca);

    $s = evk_backup_get_settings();
    $job['archive'] = evk_backup_archive_name($job['source']);
    evk_backup_job_update($job['id'], ['archive' => $job['archive']]);

    // Tryb konserwacji na czas zrzutu bazy — zapamiętujemy stan sprzed.
    $job['state']['maint_before'] = null;
    $job['state']['maint_forced'] = null;
    if (!empty($s['maintenance_db'])) {
        $job['state']['maint_before'] = (string) get_option('maintenance_mode', '');
        /* Do manifestu: zrzut złapie tryb konserwacji WŁĄCZONY przez kopię,
           a strona po przywróceniu ma wrócić do stanu sprzed niej. */
        $job['state']['maint_forced'] = $job['state']['maint_before'];
        update_option('maintenance_mode', '1');
        evk_backup_job_log($job['id'], 'Tryb konserwacji włączony na czas zrzutu bazy.');
    }

    $job['state']['db'] = evk_backup_db_dump_start();
    $job['state']['db_file'] = $praca . '/database.jsonl';
    @unlink($job['state']['db_file']);
    $job['progress_total'] = max(1, (int) $job['state']['db']['estimate']);
    $job['progress_done'] = 0;
    $job['phase'] = 'db';
    evk_backup_job_log($job['id'], sprintf('Baza: %d tabel, ok. %d wierszy.', count($job['state']['db']['tables']), $job['state']['db']['estimate']));
    return $job;
}

function evk_backup_phase_db(array $job, float $deadline): array {
    $st = evk_backup_db_dump_step($job['state']['db_file'], $job['state']['db'], $deadline);
    $job['state']['db'] = $st;
    $job['progress_done'] = $st['rows'];
    if (!$st['done']) return $job;

    evk_backup_maintenance_restore($job);
    $job['state']['maint_before'] = null;
    evk_backup_job_log($job['id'], sprintf('Zrzut bazy gotowy: %d wierszy, %s.', $st['rows'], evk_backup_bytes_label((float) $st['bytes'])));

    $praca = evk_backup_work_dir($job['id']);
    $job['state']['list'] = evk_backup_list_start(WP_CONTENT_DIR, 'wp-content/', evk_backup_exclusion_patterns(),
        $praca . '/lista.jsonl', evk_backup_root_preview_files());
    $job['phase'] = 'list';
    return $job;
}

function evk_backup_phase_list(array $job, float $deadline): array {
    $st = evk_backup_list_step($job['state']['list'], $deadline);
    $job['state']['list'] = $st;
    if (!$st['done']) return $job;

    evk_backup_job_log($job['id'], sprintf('Lista plików gotowa: %d plików, %s.', $st['files'], evk_backup_bytes_label((float) $st['size'])));
    foreach ($st['log_n'] as $rodzaj => $ile) {
        evk_backup_job_log($job['id'], sprintf('Pominięte (%s): %d, np. %s', str_replace('_', ' ', $rodzaj), $ile,
            implode(', ', array_slice($st['log'][$rodzaj] ?? [], 0, 5))));
    }

    // Archiwum powstaje jako .part — lista kopii go nie widzi, dopóki nie jest całe.
    $zip = evk_backup_dir() . '/' . $job['archive'] . '.part';
    $job['state']['zip_path'] = $zip;
    $job['state']['zip'] = EVK_Zip_Writer::create($zip)->state();
    $job['state']['pack'] = evk_backup_pack_start($st['list']);
    $job['state']['pack_sub'] = 'manifest';
    $job['progress_total'] = max(1, (int) $st['size'] + (int) $job['state']['db']['bytes']);
    $job['progress_done'] = 0;
    $job['phase'] = 'pack';
    return $job;
}

function evk_backup_phase_pack(array $job, float $deadline): array {
    $w = EVK_Zip_Writer::resume($job['state']['zip_path'], $job['state']['zip']);

    if ($job['state']['pack_sub'] === 'manifest') {
        // Manifest PIERWSZY — restore czyta go przed czymkolwiek innym.
        $manifest = evk_backup_manifest([
            'source'      => $job['source'],
            'db_rows'     => (int) $job['state']['db']['rows'],
            'db_tables'   => $job['state']['db']['tables'],
            'db_skipped'  => $job['state']['db']['skipped'],
            'files'       => (int) $job['state']['list']['files'],
            'files_size'  => (int) $job['state']['list']['size'],
            'files_log'   => $job['state']['list']['log_n'],
            'exclusions'  => evk_backup_exclusion_patterns(),
            'root_files'  => array_keys(evk_backup_root_preview_files()),
            // Katalog tej wtyczki — przywracanie go pomija, także pod inną nazwą.
            'plugin_dir'  => basename(rtrim(EVOKE_ONE_DIR, '/')),
            // null = kopia nie ruszała trybu konserwacji; inaczej stan sprzed niej.
            'maintenance_before' => $job['state']['maint_forced'] ?? null,
        ]);
        $w->add_string('manifest.json', (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $job['state']['pack_sub'] = 'db';
    }

    if ($job['state']['pack_sub'] === 'db') {
        $wynik = $w->add_file($job['state']['db_file'], 'database.jsonl', $deadline);
        if ($wynik === 'skipped') throw new \RuntimeException('Zrzut bazy zniknął z katalogu roboczego przed spakowaniem.');
        if ($wynik === 'done') {
            $job['state']['pack_sub'] = 'files';
            $job['progress_done'] = (int) $job['state']['db']['bytes'];
        }
    }

    if ($job['state']['pack_sub'] === 'files' && microtime(true) < $deadline) {
        $job['state']['pack'] = evk_backup_pack_step($w, $job['state']['pack'], $deadline);
        if ($job['state']['pack']['done']) $job['phase'] = 'finalize';
    }

    $job['state']['zip'] = $w->state();
    /* Postęp liczy też bajty pliku przerwanego w połowie — inaczej duży plik
       (wideo, archiwum) trzymał pasek w miejscu przez cały swój czas. */
    $wisi = (int) ($job['state']['zip']['pending']['pos'] ?? 0);
    $job['progress_done'] = $job['state']['pack_sub'] === 'files'
        ? (int) $job['state']['db']['bytes'] + (int) $job['state']['pack']['bytes'] + $wisi
        : $wisi;
    return $job;
}

function evk_backup_phase_finalize(array $job): array {
    $w = EVK_Zip_Writer::resume($job['state']['zip_path'], $job['state']['zip']);
    $w->finish();
    $cel = evk_backup_dir() . '/' . $job['archive'];
    if (!@rename($job['state']['zip_path'], $cel)) throw new \RuntimeException('Nie można nadać archiwum nazwy końcowej: ' . $cel);

    $meta = [
        'archive'    => $job['archive'],
        'created_at' => time(),
        'source'     => $job['source'],
        'pinned'     => false,
        'size'       => (int) filesize($cel),
        'db_rows'    => (int) $job['state']['db']['rows'],
        'files'      => (int) $job['state']['list']['files'],
        'skipped'    => (int) $job['state']['pack']['skipped_n'],
        'siteurl'    => (string) get_option('siteurl'),
        'plugin_version' => defined('EVOKE_ONE_VERSION') ? EVOKE_ONE_VERSION : '',
        'job'        => $job['id'],
    ];
    evk_backup_meta_write($cel, $meta);

    evk_backup_rmdir(evk_backup_work_dir($job['id']));
    $job['status'] = 'done';
    $job['phase'] = 'done';
    $job['progress_done'] = $job['progress_total'];
    evk_backup_job_update($job['id'], [
        'status' => 'done', 'phase' => 'done', 'finished_at' => time(), 'state' => $job['state'],
        'progress_done' => $job['progress_done'], 'lock_until' => 0,
    ]);
    evk_backup_job_log($job['id'], sprintf('Kopia gotowa: %s (%s), %d plików, %d wierszy bazy%s.',
        $job['archive'], evk_backup_bytes_label((float) $meta['size']), $meta['files'], $meta['db_rows'],
        $meta['skipped'] ? ', pominięte w trakcie: ' . $meta['skipped'] : ''));

    /* Kopia sprzed przywrócenia: bez retencji — mogłaby usunąć archiwum,
       z którego zaraz przywracamy (starsze niż limit). */
    if (!$job['next_job_id']) {
        $usuniete = evk_backup_rotate();
        if ($usuniete) evk_backup_job_log($job['id'], 'Retencja: usunięte starsze kopie: ' . implode(', ', $usuniete) . '.');
    }
    do_action('evk_backup_done', $job, $meta);
    evk_backup_release_next($job);
    return $job;
}

// =========================================================================
// PORAŻKA, ANULOWANIE, SPRZĄTANIE
// =========================================================================

function evk_backup_maintenance_restore(array $job): void {
    if (!array_key_exists('maint_before', $job['state']) || $job['state']['maint_before'] === null) return;
    update_option('maintenance_mode', $job['state']['maint_before']);
    evk_backup_job_log($job['id'], 'Tryb konserwacji przywrócony do stanu sprzed kopii.');
}

/** Usuwa to, co zadanie zostawiło: katalog roboczy i niedokończone archiwum. */
function evk_backup_cleanup(array $job): void {
    if ($job['type'] === 'restore') { evk_restore_cleanup($job); return; }
    if ($job['type'] === 'upload' || $job['type'] === 'download') { evk_gdrive_cleanup($job); return; }
    evk_backup_maintenance_restore($job);
    if (!empty($job['state']['zip_path'])) {
        @unlink($job['state']['zip_path']);
        @unlink($job['state']['zip_path'] . '.cd');
    }
    evk_backup_rmdir(evk_backup_work_dir($job['id']));
}

function evk_backup_fail(array $job, string $powod): void {
    evk_backup_cleanup($job);
    evk_backup_job_update($job['id'], ['status' => 'failed', 'error' => $powod, 'finished_at' => time(), 'lock_until' => 0]);
    evk_backup_job_log($job['id'], 'BŁĄD: ' . $powod);
    wp_clear_scheduled_hook('evk_backup_tick', [$job['id']]);
    // Przywracanie czekające na tę kopię nie ruszy — i mówi dlaczego.
    if ($job['next_job_id']) {
        $nast = evk_backup_job_get($job['next_job_id']);
        if ($nast && $nast['status'] === 'waiting') {
            evk_backup_job_update($nast['id'], ['status' => 'failed', 'finished_at' => time(),
                'error' => 'Kopia sprzed przywrócenia nie powiodła się — przywracanie nie ruszyło, strona bez zmian. ' . $powod]);
        }
    }
    do_action('evk_backup_failed', $job, $powod);
}

/** Anulowanie z panelu. Pracujący krok zobaczy status między porcjami i posprząta. */
function evk_backup_cancel(int $id): bool {
    $job = evk_backup_job_get($id);
    if (!$job || !in_array($job['status'], ['queued', 'running', 'waiting'], true)) return false;
    // Przywracanie, które podmienia już pliki albo bazę, przerwane zostawiłoby stronę w połowie.
    if ($job['type'] === 'restore' && !evk_restore_cancellable($job)) return false;
    evk_backup_job_update($id, ['status' => 'cancelled', 'finished_at' => time()]);
    evk_backup_job_log($id, 'Anulowane z panelu.');
    wp_clear_scheduled_hook('evk_backup_tick', [$id]);
    // Anulowana kopia sprzed przywrócenia anuluje też czekające przywracanie — i odwrotnie.
    if ($job['next_job_id']) evk_backup_cancel($job['next_job_id']);
    $przed = evk_backup_job_before($id);
    if ($przed) evk_backup_cancel($przed['id']);
    // Nikt nie pracuje — sprzątamy od razu. Pracujący krok posprząta sam.
    if ($job['lock_until'] === 0 || $job['lock_until'] < time()) evk_backup_cleanup($job);
    return true;
}

function evk_backup_rmdir(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        (is_dir($p) && !is_link($p)) ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

// =========================================================================
// LISTA KOPII I RETENCJA
// =========================================================================

/**
 * Opis kopii leży obok niej (`<nazwa>.zip.json`) — lista nie otwiera archiwów.
 */
function evk_backup_meta_write(string $zip, array $meta): void {
    file_put_contents($zip . '.json', wp_json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Kopie w katalogu, od najnowszej. Archiwum bez opisu dostaje opis z pliku. */
function evk_backup_list_archives(): array {
    $dir = evk_backup_dir();
    $kopie = [];
    foreach (glob($dir . '/*.zip') ?: [] as $zip) {
        $meta = is_file($zip . '.json') ? (json_decode((string) file_get_contents($zip . '.json'), true) ?: []) : [];
        $kopie[] = array_merge([
            'archive' => basename($zip), 'created_at' => (int) filemtime($zip), 'source' => 'upload',
            'pinned' => false,
        ], $meta, ['size' => (int) filesize($zip), 'archive' => basename($zip)]);
    }
    usort($kopie, static function ($a, $b) { return $b['created_at'] <=> $a['created_at']; });
    return $kopie;
}

/** Nazwa kopii z żądania → ścieżka, wyłącznie w katalogu kopii. null = odmowa. */
function evk_backup_archive_path(string $nazwa): ?string {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/', $nazwa)) return null;
    $p = evk_backup_dir() . '/' . $nazwa;
    return is_file($p) ? $p : null;
}

function evk_backup_delete_archive(string $nazwa): bool {
    $p = evk_backup_archive_path($nazwa);
    if (!$p) return false;
    @unlink($p . '.json');
    return @unlink($p);
}

function evk_backup_set_pinned(string $nazwa, bool $przypieta): bool {
    $p = evk_backup_archive_path($nazwa);
    if (!$p) return false;
    $meta = is_file($p . '.json') ? (json_decode((string) file_get_contents($p . '.json'), true) ?: []) : [];
    $meta['pinned'] = $przypieta;
    $meta += ['archive' => $nazwa, 'created_at' => (int) filemtime($p), 'source' => 'upload'];
    evk_backup_meta_write($p, $meta);
    // Kopia wysłana na Dysk Google — przypięcie jedzie też tam (gdrive.php).
    do_action('evk_backup_pinned', $nazwa, $przypieta, $meta);
    return true;
}

/**
 * Retencja: limit w SZTUKACH, liczone wyłącznie kopie ręczne i nocne.
 * Przypięte, sprzed przywrócenia i wgrane z zewnątrz nie liczą się i nie są
 * usuwane. Oddaje nazwy usuniętych.
 */
function evk_backup_rotate(): array {
    $limit = (int) evk_backup_get_settings()['retention_count'];
    $liczone = array_values(array_filter(evk_backup_list_archives(), static function ($k) {
        return empty($k['pinned']) && in_array($k['source'] ?? '', ['manual', 'schedule'], true);
    }));
    $usuniete = [];
    foreach (array_slice($liczone, $limit) as $k) {
        if (evk_backup_delete_archive($k['archive'])) $usuniete[] = $k['archive'];
    }
    return $usuniete;
}
