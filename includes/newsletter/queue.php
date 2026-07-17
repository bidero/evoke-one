<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Silnik kolejkowania (WP-Cron)
 */

// =========================================================================
// HOOK WP-CRON
// =========================================================================

add_action('evk_nl_process_batch', 'evk_nl_process_batch');

function evk_nl_process_batch(int $campaign_id): void {
    // Sprawdź czy moduł aktywny
    $opts = get_option('evk_newsletter', []);
    if (empty($opts['enabled'])) return;

    $campaign = evk_nl_get_campaign($campaign_id);
    if (!$campaign || $campaign['status'] === 'done' || $campaign['status'] === 'paused') return;

    // Zmień scheduled → sending gdy cron faktycznie odpala
    if (in_array($campaign['status'], ['scheduled', 'draft'], true)) {
        evk_nl_update_campaign($campaign_id, ['status' => 'sending']);
        $campaign['status'] = 'sending';
    }

    $template = evk_nl_get_template((int) $campaign['template_id']);
    if (!$template) {
        evk_nl_log($campaign_id, 'error', null, ['msg' => 'Brak szablonu ID: ' . $campaign['template_id']]);
        evk_nl_update_campaign($campaign_id, ['status' => 'done']);
        return;
    }

    $batch_size = max(1, (int) $campaign['batch_size']);
    $q          = evk_nl_table('queue');

    global $wpdb;

    // Pobierz batch pending
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $q WHERE campaign_id=%d AND status='pending' ORDER BY id ASC LIMIT %d",
        $campaign_id, $batch_size
    ), ARRAY_A);

    if (empty($rows)) {
        // Brak pending — kampania zakończona
        evk_nl_update_campaign($campaign_id, ['status' => 'done']);
        evk_nl_log($campaign_id, 'sent', null, ['msg' => 'Kampania zakończona.']);
        return;
    }

    @set_time_limit(300);

    $interval_seconds = max(60, (int) $campaign['batch_interval'] * 60);

    // Bezpiecznik: po serii błędów pod rząd (zwykle limit wysyłki u dostawcy
    // SMTP) przerwij paczkę zamiast dobijać się do serwera — reszta maili
    // zostaje pending i pójdzie w kolejnej paczce bez spalania prób.
    $max_consecutive = max(1, (int) apply_filters('evk_nl_max_consecutive_failures', 3));

    // Rozłóż maile równomiernie w czasie zamiast wysyłać serią — filtry
    // antyspamowe ("too quickly") reagują na chwilowe tempo, nie na średnią.
    // Okno rozkładu jest przycięte, bo hosting ubija proces PHP po
    // max_execution_time — przebieg nie może trwać całego odstępu paczki.
    $spread_window = min((int) floor($interval_seconds * 0.8), max(0, (int) apply_filters('evk_nl_batch_spread_max_seconds', 240)));
    $spread_ms     = count($rows) > 1 ? (int) floor($spread_window * 1000 / count($rows)) : 0;
    $delay_ms      = max($spread_ms, max(0, (int) apply_filters('evk_nl_send_delay_ms', 250)));

    // Zapas na wypadek ubicia procesu w trakcie rozłożonej paczki: kolejna
    // paczka zaplanowana z góry (wysłane maile są już oznaczone, pending
    // pójdą dalej). Przy normalnym zakończeniu zapas jest przeplanowywany.
    wp_schedule_single_event(time() + $interval_seconds + $spread_window + 60, 'evk_nl_process_batch', [$campaign_id]);

    $consecutive_failures = 0;
    $breaker_tripped      = false;
    $last_index           = count($rows) - 1;

    foreach ($rows as $i => $queue_row) {
        $subscriber = evk_nl_get_subscriber((int) $queue_row['subscriber_id']);
        if (!$subscriber || (int) $subscriber['status'] !== 1) {
            // Subskrybent wypisany lub usunięty — pomijamy
            $wpdb->update($q, ['status' => 'failed', 'error_message' => 'Subskrybent nieaktywny.'], ['id' => $queue_row['id']]);
            continue;
        }

        if (evk_nl_send_single($queue_row, $campaign, $template, $subscriber)) {
            $consecutive_failures = 0;
        } else {
            $consecutive_failures++;
            if ($consecutive_failures >= $max_consecutive) {
                $breaker_tripped = true;
                evk_nl_log($campaign_id, 'error', null, [
                    'msg' => sprintf(
                        'Paczka przerwana po %d błędach wysyłki pod rząd (prawdopodobny limit u dostawcy SMTP). Pozostałe maile zostaną wysłane w kolejnej paczce z automatycznie wydłużonym odstępem.',
                        $consecutive_failures
                    ),
                ]);
                break;
            }
        }

        if ($delay_ms > 0 && $i < $last_index) evk_nl_sleep_ms($delay_ms);
    }

    // Zamknij współdzielone połączenie SMTP po paczce
    evk_nl_mailer_close();

    // Sprawdź czy zostały pending
    $pending_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='pending'", $campaign_id
    ));

    if ($pending_count > 0) {
        if ($breaker_tripped) {
            // Automatyczny backoff: każde zadziałanie bezpiecznika podwaja
            // odstęp przed kolejną paczką (aż do limitu mnożnika); paczka
            // zakończona bez bezpiecznika zeruje mnożnik.
            $max_mult = max(1, (int) apply_filters('evk_nl_backoff_max_multiplier', 8));
            $mult     = min($max_mult, 2 * max(1, (int) get_option('evk_nl_backoff_' . $campaign_id, 1)));
            update_option('evk_nl_backoff_' . $campaign_id, $mult, false);
            $interval_seconds *= $mult;
            evk_nl_log($campaign_id, 'error', null, [
                'msg' => sprintf(
                    'Odstęp wydłużony ×%d po limicie SMTP — kolejna paczka za ok. %d min.',
                    $mult, max(1, (int) round($interval_seconds / 60))
                ),
            ]);
        } else {
            evk_nl_backoff_reset($campaign_id);
        }

        // Usuń zapasowy event i zaplanuj właściwy termin (WP odrzuciłby
        // duplikat hooka z tymi samymi argumentami w oknie 10 minut)
        wp_clear_scheduled_hook('evk_nl_process_batch', [$campaign_id]);
        wp_schedule_single_event(time() + $interval_seconds, 'evk_nl_process_batch', [$campaign_id]);
    } else {
        wp_clear_scheduled_hook('evk_nl_process_batch', [$campaign_id]);
        evk_nl_backoff_reset($campaign_id);
        evk_nl_update_campaign($campaign_id, ['status' => 'done']);
        evk_nl_log($campaign_id, 'sent', null, ['msg' => 'Wszystkie maile wysłane.']);
    }
}

function evk_nl_backoff_reset(int $campaign_id): void {
    delete_option('evk_nl_backoff_' . $campaign_id);
}

/**
 * Sen w milisekundach — usleep() powyżej sekundy nie jest przenośne,
 * więc pełne sekundy śpimy sleep()em.
 */
function evk_nl_sleep_ms(int $ms): void {
    if ($ms <= 0) return;
    $s = intdiv($ms, 1000);
    if ($s > 0) sleep($s);
    $rest = ($ms % 1000) * 1000;
    if ($rest > 0) usleep($rest);
}

// =========================================================================
// WYSYŁKA POJEDYNCZEGO MAILA
// =========================================================================

function evk_nl_send_single(array $queue_row, array $campaign, array $template, array $subscriber): bool {
    global $wpdb;
    $q = evk_nl_table('queue');

    // Inkrementuj attempts
    $attempts = (int) $queue_row['attempts'] + 1;
    $wpdb->update($q, ['attempts' => $attempts], ['id' => $queue_row['id']]);

    $result = evk_nl_send_mail($subscriber, $campaign, $template, $queue_row);

    if (is_wp_error($result)) {
        $error_msg = $result->get_error_message();
        $status    = $attempts >= 3 ? 'failed' : 'pending'; // Retry max 3 razy
        $wpdb->update($q, [
            'status'        => $status,
            'error_message' => $error_msg,
        ], ['id' => $queue_row['id']]);
        evk_nl_log((int) $campaign['id'], 'error', (int) $subscriber['id'], ['error' => $error_msg]);
        return false;
    }

    $wpdb->update($q, [
        'status'  => 'sent',
        'sent_at' => current_time('mysql'),
    ], ['id' => $queue_row['id']]);
    evk_nl_log((int) $campaign['id'], 'sent', (int) $subscriber['id']);
    return true;
}

// =========================================================================
// URUCHOMIENIE KAMPANII
// =========================================================================

function evk_nl_launch_campaign(int $campaign_id): bool {
    $campaign = evk_nl_get_campaign($campaign_id);
    if (!$campaign) return false;

    // Zablokuj ponowne uruchomienie jeśli already sending
    if (in_array($campaign['status'], ['sending', 'done'], true)) return false;

    $list_ids = json_decode($campaign['lists_json'] ?? '[]', true) ?: [];
    if (empty($list_ids)) return false;

    // Pobierz aktywnych subskrybentów
    $subscribers = evk_nl_get_campaign_subscribers($list_ids);
    if (empty($subscribers)) return false;

    global $wpdb;
    $q = evk_nl_table('queue');

    // Wyczyść starą kolejkę jeśli to restart
    $wpdb->delete($q, ['campaign_id' => $campaign_id]);

    // Wstaw do kolejki
    foreach ($subscribers as $sub) {
        $wpdb->insert($q, [
            'campaign_id'   => $campaign_id,
            'subscriber_id' => (int) $sub['id'],
            'status'        => 'pending',
            'attempts'      => 0,
        ]);
    }

    // Ustaw status — scheduled jeśli data w przyszłości, sending jeśli teraz
    // scheduled_at jest w czasie lokalnym WP (wp_timezone) — konwertuj na UTC timestamp
    $scheduled = $campaign['scheduled_at'] ?? '';
    if (!empty($scheduled)) {
        try {
            $tz   = new \DateTimeZone(wp_timezone_string());
            $dt   = new \DateTime($scheduled, $tz);
            $when = $dt->getTimestamp(); // UTC timestamp
        } catch (\Exception $e) {
            $when = strtotime($scheduled) ?: time();
        }
    } else {
        $when = time();
    }
    if ($when < time()) $when = time();

    $initial_status = ($when > time() + 30) ? 'scheduled' : 'sending';
    evk_nl_update_campaign($campaign_id, ['status' => $initial_status]);

    // Wyczyść ewentualny poprzedni zaplanowany cron przed dodaniem nowego
    wp_clear_scheduled_hook('evk_nl_process_batch', [$campaign_id]);
    evk_nl_backoff_reset($campaign_id);
    wp_schedule_single_event($when, 'evk_nl_process_batch', [$campaign_id]);

    $when_str = date('Y-m-d H:i:s', $when);
    evk_nl_log($campaign_id, 'sent', null, [
        'msg'          => 'Kampania uruchomiona. Subskrybentów: ' . count($subscribers),
        'scheduled_at' => $when_str,
    ]);
    return true;
}

// =========================================================================
// PAUZA / WZNOWIENIE
// =========================================================================

function evk_nl_pause_campaign(int $campaign_id): bool {
    $campaign = evk_nl_get_campaign($campaign_id);
    if (!$campaign || !in_array($campaign['status'], ['sending', 'scheduled'], true)) return false;
    // Anuluj zaplanowany cron
    wp_clear_scheduled_hook('evk_nl_process_batch', [$campaign_id]);
    return evk_nl_update_campaign($campaign_id, ['status' => 'paused']);
}

function evk_nl_cancel_campaign(int $campaign_id): bool {
    $campaign = evk_nl_get_campaign($campaign_id);
    if (!$campaign || !in_array($campaign['status'], ['sending', 'scheduled', 'paused'], true)) return false;
    wp_clear_scheduled_hook('evk_nl_process_batch', [$campaign_id]);
    evk_nl_backoff_reset($campaign_id);
    global $wpdb;
    $q = evk_nl_table('queue');
    $wpdb->query($wpdb->prepare(
        "UPDATE $q SET status='cancelled' WHERE campaign_id=%d AND status='pending'", $campaign_id
    ));
    evk_nl_log($campaign_id, 'error', null, ['msg' => 'Kampania anulowana — wysyłka zatrzymana.']);
    return evk_nl_update_campaign($campaign_id, ['status' => 'cancelled']);
}

function evk_nl_resume_campaign(int $campaign_id): bool {
    $campaign = evk_nl_get_campaign($campaign_id);
    if (!$campaign || $campaign['status'] !== 'paused') return false;
    evk_nl_update_campaign($campaign_id, ['status' => 'sending']);
    wp_schedule_single_event(time() + 5, 'evk_nl_process_batch', [$campaign_id]);
    return true;
}

function evk_nl_restart_campaign(int $campaign_id): bool {
    $campaign = evk_nl_get_campaign($campaign_id);
    if (!$campaign) return false;
    evk_nl_update_campaign($campaign_id, ['status' => 'draft']);
    return evk_nl_launch_campaign($campaign_id);
}
