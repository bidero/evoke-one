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
    $etapy = ['init' => 1, 'db' => 1, 'list' => 2, 'pack' => 3, 'finalize' => 3, 'done' => 3];
    $proc = $job['progress_total'] > 0 ? (int) floor(100 * $job['progress_done'] / $job['progress_total']) : 0;
    // Szacunek wierszy bazy bywa zaniżony — 100% tylko po faktycznym końcu.
    if ($job['status'] !== 'done') $proc = min(99, $proc);
    return [
        'id'       => $job['id'],
        'status'   => $job['status'],
        'phase'    => $job['phase'],
        'label'    => evk_backup_phase_label($job['phase']),
        'step'     => $etapy[$job['phase']] ?? 1,
        'steps'    => 3,
        'percent'  => $job['status'] === 'done' ? 100 : $proc,
        'archive'  => $job['archive'],
        'error'    => (string) $job['error'],
        'ticks'    => $job['ticks'],
        'budget_s' => round($job['budget_ms'] / 1000, 1),
        'log'      => array_slice(explode("\n", (string) $job['log']), -12),
    ];
}

add_action('wp_ajax_evk_backup_start', function () {
    evk_backup_ajax_guard();
    $id = evk_backup_start('manual');
    if (is_wp_error($id)) wp_send_json_error(['msg' => $id->get_error_message()]);
    wp_send_json_success(['job' => evk_backup_job_public(evk_backup_job_get($id))]);
});

/**
 * Stan zadania — i POPYCHANIE: zadanie, którego od 3 s nikt nie ruszył
 * (lock wolny, znak życia stary), dostaje krok z tego żądania. Tak kopia idzie
 * dalej przy otwartej zakładce nawet wtedy, gdy serwer blokuje żądania do
 * samego siebie. Krótszy budżet (8 s), żeby pasek postępu żył.
 */
add_action('wp_ajax_evk_backup_status', function () {
    evk_backup_ajax_guard();
    $id = absint($_POST['id'] ?? 0);
    $job = $id ? evk_backup_job_get($id) : evk_backup_job_active();
    if ($job && in_array($job['status'], ['queued', 'running'], true)
        && $job['lock_until'] === 0 && time() - $job['heartbeat'] >= 3) {
        evk_backup_tick($job['id'], 8000);
        $job = evk_backup_job_get($job['id']);
    }
    wp_send_json_success(['job' => evk_backup_job_public($job)]);
});

add_action('wp_ajax_evk_backup_cancel', function () {
    evk_backup_ajax_guard();
    if (!evk_backup_cancel(absint($_POST['id'] ?? 0))) wp_send_json_error(['msg' => 'Tego zadania nie da się już anulować.']);
    wp_send_json_success();
});

add_action('wp_ajax_evk_backup_delete', function () {
    evk_backup_ajax_guard();
    if (!evk_backup_delete_archive(sanitize_file_name(wp_unslash($_POST['archive'] ?? '')))) {
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
    wp_send_json_success(['html' => evk_backup_render_list()]);
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
    evk_backup_tick($id);
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
    return ['manual' => 'ręczna', 'schedule' => 'nocna', 'snapshot' => 'przed przywróceniem', 'upload' => 'wgrana'][$s] ?? $s;
}

function evk_backup_render_list(): string {
    $kopie = evk_backup_list_archives();
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
                <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $k['created_at'])); ?>
                    <?php if ($pin): ?><span class="evo-badge" title="Przypięta — retencja jej nie usuwa">przypięta</span><?php endif; ?></td>
                <td><?php echo esc_html(evk_backup_source_label((string) $k['source'])); ?></td>
                <td><?php echo esc_html(evk_backup_bytes_label((float) $k['size'])); ?></td>
                <td class="evo-muted"><?php
                    echo isset($k['files']) ? esc_html(sprintf('%d plików, %d wierszy bazy', (int) $k['files'], (int) ($k['db_rows'] ?? 0))) : '—';
                ?></td>
                <td class="is-right">
                    <a class="button button-small" href="<?php echo esc_url(evk_backup_download_url($n)); ?>" data-evk-backup-download>Pobierz</a>
                    <button type="button" class="button button-small" data-evk-backup-pin="<?php echo $pin ? '0' : '1'; ?>"><?php echo $pin ? 'Odepnij' : 'Przypnij'; ?></button>
                    <button type="button" class="button button-small evo-btn-plain is-danger" data-evk-backup-delete>Usuń</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
    return (string) ob_get_clean();
}
