<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — kopia nocna i powiadomienia o nieudanej kopii.
 *
 * HARMONOGRAM. Jedno zdarzenie WP-Cron `evk_backup_nightly` z terminem
 * w argumencie, planowane za każdym razem na NASTĘPNE wystąpienie godziny
 * z ustawień, w strefie czasowej strony. Nie zdarzenie cykliczne „daily":
 * to przesuwa się o godzinę przy zmianie czasu i o każde spóźnienie.
 *
 * SPÓŹNIENIE. WP-Cron rusza przy odwiedzinach, więc o 03:00 na stronie bez
 * ruchu nic się nie dzieje — kopia rusza przy pierwszej wizycie po terminie
 * (decyzja z 1.227.0: nadrabiamy). Gdy do terminu dołożyła się cała doba,
 * WP-Cron w praktyce nie działa: kopia i tak rusza, ale idzie też
 * powiadomienie. Gdy WP-Cron jest wyłączony całkiem, zdarzenie nie wykona
 * się nigdy — to wykrywa admin_init (evk_backup_schedule_watchdog).
 *
 * POWIADOMIENIA (evk_backup_alert) — e-mail WYŁĄCZNIE na adresy wpisane
 * w ustawieniach (puste pole = bez maili) i komunikat w panelu WordPressa,
 * jeśli włączony. Komunikat znika po następnej udanej kopii albo po
 * zamknięciu krzyżykiem.
 */

const EVK_BACKUP_NIGHTLY_HOOK = 'evk_backup_nightly';
/** Plan: {time: '03:00', at: termin}. Stan maszyny — osobna opcja, nie ustawienie. */
const EVK_BACKUP_SCHED_OPTION = 'evk_backup_sched';
const EVK_BACKUP_ALERT_OPTION = 'evk_backup_alert';
/** Ponowienie, gdy o czasie kopii trwa inna praca (np. kopia ręczna). */
const EVK_BACKUP_RETRY_S      = 900;

/** Następny termin godziny HH:MM w strefie strony, później niż $po. */
function evk_backup_next_run(string $hhmm, ?int $po = null): int {
    $po = $po ?? time();
    [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => 0]);
    $d = (new DateTimeImmutable('@' . $po))->setTimezone(wp_timezone())->setTime($h, $m);
    if ($d->getTimestamp() <= $po) $d = $d->modify('+1 day')->setTime($h, $m);
    return $d->getTimestamp();
}

/**
 * Spóźnienie słownie, po polsku niezależnie od języka strony —
 * human_time_diff() mówi językiem WordPressa („2 days" w polskim zdaniu).
 */
function evk_backup_delay_label(int $s): string {
    $dni = intdiv($s, DAY_IN_SECONDS);
    if ($dni >= 1) return $dni === 1 ? '1 dzień' : $dni . ' dni';
    return max(1, intdiv($s, HOUR_IN_SECONDS)) . ' godz.';
}

/**
 * Dopasowuje zdarzenie do ustawień: brak harmonogramu → brak zdarzenia,
 * zmieniona godzina → nowy termin. Tanie (odczyt dwóch opcji z pamięci),
 * więc woła się je przy każdym żądaniu — zdarzenie usunięte przez kogoś
 * z listy cronów wraca samo.
 */
function evk_backup_schedule_sync(): void {
    $s = evk_backup_get_settings();
    $plan = get_option(EVK_BACKUP_SCHED_OPTION, []);
    $plan = is_array($plan) ? $plan : [];
    if (empty($s['enabled']) || empty($s['schedule_enabled'])) {
        if ($plan) {
            wp_unschedule_hook(EVK_BACKUP_NIGHTLY_HOOK);
            delete_option(EVK_BACKUP_SCHED_OPTION);
        }
        return;
    }
    $at = (int) ($plan['at'] ?? 0);
    if ($at && ($plan['time'] ?? '') === $s['schedule_time'] && wp_next_scheduled(EVK_BACKUP_NIGHTLY_HOOK, [$at])) return;
    evk_backup_schedule_plan($s['schedule_time']);
}

function evk_backup_schedule_plan(string $hhmm): int {
    wp_unschedule_hook(EVK_BACKUP_NIGHTLY_HOOK);
    $at = evk_backup_next_run($hhmm);
    wp_schedule_single_event($at, EVK_BACKUP_NIGHTLY_HOOK, [$at]);
    update_option(EVK_BACKUP_SCHED_OPTION, ['time' => $hhmm, 'at' => $at], false);
    return $at;
}

add_action('init', 'evk_backup_schedule_sync');
add_action('update_option_' . EVK_BACKUP_OPTION, 'evk_backup_schedule_sync');

/**
 * Kopia nocna. $planowany — termin, na który była zaplanowana; $proba > 0 —
 * ponowienie, bo o czasie trwała inna praca.
 */
function evk_backup_nightly_run(int $planowany = 0, int $proba = 0): void {
    $s = evk_backup_get_settings();
    if (empty($s['enabled']) || empty($s['schedule_enabled'])) return;

    // Następny termin NAJPIERW — błąd tej kopii nie może przerwać łańcucha nocy.
    if ($proba === 0) evk_backup_schedule_plan($s['schedule_time']);

    $spoznienie = $planowany ? time() - $planowany : 0;
    if ($proba === 0 && $spoznienie > DAY_IN_SECONDS) {
        evk_backup_alert('Kopia nocna ruszyła z opóźnieniem', sprintf(
            'Kopia zaplanowana na %s ruszyła dopiero teraz (%s po terminie). WP-Cron uruchamia się przy odwiedzinach strony — przy małym ruchu albo wyłączonym WP-Cron warto ustawić cron systemowy (instrukcja w zakładce Kopie zapasowe).',
            wp_date('Y-m-d H:i', $planowany), evk_backup_delay_label($spoznienie)));
    }

    $id = evk_backup_start('schedule');
    if (!is_wp_error($id)) return;

    if ($spoznienie < 12 * HOUR_IN_SECONDS) {
        wp_schedule_single_event(time() + EVK_BACKUP_RETRY_S, EVK_BACKUP_NIGHTLY_HOOK, [$planowany, $proba + 1]);
        return;
    }
    evk_backup_alert('Kopia nocna nie została zrobiona', sprintf(
        'Kopia zaplanowana na %s nie ruszyła: przez 12 godzin trwała inna kopia albo przywracanie (%s).',
        wp_date('Y-m-d H:i', $planowany), $id->get_error_message()));
}
add_action(EVK_BACKUP_NIGHTLY_HOOK, 'evk_backup_nightly_run', 10, 2);

/**
 * Zdarzenie, które nie wykonało się przez dobę po terminie — WP-Cron nie
 * działa wcale (DISABLE_WP_CRON bez crona systemowego). Raz na zaległy termin.
 */
function evk_backup_schedule_watchdog(): void {
    $plan = get_option(EVK_BACKUP_SCHED_OPTION, []);
    if (!is_array($plan) || empty($plan['at']) || !empty($plan['alerted'])) return;
    if (time() - (int) $plan['at'] < DAY_IN_SECONDS) return;
    $plan['alerted'] = 1;
    update_option(EVK_BACKUP_SCHED_OPTION, $plan, false);
    evk_backup_alert('Kopia nocna nie ruszyła', sprintf(
        'Kopia zaplanowana na %s nie ruszyła do tej pory — WP-Cron na tej stronie nie działa. Ustaw cron systemowy (instrukcja w zakładce Kopie zapasowe).',
        wp_date('Y-m-d H:i', (int) $plan['at'])));
}
add_action('admin_init', 'evk_backup_schedule_watchdog');

// =========================================================================
// POWIADOMIENIA
// =========================================================================

/** Adresy z ustawień — lista, puste pole = brak maili. */
function evk_backup_notify_addresses(): array {
    $s = evk_backup_get_settings();
    return array_values(array_filter(array_map('trim', explode(',', (string) $s['notify_address'])), 'is_email'));
}

/** E-mail na adresy z ustawień i komunikat w panelu (jeśli włączony). */
function evk_backup_alert(string $tytul, string $tresc): void {
    $s = evk_backup_get_settings();
    if (!empty($s['notify_notice'])) {
        update_option(EVK_BACKUP_ALERT_OPTION, ['title' => $tytul, 'text' => $tresc, 'time' => time()], false);
    }
    $adresy = evk_backup_notify_addresses();
    if ($adresy) {
        $link = add_query_arg(['page' => 'evoke-one', 'tab' => 'backup'], admin_url('options-general.php'));
        wp_mail($adresy, '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . $tytul,
            $tresc . "\n\nStrona: " . home_url() . "\nKopie zapasowe: " . $link);
    }
}

add_action('evk_backup_failed', static function (array $job, string $powod): void {
    if (($job['type'] ?? 'backup') !== 'backup') return;
    $rodzaj = ['schedule' => 'nocna', 'manual' => 'ręczna', 'snapshot' => 'sprzed przywrócenia'][$job['source'] ?? ''] ?? '';
    evk_backup_alert('Kopia zapasowa nie powiodła się', trim('Kopia ' . $rodzaj) . ' z ' . wp_date('Y-m-d H:i', (int) ($job['created_at'] ?? time()))
        . ' nie powiodła się: ' . $powod);
}, 10, 2);

// Udana kopia gasi komunikat — sprawa nieaktualna.
add_action('evk_backup_done', static function (): void {
    delete_option(EVK_BACKUP_ALERT_OPTION);
});

add_action('admin_notices', static function (): void {
    if (!current_user_can('manage_options')) return;
    $a = get_option(EVK_BACKUP_ALERT_OPTION);
    if (!is_array($a) || empty($a['text'])) return;
    $link = add_query_arg(['page' => 'evoke-one', 'tab' => 'backup'], admin_url('options-general.php'));
    ?>
    <div class="notice notice-error is-dismissible" data-evk-backup-alert data-nonce="<?php echo esc_attr(wp_create_nonce('evk_backup_alert')); ?>">
        <p><strong>Evoke ONE — <?php echo esc_html((string) $a['title']); ?>.</strong> <?php echo esc_html((string) $a['text']); ?>
        <a href="<?php echo esc_url($link); ?>">Kopie zapasowe</a></p>
    </div>
    <script>
    document.addEventListener('click', function (e) {
        var n = e.target.closest('[data-evk-backup-alert] .notice-dismiss');
        if (!n || typeof ajaxurl === 'undefined') return;
        var fd = new FormData();
        fd.append('action', 'evk_backup_alert_dismiss');
        fd.append('nonce', n.closest('[data-evk-backup-alert]').getAttribute('data-nonce'));
        fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
    });
    </script>
    <?php
});

add_action('wp_ajax_evk_backup_alert_dismiss', static function (): void {
    if (!current_user_can('manage_options') || !check_ajax_referer('evk_backup_alert', 'nonce', false)) {
        wp_send_json_error(null, 403);
    }
    delete_option(EVK_BACKUP_ALERT_OPTION);
    wp_send_json_success();
});
