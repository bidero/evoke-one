<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — cienka warstwa nad silnikiem: żądania z panelu,
 * żądanie zwrotne kroku, pobieranie kopii i test napędu w tle.
 *
 * Wszystko z panelu: manage_options + nonce `evk_backup`. Żądanie zwrotne
 * kroku przychodzi BEZ zalogowania (to serwer woła sam siebie), więc jego
 * jedynym poświadczeniem jest podpis HMAC kluczem z opcji `evk_backup_key`.
 */

function evk_backup_ajax_guard(): void {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'Brak uprawnień.'], 403);
    if (!check_ajax_referer('evk_backup', 'nonce', false)) wp_send_json_error(['msg' => 'Sesja wygasła — odśwież stronę.'], 403);
}

/** Zadanie w postaci dla panelu — bez stanu wewnętrznego. */
function evk_backup_job_public(?array $job): ?array {
    if (!$job) return null;
    /* Przywracanie czekające na kopię sprzed niego pokazuje postęp TEJ kopii —
       panel śledzi jedno zadanie od kliknięcia do końca. */
    if ($job['status'] === 'waiting') {
        $przed = evk_backup_job_before($job['id']);
        if ($przed) {
            $pub = evk_backup_job_public($przed);
            if ($pub) {
                $pub['id'] = $job['id'];
                $pub['type'] = 'restore';
                $pub['status'] = 'waiting';
                $pub['label'] = 'kopia obecnego stanu: ' . $pub['label'];
                return $pub;
            }
        }
    }
    $przywracanie = $job['type'] === 'restore';
    $etapy = [
        'restore'  => ['r_check' => 1, 'r_extract' => 1, 'r_db' => 2, 'r_files' => 3, 'r_sweep' => 3, 'r_swap' => 4, 'done' => 4],
        'upload'   => ['u_session' => 1, 'u_send' => 1, 'u_finish' => 2, 'done' => 2],
        'download' => ['d_fetch' => 1, 'done' => 1],
    ][$job['type']] ?? ['init' => 1, 'db' => 1, 'list' => 2, 'pack' => 3, 'finalize' => 3, 'done' => 3];
    $proc = $job['progress_total'] > 0 ? (int) floor(100 * $job['progress_done'] / $job['progress_total']) : 0;
    // Szacunek wierszy bazy bywa zaniżony — 100% tylko po faktycznym końcu.
    if ($job['status'] !== 'done') $proc = min(99, $proc);
    return [
        'id'       => $job['id'],
        'type'     => $job['type'],
        'status'   => $job['status'],
        'phase'    => $job['phase'],
        'label'    => evk_backup_phase_label($job['phase']),
        'step'     => $etapy[$job['phase']] ?? 1,
        'steps'    => max($etapy),
        'percent'  => $job['status'] === 'done' ? 100 : $proc,
        'archive'  => $job['archive'],
        'error'    => (string) $job['error'],
        'ticks'    => $job['ticks'],
        'budget_s' => round($job['budget_ms'] / 1000, 1),
        'detail'   => evk_backup_job_detail($job),
        // Pobieranie z Dysku czeka na pierwszy bajt: sekundy (panel pokazuje pasek w ruchu) albo null.
        'czeka'    => function_exists('evk_gdrive_czeka') ? evk_gdrive_czeka($job) : null,
        // Przywracanie: zakres decyduje, czy po końcu trzeba się zalogować od nowa.
        'scope'    => $przywracanie ? (string) ($job['state']['scope'] ?? 'all') : '',
        'log'      => array_slice(explode("\n", (string) $job['log']), -12),
    ];
}

/**
 * Szczegół etapu w liczbach, które zmieniają się przy każdej porcji —
 * procent bywa przez chwilę ten sam, a „ile już" rośnie zawsze.
 */
function evk_backup_job_detail(array $job): string {
    $s = $job['state'];
    switch ($job['phase']) {
        case 'db':
            return sprintf('%s wierszy z ok. %s', number_format_i18n((int) ($s['db']['rows'] ?? 0)),
                number_format_i18n((int) ($s['db']['estimate'] ?? 0)));
        case 'list':
            return sprintf('znaleziono %s plików (%s)', number_format_i18n((int) ($s['list']['files'] ?? 0)),
                evk_backup_bytes_label((float) ($s['list']['size'] ?? 0)));
        case 'd_fetch':
            $czeka = function_exists('evk_gdrive_czeka') ? evk_gdrive_czeka($job) : null;
            if ($czeka !== null) return evk_gdrive_czeka_opis($czeka);
            return sprintf('%s z %s', evk_backup_bytes_label((float) $job['progress_done']),
                evk_backup_bytes_label((float) $job['progress_total']));
        case 'pack':
        case 'finalize':
        case 'r_extract':
        case 'u_send':
            return sprintf('%s z %s', evk_backup_bytes_label((float) $job['progress_done']),
                evk_backup_bytes_label((float) $job['progress_total']));
        case 'r_db':
            return sprintf('tabela %s, %s wierszy', number_format_i18n(count((array) ($s['db']['tables'] ?? []))),
                number_format_i18n((int) ($s['db']['rows'] ?? 0)));
        case 'r_files':
            return sprintf('%s plików na miejscu', number_format_i18n((int) ($s['mv']['files'] ?? 0)));
    }
    return '';
}

add_action('wp_ajax_evk_backup_start', function () {
    evk_backup_ajax_guard();
    $id = evk_backup_start('manual');
    if (is_wp_error($id)) wp_send_json_error(['msg' => $id->get_error_message()]);
    wp_send_json_success(['job' => evk_backup_job_public(evk_backup_job_get($id))]);
});

/**
 * POPYCHANIE: zadanie, którego od 3 s nikt nie ruszył (lock wolny, znak życia
 * stary), dostaje krok z panelu. Tak kopia idzie dalej przy otwartej zakładce
 * nawet wtedy, gdy serwer blokuje żądania do samego siebie. Krótszy budżet
 * (8 s).
 *
 * Krok idzie OSOBNYM żądaniem (evk_backup_nudge), nie w pytaniu o stan. Do
 * 1.229.3 robiło go samo pytanie o stan, więc odpowiedź przychodziła dopiero
 * po kroku, a zapisy postępu w jego trakcie czytał nikt. Zgłoszone z evoke.pl:
 * „pasek się nie odświeża". Zmierzone w teście panelu (pobranie z Dysku bez
 * pracy w tle, ~6,5 s): pasek 0 → 100, nic pomiędzy.
 */
/** Id zadania do popchnięcia z panelu (także kopii, na którą czeka przywracanie) albo 0. */
function evk_backup_needs_nudge(?array $job): int {
    if (!$job) return 0;
    $praca = $job['status'] === 'waiting' ? evk_backup_job_before($job['id']) : $job;
    return $praca && in_array($praca['status'], ['queued', 'running'], true)
        && $praca['lock_until'] === 0 && time() - $praca['heartbeat'] >= 3 ? (int) $praca['id'] : 0;
}

function evk_backup_nudge(?array $job): void {
    $id = evk_backup_needs_nudge($job);
    if ($id) evk_backup_tick($id, 8000, 'z panelu');
}

/** Stan dla panelu z podpowiedzią, czy wysłać popchnięcie. */
function evk_backup_status_payload(?array $job): array {
    $pub = evk_backup_job_public($job);
    if ($pub) $pub['popchnij'] = evk_backup_needs_nudge($job) > 0;
    return ['job' => $pub];
}

add_action('wp_ajax_evk_backup_status', function () {
    evk_backup_ajax_guard();
    $id = absint($_POST['id'] ?? 0);
    wp_send_json_success(evk_backup_status_payload($id ? evk_backup_job_get($id) : evk_backup_job_active()));
});

add_action('wp_ajax_evk_backup_nudge', function () {
    evk_backup_ajax_guard();
    $id = absint($_POST['id'] ?? 0);
    evk_backup_nudge($id ? evk_backup_job_get($id) : evk_backup_job_active());
    wp_send_json_success();
});

// =========================================================================
// PRZYWRACANIE
// =========================================================================

/**
 * Stan przywracania BEZ SESJI. Podmiana bazy podmienia użytkowników i sesje —
 * osoba, która kliknęła „Przywróć", jest od tej chwili wylogowana, a pasek
 * postępu ma dojść do końca. Poświadczeniem jest token zadania (HMAC kluczem
 * instalacji, który przywracanie zachowuje), wydany przy starcie.
 */
function evk_restore_status_token(int $id): string {
    return hash_hmac('sha256', 'evk-restore-status-' . $id, evk_backup_loopback_key());
}

add_action('wp_ajax_evk_backup_restore_info', function () {
    evk_backup_ajax_guard();
    try {
        wp_send_json_success(evk_restore_info(sanitize_file_name(wp_unslash($_POST['archive'] ?? ''))));
    } catch (\RuntimeException $e) {
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
});

add_action('wp_ajax_evk_backup_restore_start', function () {
    evk_backup_ajax_guard();
    // Słowo z okna potwierdzenia sprawdzane też tu — nie tylko przyciskiem w przeglądarce.
    if (trim((string) wp_unslash($_POST['confirm'] ?? '')) !== 'PRZYWRÓĆ') {
        wp_send_json_error(['msg' => 'Wpisz PRZYWRÓĆ, żeby potwierdzić.']);
    }
    $id = evk_restore_start(sanitize_file_name(wp_unslash($_POST['archive'] ?? '')),
        sanitize_key(wp_unslash($_POST['scope'] ?? 'all')), !empty($_POST['mirror']), !empty($_POST['snapshot']));
    if (is_wp_error($id)) wp_send_json_error(['msg' => $id->get_error_message()]);
    wp_send_json_success(['job' => evk_backup_job_public(evk_backup_job_get($id)), 'token' => evk_restore_status_token($id)]);
});

/** Przywracanie wskazane przez id i token z żądania — albo 403/404 i koniec. */
function evk_restore_job_by_token(): array {
    $id = absint($_POST['id'] ?? 0);
    $token = (string) wp_unslash($_POST['token'] ?? '');
    if (!$id || !hash_equals(evk_restore_status_token($id), $token)) {
        wp_send_json_error(['msg' => 'Zły token przywracania.'], 403);
    }
    $job = evk_backup_job_get($id);
    if (!$job || $job['type'] !== 'restore') wp_send_json_error(['msg' => 'Nie ma takiego przywracania.'], 404);
    return $job;
}

function evk_restore_status_handler(): void {
    wp_send_json_success(evk_backup_status_payload(evk_restore_job_by_token()));
}
add_action('wp_ajax_nopriv_evk_backup_restore_status', 'evk_restore_status_handler');
add_action('wp_ajax_evk_backup_restore_status', 'evk_restore_status_handler');

function evk_restore_nudge_handler(): void {
    evk_backup_nudge(evk_restore_job_by_token());
    wp_send_json_success();
}
add_action('wp_ajax_nopriv_evk_backup_restore_nudge', 'evk_restore_nudge_handler');
add_action('wp_ajax_evk_backup_restore_nudge', 'evk_restore_nudge_handler');

add_action('wp_ajax_evk_backup_cancel', function () {
    evk_backup_ajax_guard();
    if (!evk_backup_cancel(absint($_POST['id'] ?? 0))) wp_send_json_error(['msg' => 'Tego zadania nie da się już anulować.']);
    wp_send_json_success();
});

add_action('wp_ajax_evk_backup_delete', function () {
    evk_backup_ajax_guard();
    $nazwa = sanitize_file_name(wp_unslash($_POST['archive'] ?? ''));
    $trwa = evk_backup_job_active();
    if ($trwa && in_array($trwa['type'], ['restore', 'upload'], true) && ($trwa['state']['archive'] ?? '') === $nazwa) {
        wp_send_json_error(['msg' => $trwa['type'] === 'restore' ? 'Z tej kopii właśnie trwa przywracanie.' : 'Ta kopia właśnie jedzie na Dysk Google.']);
    }
    if (!evk_backup_delete_archive($nazwa)) {
        wp_send_json_error(['msg' => 'Nie ma takiej kopii.']);
    }
    wp_send_json_success(['html' => evk_backup_render_list()]);
});

add_action('wp_ajax_evk_backup_pin', function () {
    evk_backup_ajax_guard();
    $ok = evk_backup_set_pinned(sanitize_file_name(wp_unslash($_POST['archive'] ?? '')), !empty($_POST['pinned']));
    if (!$ok) wp_send_json_error(['msg' => 'Nie ma takiej kopii.']);
    wp_send_json_success(['html' => evk_backup_render_list()]);
});

add_action('wp_ajax_evk_backup_list', function () {
    evk_backup_ajax_guard();
    $wgrane = evk_backup_import_scan();
    // Pliki, które jeszcze czekają (zmienione w ostatniej minucie — mogą się wgrywać).
    $czekaja = array_map('basename', glob(evk_backup_import_dir() . '/*.zip') ?: []);
    wp_send_json_success(['html' => evk_backup_render_list(), 'moved' => $wgrane, 'waiting' => $czekaja]);
});

// =========================================================================
// ŻĄDANIE ZWROTNE KROKU (bez zalogowania, podpis HMAC)
// =========================================================================

/**
 * Odpowiedź dla wołającego kończymy OD RAZU (fastcgi_finish_request na FPM,
 * litespeed_finish_request na LiteSpeed) — po drugiej stronie i tak nikt nie
 * czeka, a serwer, który ubija proces po rozłączeniu klienta, dostaje
 * zamkniętą odpowiedź zamiast zerwanego połączenia. Czy to wystarcza na danym
 * serwerze, mierzy test napędu w tle (niżej).
 */
function evk_backup_finish_request(): void {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
}

function evk_backup_loopback_handler(): void {
    $id  = absint($_POST['id'] ?? 0);
    $sig = (string) wp_unslash($_POST['sig'] ?? '');
    if (!$id || !hash_equals(evk_backup_loopback_sig($id), $sig)) {
        status_header(403);
        wp_die('', '', ['response' => 403]);
    }
    evk_backup_finish_request();
    evk_backup_tick($id, null, 'w tle');
    wp_die('', '', ['response' => 200]);
}
add_action('wp_ajax_nopriv_evk_backup_loopback', 'evk_backup_loopback_handler');
add_action('wp_ajax_evk_backup_loopback', 'evk_backup_loopback_handler');

// =========================================================================
// TEST NAPĘDU W TLE — ile żyje żądanie do samego siebie
// =========================================================================

/**
 * LiteSpeed (i niektóre konfiguracje Apache/nginx) potrafią ubić PHP, gdy
 * klient zamknie połączenie — a żądanie zwrotne zamyka je natychmiast. Czy
 * tak jest NA TYM serwerze, nie zgadujemy: próbnik w tle zapisuje znak życia
 * co sekundę przez 30 s, panel odczytuje, ile przeżył.
 */
const EVK_BACKUP_PROBE_SECONDS = 30;

add_action('wp_ajax_evk_backup_probe_start', function () {
    evk_backup_ajax_guard();
    $token = bin2hex(random_bytes(8));
    update_option('evk_backup_probe', ['token' => $token, 'start' => microtime(true), 'last' => 0.0], false);
    $wynik = wp_remote_post(admin_url('admin-ajax.php'), [
        'blocking' => false, 'timeout' => 0.01, 'sslverify' => false,
        'body' => ['action' => 'evk_backup_probe_run', 'token' => $token,
                   'sig' => hash_hmac('sha256', 'evk-backup-probe-' . $token, evk_backup_loopback_key())],
    ]);
    wp_send_json_success(['sent' => !is_wp_error($wynik), 'seconds' => EVK_BACKUP_PROBE_SECONDS]);
});

function evk_backup_probe_handler(): void {
    $token = (string) wp_unslash($_POST['token'] ?? '');
    $sig   = (string) wp_unslash($_POST['sig'] ?? '');
    if ($token === '' || !hash_equals(hash_hmac('sha256', 'evk-backup-probe-' . $token, evk_backup_loopback_key()), $sig)) {
        status_header(403);
        wp_die('', '', ['response' => 403]);
    }
    evk_backup_finish_request();
    if (function_exists('set_time_limit')) @set_time_limit(EVK_BACKUP_PROBE_SECONDS + 30);
    $p = get_option('evk_backup_probe', []);
    if (($p['token'] ?? '') !== $token) wp_die();
    for ($i = 0; $i < EVK_BACKUP_PROBE_SECONDS; $i++) {
        $p['last'] = microtime(true);
        update_option('evk_backup_probe', $p, false);
        sleep(1);
    }
    $p['last'] = microtime(true);
    $p['done'] = true;
    update_option('evk_backup_probe', $p, false);
    wp_die();
}
add_action('wp_ajax_nopriv_evk_backup_probe_run', 'evk_backup_probe_handler');
add_action('wp_ajax_evk_backup_probe_run', 'evk_backup_probe_handler');

add_action('wp_ajax_evk_backup_probe_status', function () {
    evk_backup_ajax_guard();
    wp_cache_delete('evk_backup_probe', 'options');
    $p = get_option('evk_backup_probe', []);
    $zyl = !empty($p['last']) ? max(0.0, (float) $p['last'] - (float) $p['start']) : 0.0;
    wp_send_json_success([
        'alive_s'  => round($zyl, 1),
        'done'     => !empty($p['done']),
        'since_s'  => !empty($p['start']) ? round(microtime(true) - (float) $p['start'], 1) : 0,
        'seconds'  => EVK_BACKUP_PROBE_SECONDS,
    ]);
});

// =========================================================================
// POBIERANIE (admin_init — nagłówki HTTP przed jakimkolwiek wyjściem)
// =========================================================================

function evk_backup_download_url(string $nazwa): string {
    return wp_nonce_url(add_query_arg(['page' => 'evoke-one', 'tab' => 'backup', 'evk_backup_download' => $nazwa],
        admin_url('options-general.php')), 'evk_backup_download_' . $nazwa);
}

add_action('admin_init', function () {
    if (empty($_GET['evk_backup_download'])) return;
    $nazwa = (string) wp_unslash($_GET['evk_backup_download']);
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response' => 403]);
    check_admin_referer('evk_backup_download_' . $nazwa);
    $p = evk_backup_archive_path($nazwa);
    if (!$p) wp_die('Nie ma takiej kopii.', '', ['response' => 404]);

    // Strumieniem, porcjami — kopia bywa większa niż limit pamięci PHP.
    while (ob_get_level()) ob_end_clean();
    if (function_exists('set_time_limit')) @set_time_limit(0);
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nazwa . '"');
    header('Content-Length: ' . filesize($p));
    $fh = fopen($p, 'rb');
    while ($fh && !feof($fh)) {
        echo fread($fh, 1048576);
        flush();
    }
    if ($fh) fclose($fh);
    exit;
});

// =========================================================================
// LISTA KOPII (HTML — ten sam w zakładce i w odpowiedziach AJAX)
// =========================================================================

function evk_backup_source_label(string $s): string {
    return ['manual' => 'ręczna', 'schedule' => 'nocna', 'snapshot' => 'przed przywróceniem', 'upload' => 'wgrana',
            'gdrive' => 'z Dysku Google'][$s] ?? $s;
}

function evk_backup_render_list(): string {
    $kopie = evk_backup_list_archives();
    $dysk = function_exists('evk_gdrive_connected') && evk_gdrive_connected();
    ob_start();
    if (!$kopie) {
        echo '<p class="evo-empty evo-muted" data-evk-backup-empty>Nie ma jeszcze żadnej kopii.</p>';
        return (string) ob_get_clean();
    }
    ?>
    <div class="evo-tbl-wrap"><table class="evo-table evk-backup-lista">
        <thead><tr><th>Data</th><th>Rodzaj</th><th>Rozmiar</th><th>Zawartość</th><th class="is-right">Akcje</th></tr></thead>
        <tbody>
        <?php foreach ($kopie as $k): $n = (string) $k['archive']; $pin = !empty($k['pinned']); ?>
            <tr data-archive="<?php echo esc_attr($n); ?>">
                <td class="evk-backup-data"><?php echo esc_html(wp_date('Y-m-d H:i', (int) $k['created_at'])); ?>
                    <?php if ($pin): ?><span class="evo-badge" title="Przypięta — retencja jej nie usuwa">przypięta</span><?php endif; ?>
                    <?php if (!empty($k['drive_id'])): ?><span class="evo-badge evk-badge-dysk" data-evk-backup-on-drive title="Kopia jest też na Dysku Google">na Dysku</span><?php endif; ?></td>
                <td data-label="Rodzaj"><?php echo esc_html(evk_backup_source_label((string) $k['source'])); ?></td>
                <td data-label="Rozmiar"><?php echo esc_html(evk_backup_bytes_label((float) $k['size'])); ?></td>
                <td data-label="Zawartość" class="evo-muted"><?php
                    echo isset($k['files']) ? esc_html(sprintf('%d plików, %d wierszy bazy', (int) $k['files'], (int) ($k['db_rows'] ?? 0))) : '—';
                ?></td>
                <td class="is-right evk-backup-akcje">
                    <?php /* Przypięcie jako SAMA pinezka, pierwsza: z czterema opisanymi
                             przyciskami rząd nie mieścił się na telefonie (zgłoszone,
                             1.227.1). Nazwa w aria-label i dymku, stan w aria-pressed. */ ?>
                    <button type="button" class="button button-small evk-backup-pin<?php echo $pin ? ' is-pinned' : ''; ?>"
                            data-evk-backup-pin="<?php echo $pin ? '0' : '1'; ?>" aria-pressed="<?php echo $pin ? 'true' : 'false'; ?>"
                            aria-label="<?php echo $pin ? 'Odepnij kopię' : 'Przypnij kopię'; ?>"
                            title="<?php echo $pin ? 'Odepnij — retencja znów może ją usunąć' : 'Przypnij — retencja jej nie usunie'; ?>"><span class="dashicons dashicons-admin-post" aria-hidden="true"></span></button>
                    <?php if ($dysk && empty($k['drive_id'])): /* Sama ikona, jak pinezka — rząd musi się zmieścić na telefonie. */ ?>
                    <button type="button" class="button button-small evk-backup-ikona" data-evk-backup-drive
                            aria-label="Wyślij na Dysk Google" title="Wyślij na Dysk Google"><span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span></button>
                    <?php endif; ?>
                    <a class="button button-small" href="<?php echo esc_url(evk_backup_download_url($n)); ?>" data-evk-backup-download>Pobierz</a>
                    <button type="button" class="button button-small" data-evk-backup-restore>Przywróć</button>
                    <button type="button" class="button button-small evk-backup-usun" data-evk-backup-delete>Usuń</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
    return (string) ob_get_clean();
}
