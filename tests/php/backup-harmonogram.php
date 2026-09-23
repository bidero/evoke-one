<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — kopia nocna i powiadomienia (includes/backup/schedule.php) na
 * prawdziwym WordPressie (tools/testowy-wp.sh). Maile przechwytuje
 * `pre_wp_mail` — nic nie wychodzi.
 *
 *   php tests/php/backup-harmonogram.php <scenariusz>
 *
 * Scenariusze: termin, sync, uruchom, spoznienie, zajety, watchdog, blad, komunikat.
 */

$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick', 'schedule' => 'evk_backup_next_run',
];
require __DIR__ . '/_testowy-wp.php';

add_filter('evk_backup_loopback', '__return_false');
global $wpdb;
evk_backup_create_tables();

$maile = [];
add_filter('pre_wp_mail', static function ($nic, $atts) use (&$maile) { $maile[] = $atts; return true; }, 10, 2);

// Stan wyjściowy każdego scenariusza — i sprzątanie po nim.
$ustaw = static function (array $s): void {
    update_option(EVK_BACKUP_OPTION, array_merge(['enabled' => 1, 'schedule_enabled' => 1, 'schedule_time' => '03:00',
        'notify_address' => '', 'notify_notice' => 0], $s));
};
$sprzataj = static function () use ($wpdb): void {
    wp_unschedule_hook(EVK_BACKUP_NIGHTLY_HOOK);
    delete_option(EVK_BACKUP_SCHED_OPTION);
    delete_option(EVK_BACKUP_ALERT_OPTION);
    foreach ((array) $wpdb->get_col('SELECT id FROM ' . evk_backup_jobs_table() . " WHERE status IN ('queued','running','waiting')") as $id) {
        evk_backup_cancel((int) $id);
    }
    update_option('timezone_string', '');
};
$sprzataj();
register_shutdown_function($sprzataj);

/** Zdarzenia nocne w cronie: [termin => argumenty]. */
function zdarzenia(): array {
    $z = [];
    foreach ((array) _get_cron_array() as $czas => $haki) {
        foreach ((array) ($haki[EVK_BACKUP_NIGHTLY_HOOK] ?? []) as $e) $z[] = [(int) $czas, $e['args']];
    }
    return $z;
}

$scen = $argv[1] ?? '';
$wynik = [];
switch ($scen) {

    case 'termin':
        // Warszawa: 29 marca 2026 zmiana na czas letni, 25 października na zimowy.
        update_option('timezone_string', 'Europe/Warsaw');
        $lok = static function (int $ts): string { return wp_date('Y-m-d H:i', $ts); };
        $t = static function (string $lokalnie): int { return (new DateTimeImmutable($lokalnie, wp_timezone()))->getTimestamp(); };
        $wynik = [
            'przed_godzina' => $lok(evk_backup_next_run('03:00', $t('2026-06-10 01:00'))),
            'po_godzinie'   => $lok(evk_backup_next_run('03:00', $t('2026-06-10 03:00'))),
            'przed_letnim'  => $lok(evk_backup_next_run('03:00', $t('2026-03-28 04:00'))),
            'przed_zimowym' => $lok(evk_backup_next_run('03:00', $t('2026-10-24 04:00'))),
            'polnoc'        => $lok(evk_backup_next_run('00:00', $t('2026-12-31 23:59'))),
        ];
        break;

    case 'sync':
        $ustaw(['schedule_time' => '04:15']);
        evk_backup_schedule_sync();
        $plan = get_option(EVK_BACKUP_SCHED_OPTION);
        $wynik['zaplanowane'] = zdarzenia();
        $wynik['plan'] = $plan;
        $wynik['godzina'] = wp_date('H:i', (int) $plan['at']);
        evk_backup_schedule_sync();   // bez zmian — to samo zdarzenie
        $wynik['bez_zmian'] = zdarzenia() === $wynik['zaplanowane'];
        $ustaw(['schedule_time' => '05:30']);
        evk_backup_schedule_sync();
        $wynik['po_zmianie'] = array_map(static function ($e) { return wp_date('H:i', $e[0]); }, zdarzenia());
        $ustaw(['schedule_enabled' => 0]);
        evk_backup_schedule_sync();
        $wynik['po_wylaczeniu'] = [zdarzenia(), get_option(EVK_BACKUP_SCHED_OPTION)];
        $ustaw(['schedule_time' => '05:30']);
        evk_backup_schedule_sync();
        wp_unschedule_hook(EVK_BACKUP_NIGHTLY_HOOK);   // ktoś wyczyścił crona
        evk_backup_schedule_sync();
        $wynik['odtworzone'] = count(zdarzenia());
        $ustaw(['enabled' => 0]);
        evk_backup_schedule_sync();
        $wynik['modul_wylaczony'] = zdarzenia();
        break;

    case 'uruchom':
    case 'spoznienie':
        $ustaw(['notify_address' => 'kopie@example.com', 'notify_notice' => 1]);
        $planowany = $scen === 'uruchom' ? time() - 60 : time() - 2 * DAY_IN_SECONDS;
        /* Jak WP-Cron: zdarzenie zdjęte z listy PRZED wywołaniem haka. Zapis
           ustawień wyżej sam zaplanował termin (sync) — bez tego następny
           termin byłby na liście niezależnie od kopii nocnej (mutacja H3). */
        wp_unschedule_hook(EVK_BACKUP_NIGHTLY_HOOK);
        evk_backup_nightly_run($planowany);
        $job = evk_backup_job_active();
        $wynik = [
            'zadanie'  => $job ? $job['source'] : null,
            'nastepne' => array_map(static function ($e) { return $e[0] > time(); }, zdarzenia()),
            'alert'    => get_option(EVK_BACKUP_ALERT_OPTION),
            'maile'    => array_map(static function ($m) { return [$m['to'], $m['subject']]; }, $maile),
        ];
        break;

    case 'zajety':
        // Inna kopia trwa: ponowienie za 15 min, a po 12 h spóźnienia — powiadomienie.
        $ustaw(['notify_address' => 'kopie@example.com', 'notify_notice' => 1]);
        evk_backup_start('manual');
        $planowany = time() - 60;
        evk_backup_nightly_run($planowany);
        $wynik['ponowienie'] = array_values(array_filter(zdarzenia(), static function ($e) { return count($e[1]) === 2; }));
        $wynik['ponowienie_za_s'] = $wynik['ponowienie'] ? $wynik['ponowienie'][0][0] - time() : null;
        $wynik['alert_po_ponowieniu'] = get_option(EVK_BACKUP_ALERT_OPTION);
        evk_backup_nightly_run(time() - 13 * HOUR_IN_SECONDS, 5);
        $wynik['alert_po_12h'] = get_option(EVK_BACKUP_ALERT_OPTION);
        $wynik['maile'] = count($maile);
        break;

    case 'watchdog':
        $ustaw(['notify_address' => 'kopie@example.com', 'notify_notice' => 1]);
        update_option(EVK_BACKUP_SCHED_OPTION, ['time' => '03:00', 'at' => time() - 3600]);
        evk_backup_schedule_watchdog();
        $wynik['godzine_po'] = [get_option(EVK_BACKUP_ALERT_OPTION), count($maile)];
        update_option(EVK_BACKUP_SCHED_OPTION, ['time' => '03:00', 'at' => time() - 2 * DAY_IN_SECONDS]);
        evk_backup_schedule_watchdog();
        evk_backup_schedule_watchdog();   // drugi raz: bez drugiego maila
        $wynik['doba_po'] = [get_option(EVK_BACKUP_ALERT_OPTION)['title'] ?? null, count($maile)];
        break;

    case 'blad':
        $wynik['adresy'] = evk_backup_sanitize(['notify_address' => 'a@example.com; zly-adres, b@example.com a@example.com'])['notify_address'];
        $job = ['id' => 0, 'type' => 'backup', 'source' => 'schedule', 'created_at' => time(), 'state' => [], 'next_job_id' => 0];

        $ustaw(['notify_address' => 'a@example.com, b@example.com', 'notify_notice' => 1]);
        do_action('evk_backup_failed', $job, 'Brak miejsca na dysku');
        $wynik['z_mailem'] = [array_map(static function ($m) { return [$m['to'], $m['subject'], strpos($m['message'], 'Brak miejsca') !== false]; }, $maile),
                              get_option(EVK_BACKUP_ALERT_OPTION)['text'] ?? null];
        do_action('evk_backup_done', $job, []);
        $wynik['po_udanej'] = get_option(EVK_BACKUP_ALERT_OPTION);

        $maile = [];
        $ustaw(['notify_address' => '', 'notify_notice' => 0]);
        do_action('evk_backup_failed', $job, 'x');
        $wynik['bez_ustawien'] = [count($maile), get_option(EVK_BACKUP_ALERT_OPTION)];

        $ustaw(['notify_address' => 'a@example.com', 'notify_notice' => 1]);
        do_action('evk_backup_failed', ['type' => 'restore'] + $job, 'x');
        $wynik['przywracanie'] = [count($maile), get_option(EVK_BACKUP_ALERT_OPTION)];
        break;

    case 'domyslne':
        /* Zapis spoza formularza (przełącznik modułu przez AJAX) na świeżej
           instalacji: do 1.227.0 zerował wykluczenia i komunikat. */
        $zapis = get_option(EVK_BACKUP_OPTION);
        delete_option(EVK_BACKUP_OPTION);
        $wynik['swieza'] = evk_backup_get_settings()['notify_notice'];
        $po = evk_backup_sanitize(['enabled' => 1]);
        $wynik['przelacznik_swieza'] = [$po['exclusions'] === implode("\n", evk_backup_default_exclusions()), $po['notify_notice'], $po['retention_count']];
        // Zapisane „wyłączony" zostaje (decyzja z 1.227.1), także po przełączniku.
        update_option(EVK_BACKUP_OPTION, ['enabled' => 0, 'notify_notice' => 0, 'exclusions' => 'moje/']);
        $po = evk_backup_sanitize(['enabled' => 1, 'notify_notice' => 0, 'exclusions' => 'moje/']);
        $wynik['przelacznik_zapisane'] = [$po['notify_notice'], $po['exclusions']];
        // Formularz bez pola checkboxa (odznaczony) — wyłącza.
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1, 'notify_notice' => 1]);
        $po = evk_backup_sanitize(['_formularz' => '1', 'retention_count' => '5', 'exclusions' => 'x/']);
        $wynik['formularz_odznaczony'] = [$po['notify_notice'], $po['enabled'], isset($po['_formularz'])];
        update_option(EVK_BACKUP_OPTION, $zapis);
        break;

    case 'komunikat':
        update_option(EVK_BACKUP_ALERT_OPTION, ['title' => 'Kopia zapasowa nie powiodła się', 'text' => 'Test <b>komunikatu</b>', 'time' => time()]);
        $admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
        wp_set_current_user($admin ? $admin->ID : 0);
        ob_start(); do_action('admin_notices'); $html = (string) ob_get_clean();
        $sub = wp_insert_user(['user_login' => 'evk-sub-' . getmypid(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        wp_set_current_user((int) $sub);
        ob_start(); do_action('admin_notices'); $html_sub = (string) ob_get_clean();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $sub);
        $wynik = [
            'jest'     => strpos($html, 'data-evk-backup-alert') !== false,
            'zamykany' => strpos($html, 'is-dismissible') !== false,
            'escape'   => strpos($html, '&lt;b&gt;komunikatu') !== false && strpos($html, '<b>komunikatu') === false,
            'link'     => strpos($html, 'tab=backup') !== false,
            'dla_subskrybenta' => strpos($html_sub, 'data-evk-backup-alert') !== false,
        ];
        break;
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
